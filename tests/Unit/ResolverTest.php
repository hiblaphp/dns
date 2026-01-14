<?php

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

        expect(fn() => $resolver->resolve('nonexistent.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Non-Existent Domain');
    });

    it('throws RecordNotFoundException on empty answer (NODATA)', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers = []; 

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolve('exists.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
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

describe('Resolver - Edge Cases', function () {
    
    it('handles CNAME chains with no final answer', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('alias.example.com', RecordType::CNAME, RecordClass::IN, 300, 'target.example.com');
        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolveAll('alias.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
    });

    it('prevents infinite recursion on circular CNAME references', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('a.example.com', RecordType::CNAME, RecordClass::IN, 300, 'b.example.com');
        $message->answers[] = new Record('b.example.com', RecordType::CNAME, RecordClass::IN, 300, 'a.example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolveAll('a.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
    });

    it('prevents stack overflow on self-referencing CNAME', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('loop.example.com', RecordType::CNAME, RecordClass::IN, 300, 'loop.example.com');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolveAll('loop.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
    });

    it('enforces maximum CNAME chain depth limit', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
    
        for ($i = 0; $i < 12; $i++) {
            $from = "chain{$i}.example.com";
            $to = "chain" . ($i + 1) . ".example.com";
            $message->answers[] = new Record($from, RecordType::CNAME, RecordClass::IN, 300, $to);
        }
      
        $message->answers[] = new Record('chain12.example.com', RecordType::A, RecordClass::IN, 300, '1.2.3.4');

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolveAll('chain0.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
    });

    it('allows CNAME chains within depth limit', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        
        for ($i = 0; $i < 5; $i++) {
            $from = "chain{$i}.example.com";
            $to = "chain" . ($i + 1) . ".example.com";
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

        expect(fn() => $resolver->resolve('example.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Format Error');
    });

    it('throws RecordNotFoundException for SERVER_FAILURE', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::SERVER_FAILURE;

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolve('example.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Server Failure');
    });

    it('throws RecordNotFoundException for REFUSED', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::REFUSED;

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolve('example.com')->wait())
            ->toThrow(RecordNotFoundException::class, 'Refused');
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

    it('resolves MX records correctly', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers[] = new Record('example.com', RecordType::MX, RecordClass::IN, 300, ['priority' => 10, 'target' => 'mail1.example.com']);
        $message->answers[] = new Record('example.com', RecordType::MX, RecordClass::IN, 300, ['priority' => 20, 'target' => 'mail2.example.com']);

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        $results = $resolver->resolveAll('example.com', RecordType::MX)->wait();
        
        expect($results)->toHaveCount(2);
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
        $error = new \RuntimeException('Network timeout');
        $mock = new MockExecutor(errorToThrow: $error);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolve('example.com')->wait())
            ->toThrow(\RuntimeException::class, 'Network timeout');
    });

    it('handles empty domain name edge case', function () {
        $message = new Message();
        $message->responseCode = ResponseCode::OK;
        $message->answers = [];

        $mock = new MockExecutor(resultToReturn: $message);
        $resolver = new Resolver($mock);

        expect(fn() => $resolver->resolve('')->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
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
        
        expect(fn() => $resolver->resolveAll('subdomain.example.com', RecordType::A)->wait())
            ->toThrow(RecordNotFoundException::class, 'did not return a valid answer');
    });
});