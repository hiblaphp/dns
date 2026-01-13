<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

final class MockExecutor implements ExecutorInterface
{
    public ?Promise $pendingPromise = null;
    public bool $wasCalled = false;
    public bool $wasCancelled = false;

    public function __construct(
        private readonly ?Message $resultToReturn = null,
        private readonly ?Throwable $errorToThrow = null,
        private readonly bool $shouldHang = false
    ) {}

    public function query(Query $query): PromiseInterface
    {
        $this->wasCalled = true;
        
        /** @var Promise<Message> $promise */
        $promise = new Promise();
        $this->pendingPromise = $promise;

        $promise->onCancel(function () {
            $this->wasCancelled = true;
        });

        if ($this->shouldHang) {
            return $promise;
        }

        if ($this->errorToThrow !== null) {
            $promise->reject($this->errorToThrow);
        } elseif ($this->resultToReturn !== null) {
            $promise->resolve($this->resultToReturn);
        } else {
            // Default behavior if nothing specified: return a fresh Message
            $promise->resolve(new Message());
        }

        return $promise;
    }
}