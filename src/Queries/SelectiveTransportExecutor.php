<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Exceptions\ResponseTruncatedException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Message;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

/**
 * Attempts to send a query via UDP. If the response is truncated (TC bit set),
 * it automatically retries the query via TCP.
 */
final class SelectiveTransportExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $udpExecutor,
        private readonly ExecutorInterface $tcpExecutor
    ) {}

    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        $udpPromise = $this->udpExecutor->query($query);

        $udpPromise->then(
            onFulfilled: fn($response) => $promise->resolve($response),
            onRejected: function (Throwable $e) use ($query, $promise) {
                if ($e instanceof ResponseTruncatedException) {
                    $this->retryWithTcp($query, $promise);
                } else {
                    $promise->reject($e);
                }
            }
        );

        $promise->onCancel(function () use ($udpPromise) {
            $udpPromise->cancelChain();
        });

        return $promise;
    }

    private function retryWithTcp(Query $query, Promise $promise): void
    {
        $tcpPromise = $this->tcpExecutor->query($query);

        $tcpPromise->then(
            fn($response) => $promise->resolve($response),
            fn($error) => $promise->reject($error)
        );

        $promise->onCancel(function () use ($tcpPromise) {
            $tcpPromise->cancelChain();
        });
    }
}