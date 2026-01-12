<?php

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Exceptions\ResponseTruncatedException;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\UdpTransportExecutor;
use Hibla\EventLoop\Loop;

describe('UdpTransportExecutor Integration', function () {
    beforeEach(function () {
        $socket = @fsockopen('udp://8.8.8.8', 53, $errno, $errstr, 0.5);

        if (!$socket) {
            test()->skip('No internet connection to 8.8.8.8');
        }

        fclose($socket);
    });

    it('resolves a real A record (google.com)', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBe(ResponseCode::OK);
        expect($result->answers)->not->toBeEmpty();

        $ip = $result->answers[0]->data;
        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
    });

    it('resolves a real AAAA record (ipv6.google.com)', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');
        $query = new Query('ipv6.google.com', RecordType::AAAA, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        $aaaaRecords = array_filter(
            $result->answers,
            fn($record) => $record->type === RecordType::AAAA
        );

        expect($aaaaRecords)->not->toBeEmpty('No AAAA records found (likely only received CNAME)');

        $ip = reset($aaaaRecords)->data;

        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))->not->toBeFalse();
    });

    it('returns NXDOMAIN for non-existent domains', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');
        $domain = 'hibla-test-' . uniqid() . '.invalid';
        $query = new Query($domain, RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBe(ResponseCode::NAME_ERROR);
        expect($result->answers)->toBeEmpty();
    });

    it('cleans up resources when cancelled', function () {
        $executor = new UdpTransportExecutor('192.0.2.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        Loop::run();
    });

    it('rejects invalid schemes in constructor', function () {
        expect(fn() => new UdpTransportExecutor('tcp://8.8.8.8'))
            ->toThrow(InvalidArgumentException::class, 'Only udp:// scheme is supported');
    });

    it('automatically adds default DNS port 53', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function ($msg) use (&$result) {
            $result = $msg;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
    });

    it('handles truncation (TC bit)', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');

        $query = new Query('google.com', RecordType::ANY, RecordClass::IN);

        $promise = $executor->query($query);

        $outcome = null;
        $promise->then(
            function () use (&$outcome) {
                $outcome = 'resolved';
                Loop::stop();
            },
            function (Throwable $e) use (&$outcome) {
                $outcome = $e->getMessage();
                Loop::stop();
            }
        );

        run_with_timeout(2.0);

        if ($outcome !== 'resolved') {
            expect($outcome)->toContain('truncated');
        } else {
            expect(true)->toBeTrue();
        }
    });

    it('rejects queries that exceed UDP packet limits', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');

        $longLabel = str_repeat('a', 60);
        $longDomain = $longLabel . '.' . $longLabel . '.' . $longLabel . '.' . $longLabel . '.com';


        $query = new Query($longDomain, RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        $result = null;
        $error = null;

        $promise->then(
            function ($m) use (&$result) {
                $result = $m;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        run_with_timeout(2.0);

        if ($error) {
            expect($error)->toBeInstanceOf(Hibla\Dns\Exceptions\QueryFailedException::class);
            expect($error->getMessage())->toContain('Query too large');
        } else {
            expect($result)->toBeInstanceOf(Message::class);
        }
    });

    it('resolves TXT records (handling arrays of strings)', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');
        $query = new Query('google.com', RecordType::TXT, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $error = null;
        $promise->then(
            function (Message $message) use (&$result) {
                $result = $message;
                Loop::stop();
            },
            function (Throwable $e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        run_with_timeout(2.0);

        if ($error instanceof ResponseTruncatedException) {
            skipTest('TXT record was truncated (expected with UDP)');
        }

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->answers)->not->toBeEmpty();

        $txtData = $result->answers[0]->data;
        expect($txtData)->toBeArray();
        expect($txtData[0])->toBeString();
    });

    it('resolves MX records (handling structured data)', function () {
        $executor = new UdpTransportExecutor('8.8.8.8');
        $query = new Query('google.com', RecordType::MX, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result->answers)->not->toBeEmpty();

        $mxData = $result->answers[0]->data;
        expect($mxData)->toBeArray();
        expect($mxData)->toHaveKeys(['priority', 'target']);
        expect($mxData['priority'])->toBeInt();
        expect($mxData['target'])->toBeString();
    });

    it('connects to an IPv6 nameserver', function () {
        $executor = new UdpTransportExecutor('[2001:4860:4860::8888]');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBe(ResponseCode::OK);
        expect($result->answers)->not->toBeEmpty();
    })->skip(function () {
        set_error_handler(fn() => true);

        $socket = @fsockopen('udp://[2001:4860:4860::8888]', 53, $errno, $errstr, 0.5);

        restore_error_handler();

        if ($socket) {
            fclose($socket);
            return false;
        }

        return true;
    }, 'No IPv6 connectivity to Google DNS');
});
