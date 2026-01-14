<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Exceptions\TimeoutException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final class TimeoutExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $executor,
        private readonly float $timeout
    ) {}

    /**
     * @inheritDoc
     */
    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        $pendingPromise = $this->executor->query($query);
        /** @var string|null $timerId */
        $timerId = null;

        $cleanup = function () use (&$timerId): void {
            if ($timerId !== null) {
                Loop::cancelTimer($timerId);
                $timerId = null;
            }
        };

        $timerId = Loop::addTimer($this->timeout, function () use ($promise, $pendingPromise, $cleanup, $query) {
            $cleanup();
            $pendingPromise->cancel();

            $promise->reject(new TimeoutException(
                \sprintf('DNS query for %s timed out after %.2f seconds', $query->name, $this->timeout)
            ));
        });

        $pendingPromise->then(
            function ($response) use ($promise, $cleanup) {
                $cleanup();
                $promise->resolve($response);
            },
            function (mixed $e) use ($promise, $cleanup) {
                $cleanup();
                $promise->reject($e);
            }
        );

        $promise->onCancel(function () use ($pendingPromise, $cleanup) {
            $cleanup();
            $pendingPromise->cancelChain();
        });

        return $promise;
    }
}