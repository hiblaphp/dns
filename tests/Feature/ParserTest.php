<?php

use Hibla\Dns\Enums\Opcode;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;
use Hibla\Dns\Protocols\BinaryDumper;
use Hibla\Dns\Protocols\Parser;

describe('Parser', function () {
    $parser = new Parser();
    $dumper = new BinaryDumper();

    it('parses a standard A-record response', function () use ($parser, $dumper) {
        $original = new Message();
        $original->id = 0xAAAA;
        $original->isResponse = true;
        $original->recursionDesired = true;
        $original->recursionAvailable = true;
        $original->responseCode = ResponseCode::OK;
        
        $original->questions[] = new Query('google.com', RecordType::A, RecordClass::IN);
        $original->answers[] = new Record('google.com', RecordType::A, RecordClass::IN, 300, '142.250.1.1');

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        expect($parsed->id)->toBe(0xAAAA);
        expect($parsed->isResponse)->toBeTrue();
        expect($parsed->opcode)->toBe(Opcode::QUERY);
        expect($parsed->responseCode)->toBe(ResponseCode::OK);
        
        expect($parsed->questions)->toHaveCount(1);
        expect($parsed->questions[0]->name)->toBe('google.com');
        expect($parsed->questions[0]->type)->toBe(RecordType::A);

        expect($parsed->answers)->toHaveCount(1);
        expect($parsed->answers[0]->name)->toBe('google.com');
        expect($parsed->answers[0]->ttl)->toBe(300);
        expect($parsed->answers[0]->data)->toBe('142.250.1.1');
    });

    it('parses IPv6 (AAAA) records', function () use ($parser, $dumper) {
        $original = new Message();
        $original->isResponse = true;
        $original->answers[] = new Record('host.ipv6', RecordType::AAAA, RecordClass::IN, 60, '2001:db8::1');

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        expect($parsed->answers[0]->data)->toBe('2001:db8::1');
    });

    it('parses TXT records', function () use ($parser, $dumper) {
        $original = new Message();
        $original->isResponse = true;
        $original->answers[] = new Record('txt.com', RecordType::TXT, RecordClass::IN, 60, ['v=spf1 -all']);

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        expect($parsed->answers[0]->data)->toBe(['v=spf1 -all']);
    });

    it('parses MX records', function () use ($parser, $dumper) {
        $original = new Message();
        $original->isResponse = true;
        $original->answers[] = new Record('mail.com', RecordType::MX, RecordClass::IN, 60, [
            'priority' => 10, 
            'target' => 'mx.mail.com'
        ]);

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        $data = $parsed->answers[0]->data;
        expect($data['priority'])->toBe(10);
        expect($data['target'])->toBe('mx.mail.com');
    });

    it('handles DNS compression pointers correctly', function () use ($parser) {
        // Manually construct a packet to force the use of pointers (0xC0xx)
        // because the Dumper might simply write full names (it's naive).
        
        // Header (ID=1, Flags=0, QD=2, AN=0...)
        $data = pack('nnnnnn', 1, 0, 2, 0, 0, 0);

        // Question 1: "test.com"
        // Offset 12: \x04test\x03com\x00 (length 10 bytes)
        // End of Q1 name is at 12 + 10 = 22.
        $data .= "\x04test\x03com\x00" . pack('nn', 1, 1);

        // Question 2: "www.test.com" using a pointer
        // \x03www (4 bytes) + Pointer to "test.com"
        // "test.com" starts at offset 12.
        // Pointer = 1100 0000 (0xC0) | 0000 0000 0000 1100 (12) => 0xC00C
        $data .= "\x03www\xC0\x0C" . pack('nn', 1, 1);

        $parsed = $parser->parseMessage($data);

        expect($parsed->questions)->toHaveCount(2);
        expect($parsed->questions[0]->name)->toBe('test.com');
        
        // If compression logic works, it jumped back to offset 12 and read "test.com"
        expect($parsed->questions[1]->name)->toBe('www.test.com');
    });

    it('throws InvalidArgumentException for truncated data', function () use ($parser) {
        // Valid header (12 bytes) but claims to have 1 question that isn't there
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        
        expect(fn() => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for invalid compression pointer', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        // Name starts with a pointer to offset 999 (out of bounds)
        $data .= "\xC3\xE7"; 
        
        expect(fn() => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('parses header flags correctly', function () use ($parser, $dumper) {
        $original = new Message();
        $original->isResponse = true;
        $original->isAuthoritative = true;
        $original->isTruncated = true;
        $original->recursionDesired = true;
        $original->recursionAvailable = true;
        $original->responseCode = ResponseCode::SERVER_FAILURE;

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        expect($parsed->isResponse)->toBeTrue();
        expect($parsed->isAuthoritative)->toBeTrue();
        expect($parsed->isTruncated)->toBeTrue();
        expect($parsed->recursionDesired)->toBeTrue();
        expect($parsed->recursionAvailable)->toBeTrue();
        expect($parsed->responseCode)->toBe(ResponseCode::SERVER_FAILURE);
    });

      it('parses the root domain "." correctly', function () use ($parser) {
        // Header + Question
        // Question Name: \x00 (The root)
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        $data .= "\x00" . pack('nn', 1, 1);

        $parsed = $parser->parseMessage($data);
        
        // When imploding an empty array of labels, we get empty string ""
        expect($parsed->questions[0]->name)->toBe(''); 
    });

    it('handles unknown record types gracefully', function () use ($parser) {
        // Header: 1 Answer
        $data = pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0);
        
        // Answer: name "a" (1.61.00), Type 999 (03E7), Class 1, TTL 0, Len 4, Data DEAD
        $record = "\x01a\x00" . pack('nnNn', 999, 1, 0, 4) . "\xDE\xAD\xBE\xEF";
        $data .= $record;

        $parsed = $parser->parseMessage($data);

        $answer = $parsed->answers[0];
        // Enums usually fall back to ANY or null if not found, 
        // depending on your Parser logic (tryFrom ?? ANY)
        expect($answer->type)->toBe(RecordType::ANY); 
        expect($answer->data)->toBe("\xDE\xAD\xBE\xEF");
    });

    it('throws InvalidArgumentException for infinite compression loops', function () use ($parser) {
        // This is a security test. 
        // The name pointer at offset 12 points to... offset 12.
        
        // Header
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        // Pointer 0xC00C (Points to byte 12)
        $data .= "\xC0\x0C" . pack('nn', 1, 1);

        expect(fn() => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class, 'Too many compression pointers');
    });

    it('parses complex TXT records with multiple strings', function () use ($parser, $dumper) {
        // While BinaryDumper only writes simple TXT, the parser handles multi-part TXT
        // Manually construct: Len 5 "Hello", Len 5 "World"
        $txtData = "\x05Hello\x05World";
        
        // Header: 1 Answer
        $data = pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0);
        // Name "t", Type TXT(16), Class 1, TTL 0, Len 12
        $data .= "\x01t\x00" . pack('nnNn', 16, 1, 0, strlen($txtData)) . $txtData;

        $parsed = $parser->parseMessage($data);
        
        // Expected: array of strings
        expect($parsed->answers[0]->data)->toBe(['Hello', 'World']);
    });

    it('throws exception if record data length is invalid', function () use ($parser) {
        // Header: 1 Answer
        $data = pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0);
        // Record says it has 10 bytes of data, but we provide 0
        $data .= "\x01a\x00" . pack('nnNn', 1, 1, 0, 10); 

        expect(fn() => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws exception if label length exceeds packet size', function () use ($parser) {
        // Header: 1 Question
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        // Label says it is 50 bytes long, but packet ends immediately
        $data .= "\x32"; 

        expect(fn() => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class);
    });
});