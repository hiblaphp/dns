<?php

declare(strict_types=1);

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\CoopExecutor;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Tests\Helpers\MockExecutor;

describe('CoopExecutor', function () {
    $query = new Query('google.com', RecordType::A, RecordClass::IN);

    it('merges multiple identical queries into one network execution', function () use ($query) {
        $mock = Mockery::mock(ExecutorInterface::class);
        $mock->shouldReceive('query')
            ->once()
            ->with($query)
            ->andReturn(Promise::resolved(new Message()))
        ;

        $executor = new CoopExecutor($mock);

        $p1 = $executor->query($query);
        $p2 = $executor->query($query);
        $p3 = $executor->query($query);

        Loop::runOnce();

        expect($p1->isFulfilled())->toBeTrue();
        expect($p2->isFulfilled())->toBeTrue();
        expect($p3->isFulfilled())->toBeTrue();
    });

    it('executes distinct queries separately', function () {
        $q1 = new Query('a.com', RecordType::A, RecordClass::IN);
        $q2 = new Query('b.com', RecordType::A, RecordClass::IN);

        $mock = Mockery::mock(ExecutorInterface::class);
        $mock->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

        $executor = new CoopExecutor($mock);

        $executor->query($q1);
        $executor->query($q2);

        Loop::runOnce();
    });

    it('broadcasts errors to all listeners', function () use ($query) {
        $error = new QueryFailedException('Network Boom');

        $mock = Mockery::mock(ExecutorInterface::class);
        $mock->shouldReceive('query')->once()->andReturn(Promise::rejected($error));

        $executor = new CoopExecutor($mock);

        $p1 = $executor->query($query);
        $p2 = $executor->query($query);

        $p1->catch(fn () => null);
        $p2->catch(fn () => null);

        Loop::runOnce();

        expect($p1->isRejected())->toBeTrue();
        expect($p2->isRejected())->toBeTrue();
    });

    it('does NOT cancel network request if other listeners exist', function () use ($query) {
        $mock = new MockExecutor(shouldHang: true);
        $executor = new CoopExecutor($mock);

        $p1 = $executor->query($query);
        $p2 = $executor->query($query);

        $p1->cancel();

        expect($mock->wasCancelled)->toBeFalse();
        expect($p1->isCancelled())->toBeTrue();

        $p2->cancel();
        expect($mock->wasCancelled)->toBeTrue();
    });

    it('cancels network request when LAST listener cancels', function () use ($query) {
        $mock = new MockExecutor(shouldHang: true);
        $executor = new CoopExecutor($mock);

        $p1 = $executor->query($query);

        $p1->cancel();

        expect($mock->wasCancelled)->toBeTrue();
    });

    describe('Edge Cases - Query Coalescing', function () {
        it('handles rapid sequential identical queries', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->once()->andReturn(Promise::resolved(new Message()));

            $executor = new CoopExecutor($mock);

            $promises = [];
            for ($i = 0; $i < 10; $i++) {
                $promises[] = $executor->query($query);
            }

            Loop::runOnce();

            foreach ($promises as $promise) {
                expect($promise->isFulfilled())->toBeTrue();
            }
        });

        it('differentiates queries by name', function () {
            $q1 = new Query('a.com', RecordType::A, RecordClass::IN);
            $q2 = new Query('b.com', RecordType::A, RecordClass::IN);

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

            $executor = new CoopExecutor($mock);

            $executor->query($q1);
            $executor->query($q2);

            Loop::runOnce();
        });

        it('differentiates queries by record type', function () {
            $q1 = new Query('example.com', RecordType::A, RecordClass::IN);
            $q2 = new Query('example.com', RecordType::AAAA, RecordClass::IN);

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

            $executor = new CoopExecutor($mock);

            $executor->query($q1);
            $executor->query($q2);

            Loop::runOnce();
        });

        it('differentiates queries by record class', function () {
            $q1 = new Query('example.com', RecordType::A, RecordClass::IN);
            $q2 = new Query('example.com', RecordType::A, RecordClass::CH);

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

            $executor = new CoopExecutor($mock);

            $executor->query($q1);
            $executor->query($q2);

            Loop::runOnce();
        });

        it('allows new queries after previous ones complete', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            Loop::runOnce();
            expect($p1->isFulfilled())->toBeTrue();

            $p2 = $executor->query($query);
            Loop::runOnce();
            expect($p2->isFulfilled())->toBeTrue();
        });

        it('handles queries arriving after network request starts', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);

            $p2 = $executor->query($query);

            $mock->pendingPromise?->resolve(new Message());
            Loop::runOnce();

            expect($p1->isFulfilled())->toBeTrue();
            expect($p2->isFulfilled())->toBeTrue();
        });
    });

    describe('Edge Cases - Cancellation Behavior', function () {
        it('handles cancellation of already-resolved promise', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(resultToReturn: new Message());
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            Loop::runOnce();

            expect($p1->isFulfilled())->toBeTrue();

            $p1->cancel();
            expect($p1->isFulfilled())->toBeTrue();
        });

        it('handles cancellation in middle of multiple listeners', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p2 = $executor->query($query);
            $p3 = $executor->query($query);

            $p2->cancel();

            expect($mock->wasCancelled)->toBeFalse();
            expect($p2->isCancelled())->toBeTrue();

            $p1->cancel();
            $p3->cancel();
        });

        it('handles all listeners cancelling in sequence', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p2 = $executor->query($query);
            $p3 = $executor->query($query);

            $p1->cancel();
            expect($mock->wasCancelled)->toBeFalse();

            $p2->cancel();
            expect($mock->wasCancelled)->toBeFalse();

            $p3->cancel();
            expect($mock->wasCancelled)->toBeTrue();
        });

        it('handles all listeners cancelling simultaneously', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $promises = [];
            for ($i = 0; $i < 5; $i++) {
                $promises[] = $executor->query($query);
            }

            foreach ($promises as $promise) {
                $promise->cancel();
            }

            expect($mock->wasCancelled)->toBeTrue();
        });

        it('handles cancellation before event loop runs', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);

            $p1->cancel();

            expect($mock->wasCancelled)->toBeTrue();
            expect($p1->isCancelled())->toBeTrue();
        });

        it('handles partial cancellation then resolution', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p2 = $executor->query($query);

            $p1->cancel();
            expect($p1->isCancelled())->toBeTrue();

            $mock->pendingPromise?->resolve(new Message());
            Loop::runOnce();

            expect($p2->isFulfilled())->toBeTrue();
        });

        it('does not affect other queries when cancelling one', function () {
            $q1 = new Query('a.com', RecordType::A, RecordClass::IN);
            $q2 = new Query('b.com', RecordType::A, RecordClass::IN);

            $mock1 = new MockExecutor(shouldHang: true);
            $mock2 = new MockExecutor(shouldHang: true);

            $mockWrapper = Mockery::mock(ExecutorInterface::class);
            $mockWrapper->shouldReceive('query')
                ->with($q1)
                ->andReturn($mock1->query($q1))
            ;
            $mockWrapper->shouldReceive('query')
                ->with($q2)
                ->andReturn($mock2->query($q2))
            ;

            $executor = new CoopExecutor($mockWrapper);

            $p1 = $executor->query($q1);
            $p2 = $executor->query($q2);

            $p1->cancel();

            expect($mock1->wasCancelled)->toBeTrue();
            expect($mock2->wasCancelled)->toBeFalse();
        });
    });

    describe('Edge Cases - Error Handling', function () {
        it('handles error after some listeners cancel', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p2 = $executor->query($query);
            $p3 = $executor->query($query);

            $p1->cancel();

            $mock->pendingPromise?->reject(new QueryFailedException('Error'));

            $p2->catch(fn () => null);
            $p3->catch(fn () => null);

            Loop::runOnce();

            expect($p1->isCancelled())->toBeTrue();
            expect($p2->isRejected())->toBeTrue();
            expect($p3->isRejected())->toBeTrue();
        });

        it('cleans up pending state on error', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $error = new QueryFailedException('Network error');

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->once()->andReturn(Promise::rejected($error));

            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p1->catch(fn () => null);

            Loop::runOnce();

            $mock->shouldReceive('query')->once()->andReturn(Promise::resolved(new Message()));

            $p2 = $executor->query($query);
            Loop::runOnce();

            expect($p2->isFulfilled())->toBeTrue();
        });

        it('handles multiple errors for same query type', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $error1 = new QueryFailedException('Error 1');
            $error2 = new QueryFailedException('Error 2');

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')
                ->once()
                ->andReturn(Promise::rejected($error1))
            ;

            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p1->catch(fn () => null);
            Loop::runOnce();

            $mock->shouldReceive('query')
                ->once()
                ->andReturn(Promise::rejected($error2))
            ;

            $p2 = $executor->query($query);
            $p2->catch(fn () => null);
            Loop::runOnce();

            expect($p1->isRejected())->toBeTrue();
            expect($p2->isRejected())->toBeTrue();
        });
    });

    describe('Edge Cases - Reference Counting', function () {
        it('maintains correct count when queries arrive during resolution', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $promises = [];

            $promises[] = $executor->query($query);

            for ($i = 0; $i < 5; $i++) {
                $promises[] = $executor->query($query);
            }

            $promises[0]->cancel();
            $promises[2]->cancel();
            $promises[4]->cancel();

            expect($mock->wasCancelled)->toBeFalse();

            $promises[1]->cancel();
            $promises[3]->cancel();
            $promises[5]->cancel();

            expect($mock->wasCancelled)->toBeTrue();
        });

        it('handles zero count edge case gracefully', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);

            $p1->cancel();
            expect($mock->wasCancelled)->toBeTrue();

            $p1->cancel();

            expect($mock->wasCancelled)->toBeTrue();
        });
    });

    describe('Edge Cases - Promise Chain Behavior', function () {
        it('ensures each listener gets independent promise', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(resultToReturn: new Message());
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p2 = $executor->query($query);

            expect($p1)->not->toBe($p2);

            Loop::runOnce();

            expect($p1->isFulfilled())->toBeTrue();
            expect($p2->isFulfilled())->toBeTrue();
        });

        it('allows listeners to attach callbacks independently', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = new MockExecutor(shouldHang: true);
            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p2 = $executor->query($query);

            $result1 = null;
            $result2 = null;

            $p1->then(function ($msg) use (&$result1) {
                $result1 = $msg;
            });

            $p2->then(function ($msg) use (&$result2) {
                $result2 = $msg;
            });

            $mock->pendingPromise?->resolve(new Message());
            Loop::runOnce();

            expect($result1)->toBeInstanceOf(Message::class);
            expect($result2)->toBeInstanceOf(Message::class);
        });
    });

    describe('Edge Cases - Memory & Cleanup', function () {
        it('cleans up pending state on successful resolution', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

            $executor = new CoopExecutor($mock);
            $p1 = $executor->query($query);
            Loop::runOnce();

            $p2 = $executor->query($query);
            Loop::runOnce();

            expect($p1->isFulfilled())->toBeTrue();
            expect($p2->isFulfilled())->toBeTrue();
        });

        it('handles rapid query-cancel-query cycles', function () {
            $query = new Query('example.com', RecordType::A, RecordClass::IN);
            $callCount = 0;

            $mock = Mockery::mock(ExecutorInterface::class);
            $mock->shouldReceive('query')
                ->times(3)
                ->andReturnUsing(function () use (&$callCount) {
                    $callCount++;

                    return (new MockExecutor(shouldHang: true))->query(new Query('x', RecordType::A, RecordClass::IN));
                })
            ;

            $executor = new CoopExecutor($mock);

            $p1 = $executor->query($query);
            $p1->cancel();

            $p2 = $executor->query($query);
            $p2->cancel();

            $p3 = $executor->query($query);
            $p3->cancel();

            expect($callCount)->toBe(3);
        });
    });
});
