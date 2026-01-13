<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Handlers\TcpStreamHandler;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Protocols\BinaryDumper;
use Hibla\Dns\Protocols\Parser;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\DuplexResourceStream;
use InvalidArgumentException;
use Random\Randomizer;

final class TcpTransportExecutor implements ExecutorInterface
{
    private readonly string $nameserver;
    private readonly Parser $parser;
    private readonly BinaryDumper $dumper;
    private readonly Randomizer $randomizer;
    private ?TcpStreamHandler $handler = null;
    
    /** @var callable|null */
    private $socketFactory;

    /**
     * @param string $nameserver DNS server address (e.g., "8.8.8.8" or "tcp://8.8.8.8:53")
     * @param callable|null $socketFactory Optional factory function that returns a socket resource for testing
     */
    public function __construct(string $nameserver, ?callable $socketFactory = null)
    {
        $this->socketFactory = $socketFactory;
        
        if ($socketFactory === null) {
            if (!str_contains($nameserver, '://')) {
                $nameserver = 'tcp://' . $nameserver;
            } elseif (!str_starts_with($nameserver, 'tcp://')) {
                throw new InvalidArgumentException('Only tcp:// scheme is supported');
            }

            $parts = parse_url($nameserver);
            if (!isset($parts['port'])) {
                $nameserver .= ':53';
            }
        }

        $this->nameserver = $nameserver;
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
        $this->randomizer = new Randomizer();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function query(Query $query): PromiseInterface
    {
        $request = Message::createRequest($query);
        $request->id = $this->randomizer->getInt(0, 0xFFFF);

        $queryData = $this->dumper->toBinary($request);
        $length = \strlen($queryData);

        if ($length > 0xFFFF) {
            return Promise::rejected(new QueryFailedException(
                \sprintf('DNS query for %s failed: Query too large for TCP transport', $query->name)
            ));
        }

        // Framing: [Length (2 bytes)] + [Data]
        $packet = pack('n', $length) . $queryData;

        if ($this->handler === null) {
            try {
                $this->connect();
            } catch (QueryFailedException $e) {
                return Promise::rejected($e);
            }
        }

        /** @var Promise<Message> $promise */
        $promise = new Promise();
        $id = $request->id;

        // Delegate to the Handler
        $this->handler->send($packet, $id, $promise);

        $promise->onCancel(function () use ($id) {
            if ($this->handler) {
                $this->handler->cancel($id);
                
                if ($this->handler->isEmpty()) {
                    $this->handler->close();
                }
            }
        });

        return $promise;
    }

    /**
     * Establishes the TCP connection and initializes the handler.
     * @throws QueryFailedException
     */
    private function connect(): void
    {
        if ($this->socketFactory !== null) {
            $socket = ($this->socketFactory)();
            
            if (!\is_resource($socket)) {
                throw new QueryFailedException(
                    'Socket factory must return a valid stream resource'
                );
            }
        } else {
            $socket = @stream_socket_client(
                $this->nameserver,
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($socket === false) {
                throw new QueryFailedException(
                    \sprintf('Unable to connect to DNS server %s: %s', $this->nameserver, $errstr),
                    $errno
                );
            }
        }

        stream_set_blocking($socket, false);

        $stream = new DuplexResourceStream($socket);

        $this->handler = new TcpStreamHandler(
            $stream,
            $this->parser,
            fn() => $this->handler = null // Reset property when handler closes
        );
    }

    public function close(): void
    {
        if ($this->handler !== null) {
            $this->handler->close('Executor closed');
            $this->handler = null;
        }
    }
}