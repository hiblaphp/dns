<?php

declare(strict_types=1);

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\FallbackExecutor;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Tests\Helpers\MockExecutor;

describe('FallbackExecutor', function () {
    $query = new Query('example.com', RecordType::A, RecordClass::IN);

    describe('Basic Behavior', function () use ($query) {
        it('returns primary result when primary succeeds', function () use ($query) {
            $primaryMessage = new Message();
            $primary = new MockExecutor(resultToReturn: $primaryMessage);
            $secondary = new MockExecutor(resultToReturn: new Message());

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            Loop::runOnce();

            expect($promise->isFulfilled())->toBeTrue();
            expect($secondary->wasCalled)->toBeFalse();
        });

        it('returns secondary result when primary fails', function () use ($query) {
            $primaryError = new QueryFailedException('Primary failed');
            $secondaryMessage = new Message();

            $primary = new MockExecutor(errorToThrow: $primaryError);
            $secondary = new MockExecutor(resultToReturn: $secondaryMessage);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            Loop::run();

            expect($promise->isFulfilled())->toBeTrue();
            expect($primary->wasCalled)->toBeTrue();
            expect($secondary->wasCalled)->toBeTrue();
        });

        it('rejects with combined error when both fail', function () use ($query) {
            $primaryError = new QueryFailedException('Primary timeout');
            $secondaryError = new QueryFailedException('Secondary unreachable');

            $primary = new MockExecutor(errorToThrow: $primaryError);
            $secondary = new MockExecutor(errorToThrow: $secondaryError);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            $capturedError = null;
            $promise->catch(function ($error) use (&$capturedError) {
                $capturedError = $error;
            });

            Loop::run();

            expect($promise->isRejected())->toBeTrue();
            expect($capturedError)->toBeInstanceOf(RuntimeException::class);
            expect($capturedError->getMessage())->toContain('Primary timeout');
            expect($capturedError->getMessage())->toContain('Secondary unreachable');
        });
    });

    describe('Cancellation Behavior', function () use ($query) {
        it('cancels primary request when cancelled before primary completes', function () use ($query) {
            $primary = new MockExecutor(shouldHang: true);
            $secondary = new MockExecutor(shouldHang: true);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            $promise->cancel();

            expect($primary->wasCancelled)->toBeTrue();
            expect($secondary->wasCalled)->toBeFalse();
        });

        it('cancels secondary request when cancelled during fallback', function () use ($query) {
            $primaryError = new QueryFailedException('Primary failed');
            $primary = new MockExecutor(errorToThrow: $primaryError);
            $secondary = new MockExecutor(shouldHang: true);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            Loop::runOnce();

            $promise->cancel();

            expect($secondary->wasCancelled)->toBeTrue();
        });

        it('handles cancellation after primary succeeds (no-op)', function () use ($query) {
            $primary = new MockExecutor(resultToReturn: new Message());
            $secondary = new MockExecutor();

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            Loop::runOnce();

            expect($promise->isFulfilled())->toBeTrue();

            $promise->cancel();
            expect($promise->isFulfilled())->toBeTrue();
        });

        it('handles cancellation after both fail (no-op)', function () use ($query) {
            $primary = new MockExecutor(errorToThrow: new QueryFailedException('P'));
            $secondary = new MockExecutor(errorToThrow: new QueryFailedException('S'));

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            $promise->catch(fn () => null);

            Loop::run();

            expect($promise->isRejected())->toBeTrue();

            $promise->cancel();
            expect($promise->isRejected())->toBeTrue();
        });
    });

    describe('Edge Cases - Error Handling', function () use ($query) {
        it('formats combined error message correctly with periods', function () use ($query) {
            $primary = new MockExecutor(errorToThrow: new QueryFailedException('Primary timeout.'));
            $secondary = new MockExecutor(errorToThrow: new QueryFailedException('Secondary down'));

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            $capturedError = null;
            $promise->catch(function ($error) use (&$capturedError) {
                $capturedError = $error;
            });

            Loop::run();

            expect($capturedError->getMessage())->toBe('Primary timeout. Fallback failed: Secondary down');
        });

        it('formats combined error message without periods', function () use ($query) {
            $primary = new MockExecutor(errorToThrow: new QueryFailedException('Primary timeout'));
            $secondary = new MockExecutor(errorToThrow: new QueryFailedException('Secondary down'));

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            $capturedError = null;
            $promise->catch(function ($error) use (&$capturedError) {
                $capturedError = $error;
            });

            Loop::run();

            expect($capturedError->getMessage())->toBe('Primary timeout. Fallback failed: Secondary down');
        });

        it('preserves secondary error as previous exception', function () use ($query) {
            $primaryError = new QueryFailedException('Primary error');
            $secondaryError = new QueryFailedException('Secondary error');

            $primary = new MockExecutor(errorToThrow: $primaryError);
            $secondary = new MockExecutor(errorToThrow: $secondaryError);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            $capturedError = null;
            $promise->catch(function ($error) use (&$capturedError) {
                $capturedError = $error;
            });

            Loop::run();

            expect($capturedError->getPrevious())->toBe($secondaryError);
        });

        it('handles different exception types', function () use ($query) {
            $primary = new MockExecutor(errorToThrow: new Exception('Primary generic error'));
            $secondary = new MockExecutor(errorToThrow: new RuntimeException('Secondary runtime error'));

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            $promise->catch(fn () => null);

            Loop::run();

            expect($promise->isRejected())->toBeTrue();
        });
    });

    describe('Edge Cases - Timing and Race Conditions', function () use ($query) {
        it('handles primary taking long time but succeeding', function () use ($query) {
            $primary = new MockExecutor(shouldHang: true);
            $secondary = new MockExecutor(resultToReturn: new Message());

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            expect($promise->isPending())->toBeTrue();

            $primary->pendingPromise?->resolve(new Message());
            Loop::runOnce();

            expect($promise->isFulfilled())->toBeTrue();
            expect($secondary->wasCalled)->toBeFalse();
        });

        it('does not call secondary if cancelled before primary fails', function () use ($query) {
            $primary = new MockExecutor(shouldHang: true);
            $secondary = new MockExecutor(resultToReturn: new Message());

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            $promise->cancel();

            $primary->pendingPromise?->reject(new QueryFailedException('Too late'));
            Loop::runOnce();

            expect($secondary->wasCalled)->toBeFalse();
        });

        it('handles secondary resolving after cancellation flag is set', function () use ($query) {
            $primaryError = new QueryFailedException('Primary failed');
            $primary = new MockExecutor(errorToThrow: $primaryError);
            $secondary = new MockExecutor(shouldHang: true);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            Loop::runOnce();

            $promise->cancel();

            $secondary->pendingPromise?->resolve(new Message());
            Loop::runOnce();

            expect($promise->isCancelled())->toBeTrue();
        });
    });

    describe('Edge Cases - Multiple Queries', function () {
        it('handles multiple independent queries', function () {
            $q1 = new Query('a.com', RecordType::A, RecordClass::IN);
            $q2 = new Query('b.com', RecordType::A, RecordClass::IN);

            $primary = Mockery::mock(ExecutorInterface::class);
            $secondary = Mockery::mock(ExecutorInterface::class);

            $primary->shouldReceive('query')->twice()->andReturn(Promise::resolved(new Message()));

            $executor = new FallbackExecutor($primary, $secondary);

            $p1 = $executor->query($q1);
            $p2 = $executor->query($q2);

            Loop::runOnce();

            expect($p1->isFulfilled())->toBeTrue();
            expect($p2->isFulfilled())->toBeTrue();
        });

        it('handles one query succeeding and another failing over', function () {
            $q1 = new Query('good.com', RecordType::A, RecordClass::IN);
            $q2 = new Query('bad.com', RecordType::A, RecordClass::IN);

            $primary = Mockery::mock(ExecutorInterface::class);
            $secondary = Mockery::mock(ExecutorInterface::class);

            $primary->shouldReceive('query')
                ->with($q1)
                ->once()
                ->andReturn(Promise::resolved(new Message()))
            ;

            $primary->shouldReceive('query')
                ->with($q2)
                ->once()
                ->andReturn(Promise::rejected(new QueryFailedException('Primary failed')))
            ;

            $secondary->shouldReceive('query')
                ->with($q2)
                ->once()
                ->andReturn(Promise::resolved(new Message()))
            ;

            $executor = new FallbackExecutor($primary, $secondary);

            $p1 = $executor->query($q1);
            $p2 = $executor->query($q2);

            Loop::run();

            expect($p1->isFulfilled())->toBeTrue();
            expect($p2->isFulfilled())->toBeTrue();
        });
    });

    describe('Edge Cases - Promise State', function () use ($query) {
        it('maintains correct promise state during transitions', function () use ($query) {
            $primary = new MockExecutor(shouldHang: true);
            $secondary = new MockExecutor(shouldHang: true);

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            expect($promise->isPending())->toBeTrue();
            expect($promise->isFulfilled())->toBeFalse();
            expect($promise->isRejected())->toBeFalse();

            $primary->pendingPromise?->reject(new QueryFailedException('Primary failed'));
            Loop::runOnce();

            expect($promise->isPending())->toBeTrue();

            $secondary->pendingPromise?->resolve(new Message());
            Loop::runOnce();

            expect($promise->isFulfilled())->toBeTrue();
            expect($promise->isPending())->toBeFalse();
        });

        it('allows attaching callbacks at any time', function () use ($query) {
            $primary = new MockExecutor(shouldHang: true);
            $secondary = new MockExecutor(resultToReturn: new Message());

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);

            $result = null;
            $promise->then(function ($msg) use (&$result) {
                $result = $msg;
            });

            $primary->pendingPromise?->reject(new QueryFailedException('Failed'));
            Loop::run();

            expect($result)->toBeInstanceOf(Message::class);
        });
    });

    describe('Edge Cases - Empty or Null Messages', function () use ($query) {
        it('handles empty Message objects', function () use ($query) {
            $primary = new MockExecutor(resultToReturn: new Message());
            $secondary = new MockExecutor(resultToReturn: new Message());

            $executor = new FallbackExecutor($primary, $secondary);

            $promise = $executor->query($query);
            Loop::runOnce();

            expect($promise->isFulfilled())->toBeTrue();
        });
    });

    describe('Edge Cases - Chained Fallbacks', function () use ($query) {
        it('supports chaining multiple fallback executors', function () use ($query) {
            $first = new MockExecutor(errorToThrow: new QueryFailedException('First failed'));
            $second = new MockExecutor(errorToThrow: new QueryFailedException('Second failed'));
            $third = new MockExecutor(resultToReturn: new Message());

            $fallback1 = new FallbackExecutor($first, $second);
            $fallback2 = new FallbackExecutor($fallback1, $third);

            $promise = $fallback2->query($query);
            Loop::run();

            expect($promise->isFulfilled())->toBeTrue();
            expect($third->wasCalled)->toBeTrue();
        });

        it('handles all executors in chain failing', function () use ($query) {
            $first = new MockExecutor(errorToThrow: new QueryFailedException('First'));
            $second = new MockExecutor(errorToThrow: new QueryFailedException('Second'));
            $third = new MockExecutor(errorToThrow: new QueryFailedException('Third'));

            $fallback1 = new FallbackExecutor($first, $second);
            $fallback2 = new FallbackExecutor($fallback1, $third);

            $promise = $fallback2->query($query);
            $promise->catch(fn () => null);

            Loop::run();

            expect($promise->isRejected())->toBeTrue();
        });
    });
});
