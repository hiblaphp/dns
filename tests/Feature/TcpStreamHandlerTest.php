<?php

use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Handlers\TcpStreamHandler;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Models\Record;
use Hibla\Dns\Protocols\BinaryDumper;
use Hibla\Dns\Protocols\Parser;
use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use Hibla\Promise\Promise;
use Hibla\Stream\DuplexResourceStream;

describe('TcpStreamHandler', function () {
    $dumper = new BinaryDumper();
    $parser = new Parser();

    $resources = [];
    $handlers = [];

    beforeEach(function () {
        Loop::reset();
    });

    afterEach(function () use (&$resources, &$handlers) {
        foreach ($handlers as $handler) {
            if (method_exists($handler, 'close')) {
                try {
                    $handler->close();
                } catch (\Throwable $e) {
                    // Suppress errors during cleanup
                }
            }
        }
        $handlers = [];

        foreach ($resources as $resource) {
            if ($resource instanceof DuplexResourceStream) {
                try {
                    $resource->close();
                } catch (\Throwable $e) {
                    // Suppress errors during cleanup
                }
            } elseif (is_resource($resource)) {
                @fclose($resource);
            }
        }
        $resources = [];

        Loop::reset();
        Loop::stop();
    });

    it('sends data with correct 2-byte length prefix', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();

        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $query = new Query('google.com', RecordType::A, RecordClass::IN);
        $message = Message::createRequest($query);
        $message->id = 12345;
        $binary = $dumper->toBinary($message);

        $packet = pack('n', strlen($binary)) . $binary;
        $promise = new Promise();

        $received = '';
        $watcherId = Loop::addStreamWatcher($serverSock, function () use ($serverSock, &$received, $binary, &$watcherId) {
            $chunk = fread($serverSock, 1024);
            if ($chunk === false || $chunk === '') return;

            $received .= $chunk;
            if (strlen($received) >= strlen($binary) + 2) {
                Loop::removeStreamWatcher($watcherId);
                Loop::stop();
            }
        }, StreamWatcher::TYPE_READ);

        $handler->send($packet, 12345, $promise);

        Loop::run();

        $lengthData = unpack('n', substr($received, 0, 2));
        expect($lengthData[1])->toBe(strlen($binary));
        expect(substr($received, 2))->toBe($binary);
    });

    it('handles fragmented reads (split packet)', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $promise = new Promise();
        $promise->then(function () {
            Loop::stop();
        });

        $response = new Message();
        $response->id = 1;
        $response->isResponse = true;
        $binary = $dumper->toBinary($response);
        $packet = pack('n', strlen($binary)) . $binary;

        $handler->send("dummy", 1, $promise);

        // Schedule fragmented writes
        Loop::addTimer(0.01, fn() => fwrite($serverSock, substr($packet, 0, 1)));
        Loop::addTimer(0.02, fn() => fwrite($serverSock, substr($packet, 1, 5)));
        Loop::addTimer(0.03, fn() => fwrite($serverSock, substr($packet, 6)));

        Loop::addTimer(1.0, function () use ($promise) {
            if (!$promise->isFulfilled()) {
                Loop::stop();
            }
        });

        Loop::run();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->id)->toBe(1);
    });

    it('handles multiple packets in one chunk (Multiplexing)', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $p1 = new Promise();
        $p2 = new Promise();

        $handler->send("d1", 10, $p1);
        $handler->send("d2", 20, $p2);

        $m1 = new Message();
        $m1->id = 10;
        $m1->isResponse = true;

        $m2 = new Message();
        $m2->id = 20;
        $m2->isResponse = true;

        $packet1 = pack('n', strlen($dumper->toBinary($m1))) . $dumper->toBinary($m1);
        $packet2 = pack('n', strlen($dumper->toBinary($m2))) . $dumper->toBinary($m2);

        Loop::addTimer(0.01, fn() => fwrite($serverSock, $packet1 . $packet2));

        Promise::all([$p1, $p2])->then(fn() => Loop::stop());

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        expect($p1->isFulfilled())->toBeTrue();
        expect($p2->isFulfilled())->toBeTrue();
    });

    it('closes and rejects promises on connection loss', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function () use (&$closed) {
            $closed = true;
        });
        $handlers[] = $handler;

        $promise = new Promise();
        $handler->send("req", 1, $promise);

        $promise->catch(function () {
            Loop::stop();
        });

        Loop::addTimer(0.01, fn() => fclose($serverSock));

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        expect($closed)->toBeTrue();
        expect($promise->isRejected())->toBeTrue();
        expect(fn() => $promise->wait())->toThrow(QueryFailedException::class, 'Connection closed');
    });

    it('closes on idle timeout', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function () use (&$closed) {
            $closed = true;
            Loop::stop();
        });
        $handlers[] = $handler;

        $p = new Promise();
        $handler->send("data", 1, $p);

        Loop::addTimer(0.01, fn() => $handler->cancel(1));

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        expect($closed)->toBeTrue();
    });

    it('closes on malformed data', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function () use (&$closed) {
            $closed = true;
        });
        $handlers[] = $handler;

        $p = new Promise();
        $handler->send("req", 1, $p);

        $p->catch(function () {
            Loop::stop();
        });

        Loop::addTimer(0.01, function () use ($serverSock) {
            $garbage = pack('n', 10) . "NOT_DNS_PKT";
            fwrite($serverSock, $garbage);
        });

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        expect($closed)->toBeTrue();
        expect($p->isRejected())->toBeTrue();
        expect(fn() => $p->wait())->toThrow(QueryFailedException::class, 'Invalid message');
    });

    it('handles partial length prefix (only 1 byte arrives)', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $promise = new Promise();
        $promise->then(fn() => Loop::stop());

        $response = new Message();
        $response->id = 1;
        $response->isResponse = true;
        $binary = $dumper->toBinary($response);
        $packet = pack('n', strlen($binary)) . $binary;

        $handler->send("dummy", 1, $promise);

        // Send only 1 byte of length prefix, then complete later
        Loop::addTimer(0.01, fn() => fwrite($serverSock, substr($packet, 0, 1)));
        Loop::addTimer(0.02, fn() => fwrite($serverSock, substr($packet, 1)));

        Loop::addTimer(1.0, function () use ($promise) {
            if (!$promise->isFulfilled()) {
                Loop::stop();
            }
        });

        Loop::run();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->id)->toBe(1);
    });

    it('handles multiple fragmented packets in sequence', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $p1 = new Promise();
        $p2 = new Promise();
        $p3 = new Promise();

        $handler->send("d1", 1, $p1);
        $handler->send("d2", 2, $p2);
        $handler->send("d3", 3, $p3);

        $packets = [];
        foreach ([1, 2, 3] as $id) {
            $m = new Message();
            $m->id = $id;
            $m->isResponse = true;
            $binary = $dumper->toBinary($m);
            $packets[] = pack('n', strlen($binary)) . $binary;
        }

        // Send all packets fragmented and interleaved
        Loop::addTimer(0.01, fn() => fwrite($serverSock, substr($packets[0], 0, 3)));
        Loop::addTimer(0.02, fn() => fwrite($serverSock, substr($packets[0], 3) . substr($packets[1], 0, 2)));
        Loop::addTimer(0.03, fn() => fwrite($serverSock, substr($packets[1], 2) . substr($packets[2], 0, 5)));
        Loop::addTimer(0.04, fn() => fwrite($serverSock, substr($packets[2], 5)));

        Promise::all([$p1, $p2, $p3])->then(fn() => Loop::stop());

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        expect($p1->isFulfilled())->toBeTrue();
        expect($p2->isFulfilled())->toBeTrue();
        expect($p3->isFulfilled())->toBeTrue();
        expect($p1->getValue()->id)->toBe(1);
        expect($p2->getValue()->id)->toBe(2);
        expect($p3->getValue()->id)->toBe(3);
    });

    it('ignores response for unknown transaction ID', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $p1 = new Promise();
        $handler->send("d1", 10, $p1);

        // Send response for ID 999 (unknown) and then correct ID 10
        $m1 = new Message();
        $m1->id = 999; // Unknown ID
        $m1->isResponse = true;

        $m2 = new Message();
        $m2->id = 10; // Correct ID
        $m2->isResponse = true;

        $packet1 = pack('n', strlen($dumper->toBinary($m1))) . $dumper->toBinary($m1);
        $packet2 = pack('n', strlen($dumper->toBinary($m2))) . $dumper->toBinary($m2);

        Loop::addTimer(0.01, fn() => fwrite($serverSock, $packet1 . $packet2));

        $p1->then(fn() => Loop::stop());

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        // Only p1 should resolve (ID 10), unknown ID 999 is ignored
        expect($p1->isFulfilled())->toBeTrue();
        expect($p1->getValue()->id)->toBe(10);
    });

    it('handles rapid successive sends', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $promises = [];
       
        for ($i = 1; $i <= 10; $i++) {
            $promises[$i] = new Promise();
            $handler->send("data$i", $i, $promises[$i]);
        }

        // Respond to all
        Loop::addTimer(0.01, function () use ($serverSock, $dumper) {
            for ($i = 1; $i <= 10; $i++) {
                $m = new Message();
                $m->id = $i;
                $m->isResponse = true;
                $packet = pack('n', strlen($dumper->toBinary($m))) . $dumper->toBinary($m);
                fwrite($serverSock, $packet);
            }
        });

        Promise::all($promises)->then(fn() => Loop::stop());

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        foreach ($promises as $id => $promise) {
            expect($promise->isFulfilled())->toBeTrue();
            expect($promise->getValue()->id)->toBe($id);
        }
    });

    it('handles cancel of non-existent transaction', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        // Cancel non-existent ID should not throw
        $handler->cancel(999);
        $handler->cancel(1000);

        expect(true)->toBeTrue(); // Just ensure no exception
    });

    it('handles multiple cancels of same transaction', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function () use (&$closed) {
            $closed = true;
            Loop::stop();
        });
        $handlers[] = $handler;

        $p = new Promise();
        $handler->send("data", 1, $p);

        // Cancel same ID multiple times
        Loop::addTimer(0.01, function () use ($handler) {
            $handler->cancel(1);
            $handler->cancel(1); // Second cancel
            $handler->cancel(1); // Third cancel
        });

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        // Should close on idle after cancellation
        expect($closed)->toBeTrue();
    });

    it('handles responses arriving out of order', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $p1 = new Promise();
        $p2 = new Promise();
        $p3 = new Promise();

        $handler->send("d1", 10, $p1);
        $handler->send("d2", 20, $p2);
        $handler->send("d3", 30, $p3);

        // Send responses in different order: 30, 10, 20
        $messages = [30, 10, 20];
        $packets = '';
        foreach ($messages as $id) {
            $m = new Message();
            $m->id = $id;
            $m->isResponse = true;
            $binary = $dumper->toBinary($m);
            $packets .= pack('n', strlen($binary)) . $binary;
        }

        Loop::addTimer(0.01, fn() => fwrite($serverSock, $packets));

        Promise::all([$p1, $p2, $p3])->then(fn() => Loop::stop());

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        expect($p1->isFulfilled())->toBeTrue();
        expect($p2->isFulfilled())->toBeTrue();
        expect($p3->isFulfilled())->toBeTrue();
        expect($p1->getValue()->id)->toBe(10);
        expect($p2->getValue()->id)->toBe(20);
        expect($p3->getValue()->id)->toBe(30);
    });

    it('handles close during pending operations', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $promises = [];
        for ($i = 1; $i <= 5; $i++) {
            $promises[] = new Promise();
            $handler->send("data$i", $i, $promises[$i - 1]);
        }

        $rejectedCount = 0;
        foreach ($promises as $promise) {
            $promise->catch(function () use (&$rejectedCount) {
                $rejectedCount++;
                if ($rejectedCount === 5) {
                    Loop::stop();
                }
            });
        }

        // Close handler while operations pending
        Loop::addTimer(0.01, fn() => $handler->close('Manual close'));

        Loop::addTimer(1.0, fn() => Loop::stop());

        Loop::run();

        // All should be rejected
        expect($rejectedCount)->toBe(5);
        foreach ($promises as $promise) {
            expect($promise->isRejected())->toBeTrue();
        }
    });

    it('handles extremely large packet', function () use (&$resources, &$handlers, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $handler = new TcpStreamHandler($stream, $parser, fn() => null);
        $handlers[] = $handler;

        $promise = new Promise();
        $promise->then(fn() => Loop::stop());

        $response = new Message();
        $response->id = 1;
        $response->isResponse = true;

        for ($i = 0; $i < 50; $i++) {
            $response->answers[] = new Record(
                name: "example{$i}.com",
                type: RecordType::A,
                class: RecordClass::IN,
                ttl: 3600,
                data: '192.168.1.' . ($i % 256)
            );
        }

        $binary = $dumper->toBinary($response);
        $packet = pack('n', strlen($binary)) . $binary;

        $handler->send("dummy", 1, $promise);

        $chunkSize = 512;
        $offset = 0;
        $delay = 0.01;
        while ($offset < strlen($packet)) {
            $chunk = substr($packet, $offset, $chunkSize);
            Loop::addTimer($delay, fn() => fwrite($serverSock, $chunk));
            $offset += $chunkSize;
            $delay += 0.01;
        }

        Loop::addTimer(2.0, function () use ($promise) {
            if (!$promise->isFulfilled()) {
                Loop::stop();
            }
        });

        Loop::run();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->id)->toBe(1);
        expect(count($promise->getValue()->answers))->toBe(50);
    });

    it('does not close on idle when operations are pending', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function () use (&$closed) {
            $closed = true;
        });
        $handlers[] = $handler;

        $p = new Promise();
        $handler->send("data", 1, $p);

        Loop::addTimer(0.2, fn() => Loop::stop());

        Loop::run();

        expect($closed)->toBeFalse();
    });

    it('restarts idle timer on new send after cancel', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];

        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function () use (&$closed) {
            $closed = true;
            Loop::stop();
        });
        $handlers[] = $handler;

        $p1 = new Promise();
        $handler->send("data1", 1, $p1);

        Loop::addTimer(0.01, function () use ($handler) {
            $handler->cancel(1);
        });

        Loop::addTimer(0.02, function () use ($handler) {
            $p2 = new Promise();
            $handler->send("data2", 2, $p2); 
        });

        Loop::addTimer(0.15, function () use (&$closed) {
            if (!$closed) {
                Loop::stop();
            }
        });

        Loop::run();

        // Should NOT have closed because new send canceled idle timer
        expect($closed)->toBeFalse();
    });
});
