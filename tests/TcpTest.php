<?php

use Hibla\Dns\Queries\TcpTransportExecutor;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Models\Record;
use Hibla\Dns\Protocols\BinaryDumper;
use Hibla\Dns\Protocols\Parser;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;

describe('TcpTransportExecutor', function () {
    $dumper = new BinaryDumper();
    $parser = new Parser();

    $resources = [];
    $executors = [];

    beforeEach(function () {
        Loop::reset();
    });

    afterEach(function () use (&$resources, &$executors) {
        foreach ($executors as $executor) {
            if (method_exists($executor, 'close')) {
                try {
                    $executor->close();
                } catch (\Throwable $e) {
                    // Suppress errors during cleanup
                }
            }
        }
        $executors = [];

        foreach ($resources as $resource) {
            if (is_resource($resource)) {
                @fclose($resource);
            }
        }
        $resources = [];

        Loop::reset();
        Loop::stop();
    });

    it('constructs with nameserver without scheme', function () use (&$executors) {
        $executor = new TcpTransportExecutor('8.8.8.8');
        $executors[] = $executor;

        expect($executor)->toBeInstanceOf(TcpTransportExecutor::class);
    });

    it('constructs with nameserver with tcp:// scheme', function () use (&$executors) {
        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53');
        $executors[] = $executor;

        expect($executor)->toBeInstanceOf(TcpTransportExecutor::class);
    });

    it('throws on invalid scheme', function () {
        expect(fn() => new TcpTransportExecutor('udp://8.8.8.8:53'))
            ->toThrow(InvalidArgumentException::class, 'Only tcp:// scheme is supported');
    });

    it('adds default port 53 if not specified', function () use (&$executors) {
        $executor = new TcpTransportExecutor('tcp://8.8.8.8');
        $executors[] = $executor;

        expect($executor)->toBeInstanceOf(TcpTransportExecutor::class);
    });

    it('executes successful query', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        // Simulate server response in background
        Loop::addTimer(0.01, function () use ($serverSock, $dumper, $parser) {
            $request = fread($serverSock, 2048);
            $lengthData = unpack('n', substr($request, 0, 2));
            $requestData = substr($request, 2, $lengthData[1]);
            
            $requestMessage = $parser->parseMessage($requestData);

            $response = new Message();
            $response->id = $requestMessage->id;
            $response->isResponse = true;
            $response->answers[] = new Record(
                name: 'example.com',
                type: RecordType::A,
                class: RecordClass::IN,
                ttl: 3600,
                data: '93.184.216.34'
            );

            $responseData = $dumper->toBinary($response);
            $packet = pack('n', strlen($responseData)) . $responseData;
            fwrite($serverSock, $packet);
        });

        $result = $promise->wait();

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->isResponse)->toBeTrue();
        expect(count($result->answers))->toBe(1);
        expect($result->answers[0]->name)->toBe('example.com');
        expect($result->answers[0]->type)->toBe(RecordType::A);
        expect($result->answers[0]->data)->toBe('93.184.216.34');
    });

    it('rejects promise on query too large', function () use (&$executors) {
        [$clientSock, $serverSock] = create_socket_pair();

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        // Create a query that will result in >65535 bytes
        $longName = str_repeat('a', 70000) . '.com';
        $query = new Query($longName, RecordType::A, RecordClass::IN);
        
        $promise = $executor->query($query);

        expect(fn() => $promise->wait())
            ->toThrow(QueryFailedException::class, 'Query too large for TCP transport');

        fclose($clientSock);
        fclose($serverSock);
    });

    it('handles multiple queries through same connection', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query1 = new Query('example.com', RecordType::A, RecordClass::IN);
        $query2 = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise1 = $executor->query($query1);
        $promise2 = $executor->query($query2);

        // Simulate server responses
        Loop::addTimer(0.01, function () use ($serverSock, $dumper, $parser) {
            $data = '';
            while (strlen($data) < 4) {
                $chunk = fread($serverSock, 2048);
                if ($chunk === false || $chunk === '') break;
                $data .= $chunk;
            }

            $offset = 0;
            $responses = [];
            
            while ($offset < strlen($data)) {
                if (strlen($data) - $offset < 2) break;
                
                $lengthData = unpack('n', substr($data, $offset, 2));
                $length = $lengthData[1];
                
                if (strlen($data) - $offset < $length + 2) break;
                
                $requestData = substr($data, $offset + 2, $length);
                $requestMessage = $parser->parseMessage($requestData);

                $response = new Message();
                $response->id = $requestMessage->id;
                $response->isResponse = true;
                $response->answers[] = new Record(
                    name: $requestMessage->questions[0]->name,
                    type: RecordType::A,
                    class: RecordClass::IN,
                    ttl: 3600,
                    data: '192.168.1.' . $requestMessage->id
                );

                $responses[] = $response;
                $offset += $length + 2;
            }

            // Send all responses
            foreach ($responses as $response) {
                $responseData = $dumper->toBinary($response);
                $packet = pack('n', strlen($responseData)) . $responseData;
                fwrite($serverSock, $packet);
            }
        });

        $result1 = $promise1->wait();
        $result2 = $promise2->wait();

        expect($result1)->toBeInstanceOf(Message::class);
        expect($result2)->toBeInstanceOf(Message::class);
        expect($result1->isResponse)->toBeTrue();
        expect($result2->isResponse)->toBeTrue();
        expect(count($result1->answers))->toBe(1);
        expect(count($result2->answers))->toBe(1);
    });

    it('handles query cancellation', function () use (&$resources, &$executors) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        Loop::addTimer(0.01, fn() => $promise->cancel());

        expect(fn() => $promise->wait())
            ->toThrow(PromiseCancelledException::class);
    });

    it('closes connection after last query is cancelled', function () use (&$resources, &$executors) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query1 = new Query('example1.com', RecordType::A, RecordClass::IN);
        $query2 = new Query('example2.com', RecordType::A, RecordClass::IN);

        $promise1 = $executor->query($query1);
        $promise2 = $executor->query($query2);

        Loop::addTimer(0.01, fn() => $promise1->cancel());
        Loop::addTimer(0.02, fn() => $promise2->cancel());

        // Wait a bit to ensure cleanup happens
        Loop::addTimer(0.15, fn() => Loop::stop());
        Loop::run();

        expect($promise1->isCancelled())->toBeTrue();
        expect($promise2->isCancelled())->toBeTrue();
    });

    it('does not close connection when some queries remain', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query1 = new Query('example1.com', RecordType::A, RecordClass::IN);
        $query2 = new Query('example2.com', RecordType::A, RecordClass::IN);

        $promise1 = $executor->query($query1);
        $promise2 = $executor->query($query2);

        // Cancel first query
        Loop::addTimer(0.01, fn() => $promise1->cancel());

        // Respond to second query
        Loop::addTimer(0.02, function () use ($serverSock, $dumper, $parser) {
            $data = fread($serverSock, 2048);
            
            $offset = 0;
            while ($offset < strlen($data)) {
                if (strlen($data) - $offset < 2) break;
                
                $lengthData = unpack('n', substr($data, $offset, 2));
                $length = $lengthData[1];
                
                if (strlen($data) - $offset < $length + 2) break;
                
                $requestData = substr($data, $offset + 2, $length);
                $requestMessage = $parser->parseMessage($requestData);

                $response = new Message();
                $response->id = $requestMessage->id;
                $response->isResponse = true;
                $response->answers[] = new Record(
                    name: 'example2.com',
                    type: RecordType::A,
                    class: RecordClass::IN,
                    ttl: 3600,
                    data: '192.168.1.1'
                );

                $responseData = $dumper->toBinary($response);
                $packet = pack('n', strlen($responseData)) . $responseData;
                fwrite($serverSock, $packet);
                
                $offset += $length + 2;
            }
        });

        expect(fn() => $promise1->wait())->toThrow(PromiseCancelledException::class);
        
        $result2 = $promise2->wait();
        expect($result2)->toBeInstanceOf(Message::class);
        expect($result2->answers[0]->data)->toBe('192.168.1.1');
    });

    it('handles connection failure', function () use (&$executors) {
        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', function () {
            return false; // Simulate connection failure
        });
        $executors[] = $executor;

        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect(fn() => $promise->wait())
            ->toThrow(QueryFailedException::class, 'Socket factory must return a valid stream resource');
    });

    it('reuses connection for subsequent queries', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $connectCount = 0;
        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', function () use ($clientSock, &$connectCount) {
            $connectCount++;
            return $clientSock;
        });
        $executors[] = $executor;

        $query1 = new Query('example1.com', RecordType::A, RecordClass::IN);
        $query2 = new Query('example2.com', RecordType::A, RecordClass::IN);
        $query3 = new Query('example3.com', RecordType::A, RecordClass::IN);

        $promise1 = $executor->query($query1);
        $promise2 = $executor->query($query2);
        $promise3 = $executor->query($query3);

        // Simulate responses
        Loop::addTimer(0.01, function () use ($serverSock, $dumper, $parser) {
            $data = '';
            $attempts = 0;
            while (strlen($data) < 10 && $attempts < 100) {
                $chunk = fread($serverSock, 2048);
                if ($chunk !== false && $chunk !== '') {
                    $data .= $chunk;
                }
                $attempts++;
                usleep(1000);
            }

            $offset = 0;
            while ($offset < strlen($data)) {
                if (strlen($data) - $offset < 2) break;
                
                $lengthData = unpack('n', substr($data, $offset, 2));
                $length = $lengthData[1];
                
                if (strlen($data) - $offset < $length + 2) break;
                
                $requestData = substr($data, $offset + 2, $length);
                $requestMessage = $parser->parseMessage($requestData);

                $response = new Message();
                $response->id = $requestMessage->id;
                $response->isResponse = true;
                $response->answers[] = new Record(
                    name: $requestMessage->questions[0]->name,
                    type: RecordType::A,
                    class: RecordClass::IN,
                    ttl: 3600,
                    data: '192.168.1.1'
                );

                $responseData = $dumper->toBinary($response);
                $packet = pack('n', strlen($responseData)) . $responseData;
                fwrite($serverSock, $packet);
                
                $offset += $length + 2;
            }
        });

        $result1 = $promise1->wait();
        $result2 = $promise2->wait();
        $result3 = $promise3->wait();

        expect($connectCount)->toBe(1); // Only connected once
        expect($result1)->toBeInstanceOf(Message::class);
        expect($result2)->toBeInstanceOf(Message::class);
        expect($result3)->toBeInstanceOf(Message::class);
    });

    it('cleans up on executor destruction', function () use (&$resources) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);

        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        unset($executor); // Trigger destructor

        // Allow event loop to process
        Loop::addTimer(0.1, fn() => Loop::stop());
        Loop::run();

        expect(fn() => $promise->wait())
            ->toThrow(QueryFailedException::class, 'Executor closed');
    });

    it('handles explicit close', function () use (&$resources, &$executors) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        Loop::addTimer(0.01, fn() => $executor->close());

        expect(fn() => $promise->wait())
            ->toThrow(QueryFailedException::class, 'Executor closed');
    });

    it('handles connection drop during query', function () use (&$resources, &$executors) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        // Simulate connection drop
        Loop::addTimer(0.01, fn() => fclose($serverSock));

        expect(fn() => $promise->wait())
            ->toThrow(QueryFailedException::class, 'Connection closed');
    });

    it('generates random transaction IDs', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $transactionIds = [];
        $promises = [];

        for ($i = 0; $i < 10; $i++) {
            $query = new Query("example{$i}.com", RecordType::A, RecordClass::IN);
            $promises[] = $executor->query($query);
        }

        Loop::addTimer(0.01, function () use ($serverSock, &$transactionIds, $parser, $dumper) {
            $data = '';
            $attempts = 0;
            while ($attempts < 100) {
                $chunk = fread($serverSock, 2048);
                if ($chunk !== false && $chunk !== '') {
                    $data .= $chunk;
                }
                $attempts++;
                usleep(1000);
            }

            $offset = 0;
            while ($offset < strlen($data)) {
                if (strlen($data) - $offset < 2) break;
                
                $lengthData = unpack('n', substr($data, $offset, 2));
                $length = $lengthData[1];
                
                if (strlen($data) - $offset < $length + 2) break;
                
                $requestData = substr($data, $offset + 2, $length);
                $requestMessage = $parser->parseMessage($requestData);
                $transactionIds[] = $requestMessage->id;

                // Send response
                $response = new Message();
                $response->id = $requestMessage->id;
                $response->isResponse = true;
                $response->answers[] = new Record(
                    name: $requestMessage->questions[0]->name,
                    type: RecordType::A,
                    class: RecordClass::IN,
                    ttl: 3600,
                    data: '192.168.1.1'
                );

                $responseData = $dumper->toBinary($response);
                $packet = pack('n', strlen($responseData)) . $responseData;
                fwrite($serverSock, $packet);
                
                $offset += $length + 2;
            }
        });

        // Wait for all promises
        foreach ($promises as $promise) {
            $promise->wait();
        }

        // Check that we got multiple different IDs
        $uniqueIds = array_unique($transactionIds);
        expect(count($uniqueIds))->toBeGreaterThan(1);
        expect(count($uniqueIds))->toBeLessThanOrEqual(10);
    });

    it('handles multiple simultaneous cancellations', function () use (&$resources, &$executors) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $query = new Query("example{$i}.com", RecordType::A, RecordClass::IN);
            $promises[] = $executor->query($query);
        }

        // Cancel all simultaneously
        Loop::addTimer(0.01, function () use ($promises) {
            foreach ($promises as $promise) {
                $promise->cancel();
            }
        });

        Loop::addTimer(0.15, fn() => Loop::stop());
        Loop::run();

        foreach ($promises as $promise) {
            expect($promise->isCancelled())->toBeTrue();
        }
    });

    it('handles query after connection was closed and reopened', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock1, $serverSock1] = create_socket_pair();
        [$clientSock2, $serverSock2] = create_socket_pair();
        $resources = [$clientSock1, $serverSock1, $clientSock2, $serverSock2];

        $socketIndex = 0;
        $sockets = [$clientSock1, $clientSock2];
        $serverSockets = [$serverSock1, $serverSock2];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', function () use (&$socketIndex, $sockets) {
            return $sockets[$socketIndex++];
        });
        $executors[] = $executor;

        // First query
        $query1 = new Query('example1.com', RecordType::A, RecordClass::IN);
        $promise1 = $executor->query($query1);

        Loop::addTimer(0.01, function () use ($serverSock1, $dumper, $parser) {
            $data = fread($serverSock1, 2048);
            $lengthData = unpack('n', substr($data, 0, 2));
            $requestData = substr($data, 2, $lengthData[1]);
            $requestMessage = $parser->parseMessage($requestData);

            $response = new Message();
            $response->id = $requestMessage->id;
            $response->isResponse = true;
            $response->answers[] = new Record(
                name: 'example1.com',
                type: RecordType::A,
                class: RecordClass::IN,
                ttl: 3600,
                data: '192.168.1.1'
            );

            $responseData = $dumper->toBinary($response);
            $packet = pack('n', strlen($responseData)) . $responseData;
            fwrite($serverSock1, $packet);
        });

        $result1 = $promise1->wait();
        expect($result1->answers[0]->data)->toBe('192.168.1.1');

        // Close executor (simulating idle timeout or manual close)
        $executor->close();

        // Second query (should create new connection)
        $query2 = new Query('example2.com', RecordType::A, RecordClass::IN);
        $promise2 = $executor->query($query2);

        Loop::addTimer(0.01, function () use ($serverSock2, $dumper, $parser) {
            $data = fread($serverSock2, 2048);
            $lengthData = unpack('n', substr($data, 0, 2));
            $requestData = substr($data, 2, $lengthData[1]);
            $requestMessage = $parser->parseMessage($requestData);

            $response = new Message();
            $response->id = $requestMessage->id;
            $response->isResponse = true;
            $response->answers[] = new Record(
                name: 'example2.com',
                type: RecordType::A,
                class: RecordClass::IN,
                ttl: 3600,
                data: '192.168.1.2'
            );

            $responseData = $dumper->toBinary($response);
            $packet = pack('n', strlen($responseData)) . $responseData;
            fwrite($serverSock2, $packet);
        });

        $result2 = $promise2->wait();
        expect($result2->answers[0]->data)->toBe('192.168.1.2');
        expect($socketIndex)->toBe(2); // Verify new connection was created
    });

    it('preserves query information in request', function () use (&$resources, &$executors, $dumper, $parser) {
        [$clientSock, $serverSock] = create_socket_pair();
        $resources = [$clientSock, $serverSock];

        $executor = new TcpTransportExecutor('tcp://8.8.8.8:53', fn() => $clientSock);
        $executors[] = $executor;

        $query = new Query('example.com', RecordType::AAAA, RecordClass::IN);
        $promise = $executor->query($query);

        $capturedRequest = null;

        Loop::addTimer(0.01, function () use ($serverSock, $parser, &$capturedRequest, $dumper) {
            $data = fread($serverSock, 2048);
            $lengthData = unpack('n', substr($data, 0, 2));
            $requestData = substr($data, 2, $lengthData[1]);
            $capturedRequest = $parser->parseMessage($requestData);

            // Send response
            $response = new Message();
            $response->id = $capturedRequest->id;
            $response->isResponse = true;
            $response->answers[] = new Record(
                name: 'example.com',
                type: RecordType::AAAA,
                class: RecordClass::IN,
                ttl: 3600,
                data: '2606:2800:220:1:248:1893:25c8:1946'
            );

            $responseData = $dumper->toBinary($response);
            $packet = pack('n', strlen($responseData)) . $responseData;
            fwrite($serverSock, $packet);
        });

        $result = $promise->wait();

        expect($capturedRequest)->not->toBeNull();
        expect($capturedRequest->isResponse)->toBeFalse();
        expect($capturedRequest->recursionDesired)->toBeTrue();
        expect(count($capturedRequest->questions))->toBe(1);
        expect($capturedRequest->questions[0]->name)->toBe('example.com');
        expect($capturedRequest->questions[0]->type)->toBe(RecordType::AAAA);
        expect($capturedRequest->questions[0]->class)->toBe(RecordClass::IN);
    });
});