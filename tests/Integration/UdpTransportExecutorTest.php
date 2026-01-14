<?php

declare(strict_types=1);

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Exceptions\ResponseTruncatedException;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\UdpTransportExecutor;
use Hibla\EventLoop\Loop;

describe('UdpTransportExecutor Integration', function () {
    beforeEach(function () {
        $socket = @fsockopen('udp://1.1.1.1', 53, $errno, $errstr, 0.5);

        if (! $socket) {
            test()->skip('No internet connection to 1.1.1.1');
        }

        fclose($socket);
        Loop::reset();
    });

    afterEach(function () {
        Loop::forceStop();
        Loop::reset();
    });

    it('resolves a real A record (google.com)', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');
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
            fn ($record) => $record->type === RecordType::AAAA
        );

        expect($aaaaRecords)->not->toBeEmpty('No AAAA records found (likely only received CNAME)');

        $ip = reset($aaaaRecords)->data;

        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))->not->toBeFalse();
    });

    it('returns NXDOMAIN for non-existent domains', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
        $domain = 'hibla-test-'.uniqid().'.invalid';
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
        expect(fn () => new UdpTransportExecutor('tcp://1.1.1.1'))
            ->toThrow(InvalidArgumentException::class, 'Only udp:// scheme is supported')
        ;
    });

    it('automatically adds default DNS port 53', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');

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
        $executor = new UdpTransportExecutor('1.1.1.1');

        $longLabel = str_repeat('a', 60);
        $longDomain = $longLabel.'.'.$longLabel.'.'.$longLabel.'.'.$longLabel.'.com';

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
            expect($error)->toBeInstanceOf(QueryFailedException::class);
            expect($error->getMessage())->toContain('Query too large');
        } else {
            expect($result)->toBeInstanceOf(Message::class);
        }
    });

    it('resolves TXT records (handling arrays of strings)', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('[2606:4700:4700::1111]');
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
        set_error_handler(fn () => true);

        $socket = @fsockopen('udp://[2606:4700:4700::1111]', 53, $errno, $errstr, 0.5);

        restore_error_handler();

        if ($socket) {
            fclose($socket);

            return false;
        }

        return true;
    }, 'No IPv6 connectivity to Cloudflare DNS');

    it('handles multiple concurrent queries', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');

        $domains = ['google.com', 'facebook.com', 'github.com'];
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

        run_with_timeout(3.0);

        expect($results)->toHaveCount(count($domains));
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(Message::class);
            expect($result->responseCode)->toBe(ResponseCode::OK);
        }
    });

    it('handles rapid successive queries', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');

        $results = [];
        $completed = 0;
        $totalQueries = 5;

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

        run_with_timeout(3.0);

        expect($results)->toHaveCount($totalQueries);
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(Message::class);
        }
    });

    it('handles cancellation before response arrives', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        $timeout = Loop::addTimer(0.1, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);
    });

    it('handles multiple cancellations', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $promise->cancel();
        $promise->cancel();
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        Loop::run();
    });

    it('handles connection to unreachable server', function () {
        $executor = new UdpTransportExecutor('192.0.2.1'); // TEST-NET-1 (RFC 5737)
        $query = new Query('google.com', RecordType::A, RecordClass::IN);

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

        $timeout = Loop::addTimer(2.0, function () {
            Loop::stop();
        });

        Loop::run();
        Loop::cancelTimer($timeout);

        expect($result)->toBeNull();
    });

    it('handles invalid nameserver format gracefully', function () {
        expect(fn () => new UdpTransportExecutor('not a valid address'))
            ->not->toThrow(InvalidArgumentException::class)
        ;

        $executor = new UdpTransportExecutor('not-a-valid-address');
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

        run_with_timeout(2.0);

        expect($error)->toBeInstanceOf(QueryFailedException::class);
    });

    it('handles empty domain name', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');
        $query = new Query('invalid domain with spaces.com', RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
        expect($result->responseCode)->toBeIn([
            ResponseCode::NAME_ERROR,
            ResponseCode::FORMAT_ERROR,
        ]);
    });

    it('handles nameserver with explicit port', function () {
        $executor = new UdpTransportExecutor('1.1.1.1:53');
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

    it('handles different record types for same domain', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');

        $recordTypes = [
            RecordType::A,
            RecordType::AAAA,
            RecordType::NS,
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

        run_with_timeout(3.0);

        expect($results)->toHaveCount(count($recordTypes));
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(Message::class);
        }
    });

    it('handles CNAME record queries', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');
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
        $executor = new UdpTransportExecutor('1.1.1.1');
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

    it('handles mixed success and failure queries', function () {
        retryTest(function () {
            $executor = new UdpTransportExecutor('1.1.1.1');

            $queries = [
                ['domain' => 'google.com', 'expected' => ResponseCode::OK],
                ['domain' => 'this-will-not-resolve-ever-12345.com', 'expected' => ResponseCode::NAME_ERROR],
                ['domain' => 'facebook.com', 'expected' => ResponseCode::OK],
            ];

            $results = [];
            $completed = 0;

            foreach ($queries as $i => $queryData) {
                $query = new Query($queryData['domain'], RecordType::A, RecordClass::IN);
                $executor->query($query)->then(
                    function (Message $msg) use (&$results, $i, &$completed, $queries) {
                        $results[$i] = $msg;
                        $completed++;
                        if ($completed === count($queries)) {
                            Loop::stop();
                        }
                    }
                );
            }

            run_with_timeout(3.0);

            expect($results)->toHaveCount(count($queries));
            expect($results[0]->responseCode)->toBe(ResponseCode::OK);
            expect($results[1]->responseCode)->toBe(ResponseCode::NAME_ERROR);
            expect($results[2]->responseCode)->toBe(ResponseCode::OK);
        }, maxRetries: 3, retryDelayMs: 500);
    });

    it('handles very long but valid domain names', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
        $longDomain = str_repeat('a', 50).'.'.str_repeat('b', 50).'.com';
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

    it('each query creates a new socket connection', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');

        $query1 = new Query('google.com', RecordType::A, RecordClass::IN);
        $result1 = null;

        $executor->query($query1)->then(function (Message $msg) use (&$result1) {
            $result1 = $msg;
            Loop::stop();
        });

        run_with_timeout(2.0);
        expect($result1)->toBeInstanceOf(Message::class);

        Loop::reset();

        $query2 = new Query('facebook.com', RecordType::A, RecordClass::IN);
        $result2 = null;

        $executor->query($query2)->then(function (Message $msg) use (&$result2) {
            $result2 = $msg;
            Loop::stop();
        });

        run_with_timeout(2.0);
        expect($result2)->toBeInstanceOf(Message::class);
    });

    it('handles query with maximum label length', function () {
        $executor = new UdpTransportExecutor('1.1.1.1');
        $maxLabel = str_repeat('a', 63);
        $query = new Query("{$maxLabel}.com", RecordType::A, RecordClass::IN);

        $promise = $executor->query($query);

        $result = null;
        $promise->then(function (Message $message) use (&$result) {
            $result = $message;
            Loop::stop();
        });

        run_with_timeout(2.0);

        expect($result)->toBeInstanceOf(Message::class);
    });
})->skipOnCI();
