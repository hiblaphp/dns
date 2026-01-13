<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Exceptions\ResponseTruncatedException;
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

final class UdpTransportExecutor implements ExecutorInterface
{
    private readonly string $nameserver;

    private readonly Parser $parser;

    private readonly BinaryDumper $dumper;
    
    private const int MAX_UDP_PACKET_SIZE = 512;

    /**
     * @param string $nameserver IP address of the nameserver (e.g. "8.8.8.8" or "8.8.8.8:53")
     */
    public function __construct(string $nameserver)
    {
        if (!str_contains($nameserver, '://')) {
            $nameserver = 'udp://' . $nameserver;
        } elseif (!str_starts_with($nameserver, 'udp://')) {
            throw new InvalidArgumentException('Only udp:// scheme is supported');
        }

        $parts = parse_url($nameserver);
        if (!isset($parts['port'])) {
            $nameserver .= ':53';
        }

        $this->nameserver = $nameserver;
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
    }

    /**
     * {@inheritdoc}
     */
    public function query(Query $query): PromiseInterface
    {
        $message = Message::createRequest($query);
        $queryData = $this->dumper->toBinary($message);

        if (\strlen($queryData) > 512) {
            return Promise::rejected(new QueryFailedException(
                \sprintf('DNS query for %s failed: Query too large for UDP transport', $query->name)
            ));
        }

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
            return Promise::rejected(new QueryFailedException(
                \sprintf('Unable to connect to DNS server %s: %s', $this->nameserver, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, false);

        $written = @fwrite($socket, $queryData);
        if ($written !== \strlen($queryData)) {
            fclose($socket);
            return Promise::rejected(new QueryFailedException('Failed to write DNS query to socket'));
        }

        /** @var Promise<Message> $promise */
        $promise = new Promise();
        $watcherId = null;

        $cleanup = function () use ($socket, &$watcherId): void {
            if ($watcherId !== null) {
                Loop::removeStreamWatcher($watcherId);
                $watcherId = null;
            }
            if (\is_resource($socket)) {
                fclose($socket);
            }
        };

        $watcherId = Loop::addStreamWatcher(
            $socket,
            function () use ($socket, $promise, $message, $cleanup, $query) {
                $data = fread($socket, self::MAX_UDP_PACKET_SIZE);

                if ($data === false || $data === '') {
                    return;
                }

                try {
                    $response = $this->parser->parseMessage($data);
                } catch (\Throwable $e) {
                    return;
                }

                if ($response->id !== $message->id) {
                    return;
                }

                if ($response->isTruncated) {
                    $cleanup();
                    $promise->reject(new ResponseTruncatedException(
                        \sprintf('DNS query for %s returned truncated result', $query->name)
                    ));
                    return;
                }

                $cleanup();
                $promise->resolve($response);
            },
            StreamWatcher::TYPE_READ
        );

        $promise->onCancel($cleanup);

        return $promise;
    }
}
