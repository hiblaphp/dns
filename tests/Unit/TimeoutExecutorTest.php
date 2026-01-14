<?php

declare(strict_types=1);

use Hibla\Dns\Exceptions\TimeoutException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\TimeoutExecutor;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Tests\Helpers\MockExecutor;

describe('TimeoutExecutor', function () {
    $query = new Query('google.com', Hibla\Dns\Enums\RecordType::A, Hibla\Dns\Enums\RecordClass::IN);

    it('resolves successfully if inner executor is fast', function () use ($query) {
        $mock = new class () implements ExecutorInterface {
            public function query(Query $query): Hibla\Promise\Interfaces\PromiseInterface
            {
                return Promise::resolved(new Message());
            }
        };

        $executor = new TimeoutExecutor($mock, 1.0);
        $promise = $executor->query($query);

        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
    });

    it('rejects with TimeoutException when time runs out', function () use ($query) {
        $mock = new MockExecutor(shouldHang: true);
        $executor = new TimeoutExecutor($mock, 0.01);

        $promise = $executor->query($query);

        $promise->catch(fn () => null);

        Loop::run();

        expect($promise->isRejected())->toBeTrue();

        try {
            $promise->wait();
        } catch (TimeoutException $e) {
            expect($e->getMessage())->toContain('timed out after 0.01 seconds');
        }
    });

    it('cancels the inner executor when timeout occurs', function () use ($query) {
        $mock = new MockExecutor(shouldHang: true);
        $executor = new TimeoutExecutor($mock, 0.01);

        $promise = $executor->query($query);

        $promise->catch(fn () => null);

        Loop::run();

        expect($mock->wasCancelled)->toBeTrue();
    });

    it('cancels the timer if the query finishes early', function () use ($query) {
        $mock = new MockExecutor(shouldHang: true);
        $executor = new TimeoutExecutor($mock, 5.0);

        $promise = $executor->query($query);

        $mock->pendingPromise->resolve(new Message());

        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
    });

    it('propagates manual cancellation to inner executor', function () use ($query) {
        $mock = new MockExecutor(shouldHang: true);
        $executor = new TimeoutExecutor($mock, 5.0);

        $promise = $executor->query($query);

        $promise->cancel();

        Loop::runOnce();

        expect($mock->wasCancelled)->toBeTrue();
        expect($promise->isCancelled())->toBeTrue();
    });
});
