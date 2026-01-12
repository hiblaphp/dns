<?php

declare(strict_types=1);

namespace Hibla\Dns\Handlers;

use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Protocols\Parser;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\DuplexStreamInterface;

/**
 * @internal Handles framing (length-prefix) and multiplexing for a TCP DNS connection.
 *           Now simplified by using Hibla\Stream.
 */
final class TcpStreamHandler
{
    /** @var array<int, Promise<Message>> */
    private array $pending = [];

    private string $readBuffer = '';
    private const float IDLE_PERIOD = 0.05;
    private ?string $idleTimerId = null;

    /**
     * @param callable(): void $onClose
     */
    public function __construct(
        private readonly DuplexStreamInterface $stream,
        private readonly Parser $parser,
        private readonly mixed $onClose
    ) {
        $this->stream->on('data', $this->onData(...));
        $this->stream->on('error', $this->onError(...));
        $this->stream->on('close', $this->onStreamClose(...));
    }

    public function send(string $packet, int $transactionId, Promise $promise): void
    {
        if ($this->idleTimerId !== null) {
            Loop::cancelTimer($this->idleTimerId);
            $this->idleTimerId = null;
        }

        $this->pending[$transactionId] = $promise;
        
        $this->stream->write($packet);
    }

    public function cancel(int $transactionId): void
    {
        if (isset($this->pending[$transactionId])) {
            unset($this->pending[$transactionId]);
            $this->checkIdle();
        }
    }

    private function onData(string $chunk): void
    {
        $this->readBuffer .= $chunk;

        while (\strlen($this->readBuffer) >= 2) {
            $lengthData = unpack('n', substr($this->readBuffer, 0, 2));
            $expectedLength = $lengthData[1];

            if (\strlen($this->readBuffer) < $expectedLength + 2) {
                return;
            }

            $packetData = substr($this->readBuffer, 2, $expectedLength);
            $this->readBuffer = substr($this->readBuffer, $expectedLength + 2);

            $this->processPacket($packetData);
        }
    }

    private function processPacket(string $data): void
    {
        try {
            $response = $this->parser->parseMessage($data);
        } catch (\Throwable $e) {
            $this->close('Invalid message received from DNS server');
            return;
        }

        if (isset($this->pending[$response->id])) {
            $promise = $this->pending[$response->id];
            unset($this->pending[$response->id]);
            $promise->resolve($response);
            $this->checkIdle();
        }
    }

    private function onError(\Throwable $e): void
    {
        $this->close('Stream error: ' . $e->getMessage());
    }

    private function onStreamClose(): void
    {
        $this->close('Connection closed');
    }

    private function checkIdle(): void
    {
        if ($this->idleTimerId === null && empty($this->pending)) {
            $this->idleTimerId = Loop::addTimer(self::IDLE_PERIOD, fn() => $this->close(null));
        }
    }

    public function close(?string $errorReason = null): void
    {
        if ($this->idleTimerId !== null) {
            Loop::cancelTimer($this->idleTimerId);
            $this->idleTimerId = null;
        }

        $this->stream->close();

        if ($errorReason !== null && !empty($this->pending)) {
            $exception = new QueryFailedException("DNS query failed: $errorReason");
            foreach ($this->pending as $promise) {
                $promise->reject($exception);
            }
        }

        $this->pending = [];
        ($this->onClose)();
    }
}