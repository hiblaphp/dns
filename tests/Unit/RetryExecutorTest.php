<?php

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\RetryExecutor;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Promise;

describe('RetryExecutor', function () {
    $query = new Query('google.com', RecordType::A, RecordClass::IN);

    it('returns success immediately if first attempt succeeds', function () use ($query) {
        $msg = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->once()
            ->andReturn(Promise::resolved($msg));

        $executor = new RetryExecutor($inner, 2);
        $promise = $executor->query($query);

        expect($promise->wait())->toBe($msg);
    });

    it('retries on failure and eventually succeeds', function () use ($query) {
        $msg = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->times(3)
            ->andReturn(
                Promise::rejected(new QueryFailedException('Fail 1')),
                Promise::rejected(new QueryFailedException('Fail 2')),
                Promise::resolved($msg) 
            );

        $executor = new RetryExecutor($inner, 2);
        $promise = $executor->query($query);

        expect($promise->wait())->toBe($msg);
    });

    it('fails after exhausting all retries', function () use ($query) {
        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->times(4)
            ->andReturn(
                Promise::rejected(new QueryFailedException('Fail 1')),
                Promise::rejected(new QueryFailedException('Fail 2')),
                Promise::rejected(new QueryFailedException('Fail 3')),
                Promise::rejected(new QueryFailedException('Fail Final'))
            );

        $executor = new RetryExecutor($inner, 3);
        $promise = $executor->query($query);

        try {
            $promise->wait();
            test()->fail('Should have thrown exception');
        } catch (QueryFailedException $e) {
            expect($e->getMessage())->toBe('Fail Final');
        }
    });

    it('does not retry if cancelled during an attempt', function () use ($query) {
        $pendingPromise = new Promise();
        $pendingPromise->onCancel(function() {
            // Mock cancellation behavior
        });

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->once() 
            ->andReturn($pendingPromise);

        $executor = new RetryExecutor($inner, 3);
        $promise = $executor->query($query);

        $promise->cancel();

        $pendingPromise->reject(new QueryFailedException('Aborted'));
        
        try {
            $promise->wait();
        } catch (\Throwable $e) {
            expect($e)->toBeInstanceOf(PromiseCancelledException::class);
        }
    });

    it('handles zero retries (fails immediately on first error)', function () use ($query) {
        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->once()
            ->andReturn(Promise::rejected(new QueryFailedException('Immediate failure')));

        $executor = new RetryExecutor($inner, 0);
        $promise = $executor->query($query);

        try {
            $promise->wait();
            test()->fail('Should have thrown exception');
        } catch (QueryFailedException $e) {
            expect($e->getMessage())->toBe('Immediate failure');
        }
    });

    it('succeeds on last possible retry attempt', function () use ($query) {
        $msg = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->times(4)
            ->andReturn(
                Promise::rejected(new QueryFailedException('Fail 1')),
                Promise::rejected(new QueryFailedException('Fail 2')),
                Promise::rejected(new QueryFailedException('Fail 3')),
                Promise::resolved($msg)
            );

        $executor = new RetryExecutor($inner, 3);
        $promise = $executor->query($query);

        expect($promise->wait())->toBe($msg);
    });

    it('handles different exception types across retries', function () use ($query) {
        $msg = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->times(3)
            ->andReturn(
                Promise::rejected(new \RuntimeException('Network error')),
                Promise::rejected(new \InvalidArgumentException('Invalid data')),
                Promise::resolved($msg)
            );

        $executor = new RetryExecutor($inner, 2);
        $promise = $executor->query($query);

        expect($promise->wait())->toBe($msg);
    });

    it('propagates the final exception type when all retries exhausted', function () use ($query) {
        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query)
            ->times(3)
            ->andReturn(
                Promise::rejected(new QueryFailedException('First')),
                Promise::rejected(new \RuntimeException('Second')),
                Promise::rejected(new \InvalidArgumentException('Final'))
            );

        $executor = new RetryExecutor($inner, 2);
        $promise = $executor->query($query);

        try {
            $promise->wait();
            test()->fail('Should have thrown exception');
        } catch (\InvalidArgumentException $e) {
            expect($e->getMessage())->toBe('Final');
        }
    });

    it('handles multiple concurrent queries independently', function () use ($query) {
        $msg1 = new Message();
        $msg2 = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->times(4)
            ->andReturn(
                Promise::rejected(new QueryFailedException('Query1 Fail')),
                Promise::rejected(new QueryFailedException('Query2 Fail')),
                Promise::resolved($msg1),
                Promise::resolved($msg2)
            );

        $executor = new RetryExecutor($inner, 1);
        $promise1 = $executor->query($query);
        $promise2 = $executor->query($query);

        expect($promise1->wait())->toBe($msg1);
        expect($promise2->wait())->toBe($msg2);
    });

    it('cancels during first attempt before any retries', function () use ($query) {
        $pendingPromise = new Promise();
        $wasCancelled = false;

        $pendingPromise->onCancel(function() use (&$wasCancelled) {
            $wasCancelled = true;
        });

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->once()
            ->andReturn($pendingPromise);

        $executor = new RetryExecutor($inner, 3);
        $promise = $executor->query($query);

        $promise->cancel();

        expect($wasCancelled)->toBeTrue();
    });

    it('cancels during a retry attempt (not first attempt)', function () use ($query) {
        $firstPromise = Promise::rejected(new QueryFailedException('First fail'));
        
        $secondPromise = new Promise();
        $wasCancelled = false;
        
        $secondPromise->onCancel(function() use (&$wasCancelled) {
            $wasCancelled = true;
        });

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->twice()
            ->andReturn($firstPromise, $secondPromise);

        $executor = new RetryExecutor($inner, 3);
        $promise = $executor->query($query);

        Loop::runOnce(); // Process first failure
        $promise->cancel(); // Cancel during retry

        expect($wasCancelled)->toBeTrue();
    });

    it('handles very large retry count', function () use ($query) {
        $msg = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->once()
            ->andReturn(Promise::resolved($msg));

        $executor = new RetryExecutor($inner, 1000);
        $promise = $executor->query($query);

        expect($promise->wait())->toBe($msg);
    });

    it('does not retry after cancellation even if rejection happens', function () use ($query) {
        $pendingPromise = new Promise();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->once() // Should only be called once, not retried
            ->andReturn($pendingPromise);

        $executor = new RetryExecutor($inner, 5);
        $promise = $executor->query($query);

        $promise->cancel();

        // Simulate the pending operation finishing with a failure after cancellation
        $pendingPromise->reject(new QueryFailedException('Failed after cancel'));

        Loop::runOnce();

        try {
            $promise->wait();
        } catch (\Throwable $e) {
            expect($e)->toBeInstanceOf(PromiseCancelledException::class);
        }
    });

    it('handles asynchronous resolution correctly', function () use ($query) {
        $msg = new Message();
        $asyncPromise = new Promise();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->once()
            ->andReturn($asyncPromise);

        $executor = new RetryExecutor($inner, 2);
        $promise = $executor->query($query);

        expect($promise->isPending())->toBeTrue();

        $asyncPromise->resolve($msg);
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($msg);
    });

    it('handles asynchronous rejection and retry', function () use ($query) {
        $msg = new Message();
        $asyncFailPromise = new Promise();
        $asyncSuccessPromise = new Promise();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->twice()
            ->andReturn($asyncFailPromise, $asyncSuccessPromise);

        $executor = new RetryExecutor($inner, 2);
        $promise = $executor->query($query);

        $asyncFailPromise->reject(new QueryFailedException('Async fail'));
        Loop::runOnce();

        $asyncSuccessPromise->resolve($msg);
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($msg);
    });

    it('preserves error message from final attempt', function () use ($query) {
        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->times(2)
            ->andReturn(
                Promise::rejected(new QueryFailedException('First error with details')),
                Promise::rejected(new QueryFailedException('Final error with specific message'))
            );

        $executor = new RetryExecutor($inner, 1);
        $promise = $executor->query($query);

        try {
            $promise->wait();
            test()->fail('Should have thrown exception');
        } catch (QueryFailedException $e) {
            expect($e->getMessage())->toBe('Final error with specific message');
        }
    });

    it('handles queries for different domains independently', function () {
        $query1 = new Query('example.com', RecordType::A, RecordClass::IN);
        $query2 = new Query('google.com', RecordType::A, RecordClass::IN);
        
        $msg1 = new Message();
        $msg2 = new Message();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')
            ->with($query1)
            ->once()
            ->andReturn(Promise::resolved($msg1));
            
        $inner->shouldReceive('query')
            ->with($query2)
            ->twice()
            ->andReturn(
                Promise::rejected(new QueryFailedException('Fail')),
                Promise::resolved($msg2)
            );

        $executor = new RetryExecutor($inner, 2);
        
        $promise1 = $executor->query($query1);
        $promise2 = $executor->query($query2);

        expect($promise1->wait())->toBe($msg1);
        expect($promise2->wait())->toBe($msg2);
    });
});