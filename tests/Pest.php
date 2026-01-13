<?php

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