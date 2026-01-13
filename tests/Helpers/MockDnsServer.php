<?php

namespace Tests\Helpers;

use Hibla\Dns\Models\Message;
use Hibla\Dns\Protocols\BinaryDumper;
use Hibla\Dns\Protocols\Parser;
use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

class MockDnsServer
{
    private $serverSocket;
    private $clientSocket;
    private Parser $parser;
    private BinaryDumper $dumper;
    private $watcherId;
    
    /** @var callable|null */
    private $requestHandler;

    public function __construct()
    {
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
    }

    public static function createUdpPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
        
        if ($sockets === false) {
            throw new \RuntimeException('Failed to create socket pair');
        }

        stream_set_blocking($sockets[0], false);
        stream_set_blocking($sockets[1], false);

        return $sockets;
    }

    public static function createTcpPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        
        if ($sockets === false) {
            throw new \RuntimeException('Failed to create socket pair');
        }

        stream_set_blocking($sockets[0], false);
        stream_set_blocking($sockets[1], false);

        return $sockets;
    }

    public function start($serverSocket, callable $requestHandler): void
    {
        $this->serverSocket = $serverSocket;
        $this->requestHandler = $requestHandler;

        $this->watcherId = Loop::addStreamWatcher(
            $serverSocket,
            function () {
                $this->handleRequest();
            },
            StreamWatcher::TYPE_READ
        );
    }

    public function stop(): void
    {
        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }

        if (\is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
        }
    }

    private function handleRequest(): void
    {
        $data = fread($this->serverSocket, 65535);
        
        if ($data === false || $data === '') {
            return;
        }

        try {
            $request = $this->parser->parseMessage($data);
            
            $response = ($this->requestHandler)($request);
            
            if ($response instanceof Message) {
                $responseData = $this->dumper->toBinary($response);
                fwrite($this->serverSocket, $responseData);
            }
        } catch (\Throwable $e) {
            // Ignore malformed requests in tests
        }
    }


    public function startTcp($serverSocket, callable $requestHandler): void
    {
        $this->serverSocket = $serverSocket;
        $this->requestHandler = $requestHandler;

        $buffer = '';

        $this->watcherId = Loop::addStreamWatcher(
            $serverSocket,
            function () use (&$buffer) {
                $chunk = fread($this->serverSocket, 8192);
                
                if ($chunk === false || $chunk === '') {
                    return;
                }

                $buffer .= $chunk;

                while (\strlen($buffer) >= 2) {
                    $length = unpack('n', substr($buffer, 0, 2))[1];
                    
                    if (\strlen($buffer) < $length + 2) {
                        break; // Wait for more data
                    }

                    $messageData = substr($buffer, 2, $length);
                    $buffer = substr($buffer, $length + 2);

                    try {
                        $request = $this->parser->parseMessage($messageData);
                        $response = ($this->requestHandler)($request);
                        
                        if ($response instanceof Message) {
                            $responseData = $this->dumper->toBinary($response);
                            $packet = pack('n', strlen($responseData)) . $responseData;
                            fwrite($this->serverSocket, $packet);
                        }
                    } catch (\Throwable $e) {
                        // Ignore errors
                    }
                }
            },
            StreamWatcher::TYPE_READ
        );
    }
}