<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final class FallbackExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $primary,
        private readonly ExecutorInterface $secondary
    ) {}

    /**
     * @inheritDoc
     */
    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        /** @var PromiseInterface<Message>|null $currentOperation */
        $currentOperation = null;

        $currentOperation = $this->primary->query($query);

        $currentOperation->then(
            onFulfilled: fn($response) => $promise->resolve($response),

            // Failure -> Try Secondary
            onRejected: function (mixed $primaryError) use ($query, $promise, &$currentOperation) {
                // 2. Try Secondary
                $currentOperation = $this->secondary->query($query);

                $currentOperation->then(
                    fn($response) => $promise->resolve($response),
                    function (mixed $secondaryError) use ($promise, $primaryError) {
                        // Both failed. Combine error messages.
                        $errorMessage = \sprintf(
                            '%s. Fallback failed: %s',
                            $primaryError instanceof \Throwable ? rtrim($primaryError->getMessage(), '.') : 'Primary query failed',
                            $secondaryError instanceof \Throwable ? $secondaryError->getMessage() : 'Secondary query failed'
                        );
                        
                        $promise->reject(new \RuntimeException(
                            $errorMessage,
                            0,
                            $secondaryError instanceof \Throwable ? $secondaryError : null
                        ));
                    }
                );
            }
        );

        $promise->onCancel(function () use (&$currentOperation) {
            $currentOperation->cancelChain();
        });

        return $promise;
    }
}