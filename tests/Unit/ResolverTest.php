<?php

declare(strict_types=1);

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Record;
use Hibla\Dns\Resolvers\Resolver;
use Tests\Helpers\MockExecutor;

describe('Resolver', function () {

    it('resolves a simple A record', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('google.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $promise = $resolver->resolve('google.com');

        expect($promise->wait())->toBe('1.2.3.4');
    });

    it('resolves all AAAA records', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('google.com', RecordType::AAAA, RecordClass::IN, 300, '::1');
        $message->answers[] = new Record('google.com', RecordType::AAAA, RecordClass::IN, 300, '::2');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $promise = $resolver->resolveAll('google.com', RecordType::AAAA);

        expect($promise->wait())->toBe(['::1', '::2']);
    });

    it('throws RecordNotFoundException on NXDOMAIN', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::NAME_ERROR;

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('nonexistent.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Non-Existent Domain')
        ;
    });

    it('throws RecordNotFoundException on empty answer (NODATA)', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers = [];

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('exists.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });

    it('resolves CNAME chains correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('mail.example.com', RecordType::CNAME, RecordClass::IN, 300, 'ghs.google.com');
        $message->answers[] = new Record('ghs.google.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $promise = $resolver->resolveAll('mail.example.com', RecordType::A);

        expect($promise->wait())->toBe(['1.2.3.4']);
    });

    it('picks a random IP from multiple A records for simple resolve()', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '1.1.1.1');
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '2.2.2.2');
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '3.3.3.3');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        for ($i = 0; $i < 5; $i++) {
            $ip = $resolver->resolve('example.com')->wait();
            expect(['1.1.1.1', '2.2.2.2', '3.3.3.3'])->toContain($ip);
        }
    });

    it('filters out records of wrong type', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');
        $message->answers[] = new Record('example.com', RecordType::TXT, RecordClass::IN, 300, ['spf...']);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::TXT)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(['spf...']);
    });
});

