<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

final class RetryExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $executor,
        private readonly int $retries = 2
    ) {}

    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        /** @var PromiseInterface|null $currentPendingOperation */
        $currentPendingOperation = null;
        $isCancelled = false;

        $attempt = function (int $retriesLeft) use ($query, $promise, &$currentPendingOperation, &$isCancelled, &$attempt): void {
            if ($isCancelled) {
                return;
            }

            $currentPendingOperation = $this->executor->query($query);

            $currentPendingOperation->then(
                onFulfilled: function ($response) use ($promise) {
                    $promise->resolve($response);
                },
                onRejected: function (Throwable $e) use ($retriesLeft, $promise, $attempt, &$isCancelled) {
                    if ($isCancelled) {
                        return;
                    }

                    if ($retriesLeft > 0) {
                        $attempt($retriesLeft - 1);
                    } else {
                        $promise->reject($e);
                    }
                }
            );
        };

        $attempt($this->retries);

        $promise->onCancel(function () use (&$currentPendingOperation, &$isCancelled) {
            $isCancelled = true;
            if ($currentPendingOperation instanceof PromiseInterface) {
                $currentPendingOperation->cancelChain();
            }
        });

        return $promise;
    }
}
