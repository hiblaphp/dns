<?php

use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\CachingExecutor;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Tests\Helpers\MockExecutor;

describe('CachingExecutor', function () {
    $query = new Query('google.com', RecordType::A, RecordClass::IN);
    $cacheKey = 'google.com:1:1';

    it('returns cached result immediately on Hit', function () use ($query, $cacheKey) {
        $cachedMsg = new Message();

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')
            ->with($cacheKey)
            ->once()
            ->andReturn(Promise::resolved($cachedMsg));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->never();

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($cachedMsg);
    });

    it('queries network and saves to cache on Miss', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with($cacheKey)->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 300.0)->once()->andReturn(Promise::resolved(true));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->with($query)->once()->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($networkMsg);
    });

    it('calculates the minimum TTL from all records', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [300, 60, 3600]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 60.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('does NOT cache truncated responses', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [300], truncated: true);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->never();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
    });

    it('continues to network if Cache throws an error (Fail Open)', function () use ($query, $cacheKey) {
        $networkMsg = new Message();

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::rejected(new RuntimeException('Redis died')));
        $cache->shouldReceive('set')->never();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->once()->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($networkMsg);
    });

    it('cancels the Cache lookup if cancelled during Phase 1', function () use ($query, $cacheKey) {
        $pendingCachePromise = new Promise();
        $wasCancelled = false;

        $pendingCachePromise->onCancel(function () use (&$wasCancelled) {
            $wasCancelled = true;
        });

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn($pendingCachePromise);

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->never();

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        $promise->cancel();

        expect($wasCancelled)->toBeTrue();
    });

    it('cancels the Network query if cancelled during Phase 2', function () use ($query, $cacheKey) {
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));

        $mockExecutor = new MockExecutor(shouldHang: true);

        $executor = new CachingExecutor($cache, $mockExecutor);
        $promise = $executor->query($query);

        Loop::runOnce();
        $promise->cancel();

        expect($mockExecutor->wasCancelled)->toBeTrue();
    });

    it('uses default TTL when message has no answers or authority records', function () use ($query, $cacheKey) {
        $networkMsg = new Message();

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 60.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('handles TTL of 0 correctly (immediate expiration)', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [0]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 0.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('handles very large TTL values', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [2147483647]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 2147483647.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('finds minimum TTL across answers and authority sections', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(
            answerTtls: [500, 1000],
            authorityTtls: [100, 800]
        );

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 100.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('propagates network query errors to the caller', function () use ($query, $cacheKey) {
        $networkError = new RuntimeException('Network timeout');

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->never();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::rejected($networkError));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isRejected())->toBeTrue();
        expect($promise->getReason())->toBe($networkError);
    });

    it('handles multiple concurrent queries for the same domain independently', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->twice()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->twice()->andReturn(Promise::resolved(true));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->twice()->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise1 = $executor->query($query);
        $promise2 = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise1->isFulfilled())->toBeTrue();
        expect($promise2->isFulfilled())->toBeTrue();
    });

    it('generates different cache keys for different query types', function () {
        $queryA = new Query('example.com', RecordType::A, RecordClass::IN);
        $queryAAAA = new Query('example.com', RecordType::AAAA, RecordClass::IN);

        $msgA = create_message_with_ttls(answerTtls: [300]);
        $msgAAAA = create_message_with_ttls(answerTtls: [400]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with('example.com:1:1')->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('get')->with('example.com:28:1')->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with('example.com:1:1', $msgA, 300.0)->once();
        $cache->shouldReceive('set')->with('example.com:28:1', $msgAAAA, 400.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->with($queryA)->once()->andReturn(Promise::resolved($msgA));
        $inner->shouldReceive('query')->with($queryAAAA)->once()->andReturn(Promise::resolved($msgAAAA));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($queryA);
        $executor->query($queryAAAA);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('generates different cache keys for different query classes', function () {
        $queryIN = new Query('example.com', RecordType::A, RecordClass::IN);
        $queryCH = new Query('example.com', RecordType::A, RecordClass::CH);

        $msgIN = create_message_with_ttls(answerTtls: [300]);
        $msgCH = create_message_with_ttls(answerTtls: [400]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with('example.com:1:1')->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('get')->with('example.com:1:3')->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with('example.com:1:1', $msgIN, 300.0)->once();
        $cache->shouldReceive('set')->with('example.com:1:3', $msgCH, 400.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->with($queryIN)->once()->andReturn(Promise::resolved($msgIN));
        $inner->shouldReceive('query')->with($queryCH)->once()->andReturn(Promise::resolved($msgCH));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($queryIN);
        $executor->query($queryCH);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('does not cache when cache set operation fails', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));

        $failedSetPromise = Mockery::mock(PromiseInterface::class);
        $failedSetPromise->shouldReceive('then')
            ->with(null, Mockery::type('Closure'))
            ->andReturnUsing(function ($onFulfilled, $onRejected) {
                if ($onRejected) {
                    $onRejected(new RuntimeException('Cache write failed'));
                }
                return $this;
            });

        $cache->shouldReceive('set')->andReturn($failedSetPromise);

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($networkMsg);
    });

    it('handles domain names with special characters correctly', function () {
        $query = new Query('ex-ample.sub_domain.com', RecordType::A, RecordClass::IN);
        $expectedKey = 'ex-ample.sub_domain.com:1:1';
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with($expectedKey)->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($expectedKey, $networkMsg, 300.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('handles internationalized domain names (IDN)', function () {
        $query = new Query('münchen.de', RecordType::A, RecordClass::IN);
        $expectedKey = 'münchen.de:1:1';
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with($expectedKey)->once()->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($expectedKey, $networkMsg, 300.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();
    });

    it('does not crash when authority section has lower TTL than answers', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(
            answerTtls: [1000, 2000],
            authorityTtls: [50, 100]
        );

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->with($cacheKey, $networkMsg, 50.0)->once();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
    });

    it('handles cache returning cached result after network already started', function () use ($query, $cacheKey) {
        $slowCachePromise = new Promise();
        $cachedMsg = new Message();

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn($slowCachePromise);
        $cache->shouldReceive('set')->never();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->never();

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        $slowCachePromise->resolve($cachedMsg);
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($cachedMsg);
    });

    it('continues to work after cache fails then recovers', function () use ($query, $cacheKey) {
        $networkMsg1 = create_message_with_ttls(answerTtls: [300]);
        $networkMsg2 = create_message_with_ttls(answerTtls: [400]);

        $cache = Mockery::mock(CacheInterface::class);

        $cache->shouldReceive('get')
            ->once()
            ->andReturn(Promise::rejected(new RuntimeException('Cache down')));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->once()->andReturn(Promise::resolved($networkMsg1));

        $executor = new CachingExecutor($cache, $inner);
        $promise1 = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise1->isFulfilled())->toBeTrue();

        $cache->shouldReceive('get')
            ->once()
            ->andReturn(Promise::resolved(null));
        $cache->shouldReceive('set')->once()->andReturn(Promise::resolved(true));

        $inner->shouldReceive('query')->once()->andReturn(Promise::resolved($networkMsg2));

        $promise2 = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise2->isFulfilled())->toBeTrue();
    });
});
