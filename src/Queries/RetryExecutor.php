<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Retries failed DNS queries automatically.
 *
 * Wraps another executor and retries queries on failure (e.g., network timeouts,
 * packet loss). This is essential for UDP transport since it's unreliable and
 * packets can be dropped. Does not retry on application-level errors like NXDOMAIN.
 *
 * @see UdpTransportExecutor UDP benefits most from retry logic
 * @see TimeoutExecutor Often used together for timeout handling
 */
final class RetryExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $executor,
        private readonly int $retries = 2
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        /** @var PromiseInterface<Message>|null $currentPendingOperation */
        $currentPendingOperation = null;

        $attempt = function (int $retriesLeft) use ($query, $promise, &$currentPendingOperation, &$attempt): void {
            $currentPendingOperation = $this->executor->query($query);

            $currentPendingOperation->then(
                onFulfilled: function ($response) use ($promise) {
                    $promise->resolve($response);
                },
                onRejected: function (mixed $e) use ($retriesLeft, $promise, $attempt) {
                    if ($retriesLeft > 0) {
                        $attempt($retriesLeft - 1);
                    } else {
                        $promise->reject($e);
                    }
                }
            );
        };

        $attempt($this->retries);

        $promise->onCancel(function () use (&$currentPendingOperation) {
            if ($currentPendingOperation !== null) {
                $currentPendingOperation->cancelChain();
            }
        });

        return $promise;
    }
}
