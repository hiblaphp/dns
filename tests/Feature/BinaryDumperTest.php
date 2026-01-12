<?php

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

        // Header: ID(1234) | Flags(0100 = RD) | QDCOUNT(1) | AN(0) | NS(0) | AR(0)
        $expectedHeader = pack('nnnnnn', 0x1234, 0x0100, 1, 0, 0, 0);
        $expectedName = "\x06google\x03com\x00";
        $expectedQuestion = $expectedName . pack('nn', 1, 1);

        expect($binary)->toBe($expectedHeader . $expectedQuestion);
    });

    it('dumps an IPv6 (AAAA) record correctly', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;
        
        // Loopback IPv6 ::1
        $message->answers[] = new Record(
            name: 'localhost',
            type: RecordType::AAAA,
            class: RecordClass::IN,
            ttl: 300,
            data: '::1'
        );

        $binary = $dumper->toBinary($message);

        // AAAA Record Data for ::1 is 15 null bytes followed by 0x01
        $expectedIp = str_repeat("\x00", 15) . "\x01";
        
        // Verify RDLENGTH is 16 (0x0010)
        // Verify Data matches
        expect($binary)->toContain(pack('n', 16) . $expectedIp);
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
                'weight'   => 60,
                'port'     => 5060,
                'target'   => 'sipserver.example.com'
            ]
        );

        $binary = $dumper->toBinary($message);

        // SRV format: Priority(n), Weight(n), Port(n), Target(domain)
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
                'mname'   => 'ns1.example.com',
                'rname'   => 'admin.example.com',
                'serial'  => 2023100101,
                'refresh' => 7200,
                'retry'   => 3600,
                'expire'  => 1209600,
                'minimum' => 3600
            ]
        );

        $binary = $dumper->toBinary($message);

        // SOA format: mname(domain) rname(domain) 5xInt32(N)
        $expectedRdata = 
            "\x03ns1\x07example\x03com\x00" .
            "\x05admin\x07example\x03com\x00" .
            pack('NNNNN', 2023100101, 7200, 3600, 1209600, 3600);

        expect($binary)->toContain($expectedRdata);
    });

    it('dumps the root domain "." correctly', function () use ($dumper) {
        $query = new Query('.', RecordType::NS, RecordClass::IN);
        $message = Message::createRequest($query);

        $binary = $dumper->toBinary($message);

        // Root domain is represented by a single null byte "\0"
        // Followed by Type NS (2) and Class IN (1)
        $expectedSuffix = "\x00" . pack('nn', 2, 1);

        expect(str_ends_with($binary, $expectedSuffix))->toBeTrue();
    });

    it('handles complex header flag combinations', function () use ($dumper) {
        $message = new Message();
        $message->id = 0;
        
        // Turn on all flags to ensure bits don't overlap
        $message->isResponse = true;        // Bit 15
        $message->opcode = Opcode::STATUS;  // Bits 14-11 (Value 2 = 0010)
        $message->isAuthoritative = true;   // Bit 10
        $message->isTruncated = true;       // Bit 9
        $message->recursionDesired = true;  // Bit 8
        $message->recursionAvailable = true;// Bit 7
        // Z (Reserved) is Bits 6-4 (Always 0)
        $message->responseCode = ResponseCode::SERVER_FAILURE; // Bits 3-0 (Value 2 = 0010)

        // Expected Binary Calculation:
        // 1 0010 1 1 1 1 000 0010
        // Hex: 1 2 7 8 7 8 0 2 -> 0x9782 (Wait, let's re-calc carefully)
        
        // Byte 1: QR(1) Op(0010) AA(1) TC(1) RD(1)
        // 1001 0111 -> 0x97
        
        // Byte 2: RA(1) Z(000) RCODE(0010)
        // 1000 0010 -> 0x82
        
        $expectedFlags = "\x97\x82";

        $binary = $dumper->toBinary($message);
        
        // Extract the flags (Bytes 2 and 3)
        $actualFlags = substr($binary, 2, 2);
        
        expect($actualFlags)->toBe($expectedFlags);
    });

    it('dumps unknown record types as raw binary data', function () use ($dumper) {
        $message = new Message();
        $message->id = 1;
        $message->isResponse = true;

        // Use SSHFP (44) or a random type, and pass raw binary string as data
        $rawBinary = "\x01\x02\x03\x04";
        
        $message->answers[] = new Record(
            name: 'example.com',
            type: RecordType::SSHFP, // Dumper doesn't have specific logic for this yet
            class: RecordClass::IN,
            ttl: 60,
            data: $rawBinary // Should be passed through directly
        );

        $binary = $dumper->toBinary($message);

        // Expect length (4) + raw data
        $expected = pack('n', 4) . $rawBinary;
        
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
        
        // 6target3com0
        $expectedRdata = "\x06target\x03com\x00";
        expect($binary)->toContain($expectedRdata);
    });
});