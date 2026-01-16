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

/**
 * Executes DNS queries over UDP transport.
 *
 * UDP is the standard transport for DNS because it's fast and connectionless.
 * However, it has a 512-byte limit and is unreliable (no guaranteed delivery).
 * Responses exceeding this limit will be truncated and should be retried via TCP.
 *
 * This executor does NOT implement timeouts or retries - wrap it with
 * TimeoutExecutor and RetryExecutor for production use.
 *
 * @see SelectiveTransportExecutor For automatic UDP/TCP fallback on truncation
 * @see TimeoutExecutor For timeout support
 * @see RetryExecutor For retry logic
 */
final class UdpTransportExecutor implements ExecutorInterface
{
    private readonly string $nameserver;

    private readonly Parser $parser;

    private readonly BinaryDumper $dumper;

    private const int MAX_UDP_PACKET_SIZE = 512;

    public function __construct(string $nameserver)
    {
        $this->nameserver = $this->normalizeNameserver($nameserver, 'udp');
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
                $errno ?? 0
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
        /** @var string|null $watcherId */
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
}
