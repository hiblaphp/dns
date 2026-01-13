<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

final class CachingExecutor implements ExecutorInterface
{
    private const int DEFAULT_TTL = 60;

    /**
     * @param CacheInterface<Message> $cache
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ExecutorInterface $executor
    ) {}

    public function query(Query $query): PromiseInterface
    {
        $key = \sprintf('%s:%d:%d', $query->name, $query->type->value, $query->class->value);

        /** @var Promise<Message> $promise */
        $promise = new Promise();
        
        /** @var PromiseInterface|null $pendingOperation */
        $pendingOperation = null;
        
        /** @var bool $cacheError - Track if cache failed */
        $cacheError = false;

        $pendingOperation = $this->cache->get($key);

        $pendingOperation->then(
            onFulfilled: function (?Message $cachedMessage) use ($key, $query, $promise, &$pendingOperation, &$cacheError) {
                if ($cachedMessage !== null) {
                    $promise->resolve($cachedMessage);
                    return;
                }

                $this->queryNetwork($query, $key, $promise, $pendingOperation, $cacheError);
            },
            onRejected: function (Throwable $e) use ($query, $key, $promise, &$pendingOperation, &$cacheError) {
                $cacheError = true; 
                $this->queryNetwork($query, $key, $promise, $pendingOperation, $cacheError);
            }
        );

        $promise->onCancel(function () use (&$pendingOperation) {
            if ($pendingOperation instanceof PromiseInterface) {
                $pendingOperation->cancelChain();
            }
        });

        return $promise;
    }

    /**
     * Queries the network and handles caching
     */
    private function queryNetwork(Query $query, string $key, Promise $promise, ?PromiseInterface &$pendingOperation, bool $cacheError): void
    {
        $pendingOperation = $this->executor->query($query);
        
        $pendingOperation->then(
            function (Message $response) use ($promise, $key, $cacheError) {
                if (!$cacheError && !$response->isTruncated) {
                    $ttl = $this->calculateTtl($response);
                    $this->cache->set($key, $response, (float) $ttl);
                }
                $promise->resolve($response);
            },
            function (Throwable $e) use ($promise) {
                $promise->reject($e);
            }
        );
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