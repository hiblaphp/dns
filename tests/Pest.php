<?php

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Record;
use Hibla\EventLoop\Loop;

uses()->beforeEach(function () {
    Loop::reset();
})->afterEach(function () {
    Loop::reset();
    Loop::stop();
});

function skipTest(string $message = ''): void
{
    test()->markTestSkipped($message);
}

function run_with_timeout(float $seconds): void
{
    $timer = Loop::addTimer($seconds, function () {
        Loop::stop();
        test()->fail('Test timed out - check internet connection');
    });

    Loop::run();
    Loop::cancelTimer($timer);
}

function create_socket_pair(): array
{
    if (DIRECTORY_SEPARATOR === '\\') {
        skipTest('stream_socket_pair is not supported on Windows');
    }

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($sockets === false) {
        test()->fail('Failed to create socket pair');
    }

    stream_set_blocking($sockets[0], false);
    stream_set_blocking($sockets[1], false);

    return $sockets;
}


function retryTest(callable $test, int $maxRetries = 3, int $retryDelayMs = 500): void
{
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $test();
            return;
        } catch (Throwable $e) {
            $lastError = $e;

            if ($attempt < $maxRetries) {
                usleep($retryDelayMs * 1000);
                Loop::reset();
            }
        }
    }

    throw $lastError;
}

function withResolvConf(string $content, callable $testFn): void
{
    $file = sys_get_temp_dir() . '/resolv_' . uniqid() . '.conf';
    file_put_contents($file, $content);
    try {
        $testFn($file);
    } finally {
        if (file_exists($file)) unlink($file);
    }
}


function create_message_with_ttls(
    array $answerTtls = [],
    array $authorityTtls = [],
    bool $truncated = false
): Message {
    $msg = new Message();
    $msg->isTruncated = $truncated;

    foreach ($answerTtls as $ttl) {
        $msg->answers[] = new Record(
            'example.com',
            RecordType::A,
            RecordClass::IN,
            $ttl,
            '1.2.3.4'
        );
    }

    foreach ($authorityTtls as $ttl) {
        $msg->authority[] = new Record(
            'example.com',
            RecordType::NS,
            RecordClass::IN,
            $ttl,
            'ns1.example.com'
        );
    }

    return $msg;
}