describe('Resolver - Record Type Support', function () {

    it('resolves MX records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::MX, RecordClass::IN, 300, ['priority' => 10, 'target' => 'mail1.example.com']);
        $message->answers[] = new Record('example.com', RecordType::MX, RecordClass::IN, 300, ['priority' => 20, 'target' => 'mail2.example.com']);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::MX)->wait();

        expect($results)->toHaveCount(2);
        expect($results[0])->toBe(['priority' => 10, 'target' => 'mail1.example.com']);
        expect($results[1])->toBe(['priority' => 20, 'target' => 'mail2.example.com']);
    });

    it('resolves TXT records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::TXT, RecordClass::IN, 300, ['v=spf1 include:_spf.example.com ~all']);
        $message->answers[] = new Record('example.com', RecordType::TXT, RecordClass::IN, 300, ['hello', 'world']);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::TXT)->wait();

        expect($results)->toHaveCount(2);
        expect($results[0])->toBe(['v=spf1 include:_spf.example.com ~all']);
        expect($results[1])->toBe(['hello', 'world']);
    });

    it('resolves SRV records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record(
            '_xmpp._tcp.example.com',
            RecordType::SRV,
            RecordClass::IN,
            3600,
            ['priority' => 10, 'weight' => 5, 'port' => 5222, 'target' => 'xmpp.example.com']
        );

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('_xmpp._tcp.example.com', RecordType::SRV)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(['priority' => 10, 'weight' => 5, 'port' => 5222, 'target' => 'xmpp.example.com']);
    });

    it('resolves CAA records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record(
            'example.com',
            RecordType::CAA,
            RecordClass::IN,
            3600,
            ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']
        );
        $message->answers[] = new Record(
            'example.com',
            RecordType::CAA,
            RecordClass::IN,
            3600,
            ['flags' => 0, 'tag' => 'issuewild', 'value' => 'letsencrypt.org']
        );

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::CAA)->wait();

        expect($results)->toHaveCount(2);
        expect($results[0])->toBe(['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']);
        expect($results[1])->toBe(['flags' => 0, 'tag' => 'issuewild', 'value' => 'letsencrypt.org']);
    });

    it('resolves SSHFP records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record(
            'host.example.com',
            RecordType::SSHFP,
            RecordClass::IN,
            3600,
            ['algorithm' => 1, 'fptype' => 1, 'fingerprint' => '0123456789abcdef']
        );

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('host.example.com', RecordType::SSHFP)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(['algorithm' => 1, 'fptype' => 1, 'fingerprint' => '0123456789abcdef']);
    });

    it('resolves SOA records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record(
            'example.com',
            RecordType::SOA,
            RecordClass::IN,
            3600,
            [
                'mname' => 'ns1.example.com',
                'rname' => 'admin.example.com',
                'serial' => 2024011501,
                'refresh' => 7200,
                'retry' => 3600,
                'expire' => 1209600,
                'minimum' => 86400,
            ]
        );

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::SOA)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe([
            'mname' => 'ns1.example.com',
            'rname' => 'admin.example.com',
            'serial' => 2024011501,
            'refresh' => 7200,
            'retry' => 3600,
            'expire' => 1209600,
            'minimum' => 86400,
        ]);
    });

    it('resolves NS records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::NS, RecordClass::IN, 3600, 'ns1.example.com');
        $message->answers[] = new Record('example.com', RecordType::NS, RecordClass::IN, 3600, 'ns2.example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::NS)->wait();

        expect($results)->toHaveCount(2);
        expect($results[0])->toBe('ns1.example.com');
        expect($results[1])->toBe('ns2.example.com');
    });

    it('resolves PTR records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('1.1.168.192.in-addr.arpa', RecordType::PTR, RecordClass::IN, 3600, 'host.example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('1.1.168.192.in-addr.arpa', RecordType::PTR)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe('host.example.com');
    });

    it('resolves CNAME records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('www.example.com', RecordType::CNAME, RecordClass::IN, 300, 'example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('www.example.com', RecordType::CNAME)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe('example.com');
    });

    it('handles MX records with missing fields gracefully', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::MX, RecordClass::IN, 300, ['priority' => 10, 'target' => 'mail.example.com']);
        $message->answers[] = new Record('example.com', RecordType::MX, RecordClass::IN, 300, ['invalid' => 'data']);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::MX)->wait();

        expect($results)->toHaveCount(2);
        expect($results[0])->toBe(['priority' => 10, 'target' => 'mail.example.com']);
        expect($results[1])->toBe(['invalid' => 'data']);
    });

    it('handles SRV records with missing fields gracefully', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('_sip._tcp.example.com', RecordType::SRV, RecordClass::IN, 3600, ['priority' => 10]);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('_sip._tcp.example.com', RecordType::SRV)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(['priority' => 10]);
    });

    it('handles CAA records with missing fields gracefully', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::CAA, RecordClass::IN, 3600, ['flags' => 0]);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::CAA)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe(['flags' => 0]);
    });

    it('handles empty TXT record arrays', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::TXT, RecordClass::IN, 300, []);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::TXT)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe([]);
    });

    it('handles string data for TXT records', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::TXT, RecordClass::IN, 300, 'single string');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::TXT)->wait();

        expect($results)->toHaveCount(1);
        expect($results[0])->toBe('single string');
    });
});

