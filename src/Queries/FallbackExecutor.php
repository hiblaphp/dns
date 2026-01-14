<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

final class FallbackExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $primary,
        private readonly ExecutorInterface $secondary
    ) {}

    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();
        
        /** @var PromiseInterface|null $currentOperation */
        $currentOperation = null;
        $isCancelled = false;

        $currentOperation = $this->primary->query($query);

        $currentOperation->then(
            onFulfilled: fn($response) => $promise->resolve($response),
            
            // Failure -> Try Secondary
            onRejected: function (Throwable $primaryError) use ($query, $promise, &$currentOperation, &$isCancelled) {
                if ($isCancelled) {
                    return;
                }

                // 2. Try Secondary
                $currentOperation = $this->secondary->query($query);

                $currentOperation->then(
                    fn($response) => $promise->resolve($response),
                    function (Throwable $secondaryError) use ($promise, $primaryError) {
                        // Both failed. Combine error messages.
                        $promise->reject(new \RuntimeException(
                            \sprintf(
                                '%s. Fallback failed: %s', 
                                rtrim($primaryError->getMessage(), '.'), 
                                $secondaryError->getMessage()
                            ),
                            0,
                            $secondaryError
                        ));
                    }
                );
            }
        );

        $promise->onCancel(function () use (&$currentOperation, &$isCancelled) {
            $isCancelled = true;
            if ($currentOperation instanceof PromiseInterface) {
                $currentOperation->cancelChain();
            }
        });

        return $promise;
    }
}