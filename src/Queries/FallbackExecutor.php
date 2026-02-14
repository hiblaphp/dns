<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Provides failover between primary and secondary DNS executors.
 *
 * Attempts the primary executor first, automatically falling back to the
 * secondary executor on failure. Useful for redundancy with multiple nameservers
 * or when the primary server is unreachable.
 *
 * Common use cases:
 * - Multiple nameservers: Try 8.8.8.8, fallback to 1.1.1.1
 * - Transport fallback: Already handled by SelectiveTransportExecutor
 * - Geographic redundancy: Local DNS, fallback to public DNS
 */
final class FallbackExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $primary,
        private readonly ExecutorInterface $secondary
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        /** @var PromiseInterface<Message>|null $currentOperation */
        $currentOperation = null;

        $currentOperation = $this->primary->query($query);

        $currentOperation->then(
            onFulfilled: $promise->resolve(...),

            // Failure -> Try Secondary
            onRejected: function (\Throwable $primaryError) use ($query, $promise, &$currentOperation) {
                // 2. Try Secondary
                $currentOperation = $this->secondary->query($query);

                $currentOperation->then(
                    onFulfilled: $promise->resolve(...),
                    onRejected: function (\Throwable $secondaryError) use ($promise, $primaryError) {
                        // Both failed. Combine error messages.
                        $errorMessage = \sprintf(
                            '%s. Fallback failed: %s',
                            rtrim($primaryError->getMessage(), '.'),
                            $secondaryError->getMessage()
                        );

                        $promise->reject(new \RuntimeException(
                            $errorMessage,
                            0,
                            $secondaryError
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
