<?php

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\TcpTransportExecutor;
use Hibla\EventLoop\Loop;

describe('TcpTransportExecutor Integration', function () {
    beforeEach(function () {
        $socket = @fsockopen('tcp://1.1.1.1', 53, $errno, $errstr, 0.5);

        if (!$socket) {
            skipTest('No internet connection to 1.1.1.1');
        }

        fclose($socket);
        Loop::reset();
    });

    afterEach(function () {
        Loop::forceStop();
        Loop::reset();
    });

    it('resolves a real A record (google.com)', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
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
    });

    it('handles large responses without truncation', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');

        // TXT records often return large responses
        $query = new Query('google.com', RecordType::TXT, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBe(ResponseCode::OK);
        // TCP should handle large responses without truncation
        expect($result->isTruncated)->toBeFalse();
    });

    it('reuses connection for multiple queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');

        $results = [];
        $queries = [
            new Query('google.com', RecordType::A, RecordClass::IN),
            new Query('facebook.com', RecordType::A, RecordClass::IN),
        ];

        $completed = 0;
        foreach ($queries as $i => $query) {
            $executor->query($query)->then(
                function (Message $msg) use (&$results, $i, &$completed) {
                    $results[$i] = $msg;
                    $completed++;
                    if ($completed === 2) {
                        Loop::stop();
                    }
                }
            );
        }

        run_with_timeout(3.0);

        expect($results)->toHaveCount(2);
        expect($results[0])->toBeInstanceOf(Message::class);
        expect($results[1])->toBeInstanceOf(Message::class);
    });

    it('handles connection failures gracefully', function () {
        $executor = new TcpTransportExecutor('tcp://127.0.0.1:9999');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $error = null;
        $resolved = false;

        $promise->then(
            function ($result) use (&$resolved) {
                $resolved = true;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        $timeout = Loop::addTimer(3.0, function () {
            Loop::stop();
        });

        Loop::run();
        Loop::cancelTimer($timeout);
        if ($resolved) {
            test()->fail('Query should have failed but resolved successfully');
        }

        expect($error)->toBeInstanceOf(QueryFailedException::class);
    });

    it('validates scheme in constructor', function () {
        expect(fn() => new TcpTransportExecutor('udp://1.1.1.1'))
            ->toThrow(InvalidArgumentException::class, 'Only tcp:// scheme is supported');
    });

    it('automatically adds default DNS port 53', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
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

    it('connects to IPv6 nameserver', function () {
        $executor = new TcpTransportExecutor('[2606:4700:4700::1111]'); // Cloudflare IPv6
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
    })->skip(function () {
        set_error_handler(fn() => true);
        $socket = @fsockopen('tcp://[2606:4700:4700::1111]', 53, $errno, $errstr, 0.5);
        restore_error_handler();

        if ($socket) {
            fclose($socket);
            return false;
        }

        return true;
    }, 'No IPv6 connectivity to Cloudflare DNS');

    it('cancels pending queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        Loop::run();
    });

    it('handles NXDOMAIN responses', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('this-domain-definitely-does-not-exist-12345.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBe(ResponseCode::NAME_ERROR);
    });

    it('handles queries for non-existent record types', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        // Query for MX records on a domain that has none
        $query = new Query('localhost', RecordType::MX, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeIn([ResponseCode::OK, ResponseCode::NAME_ERROR]);
    });

    it('handles concurrent queries to different domains', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');

        $domains = ['google.com', 'facebook.com', 'twitter.com', 'github.com', 'stackoverflow.com'];
        $results = [];
        $completed = 0;

        foreach ($domains as $i => $domain) {
            $query = new Query($domain, RecordType::A, RecordClass::IN);
            $executor->query($query)->then(
                function (Message $msg) use (&$results, $i, &$completed, $domains) {
                    $results[$i] = $msg;
                    $completed++;
                    if ($completed === count($domains)) {
                        Loop::stop();
                    }
                }
            );
        }

        run_with_timeout(5.0);

        expect($results)->toHaveCount(count($domains));
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(Message::class);
        }
    });

    it('handles query cancellation before connection is established', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        $timeout = Loop::addTimer(0.1, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);
    });

    it('handles multiple cancellations for same query', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $promise->cancel();
        $promise->cancel();
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        Loop::run();
    });

    it('handles query after connection is closed', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');

        $query1 = new Query('google.com', RecordType::A, RecordClass::IN);
        $result1 = null;

        $executor->query($query1)->then(
            function (Message $msg) use (&$result1) {
                $result1 = $msg;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();
            }
        );

        run_with_timeout(2.0);
        expect($result1)->toBeInstanceOf(Message::class);

        Loop::reset();

        $query2 = new Query('facebook.com', RecordType::A, RecordClass::IN);
        $result2 = null;

        $executor->query($query2)->then(
            function (Message $msg) use (&$result2) {
                $result2 = $msg;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();
            }
        );

        run_with_timeout(2.0);
        expect($result2)->toBeInstanceOf(Message::class);
    });

    it('handles empty domain name', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $error = null;

        $promise->then(
            function (Message $msg) use (&$result) {
                $result = $msg;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        run_with_timeout(2.0);

        // Should either get a response or error, not hang
        expect($result !== null || $error !== null)->toBeTrue();
    });

    it('handles invalid domain characters', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('invalid domain with spaces.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        // DNS server should respond with NAME_ERROR or FORMAT_ERROR
        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeIn([
            ResponseCode::NAME_ERROR,
            ResponseCode::FORMAT_ERROR
        ]);
    });

    it('handles very long domain names', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        // DNS labels can be max 63 chars, total name max 253 chars
        $longDomain = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.com';
        $query = new Query($longDomain, RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
    });

    it('handles connection timeout gracefully', function () {
        $executor = new TcpTransportExecutor('192.0.2.1'); // TEST-NET-1 (RFC 5737)
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $error = null;
        $resolved = false;

        $promise->then(
            function ($result) use (&$resolved) {
                $resolved = true;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        $timeout = Loop::addTimer(5.0, function () {
            Loop::stop();
        });

        Loop::run();
        Loop::cancelTimer($timeout);

        expect($resolved)->toBeFalse();
    });

    it('handles mixed success and failure queries', function () {
        retryTest(function () {
            $executor = new TcpTransportExecutor('1.1.1.1');

            $queries = [
                ['domain' => 'google.com', 'expected' => ResponseCode::OK],
                ['domain' => 'this-will-not-resolve-ever-12345.com', 'expected' => ResponseCode::NAME_ERROR],
                ['domain' => 'facebook.com', 'expected' => ResponseCode::OK],
            ];

            $results = [];
            $completed = 0;
            $error = null;

            foreach ($queries as $i => $queryData) {
                $query = new Query($queryData['domain'], RecordType::A, RecordClass::IN);
                $executor->query($query)->then(
                    function (Message $msg) use (&$results, $i, &$completed, $queries) {
                        $results[$i] = $msg;
                        $completed++;
                        if ($completed === count($queries)) {
                            Loop::stop();
                        }
                    },
                    function ($e) use (&$error) {
                        $error = $e;
                        Loop::stop();
                    }
                );
            }

            run_with_timeout(5.0);

            if ($error !== null) {
                throw $error;
            }

            expect($results)->toHaveCount(count($queries));
            expect($results[0]->responseCode)->toBe(ResponseCode::OK);
            expect($results[1]->responseCode)->toBe(ResponseCode::NAME_ERROR);
            expect($results[2]->responseCode)->toBe(ResponseCode::OK);
        }, maxRetries: 3, retryDelayMs: 500);
    });

    it('handles rapid successive queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');

        $results = [];
        $completed = 0;
        $totalQueries = 10;

        for ($i = 0; $i < $totalQueries; $i++) {
            $query = new Query('google.com', RecordType::A, RecordClass::IN);
            $executor->query($query)->then(
                function (Message $msg) use (&$results, $i, &$completed, $totalQueries) {
                    $results[$i] = $msg;
                    $completed++;
                    if ($completed === $totalQueries) {
                        Loop::stop();
                    }
                }
            );
        }

        run_with_timeout(5.0);

        expect($results)->toHaveCount($totalQueries);
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(Message::class);
            expect($result->responseCode)->toBe(ResponseCode::OK);
        }
    });

    it('handles different record types for same domain', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');

        $recordTypes = [
            RecordType::A,
            RecordType::AAAA,
            RecordType::TXT,
            RecordType::NS,
            RecordType::MX,
            RecordType::SOA,
        ];

        $results = [];
        $completed = 0;

        foreach ($recordTypes as $i => $type) {
            $query = new Query('google.com', $type, RecordClass::IN);
            $executor->query($query)->then(
                function (Message $msg) use (&$results, $i, &$completed, $recordTypes) {
                    $results[$i] = $msg;
                    $completed++;
                    if ($completed === count($recordTypes)) {
                        Loop::stop();
                    }
                }
            );
        }

        run_with_timeout(5.0);

        expect($results)->toHaveCount(count($recordTypes));
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(Message::class);
            expect($result->responseCode)->toBe(ResponseCode::OK);
        }
    });

    it('handles nameserver with explicit port', function () {
        $executor = new TcpTransportExecutor('1.1.1.1:53');
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

    it('validates invalid nameserver format', function () {
        expect(fn() => new TcpTransportExecutor('not a valid address'))
            ->not->toThrow(InvalidArgumentException::class);

        $executor = new TcpTransportExecutor('not-a-valid-address');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);
        $error = null;

        $promise->then(
            function ($result) {
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        run_with_timeout(3.0);

        expect($error)->toBeInstanceOf(QueryFailedException::class);
    });

    it('handles CNAME record queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('www.google.com', RecordType::CNAME, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBe(ResponseCode::OK);
    });

    it('handles PTR record queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('1.1.1.1.in-addr.arpa', RecordType::PTR, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeIn([ResponseCode::OK, ResponseCode::NAME_ERROR]);
    });

    it('handles SRV record queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('_xmpp-server._tcp.gmail.com', RecordType::SRV, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeIn([ResponseCode::OK, ResponseCode::NAME_ERROR]);
    });

    it('handles CAA record queries', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::CAA, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeIn([ResponseCode::OK, ResponseCode::NAME_ERROR]);
    });

    it('handles server failure response', function () {
        $executor = new TcpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeInstanceOf(ResponseCode::class);
    });
})->skipOnCI();
