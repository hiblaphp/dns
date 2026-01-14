<?php

declare(strict_types=1);

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
            'target' => 'mx.mail.com',
        ]);

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        $data = $parsed->answers[0]->data;
        expect($data['priority'])->toBe(10);
        expect($data['target'])->toBe('mx.mail.com');
    });

    it('parses SOA records', function () use ($parser, $dumper) {
        $original = new Message();
        $original->isResponse = true;
        $original->answers[] = new Record('example.com', RecordType::SOA, RecordClass::IN, 3600, [
            'mname' => 'ns1.example.com',
            'rname' => 'admin.example.com',
            'serial' => 2024011501,
            'refresh' => 7200,
            'retry' => 3600,
            'expire' => 1209600,
            'minimum' => 86400,
        ]);

        $binary = $dumper->toBinary($original);
        $parsed = $parser->parseMessage($binary);

        $data = $parsed->answers[0]->data;
        expect($data['mname'])->toBe('ns1.example.com');
        expect($data['rname'])->toBe('admin.example.com');
        expect($data['serial'])->toBe(2024011501);
        expect($data['refresh'])->toBe(7200);
        expect($data['retry'])->toBe(3600);
        expect($data['expire'])->toBe(1209600);
        expect($data['minimum'])->toBe(86400);
    });

    it('handles DNS compression pointers correctly', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0, 2, 0, 0, 0);

        $data .= "\x04test\x03com\x00".pack('nn', 1, 1);
        $data .= "\x03www\xC0\x0C".pack('nn', 1, 1);

        $parsed = $parser->parseMessage($data);

        expect($parsed->questions)->toHaveCount(2);
        expect($parsed->questions[0]->name)->toBe('test.com');
        expect($parsed->questions[1]->name)->toBe('www.test.com');
    });

    it('throws InvalidArgumentException for truncated data', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);

        expect(fn () => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for invalid compression pointer', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        $data .= "\xC3\xE7";

        expect(fn () => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class)
        ;
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
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        $data .= "\x00".pack('nn', 1, 1);

        $parsed = $parser->parseMessage($data);

        expect($parsed->questions[0]->name)->toBe('');
    });

    it('handles unknown record types gracefully', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0);

        $record = "\x01a\x00".pack('nnNn', 999, 1, 0, 4)."\xDE\xAD\xBE\xEF";
        $data .= $record;

        $parsed = $parser->parseMessage($data);

        $answer = $parsed->answers[0];
        expect($answer->type)->toBe(RecordType::ANY);
        expect($answer->data)->toBe("\xDE\xAD\xBE\xEF");
    });

    it('throws InvalidArgumentException for infinite compression loops', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        $data .= "\xC0\x0C".pack('nn', 1, 1);

        expect(fn () => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class, 'Too many compression pointers')
        ;
    });

    it('parses complex TXT records with multiple strings', function () use ($parser) {
        $txtData = "\x05Hello\x05World";

        $data = pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0);
        $data .= "\x01t\x00".pack('nnNn', 16, 1, 0, strlen($txtData)).$txtData;

        $parsed = $parser->parseMessage($data);

        expect($parsed->answers[0]->data)->toBe(['Hello', 'World']);
    });

    it('throws exception if record data length is invalid', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0);
        $data .= "\x01a\x00".pack('nnNn', 1, 1, 0, 10);

        expect(fn () => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws exception if label length exceeds packet size', function () use ($parser) {
        $data = pack('nnnnnn', 1, 0, 1, 0, 0, 0);
        $data .= "\x32";

        expect(fn () => $parser->parseMessage($data))
            ->toThrow(InvalidArgumentException::class)
        ;
    });
});
