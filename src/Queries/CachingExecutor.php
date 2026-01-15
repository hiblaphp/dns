<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Caches DNS responses to avoid repeated network queries.
 *
 * Stores successful DNS responses in cache with TTL (time-to-live) from the
 * DNS records. Subsequent identical queries are served from cache until expiry.
 * This significantly improves performance and reduces DNS server load.
 *
 * Respects DNS TTL values and uses the minimum TTL from all records in the response.
 */
final class CachingExecutor implements ExecutorInterface
{
    private const int DEFAULT_TTL = 60;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ExecutorInterface $executor
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function query(Query $query): PromiseInterface
    {
        $key = \sprintf('%s:%d:%d', $query->name, $query->type->value, $query->class->value);

        /** @var Promise<Message> $promise */
        $promise = new Promise();

        /** @var PromiseInterface<Message|null> $cacheOperation */
        // @phpstan-ignore-next-line
        $cacheOperation = $this->cache->get($key);

        /** @var PromiseInterface<Message>|null $networkOperation - Track network query operation */
        $networkOperation = null;

        $cacheOperation->then(
            onFulfilled: function (?Message $cachedMessage) use ($key, $query, $promise, &$networkOperation) {
                if ($cachedMessage !== null) {
                    $promise->resolve($cachedMessage);

                    return;
                }

                $networkOperation = $this->queryNetwork($query, $key, $promise, false);
            },
            onRejected: function (mixed $e) use ($query, $key, $promise, &$networkOperation): void {
                $networkOperation = $this->queryNetwork($query, $key, $promise, true);
            }
        );

        $promise->onCancel(function () use (&$cacheOperation, &$networkOperation): void {
            $cacheOperation->cancelChain();

            if ($networkOperation !== null) {
                $networkOperation->cancelChain();
            }
        });

        return $promise;
    }

    /**
     * Queries the network and handles caching
     *
     * @param Promise<Message> $promise
     * @return PromiseInterface<Message>
     */
    private function queryNetwork(Query $query, string $key, Promise $promise, bool $cacheError): PromiseInterface
    {
        $networkOperation = $this->executor->query($query);

        $networkOperation->then(
            function (Message $response) use ($promise, $key, $cacheError): void {
                if (! $cacheError && ! $response->isTruncated) {
                    $ttl = $this->calculateTtl($response);
                    $this->cache->set($key, $response, (float) $ttl);
                }
                $promise->resolve($response);
            },
            function (mixed $e) use ($promise): void {
                $promise->reject($e);
            }
        );

        return $networkOperation;
    }

    private function calculateTtl(Message $message): int
    {
        $minTtl = null;

        foreach ($message->answers as $record) {
            if ($minTtl === null || $record->ttl < $minTtl) {
                $minTtl = $record->ttl;
            }
        }

        foreach ($message->authority as $record) {
            if ($minTtl === null || $record->ttl < $minTtl) {
                $minTtl = $record->ttl;
            }
        }

        return $minTtl ?? self::DEFAULT_TTL;
    }
}
