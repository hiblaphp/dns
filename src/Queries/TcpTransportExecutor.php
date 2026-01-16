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
use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\DuplexResourceStream;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Executes DNS queries over TCP transport.
 *
 * TCP is used when UDP responses are truncated (>512 bytes) or for operations
 * requiring reliable delivery like zone transfers. Unlike UDP, TCP requires
 * connection setup overhead but supports unlimited message sizes.
 *
 * This executor maintains a persistent connection and pipelines multiple queries
 * over the same socket for efficiency. Queries are queued during connection setup
 * and sent once the connection is established.
 *
 * @see UdpTransportExecutor For standard UDP transport
 * @see SelectiveTransportExecutor For automatic UDP/TCP selection
 */
final class TcpTransportExecutor implements ExecutorInterface
{
    private readonly string $nameserver;

    private readonly Parser $parser;

    private readonly BinaryDumper $dumper;

    private readonly Randomizer $randomizer;

    private ?TcpStreamHandler $handler = null;

    /** @var array<int, array{packet: string, promise: Promise<Message>}> */
    private array $pendingConnection = [];

    private bool $connecting = false;

    private ?string $connectionWatcherId = null;

    /** @var resource|null */
    private $connectingSocket = null;

    public function __construct(string $nameserver)
    {
        $this->nameserver = $this->normalizeNameserver($nameserver, 'tcp');
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
        $this->randomizer = new Randomizer();
    }

    /**
     * {@inheritdoc}
     */
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

        /** @var Promise<Message> $promise */
        $promise = new Promise();
        $id = $request->id;

        // If handler is ready and not connecting, send immediately
        if ($this->handler !== null && $this->connecting === false) {
            $this->handler->send($packet, $id, $promise);
        } else {
            // Queue the query FIRST before attempting connection
            $this->pendingConnection[$id] = [
                'packet' => $packet,
                'promise' => $promise,
            ];

            // Then trigger connection if needed
            if ($this->handler === null && $this->connecting === false) {
                $this->connect();
            }
        }

        $promise->onCancel(function () use ($id) {
            if (isset($this->pendingConnection[$id])) {
                unset($this->pendingConnection[$id]);
            }

            // If all pending queries are cancelled, cleanup connection attempt
            if (\count($this->pendingConnection) === 0 && $this->connecting === true) {
                $this->cleanupConnectionAttempt();
            }

            if ($this->handler !== null) {
                $this->handler->cancel($id);

                // If handler has no more pending queries, clean it up
                if ($this->handler->isEmpty()) {
                    $this->cleanupHandler();
                }
            }
        });

        return $promise;
    }

    private function connect(): void
    {
        $this->connecting = true;

        set_error_handler(fn() => true);

        $socket = @stream_socket_client(
            $this->nameserver,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        restore_error_handler();

        if ($socket === false) {
            $this->connecting = false;
            $errorMessage = $errstr !== '' ? $errstr : 'Connection failed';
            $exception = new QueryFailedException(
                \sprintf('Unable to connect to DNS server %s: %s', $this->nameserver, $errorMessage),
                $errno ?? 0
            );

            $this->rejectAllPending($exception);

            return;
        }

        stream_set_blocking($socket, false);

        // Store socket reference for cleanup
        $this->connectingSocket = $socket;

        $this->connectionWatcherId = Loop::addStreamWatcher($socket, function () use ($socket) {
            $this->handleConnectionReady($socket);
        }, StreamWatcher::TYPE_WRITE);
    }

    /**
     * @param resource $socket
     */
    private function handleConnectionReady($socket): void
    {
        // Remove watcher and clear connecting state
        $this->removeConnectionWatcher();
        $this->connecting = false;
        $this->connectingSocket = null;

        // Check if connection succeeded
        $remoteName = @stream_socket_get_name($socket, true);
        if ($remoteName === false) {
            // Connection failed - close socket
            @fclose($socket);

            $exception = new QueryFailedException(
                \sprintf(
                    'Unable to connect to DNS server %s: Connection refused or invalid address',
                    $this->nameserver
                )
            );

            $this->rejectAllPending($exception);

            return;
        }

        // Connection successful
        $stream = new DuplexResourceStream($socket);

        $this->handler = new TcpStreamHandler(
            $stream,
            $this->parser,
            function (): void {
                $this->handler = null;
            }
        );

        // Send all queued queries
        foreach ($this->pendingConnection as $id => $pending) {
            $this->handler->send($pending['packet'], $id, $pending['promise']);
        }
        $this->pendingConnection = [];
    }

    private function removeConnectionWatcher(): void
    {
        if ($this->connectionWatcherId !== null) {
            Loop::removeStreamWatcher($this->connectionWatcherId);
            $this->connectionWatcherId = null;
        }
    }

    private function cleanupConnectionAttempt(): void
    {
        $this->removeConnectionWatcher();
        $this->connecting = false;

        if ($this->connectingSocket !== null) {
            @fclose($this->connectingSocket);
            $this->connectingSocket = null;
        }
    }

    private function cleanupHandler(): void
    {
        if ($this->handler !== null) {
            $this->handler->close();
            $this->handler = null;
        }
    }

    private function rejectAllPending(\Throwable $exception): void
    {
        foreach ($this->pendingConnection as $pending) {
            $pending['promise']->reject($exception);
        }
        $this->pendingConnection = [];
    }

    private function normalizeNameserver(string $nameserver, string $scheme): string
    {
        if (str_contains($nameserver, '://')) {
            if (!str_starts_with($nameserver, $scheme . '://')) {
                throw new InvalidArgumentException("Only {$scheme}:// scheme is supported");
            }
        } else {
            $binaryIp = @inet_pton($nameserver);

            if ($binaryIp !== false) {
                if (\strlen($binaryIp) === 16) {
                    // IPv6 - wrap in brackets
                    $nameserver = "{$scheme}://[{$nameserver}]";
                } else {
                    // IPv4
                    $nameserver = "{$scheme}://{$nameserver}";
                }
            } else {
                $nameserver = "{$scheme}://{$nameserver}";
            }
        }

        $parts = parse_url($nameserver);
        if (!isset($parts['port'])) {
            $nameserver .= ':53';
        }

        return $nameserver;
    }

    public function __destruct()
    {
        $this->cleanupConnectionAttempt();
        $this->cleanupHandler();
    }
}
