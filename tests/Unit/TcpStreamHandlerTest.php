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
use Hibla\Promise\Promise;

describe('TcpStreamHandler', function () {
    $dumper = new BinaryDumper();
    $parser = new Parser();
    $resources = [];

    afterEach(function () use (&$resources) {
        foreach ($resources as $resource) {
            if (is_resource($resource)) {
                @fclose($resource);
            }
        }
        $resources = [];
    });

    it('sends data with correct 2-byte length prefix', function () use (&$resources, $dumper, $parser) {
        [$client, $server] = create_socket_pair();
        $resources = [$client, $server]; 

        $handler = new TcpStreamHandler($client, $parser, fn() => null);

        $query = new Query('google.com', RecordType::A, RecordClass::IN);
        $message = Message::createRequest($query);
        $message->id = 12345;
        $binary = $dumper->toBinary($message);
        
        $packet = pack('n', strlen($binary)) . $binary;
        $promise = new Promise();
        $handler->send($packet, 12345, $promise);

        Loop::runOnce();

        $received = fread($server, 1024);
        
        $lengthData = unpack('n', substr($received, 0, 2));
        expect($lengthData[1])->toBe(strlen($binary));
        expect(substr($received, 2))->toBe($binary);

        $handler->close();
    });

    it('handles fragmented reads (split packet)', function () use (&$resources, $dumper, $parser) {
        [$client, $server] = create_socket_pair();
        $resources = [$client, $server];

        $handler = new TcpStreamHandler($client, $parser, fn() => null);
        $promise = new Promise();
        $handler->send("dummy", 1, $promise); 

        $response = new Message();
        $response->id = 1;
        $response->isResponse = true;
        $binaryResponse = $dumper->toBinary($response);
        $packet = pack('n', strlen($binaryResponse)) . $binaryResponse;

        // Split packet into 3 chunks
        fwrite($server, substr($packet, 0, 1));
        Loop::runOnce();
        
        fwrite($server, substr($packet, 1, 5));
        Loop::runOnce();
        
        fwrite($server, substr($packet, 6));
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->id)->toBe(1);

        $handler->close();
    });

    it('handles multiple packets in one chunk (Multiplexing)', function () use (&$resources, $dumper, $parser) {
        [$client, $server] = create_socket_pair();
        $resources = [$client, $server];

        $handler = new TcpStreamHandler($client, $parser, fn() => null);
        $p1 = new Promise();
        $p2 = new Promise();
        
        $handler->send("d1", 10, $p1);
        $handler->send("d2", 20, $p2);

        // Prepare 2 responses
        $m1 = new Message(); $m1->id = 10; $m1->isResponse = true;
        $b1 = $dumper->toBinary($m1);
        
        $m2 = new Message(); $m2->id = 20; $m2->isResponse = true;
        $b2 = $dumper->toBinary($m2);

        // Write both concatenated
        $data = pack('n', strlen($b1)) . $b1 . pack('n', strlen($b2)) . $b2;
        fwrite($server, $data);

        Loop::runOnce();

        expect($p1->isFulfilled())->toBeTrue();
        expect($p2->isFulfilled())->toBeTrue();

        $handler->close();
    });

    it('closes and rejects promises on connection loss', function () use (&$resources, $parser) {
        [$client, $server] = create_socket_pair();
        $resources = [$client, $server];
        
        $closed = false;
        $handler = new TcpStreamHandler($client, $parser, function() use (&$closed) { $closed = true; });

        $promise = new Promise();
        $handler->send("req", 1, $promise);

        // Simulate connection close
        fclose($server);

        Loop::runOnce();

        expect($closed)->toBeTrue();
        expect($promise->isRejected())->toBeTrue();
        expect(fn() => $promise->wait())->toThrow(QueryFailedException::class, 'lost');
    });

    it('closes on idle timeout', function () use (&$resources, $parser) {
        [$client, $server] = create_socket_pair();
        $resources = [$client, $server];

        $closed = false;
        $handler = new TcpStreamHandler($client, $parser, function() use (&$closed) { $closed = true; });

        $p = new Promise();
        $handler->send("data", 1, $p);
        $handler->cancel(1); 

        usleep(60000); 
        Loop::runOnce();

        expect($closed)->toBeTrue();
    });

    it('closes on malformed data', function () use (&$resources, $parser) {
        [$client, $server] = create_socket_pair();
        $resources = [$client, $server];
        
        $closed = false;
        $handler = new TcpStreamHandler($client, $parser, function() use (&$closed) { $closed = true; });
        
        $p = new Promise();
        $handler->send("req", 1, $p);

        $garbage = pack('n', 10) . "NOT_DNS_PKT"; 
        fwrite($server, $garbage);

        Loop::runOnce();

        expect($closed)->toBeTrue();
        expect($p->isRejected())->toBeTrue();
        expect(fn() => $p->wait())->toThrow(QueryFailedException::class, 'Invalid message');
    });
});