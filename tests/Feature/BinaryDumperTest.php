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

describe('BinaryDumper', function () {
    $dumper = new BinaryDumper();

    it('dumps a standard A-record query correctly', function () use ($dumper) {
        $query = new Query('google.com', RecordType::A, RecordClass::IN);
        $message = Message::createRequest($query);
        $message->id = 0x1234;
        $message->recursionDesired = true;

        $binary = $dumper->toBinary($message);

        $expectedHeader = pack('nnnnnn', 0x1234, 0x0100, 1, 0, 0, 0);
        $expectedName = "\x06google\x03com\x00";
        $expectedQuestion = $expectedName.pack('nn', 1, 1);

        expect($binary)->toBe($expectedHeader.$expectedQuestion);
    });

    it('dumps an IPv6 (AAAA) record correctly', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'localhost',
            type: RecordType::AAAA,
            class: RecordClass::IN,
            ttl: 300,
            data: '::1'
        );

        $binary = $dumper->toBinary($message);

        $expectedIp = str_repeat("\x00", 15)."\x01";

        expect($binary)->toContain(pack('n', 16).$expectedIp);
    });

    it('dumps an SRV record correctly', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: '_sip._tcp.example.com',
            type: RecordType::SRV,
            class: RecordClass::IN,
            ttl: 3600,
            data: [
                'priority' => 10,
                'weight' => 60,
                'port' => 5060,
                'target' => 'sipserver.example.com',
            ]
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata = pack('nnn', 10, 60, 5060)."\x09sipserver\x07example\x03com\x00";

        expect($binary)->toContain($expectedRdata);
    });

    it('dumps an SOA record correctly', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::SOA,
            class: RecordClass::IN,
            ttl: 86400,
            data: [
                'mname' => 'ns1.example.com',
                'rname' => 'admin.example.com',
                'serial' => 2023100101,
                'refresh' => 7200,
                'retry' => 3600,
                'expire' => 1209600,
                'minimum' => 3600,
            ]
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata =
            "\x03ns1\x07example\x03com\x00".
            "\x05admin\x07example\x03com\x00".
            pack('NNNNN', 2023100101, 7200, 3600, 1209600, 3600);

        expect($binary)->toContain($expectedRdata);
    });

    it('dumps the root domain "." correctly', function () use ($dumper) {
        $query = new Query('.', RecordType::NS, RecordClass::IN);
        $message = Message::createRequest($query);

        $binary = $dumper->toBinary($message);

        $expectedSuffix = "\x00".pack('nn', 2, 1);

        expect(str_ends_with($binary, $expectedSuffix))->toBeTrue();
    });

    it('handles complex header flag combinations', function () use ($dumper) {
        $message = new Message();
        $message->id = 0;

        $message->isResponse = true;
        $message->opcode = Opcode::STATUS;
        $message->isAuthoritative = true;
        $message->isTruncated = true;
        $message->recursionDesired = true;
        $message->recursionAvailable = true;
        $message->responseCode = ResponseCode::SERVER_FAILURE;

        $expectedFlags = "\x97\x82";

        $binary = $dumper->toBinary($message);

        $actualFlags = substr($binary, 2, 2);

        expect($actualFlags)->toBe($expectedFlags);
    });

    it('dumps unknown record types as raw binary data', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;

        $rawBinary = "\x01\x02\x03\x04";

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::SSHFP,
            class: RecordClass::IN,
            ttl: 60,
            data: $rawBinary
        );

        $binary = $dumper->toBinary($message);

        $expected = pack('n', 4).$rawBinary;

        expect($binary)->toContain($expected);
    });

    it('correctly handles CNAME pointers', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'alias.com',
            type: RecordType::CNAME,
            class: RecordClass::IN,
            ttl: 100,
            data: 'target.com'
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata = "\x06target\x03com\x00";
        expect($binary)->toContain($expectedRdata);
    });
});
