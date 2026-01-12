<?php

use Hibla\Dns\Enums\Opcode;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;

describe('DNS Protocol Enums', function () {

    test('RecordType values match RFC 1035/3596', function () {
        expect(RecordType::A->value)->toBe(1);
        expect(RecordType::NS->value)->toBe(2);
        expect(RecordType::CNAME->value)->toBe(5);
        expect(RecordType::SOA->value)->toBe(6);
        expect(RecordType::PTR->value)->toBe(12);
        expect(RecordType::MX->value)->toBe(15);
        expect(RecordType::TXT->value)->toBe(16);
        expect(RecordType::AAAA->value)->toBe(28);
        expect(RecordType::SRV->value)->toBe(33);
        expect(RecordType::OPT->value)->toBe(41);
        expect(RecordType::SSHFP->value)->toBe(44);
        expect(RecordType::ANY->value)->toBe(255);
        expect(RecordType::CAA->value)->toBe(257);
    });

    test('RecordClass values match RFC 1035', function () {
        expect(RecordClass::IN->value)->toBe(1);
    });

    test('ResponseCode values match RFC 1035', function () {
        expect(ResponseCode::OK->value)->toBe(0);
        expect(ResponseCode::FORMAT_ERROR->value)->toBe(1);
        expect(ResponseCode::SERVER_FAILURE->value)->toBe(2);
        expect(ResponseCode::NAME_ERROR->value)->toBe(3);
        expect(ResponseCode::NOT_IMPLEMENTED->value)->toBe(4);
        expect(ResponseCode::REFUSED->value)->toBe(5);
    });

    test('Opcode values match RFC 1035', function () {
        expect(Opcode::QUERY->value)->toBe(0);
        expect(Opcode::IQUERY->value)->toBe(1);
        expect(Opcode::STATUS->value)->toBe(2);
    });
});