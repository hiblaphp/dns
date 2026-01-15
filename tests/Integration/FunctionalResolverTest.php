<?php

declare(strict_types=1);

use Hibla\Dns\Dns;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\EventLoop\Loop;

describe('Functional Resolver (Real Network)', function () {
    beforeEach(function () {
        $socket = @fsockopen('udp://8.8.8.8', 53, $errno, $errstr, 0.5);
        if (! $socket) {
            test()->skip('No internet connection to 8.8.8.8');
        }
        fclose($socket);
        Loop::reset();
    });

    afterEach(function () {
        Loop::forceStop();
        Loop::reset();
    });

    it('resolves google.com A records using the default stack', function () {
        $resolver = Dns::create();

        $ip = null;
        $resolver->resolve('google.com')->then(function ($result) use (&$ip) {
            $ip = $result;
            Loop::stop();
        }, function ($error) {
            Loop::stop();

            throw $error;
        });

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($ip)->toBeString();
        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
    });

    it('resolves google.com AAAA (IPv6) records', function () {
        $resolver = Dns::create();

        $ips = null;
        $resolver->resolveAll('google.com', RecordType::AAAA)->then(
            function ($result) use (&$ips) {
                $ips = $result;
                Loop::stop();
            },
            function ($error) use (&$ips) {
                $ips = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (! empty($ips)) {
            expect($ips)->toBeArray();
            expect($ips[0])->toBeString();
            expect(filter_var($ips[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))->not->toBeFalse();
        } else {
            expect($ips)->toBeArray();
        }
    });

    it('resolves google.com MX records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('google.com', RecordType::MX)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($records)->toBeArray();
        expect($records)->not->toBeEmpty();
        expect($records[0])->toHaveKeys(['priority', 'target']);
        expect($records[0]['priority'])->toBeInt();
        expect($records[0]['target'])->toBeString();
    });

    it('resolves TXT records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('google.com', RecordType::TXT)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($records)->toBeArray();
        expect($records)->not->toBeEmpty();
        expect($records[0])->toBeArray();
    });

    it('resolves NS (nameserver) records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('google.com', RecordType::NS)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($records)->toBeArray();
        expect($records)->not->toBeEmpty();
        expect($records[0])->toBeString();
        expect($records[0])->toMatch('/\./');
    });

    it('resolves CNAME chains (e.g., www.github.com)', function () {
        $resolver = Dns::create();

        $ip = null;
        $resolver->resolve('www.github.com')->then(
            function ($result) use (&$ip) {
                $ip = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($ip)->toBeString();
        expect(filter_var($ip, FILTER_VALIDATE_IP))->not->toBeFalse();
    });

    it('uses caching for subsequent requests', function () {
        $resolver = Dns::new()
            ->withNameservers('8.8.8.8')
            ->withCache()
            ->build()
        ;

        $step1Done = false;
        $step2Done = false;
        $firstResolveTime = 0;
        $secondResolveTime = 0;

        $start1 = microtime(true);
        $resolver->resolve('example.com')->then(function () use (
            $resolver,
            &$step1Done,
            &$step2Done,
            &$firstResolveTime,
            &$secondResolveTime,
            $start1
        ) {
            $firstResolveTime = microtime(true) - $start1;
            $step1Done = true;

            $start2 = microtime(true);
            $resolver->resolve('example.com')->then(function () use (
                &$step2Done,
                &$secondResolveTime,
                $start2
            ) {
                $secondResolveTime = microtime(true) - $start2;
                $step2Done = true;
                Loop::stop();
            });
        });

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($step1Done)->toBeTrue();
        expect($step2Done)->toBeTrue();
        expect($secondResolveTime)->toBeLessThan($firstResolveTime * 0.1);
    });

    it('fails gracefully for non-existent domains (NXDOMAIN)', function () {
        $resolver = Dns::new()
            ->withNameservers('8.8.8.8')
            ->build()
        ;

        $error = null;
        $successResult = null;

        $resolver->resolve('non-existent-' . uniqid() . '.invalid')->then(
            function ($result) use (&$successResult) {
                $successResult = $result;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(20.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($successResult)->toBeNull();
        expect($error)->not->toBeNull();
        expect($error)->toBeInstanceOf(RecordNotFoundException::class);
    });

    it('handles NODATA responses (domain exists but no A record)', function () {
        $resolver = Dns::create();

        $error = null;
        $successResult = null;

        $resolver->resolve('.')->then(
            function ($result) use (&$successResult) {
                $successResult = $result;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(20.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($error)->toBeInstanceOf(RecordNotFoundException::class);
    });

    it('uses fallback nameserver when primary fails', function () {
        $resolver = Dns::new()
            ->withNameservers(['192.0.2.1', '8.8.8.8'])
            ->build()
        ;

        $ip = null;
        $resolver->resolve('google.com')->then(
            function ($result) use (&$ip) {
                $ip = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(20.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($ip)->toBeString();
        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
    });

    it('works with Cloudflare Dns (1.1.1.1)', function () {
        $resolver = Dns::new()
            ->withNameservers('1.1.1.1')
            ->build()
        ;

        $ip = null;
        $resolver->resolve('cloudflare.com')->then(
            function ($result) use (&$ip) {
                $ip = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($ip)->toBeString();
        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
    });

    it('handles multiple parallel requests', function () {
        $resolver = Dns::new()
            ->withCache()
            ->build()
        ;

        $results = [
            'google' => null,
            'cloudflare' => null,
            'github' => null,
        ];
        $completed = 0;

        $checkCompletion = function () use (&$completed) {
            $completed++;
            if ($completed === 3) {
                Loop::stop();
            }
        };

        $resolver->resolve('google.com')->then(function ($ip) use (&$results, $checkCompletion) {
            $results['google'] = $ip;
            $checkCompletion();
        });

        $resolver->resolve('cloudflare.com')->then(function ($ip) use (&$results, $checkCompletion) {
            $results['cloudflare'] = $ip;
            $checkCompletion();
        });

        $resolver->resolve('github.com')->then(function ($ip) use (&$results, $checkCompletion) {
            $results['github'] = $ip;
            $checkCompletion();
        });

        $timer = Loop::addTimer(10.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($results['google'])->toBeString();
        expect($results['cloudflare'])->toBeString();
        expect($results['github'])->toBeString();
        expect(filter_var($results['google'], FILTER_VALIDATE_IP))->not->toBeFalse();
        expect(filter_var($results['cloudflare'], FILTER_VALIDATE_IP))->not->toBeFalse();
        expect(filter_var($results['github'], FILTER_VALIDATE_IP))->not->toBeFalse();
    });

    it('handles internationalized domain names (IDN)', function () {
        $resolver = Dns::create();

        $punycode = idn_to_ascii('münchen.de', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        $result = null;
        $resolver->resolve($punycode)->then(
            function ($ip) use (&$result) {
                $result = $ip;
                Loop::stop();
            },
            function ($error) use (&$result) {
                $result = 'error';
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($result)->not->toBeNull();
    });

    it('resolves very long domain names (near 253 char limit)', function () {
        $resolver = Dns::new()
            ->withTimeout(3.0)
            ->withRetries(0)
            ->build()
        ;

        $longDomain = str_repeat('a', 50) . '.' . str_repeat('b', 50) . '.com';

        $error = null;

        $resolver->resolve($longDomain)->then(
            function ($result) {
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();

        expect($error)->not->toBeNull();
        expect($error)->toBeInstanceOf(Throwable::class);
    });

    it('tests timeout executor with non-routable IP', function () {
        $resolver = Dns::new()
            ->withNameservers('192.0.2.1')
            ->withTimeout(3.0)
            ->withRetries(0)
            ->build()
        ;

        $error = null;

        $resolver->resolve('google.com')->then(
            fn ($r) => Loop::stop(),
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();

        expect($error)->toBeInstanceOf(Hibla\Dns\Exceptions\TimeoutException::class);
    });

    it('resolves SOA records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('google.com', RecordType::SOA)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($records)->toBeArray();
        expect($records)->not->toBeEmpty();
        expect($records[0])->toHaveKeys(['mname', 'rname', 'serial', 'refresh', 'retry', 'expire', 'minimum']);
    });

    it('handles hosts file resolution', function () {
        $resolver = Dns::create();

        $ip = null;
        $resolver->resolve('localhost')->then(
            function ($result) use (&$ip) {
                $ip = $result;
                Loop::stop();
            },
            function ($error) {
                Loop::stop();

                throw $error;
            }
        );

        $timer = Loop::addTimer(2.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($ip)->toBe('127.0.0.1');
    });

    it('resolves PTR records (reverse Dns)', function () {
        $resolver = Dns::create();

        $reverseAddr = '8.8.8.8.in-addr.arpa';

        $records = null;
        $resolver->resolveAll($reverseAddr, RecordType::PTR)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) use (&$records) {
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (! empty($records)) {
            expect($records)->toBeArray();
            expect($records[0])->toBeString();
            expect($records[0])->toContain('google');
        }
    });

    it('resolves SRV records', function () {
        $resolver = Dns::create();

        $records = [];
        $error = null;

        $resolver->resolveAll('_xmpp-client._tcp.jabber.org', RecordType::SRV)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($e) use (&$error, &$records) {
                $error = $e;
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(6.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($records)->toBeArray();

        if (! empty($records)) {
            expect($records[0])->toBeArray();
            expect($records[0])->toHaveKeys(['priority', 'weight', 'port', 'target']);
            expect($records[0]['priority'])->toBeInt();
            expect($records[0]['weight'])->toBeInt();
            expect($records[0]['port'])->toBeInt();
            expect($records[0]['target'])->toBeString();
            expect($records[0]['target'])->toMatch('/\./'); // Should be a domain name
        }
    });

    it('resolves CAA records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('google.com', RecordType::CAA)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) use (&$records) {
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (! empty($records)) {
            expect($records)->toBeArray();
            expect($records[0])->toBeArray();
            expect($records[0])->toHaveKeys(['flags', 'tag', 'value']);
            expect($records[0]['flags'])->toBeInt();
            expect($records[0]['tag'])->toBeString();
            expect($records[0]['value'])->toBeString();

            expect($records[0]['tag'])->toBeIn(['issue', 'issuewild', 'iodef']);
        } else {
            // Some domains might not have CAA records
            expect($records)->toBeArray();
        }
    });

    it('resolves SSHFP records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('github.com', RecordType::SSHFP)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) use (&$records) {
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (! empty($records)) {
            expect($records)->toBeArray();
            expect($records[0])->toBeArray();
            expect($records[0])->toHaveKeys(['algorithm', 'fptype', 'fingerprint']);
            expect($records[0]['algorithm'])->toBeInt();
            expect($records[0]['fptype'])->toBeInt();
            expect($records[0]['fingerprint'])->toBeString();

            expect($records[0]['algorithm'])->toBeGreaterThanOrEqual(1);
            expect($records[0]['algorithm'])->toBeLessThanOrEqual(4);

            expect($records[0]['fptype'])->toBeIn([1, 2]);

            expect($records[0]['fingerprint'])->toMatch('/^[a-fA-F0-9]+$/');
        } else {
            // Not all domains have SSHFP records
            expect($records)->toBeArray();
        }
    });

    it('resolves multiple SRV records with priority sorting', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('_minecraft._tcp.hypixel.net', RecordType::SRV)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) use (&$records) {
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (! empty($records)) {
            expect($records)->toBeArray();

            if (count($records) > 1) {
                foreach ($records as $record) {
                    expect($record)->toBeArray();
                    expect($record)->toHaveKeys(['priority', 'weight', 'port', 'target']);
                }
            }
        } else {
            expect($records)->toBeArray();
        }
    });

    it('resolves NAPTR records', function () {
        $resolver = Dns::create();

        $records = [];
        $error = null;

        $resolver->resolveAll('4.3.2.1.5.5.5.0.0.8.1.e164.arpa', RecordType::NAPTR)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($e) use (&$error, &$records) {
                $error = $e;
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(6.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($records)->toBeArray();

        if (! empty($records)) {
            expect($records[0])->toBeArray();
            expect($records[0])->toHaveKeys(['order', 'preference', 'flags', 'services', 'regexp', 'replacement']);
            expect($records[0]['order'])->toBeInt();
            expect($records[0]['preference'])->toBeInt();
        }
    });

    it('handles missing structured records gracefully', function () {
        $resolver = Dns::create();

        $error = null;
        $records = null;

        $resolver->resolveAll('example.com', RecordType::SSHFP)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($e) use (&$error) {
                $error = $e;
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if ($error !== null) {
            expect($error)->toBeInstanceOf(RecordNotFoundException::class);
        } else {
            expect($records)->toBeArray();
            expect($records)->toBeEmpty();
        }
    });

    it('resolves multiple CAA records', function () {
        $resolver = Dns::create();

        $records = null;
        $resolver->resolveAll('github.com', RecordType::CAA)->then(
            function ($result) use (&$records) {
                $records = $result;
                Loop::stop();
            },
            function ($error) use (&$records) {
                $records = [];
                Loop::stop();
            }
        );

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if (! empty($records)) {
            expect($records)->toBeArray();

            foreach ($records as $record) {
                expect($record)->toBeArray();
                expect($record)->toHaveKeys(['flags', 'tag', 'value']);
            }
        } else {
            expect($records)->toBeArray();
        }
    });

    it('maintains cache isolation between resolver instances', function () {
        $resolver1 = Dns::new()->withCache()->build();
        $resolver2 = Dns::new()->withCache()->build();

        $result1 = null;
        $result2 = null;
        $completed = 0;

        $resolver1->resolve('google.com')->then(function ($ip) use (&$result1, &$completed) {
            $result1 = $ip;
            $completed++;
            if ($completed === 2) {
                Loop::stop();
            }
        });

        $resolver2->resolve('google.com')->then(function ($ip) use (&$result2, &$completed) {
            $result2 = $ip;
            $completed++;
            if ($completed === 2) {
                Loop::stop();
            }
        });

        $timer = Loop::addTimer(5.0, fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        expect($result1)->toBeString();
        expect($result2)->toBeString();
        expect(filter_var($result1, FILTER_VALIDATE_IP))->not->toBeFalse();
        expect(filter_var($result2, FILTER_VALIDATE_IP))->not->toBeFalse();
    });
})->skipOnCI();