describe('Resolver - Edge Cases', function () {

    it('handles CNAME chains with no final answer', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('alias.example.com', RecordType::CNAME, RecordClass::IN, 300, 'target.example.com');
        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolveAll('alias.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });

    it('prevents infinite recursion on circular CNAME references', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('a.example.com', RecordType::CNAME, RecordClass::IN, 300, 'b.example.com');
        $message->answers[] = new Record('b.example.com', RecordType::CNAME, RecordClass::IN, 300, 'a.example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolveAll('a.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });

    it('prevents stack overflow on self-referencing CNAME', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('loop.example.com', RecordType::CNAME, RecordClass::IN, 300, 'loop.example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolveAll('loop.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });

    it('enforces maximum CNAME chain depth limit', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;

        for ($i = 0; $i < 12; $i++) {
            $from = "chain{$i}.example.com";
            $to = 'chain'.($i + 1).'.example.com';
            $message->answers[] = new Record($from, RecordType::CNAME, RecordClass::IN, 300, $to);
        }

        $message->answers[] = new Record('chain12.example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolveAll('chain0.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });

    it('allows CNAME chains within depth limit', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;

        for ($i = 0; $i < 5; $i++) {
            $from = "chain{$i}.example.com";
            $to = 'chain'.($i + 1).'.example.com';
            $message->answers[] = new Record($from, RecordType::CNAME, RecordClass::IN, 300, $to);
        }
        $message->answers[] = new Record('chain5.example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('chain0.example.com', RecordType::A)->wait();
        expect($results)->toBe(['1.2.3.4']);
    });

    it('handles multiple CNAME records pointing to different targets', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;

        // Note: Multiple CNAMEs for same name is technically invalid per RFC,
        $message->answers[] = new Record('alias.example.com', RecordType::CNAME, RecordClass::IN, 300, 'target1.example.com');
        $message->answers[] = new Record('alias.example.com', RecordType::CNAME, RecordClass::IN, 300, 'target2.example.com');
        $message->answers[] = new Record('target1.example.com', RecordType::A, RecordClass::IN, 300, '1.1.1.1');
        $message->answers[] = new Record('target2.example.com', RecordType::A, RecordClass::IN, 300, '2.2.2.2');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('alias.example.com', RecordType::A)->wait();

        expect($results)->toHaveCount(2);
        expect($results)->toContain('1.1.1.1');
        expect($results)->toContain('2.2.2.2');
    });

    it('handles deep CNAME chains (3+ levels)', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('www.example.com', RecordType::CNAME, RecordClass::IN, 300, 'alias.example.com');
        $message->answers[] = new Record('alias.example.com', RecordType::CNAME, RecordClass::IN, 300, 'cdn.provider.com');
        $message->answers[] = new Record('cdn.provider.com', RecordType::CNAME, RecordClass::IN, 300, 'edge.provider.com');
        $message->answers[] = new Record('edge.provider.com', RecordType::A, RecordClass::IN, 300, '5.6.7.8');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('www.example.com', RecordType::A)->wait();

        expect($results)->toBe(['5.6.7.8']);
    });

    it('handles case-insensitive domain matching', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('EXAMPLE.COM', RecordType::A, RecordClass::IN, 300, '1.2.3.4');
        $message->answers[] = new Record('Example.Com', RecordType::A, RecordClass::IN, 300, '5.6.7.8');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::A)->wait();

        expect($results)->toHaveCount(2);
        expect($results)->toContain('1.2.3.4');
        expect($results)->toContain('5.6.7.8');
    });

    it('handles case-insensitive CNAME matching', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('WWW.EXAMPLE.COM', RecordType::CNAME, RecordClass::IN, 300, 'TARGET.EXAMPLE.COM');
        $message->answers[] = new Record('target.example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('www.example.com', RecordType::A)->wait();
        expect($results)->toBe(['1.2.3.4']);
    });

    it('throws RecordNotFoundException for FORMAT_ERROR', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::FORMAT_ERROR;

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('example.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Format Error')
        ;
    });

    it('throws RecordNotFoundException for SERVER_FAILURE', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::SERVER_FAILURE;

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('example.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Server Failure')
        ;
    });

    it('throws RecordNotFoundException for REFUSED', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::REFUSED;

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('example.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Refused')
        ;
    });

    it('handles single A record without randomization issue', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '1.1.1.1');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        for ($i = 0; $i < 3; $i++) {
            expect($resolver->resolve('example.com')->wait())->toBe('1.1.1.1');
        }
    });

    it('handles CNAME with mixed record types in answers', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::CNAME, RecordClass::IN, 300, 'target.example.com');
        $message->answers[] = new Record('target.example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');
        $message->answers[] = new Record('target.example.com', RecordType::AAAA, RecordClass::IN, 300, '::1');
        $message->answers[] = new Record('target.example.com', RecordType::TXT, RecordClass::IN, 300, ['v=spf1']);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $aResults = $resolver->resolveAll('example.com', RecordType::A)->wait();
        expect($aResults)->toBe(['1.2.3.4']);

        $aaaaResults = $resolver->resolveAll('example.com', RecordType::AAAA)->wait();
        expect($aaaaResults)->toBe(['::1']);
    });

    it('handles executor throwing exceptions', function () {
        $error = new RuntimeException('Network timeout');
        $mock = new MockExecutor(errorToThrow: $error);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('example.com')->wait())
            ->toThrow(RuntimeException::class, 'Network timeout')
        ;
    });

    it('handles empty domain name edge case', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers = [];

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolve('')->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });

    it('preserves record order in resolveAll', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '1.1.1.1');
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '2.2.2.2');
        $message->answers[] = new Record('example.com', RecordType::A, RecordClass::IN, 300, '3.3.3.3');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::A)->wait();

        expect($results)->toBe(['1.1.1.1', '2.2.2.2', '3.3.3.3']);
    });

    it('does not match wildcard DNS records with strict comparison', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('*.example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn () => $resolver->resolveAll('subdomain.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer')
        ;
    });
});
