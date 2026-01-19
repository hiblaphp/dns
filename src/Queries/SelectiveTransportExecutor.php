<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Exceptions\ResponseTruncatedException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Automatically selects UDP or TCP transport based on response size.
 *
 * Implements the standard DNS fallback mechanism: attempts UDP first for speed,
 * then automatically retries with TCP if the response is truncated (TC flag set).
 * This handles cases where responses exceed UDP's 512-byte limit, such as
 * DNSSEC responses, large TXT records, or domains with many MX/NS records.
 *
 * @see UdpTransportExecutor
 * @see TcpTransportExecutor
 */
final class SelectiveTransportExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly ExecutorInterface $udpExecutor,
        private readonly ExecutorInterface $tcpExecutor
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function query(Query $query): PromiseInterface
    {
        /** @var Promise<Message> $promise */
        $promise = new Promise();

        $currentOperation = $this->udpExecutor->query($query);

        $currentOperation->then(
            onFulfilled: function ($response) use ($promise) {
                $promise->resolve($response);
            },
            onRejected: function (mixed $e) use ($query, $promise, &$currentOperation) {
                if ($e instanceof ResponseTruncatedException) {
                    $currentOperation = $this->retryWithTcp($query, $promise);
                } else {
                    $promise->reject($e);
                }
            }
        );

        $promise->onCancel(function () use (&$currentOperation) {
            $currentOperation->cancelChain();
        });

        return $promise;
    }

    /**
     * @param Promise<Message> $promise
     * @return PromiseInterface<Message>
     */
    private function retryWithTcp(Query $query, Promise $promise): PromiseInterface
    {
        $tcpPromise = $this->tcpExecutor->query($query);

        $tcpPromise->then(
            onFulfilled: function ($response) use ($promise) {
                $promise->resolve($response);
            },
            onRejected: function ($error) use ($promise) {
                $promise->reject($error);
            }
        );

        return $tcpPromise;
    }
}
