<?php

declare(strict_types=1);

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\CachingExecutor;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Tests\Helpers\MockCache;
use Tests\Helpers\MockExecutor;

describe('CachingExecutor', function () {
    $query = new Query('google.com', RecordType::A, RecordClass::IN);
    $cacheKey = 'google.com:1:1';

    it('returns cached result immediately on Hit', function () use ($query, $cacheKey) {
        $cachedMsg = new Message();

        $cache = new MockCache();
        $cache->primeWith($cacheKey, $cachedMsg);

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->never();

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->value)->toBe($cachedMsg);
    });

    it('queries network and saves to cache on Miss', function () use ($query, $cacheKey) {
        $networkMsg = create_message_with_ttls(answerTtls: [300]);
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->with($query)->once()->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->value)->toBe($networkMsg);
        expect($cache->wasSet)->toBeTrue();
        expect($cache->lastSetKey)->toBe($cacheKey);
        expect($cache->lastSetTtl)->toBe(300.0);
    });

    it('calculates the minimum TTL from all records', function () use ($query) {
        $networkMsg = create_message_with_ttls(answerTtls: [300, 60, 3600]);
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->lastSetTtl)->toBe(60.0);
    });

    it('does NOT cache truncated responses', function () use ($query) {
        $networkMsg = create_message_with_ttls(answerTtls: [300], truncated: true);
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($cache->wasSet)->toBeFalse();
    });

    it('continues to network if Cache throws an error (Fail Open)', function () use ($query) {
        $networkMsg = new Message();
        $cache = new MockCache();
        $cache->failGetWith(new RuntimeException('Redis died'));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->once()->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->value)->toBe($networkMsg);
        expect($cache->wasSet)->toBeFalse();
    });

    it('cancels the Cache lookup if cancelled during Phase 1', function () use ($query) {
        $pendingCachePromise = new Promise();
        $wasCancelled = false;

        $pendingCachePromise->onCancel(function () use (&$wasCancelled) {
            $wasCancelled = true;
        });

        $cache = new MockCache();
        $cache->overrideGetPromise($pendingCachePromise);

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->never();

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        $promise->cancel();

        expect($wasCancelled)->toBeTrue();
    });

    it('cancels the Network query if cancelled during Phase 2', function () use ($query) {
        $cache = new MockCache();
        $mockExecutor = new MockExecutor(shouldHang: true);

        $executor = new CachingExecutor($cache, $mockExecutor);
        $promise = $executor->query($query);

        Loop::runOnce();
        $promise->cancel();

        expect($mockExecutor->wasCancelled)->toBeTrue();
    });

    it('uses default TTL when message has no answers or authority records', function () use ($query, $cacheKey) {
        $networkMsg = new Message();
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->wasSet)->toBeTrue();
        expect($cache->lastSetTtl)->toBe(60.0);
    });

    it('handles TTL of 0 correctly (immediate expiration)', function () use ($query) {
        $networkMsg = create_message_with_ttls(answerTtls: [0]);
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->lastSetTtl)->toBe(0.0);
    });

    it('handles very large TTL values', function () use ($query) {
        $networkMsg = create_message_with_ttls(answerTtls: [2147483647]);
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->lastSetTtl)->toBe(2147483647.0);
    });

    it('finds minimum TTL across answers and authority sections', function () use ($query) {
        $networkMsg = create_message_with_ttls(
            answerTtls: [500, 1000],
            authorityTtls: [100, 800]
        );
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->lastSetTtl)->toBe(100.0);
    });

    it('propagates network query errors to the caller', function () use ($query) {
        $networkError = new RuntimeException('Network timeout');
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::rejected($networkError));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isRejected())->toBeTrue();
        expect($promise->reason)->toBe($networkError);
        expect($cache->wasSet)->toBeFalse();
    });

    it('handles multiple concurrent queries for the same domain independently', function () use ($query) {
        $networkMsg = create_message_with_ttls(answerTtls: [300]);
        $cache = new MockCache();

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

        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->with($queryA)->once()->andReturn(Promise::resolved($msgA));
        $inner->shouldReceive('query')->with($queryAAAA)->once()->andReturn(Promise::resolved($msgAAAA));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($queryA);
        $executor->query($queryAAAA);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->wasSet)->toBeTrue();
    });

    it('generates different cache keys for different query classes', function () {
        $queryIN = new Query('example.com', RecordType::A, RecordClass::IN);
        $queryCH = new Query('example.com', RecordType::A, RecordClass::CH);

        $msgIN = create_message_with_ttls(answerTtls: [300]);
        $msgCH = create_message_with_ttls(answerTtls: [400]);

        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->with($queryIN)->once()->andReturn(Promise::resolved($msgIN));
        $inner->shouldReceive('query')->with($queryCH)->once()->andReturn(Promise::resolved($msgCH));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($queryIN);
        $executor->query($queryCH);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->wasSet)->toBeTrue();
    });

    it('does not cache when cache set operation fails', function () use ($query) {
        $networkMsg = create_message_with_ttls(answerTtls: [300]);
        $cache = new MockCache();
        $cache->failSetWith(new RuntimeException('Cache write failed'));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->value)->toBe($networkMsg);
    });

    it('handles domain names with special characters correctly', function () {
        $query = new Query('ex-ample.sub_domain.com', RecordType::A, RecordClass::IN);
        $expectedKey = 'ex-ample.sub_domain.com:1:1';
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->lastSetKey)->toBe($expectedKey);
    });

    it('handles internationalized domain names (IDN)', function () {
        $query = new Query('münchen.de', RecordType::A, RecordClass::IN);
        $expectedKey = 'münchen.de:1:1';
        $networkMsg = create_message_with_ttls(answerTtls: [300]);

        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($cache->lastSetKey)->toBe($expectedKey);
    });

    it('does not crash when authority section has lower TTL than answers', function () use ($query) {
        $networkMsg = create_message_with_ttls(
            answerTtls: [1000, 2000],
            authorityTtls: [50, 100]
        );
        $cache = new MockCache();

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->andReturn(Promise::resolved($networkMsg));

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($cache->lastSetTtl)->toBe(50.0);
    });

    it('handles cache returning cached result after network already started', function () use ($query) {
        $pendingCachePromise = new Promise();
        $cachedMsg = new Message();

        $cache = new MockCache();
        $cache->overrideGetPromise($pendingCachePromise);

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->never();

        $executor = new CachingExecutor($cache, $inner);
        $promise = $executor->query($query);

        $pendingCachePromise->resolve($cachedMsg);
        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->value)->toBe($cachedMsg);
        expect($cache->wasSet)->toBeFalse();
    });

    it('continues to work after cache fails then recovers', function () use ($query) {
        $networkMsg1 = create_message_with_ttls(answerTtls: [300]);
        $networkMsg2 = create_message_with_ttls(answerTtls: [400]);

        $cache = new MockCache();
        $cache->failGetWith(new RuntimeException('Cache down'));

        $inner = Mockery::mock(ExecutorInterface::class);
        $inner->shouldReceive('query')->once()->andReturn(Promise::resolved($networkMsg1));

        $executor = new CachingExecutor($cache, $inner);
        $promise1 = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise1->isFulfilled())->toBeTrue();

        $cache->recoverGet();
        $inner->shouldReceive('query')->once()->andReturn(Promise::resolved($networkMsg2));

        $promise2 = $executor->query($query);

        Loop::runOnce();
        Loop::runOnce();

        expect($promise2->isFulfilled())->toBeTrue();
    });
});