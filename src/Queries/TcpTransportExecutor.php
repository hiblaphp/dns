<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Protocols\BinaryDumper;
use Hibla\Dns\Protocols\Parser;
use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use InvalidArgumentException;
use Random\Randomizer;

final class TcpTransportExecutor implements ExecutorInterface
{
    private readonly string $nameserver;
    private readonly Parser $parser;
    private readonly BinaryDumper $dumper;
    private readonly Randomizer $randomizer;

    /** @var resource|null */
    private mixed $socket = null;

    /** @var array<int, Promise<Message>> Mapping of Transaction ID to Promise */
    private array $pendingPromises = [];

    /** @var array<int, string> Mapping of Transaction ID to Query Description (for error messages) */
    private array $pendingNames = [];

    // --- State Machine & Buffers ---
    private string $writeBuffer = '';
    private ?string $writeWatcherId = null;

    private string $readBuffer = '';
    private ?string $readWatcherId = null;

    // --- Idle Timer ---
    // Closes the TCP socket if no new queries arrive within this timeframe.
    private const float IDLE_PERIOD = 0.05; // 50ms
    private ?string $idleTimerId = null;

    public function __construct(string $nameserver)
    {
        if (!str_contains($nameserver, '://')) {
            $nameserver = 'tcp://' . $nameserver;
        } elseif (!str_starts_with($nameserver, 'tcp://')) {
            throw new InvalidArgumentException('Only tcp:// scheme is supported');
        }

        $parts = parse_url($nameserver);
        if (!isset($parts['port'])) {
            $nameserver .= ':53';
        }

        $this->nameserver = $nameserver;
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
        $this->randomizer = new Randomizer();
    }

    public function query(Query $query): PromiseInterface
    {
        $request = Message::createRequest($query);

        // Ensure unique ID for pipelining
        while (isset($this->pendingPromises[$request->id])) {
            $request->id = $this->randomizer->getInt(0, 0xFFFF);
        }

        $queryData = $this->dumper->toBinary($request);
        $length = \strlen($queryData);

        // DNS over TCP enforces a 16-bit length prefix limit
        if ($length > 0xFFFF) {
            return Promise::rejected(new QueryFailedException(
                \sprintf('DNS query for %s failed: Query too large for TCP transport', $query->name)
            ));
        }

        // Prepend the 2-byte length header required for DNS over TCP
        $packet = pack('n', $length) . $queryData;

        // 1. Establish connection if not already open
        if ($this->socket === null) {
            $socket = @stream_socket_client(
                $this->nameserver,
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($socket === false) {
                return Promise::rejected(new QueryFailedException(
                    \sprintf('Unable to connect to DNS server %s: %s', $this->nameserver, $errstr),
                    $errno
                ));
            }

            stream_set_blocking($socket, false);
            $this->socket = $socket;
        }

        // Cancel idle timer since we have work to do
        if ($this->idleTimerId !== null) {
            Loop::cancelTimer($this->idleTimerId);
            $this->idleTimerId = null;
        }

        // 2. Buffer the packet and start the write watcher
        $this->writeBuffer .= $packet;
        if ($this->writeWatcherId === null) {
            $this->writeWatcherId = Loop::addStreamWatcher(
                $this->socket,
                $this->handleWritable(...),
                StreamWatcher::TYPE_WRITE
            );
        }

        // 3. Register the Promise
        /** @var Promise<Message> $promise */
        $promise = new Promise();
        $this->pendingPromises[$request->id] = $promise;
        $this->pendingNames[$request->id] = $query->name;

        // Handle Cancellation
        $promise->onCancel(function () use ($request) {
            $name = $this->pendingNames[$request->id] ?? 'unknown';
            unset($this->pendingPromises[$request->id], $this->pendingNames[$request->id]);
            
            $this->checkIdle();
        });

        return $promise;
    }

    private function handleWritable(): void
    {
        // Check if connection was successful (required for ASYNC_CONNECT)
        if ($this->readWatcherId === null) {
            if (stream_socket_get_name($this->socket, true) === false) {
                $this->closeWithError('Connection to DNS server refused or timed out');
                return;
            }

            $this->readWatcherId = Loop::addStreamWatcher(
                $this->socket,
                $this->handleReadable(...),
                StreamWatcher::TYPE_READ
            );
        }

        // Write buffer to socket
        set_error_handler(static fn() => true);
        $written = @fwrite($this->socket, $this->writeBuffer);
        restore_error_handler();

        if ($written === false || $written === 0) {
            $this->closeWithError('Lost connection to DNS server while writing');
            return;
        }

        // Update buffer
        if ($written < \strlen($this->writeBuffer)) {
            $this->writeBuffer = substr($this->writeBuffer, $written);
        } else {
            $this->writeBuffer = '';
            Loop::removeStreamWatcher($this->writeWatcherId);
            $this->writeWatcherId = null;
        }
    }

    private function handleReadable(): void
    {
        $chunk = @fread($this->socket, 65536);

        if ($chunk === false || $chunk === '') {
            $this->closeWithError('Connection to DNS server lost');
            return;
        }

        $this->readBuffer .= $chunk;

        // Process all complete packets in the buffer
        // A packet is complete when we have at least 2 bytes (length) AND the full body
        while (\strlen($this->readBuffer) >= 2) {
            $lengthData = unpack('n', substr($this->readBuffer, 0, 2));
            $expectedLength = $lengthData[1];

            // Wait for more data if we don't have the full packet
            if (\strlen($this->readBuffer) < $expectedLength + 2) {
                return;
            }

            // Extract the exact packet data and advance the buffer
            $packetData = substr($this->readBuffer, 2, $expectedLength);
            $this->readBuffer = substr($this->readBuffer, $expectedLength + 2);

            try {
                $response = $this->parser->parseMessage($packetData);
            } catch (\Throwable $e) {
                $this->closeWithError('Invalid message received from DNS server');
                return;
            }

            // Ensure we are expecting this transaction ID
            if (!isset($this->pendingPromises[$response->id])) {
                // Ignore unexpected responses (could be from a cancelled query)
                continue;
            }

            // Resolve the specific promise
            $promise = $this->pendingPromises[$response->id];
            unset($this->pendingPromises[$response->id], $this->pendingNames[$response->id]);
            
            $promise->resolve($response);

            $this->checkIdle();
        }
    }

    private function closeWithError(string $reason): void
    {
        if ($this->readWatcherId !== null) Loop::removeStreamWatcher($this->readWatcherId);
        if ($this->writeWatcherId !== null) Loop::removeStreamWatcher($this->writeWatcherId);
        if ($this->idleTimerId !== null) Loop::cancelTimer($this->idleTimerId);

        $this->readWatcherId = null;
        $this->writeWatcherId = null;
        $this->idleTimerId = null;
        $this->readBuffer = '';
        $this->writeBuffer = '';

        if (\is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;

        // Reject all pending promises
        $exception = new QueryFailedException(\sprintf('DNS query failed: %s', $reason));
        foreach ($this->pendingPromises as $promise) {
            $promise->reject($exception);
        }

        $this->pendingPromises = [];
        $this->pendingNames = [];
    }

    private function checkIdle(): void
    {
        // If there are no pending queries, schedule the socket to be closed
        // This keeps the socket alive just long enough for high-concurrency requests
        if ($this->idleTimerId === null && empty($this->pendingPromises)) {
            $this->idleTimerId = Loop::addTimer(self::IDLE_PERIOD, function () {
                $this->closeWithError('Idle timeout');
            });
        }
    }
}