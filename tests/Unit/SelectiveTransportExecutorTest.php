<?php

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Exceptions\ResponseTruncatedException;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\SelectiveTransportExecutor;
use Tests\Helpers\MockExecutor;
use Hibla\EventLoop\Loop;

describe('SelectiveTransportExecutor', function () {
    $query = new Query('google.com', RecordType::A, RecordClass::IN);
    $successMessage = new Message();

    it('returns UDP result if UDP succeeds', function () use ($query, $successMessage) {
        $udp = new MockExecutor(resultToReturn: $successMessage);
        $tcp = new MockExecutor(); 

        $executor = new SelectiveTransportExecutor($udp, $tcp);
        $promise = $executor->query($query);

        Loop::runOnce();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($successMessage);
        expect($tcp->wasCalled)->toBeFalse();
    });

    it('switches to TCP if UDP returns ResponseTruncatedException', function () use ($query, $successMessage) {
        $udp = new MockExecutor(errorToThrow: new ResponseTruncatedException('Truncated'));
        $tcp = new MockExecutor(resultToReturn: $successMessage);

        $executor = new SelectiveTransportExecutor($udp, $tcp);
        $promise = $executor->query($query);

        Loop::runOnce(); // UDP fail
        Loop::runOnce(); // TCP start/resolve

        expect($udp->wasCalled)->toBeTrue();
        expect($tcp->wasCalled)->toBeTrue();
        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue())->toBe($successMessage);
    });

    it('fails if UDP fails with a standard error (no failover)', function () use ($query) {
        $udp = new MockExecutor(errorToThrow: new QueryFailedException('Connection refused'));
        $tcp = new MockExecutor();

        $executor = new SelectiveTransportExecutor($udp, $tcp);
        $promise = $executor->query($query);

        $promise->catch(fn() => null);

        Loop::runOnce();

        expect($promise->isRejected())->toBeTrue();
        expect($tcp->wasCalled)->toBeFalse();
    });

    it('fails if failover TCP also fails', function () use ($query) {
        $udp = new MockExecutor(errorToThrow: new ResponseTruncatedException('Truncated'));
        $tcp = new MockExecutor(errorToThrow: new QueryFailedException('TCP Connection refused'));

        $executor = new SelectiveTransportExecutor($udp, $tcp);
        $promise = $executor->query($query);

        Loop::runOnce(); // UDP fail
        Loop::runOnce(); // TCP fail

        expect($promise->isRejected())->toBeTrue();
        
        try {
            $promise->wait();
        } catch (QueryFailedException $e) {
            expect($e->getMessage())->toContain('TCP Connection refused');
        }
    });

    it('cancels UDP if cancelled during UDP phase', function () use ($query) {
        $udp = new MockExecutor(shouldHang: true);
        $tcp = new MockExecutor();

        $executor = new SelectiveTransportExecutor($udp, $tcp);
        $promise = $executor->query($query);
        
        $promise->cancel();

        Loop::runOnce();

        expect($udp->wasCancelled)->toBeTrue();
        expect($tcp->wasCalled)->toBeFalse();
    });

    it('cancels TCP if cancelled during TCP phase', function () use ($query) {
        $udp = new MockExecutor(errorToThrow: new ResponseTruncatedException('Truncated'));
        $tcp = new MockExecutor(shouldHang: true);

        $executor = new SelectiveTransportExecutor($udp, $tcp);
        $promise = $executor->query($query);
        
        Loop::runOnce(); 

        $promise->cancel();
        
        Loop::runOnce();

        expect($tcp->wasCancelled)->toBeTrue();
    });
});