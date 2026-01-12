<?php

use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Handlers\TcpStreamHandler;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\RecordClass;
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
    $handlers = []; // Track handlers separately

    beforeEach(function () {
        Loop::reset(); // Reset before each test
    });

    afterEach(function () use (&$resources, &$handlers) {
        // Close handlers first (gracefully)
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

        // Then close streams and resources
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

        // Reset loop after cleanup
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

        // Send request to register the transaction ID
        $handler->send("dummy", 1, $promise);

        // Prepare response packet
        $response = new Message();
        $response->id = 1;
        $response->isResponse = true;
        $binary = $dumper->toBinary($response);
        $packet = pack('n', strlen($binary)) . $binary;

        // Schedule fragmented writes
        Loop::addTimer(0.01, fn() => @fwrite($serverSock, substr($packet, 0, 1)));
        Loop::addTimer(0.03, fn() => @fwrite($serverSock, substr($packet, 1, 5)));
        Loop::addTimer(0.05, fn() => @fwrite($serverSock, substr($packet, 6)));

        // Add timeout
        Loop::addTimer(0.5, function () use ($promise) {
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

        // Write both packets at once
        Loop::addTimer(0.01, fn() => @fwrite($serverSock, $packet1 . $packet2));
        
        Promise::all([$p1, $p2])->then(fn() => Loop::stop());

        // Add timeout
        Loop::addTimer(0.5, fn() => Loop::stop());

        Loop::run();

        expect($p1->isFulfilled())->toBeTrue();
        expect($p2->isFulfilled())->toBeTrue();
    });

    it('closes and rejects promises on connection loss', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];
        
        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function() use (&$closed) { 
            $closed = true; 
        });
        $handlers[] = $handler;

        $promise = new Promise();
        $handler->send("req", 1, $promise);
        
        $promise->catch(function () {
            Loop::stop();
        });

        Loop::addTimer(0.01, fn() => @fclose($serverSock));
        
        // Add timeout
        Loop::addTimer(0.5, fn() => Loop::stop());

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
        $handler = new TcpStreamHandler($stream, $parser, function() use (&$closed) { 
            $closed = true; 
            Loop::stop();
        });
        $handlers[] = $handler;

        $p = new Promise();
        $handler->send("data", 1, $p);
        
        // Cancel to empty the pending list -> starts idle timer
        Loop::addTimer(0.01, fn() => $handler->cancel(1));

        // Add timeout
        Loop::addTimer(0.5, fn() => Loop::stop());

        Loop::run();

        expect($closed)->toBeTrue();
    });

    it('closes on malformed data', function () use (&$resources, &$handlers, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $stream = new DuplexResourceStream($clientSock);
        $resources = [$stream, $serverSock];
        
        $closed = false;
        $handler = new TcpStreamHandler($stream, $parser, function() use (&$closed) { 
            $closed = true; 
        });
        $handlers[] = $handler;
        
        $p = new Promise();
        $handler->send("req", 1, $p);
        
        $p->catch(function () {
            Loop::stop();
        });

        // Valid length but garbage body
        Loop::addTimer(0.01, function() use ($serverSock) {
            $garbage = pack('n', 10) . "NOT_DNS_PKT"; 
            @fwrite($serverSock, $garbage);
        });

        // Add timeout
        Loop::addTimer(0.5, fn() => Loop::stop());

        Loop::run();

        expect($closed)->toBeTrue();
        expect($p->isRejected())->toBeTrue();
        expect(fn() => $p->wait())->toThrow(QueryFailedException::class, 'Invalid message');
    });
});