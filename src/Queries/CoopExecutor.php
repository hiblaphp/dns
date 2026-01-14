<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final class CoopExecutor implements ExecutorInterface
{
    /**
     * Map of cache keys to pending operations.
     *
     * Structure:
     * [
     *    'key' => [
     *        'promise' => PromiseInterface<Message>,
     *        'count'   => int
     *    ]
     * ]
     *
     * @var array<string, array{promise: PromiseInterface<Message>, count: int}>
     */
    private array $pending = [];

    public function __construct(
        private readonly ExecutorInterface $executor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function query(Query $query): PromiseInterface
    {
        $key = \sprintf('%s:%d:%d', $query->name, $query->type->value, $query->class->value);

        if (isset($this->pending[$key])) {
            // A request is already in progress. Increment listener count.
            $this->pending[$key]['count']++;
            $networkPromise = $this->pending[$key]['promise'];
        } else {
            // Start a new network request
            $networkPromise = $this->executor->query($query);

            $this->pending[$key] = [
                'promise' => $networkPromise,
                'count' => 1,
            ];

            // Cleanup when the network request settles (success or fail)
            $networkPromise->then(
                function () use ($key) {
                    unset($this->pending[$key]);
                },
                function () use ($key) {
                    unset($this->pending[$key]);
                }
            );
        }

        // Create a unique "Branch Promise" for this specific caller.
        // This allows independent cancellation logic.
        /** @var Promise<Message> $userPromise */
        $userPromise = new Promise();

        $networkPromise->then(
            fn ($response) => $userPromise->resolve($response),
            fn ($error) => $userPromise->reject($error)
        );

        $userPromise->onCancel(function () use ($key) {
            // If this specific user cancels, decrement the ref count.
            if (isset($this->pending[$key])) {
                $this->pending[$key]['count']--;

                // If NOBODY is listening anymore, abort the actual network request.
                if ($this->pending[$key]['count'] <= 0) {
                    $this->pending[$key]['promise']->cancelChain();
                    unset($this->pending[$key]);
                }
            }
        });

        return $userPromise;
    }
}
