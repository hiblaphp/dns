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
        $expectedQuestion = $expectedName . pack('nn', 1, 1);

        expect($binary)->toBe($expectedHeader . $expectedQuestion);
    });

    it('dumps an A record correctly', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::A,
            class: RecordClass::IN,
            ttl: 300,
            data: '192.168.1.1'
        );

        $binary = $dumper->toBinary($message);

        $expectedIp = "\xC0\xA8\x01\x01"; // 192.168.1.1
        expect($binary)->toContain(pack('n', 4) . $expectedIp);
    });

    it('dumps an AAAA (IPv6) record correctly', function () use ($dumper) {
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

        $expectedIp = str_repeat("\x00", 15) . "\x01";
        expect($binary)->toContain(pack('n', 16) . $expectedIp);
    });

    it('dumps a CNAME record correctly', function () use ($dumper) {
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

    it('dumps an NS record correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::NS,
            class: RecordClass::IN,
            ttl: 3600,
            data: 'ns1.example.com'
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata = "\x03ns1\x07example\x03com\x00";
        expect($binary)->toContain($expectedRdata);
    });

    it('dumps a PTR record correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: '1.1.168.192.in-addr.arpa',
            type: RecordType::PTR,
            class: RecordClass::IN,
            ttl: 3600,
            data: 'host.example.com'
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata = "\x04host\x07example\x03com\x00";
        expect($binary)->toContain($expectedRdata);
    });

    it('dumps a TXT record correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::TXT,
            class: RecordClass::IN,
            ttl: 3600,
            data: ['v=spf1 include:_spf.example.com ~all']
        );

        $binary = $dumper->toBinary($message);

        $text = 'v=spf1 include:_spf.example.com ~all';
        $expectedRdata = chr(strlen($text)) . $text;
        expect($binary)->toContain($expectedRdata);
    });

    it('dumps a TXT record with multiple strings correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::TXT,
            class: RecordClass::IN,
            ttl: 3600,
            data: ['hello', 'world']
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata = "\x05hello\x05world";
        expect($binary)->toContain($expectedRdata);
    });

    it('dumps an MX record correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::MX,
            class: RecordClass::IN,
            ttl: 3600,
            data: [
                'priority' => 10,
                'target' => 'mail.example.com',
            ]
        );

        $binary = $dumper->toBinary($message);

        $expectedRdata = pack('n', 10) . "\x04mail\x07example\x03com\x00";
        expect($binary)->toContain($expectedRdata);
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

        $expectedRdata = pack('nnn', 10, 60, 5060) . "\x09sipserver\x07example\x03com\x00";
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
            "\x03ns1\x07example\x03com\x00" .
            "\x05admin\x07example\x03com\x00" .
            pack('NNNNN', 2023100101, 7200, 3600, 1209600, 3600);

        expect($binary)->toContain($expectedRdata);
    });

    it('dumps a CAA record correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::CAA,
            class: RecordClass::IN,
            ttl: 3600,
            data: [
                'flags' => 0,
                'tag' => 'issue',
                'value' => 'letsencrypt.org',
            ]
        );

        $binary = $dumper->toBinary($message);

        // flags (1 byte) + tag length (1 byte) + tag + value
        $expectedRdata = "\x00\x05issue" . 'letsencrypt.org';
        expect($binary)->toContain($expectedRdata);
    });

    it('dumps an SSHFP record correctly', function () use ($dumper) {
        $message = new Message();
        $message->isResponse = true;

        $message->answers[] = new Record(
            name: 'host.example.com',
            type: RecordType::SSHFP,
            class: RecordClass::IN,
            ttl: 3600,
            data: [
                'algorithm' => 1,
                'fptype' => 1,
                'fingerprint' => '0123456789abcdef',
            ]
        );

        $binary = $dumper->toBinary($message);

        // algorithm (1 byte) + fptype (1 byte) + fingerprint (binary)
        $expectedRdata = "\x01\x01" . hex2bin('0123456789abcdef');
        expect($binary)->toContain($expectedRdata);
    });

    it('dumps the root domain "." correctly', function () use ($dumper) {
        $query = new Query('.', RecordType::NS, RecordClass::IN);
        $message = Message::createRequest($query);

        $binary = $dumper->toBinary($message);

        $expectedSuffix = "\x00" . pack('nn', 2, 1);
        expect(str_ends_with($binary, $expectedSuffix))->toBeTrue();
    });

    it('dumps empty domain correctly', function () use ($dumper) {
        $query = new Query('', RecordType::A, RecordClass::IN);
        $message = Message::createRequest($query);

        $binary = $dumper->toBinary($message);

        // Empty domain should be encoded as single null byte
        expect($binary)->toContain("\x00" . pack('nn', 1, 1));
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

    it('handles multiple records in different sections', function () use ($dumper) {
        $message = new Message();
        $message->id = 0x5678;
        $message->isResponse = true;

        $message->questions[] = new Query('example.com', RecordType::A, RecordClass::IN);

        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::A,
            class: RecordClass::IN,
            ttl: 300,
            data: '93.184.216.34'
        );

        $message->authority[] = new Record(
            name: 'example.com',
            type: RecordType::NS,
            class: RecordClass::IN,
            ttl: 3600,
            data: 'ns1.example.com'
        );

        $message->additional[] = new Record(
            name: 'ns1.example.com',
            type: RecordType::A,
            class: RecordClass::IN,
            ttl: 3600,
            data: '192.0.2.1'
        );

        $binary = $dumper->toBinary($message);

        // Verify header counts: 1 question, 1 answer, 1 authority, 1 additional
        $header = unpack('n6', substr($binary, 0, 12));
        expect($header[3])->toBe(1); // QDCOUNT
        expect($header[4])->toBe(1); // ANCOUNT
        expect($header[5])->toBe(1); // NSCOUNT
        expect($header[6])->toBe(1); // ARCOUNT
    });

    it('correctly encodes domain names with trailing dots', function () use ($dumper) {
        $query = new Query('example.com.', RecordType::A, RecordClass::IN);
        $message = Message::createRequest($query);

        $binary = $dumper->toBinary($message);

        $expectedName = "\x07example\x03com\x00";
        expect($binary)->toContain($expectedName);
    });
});
