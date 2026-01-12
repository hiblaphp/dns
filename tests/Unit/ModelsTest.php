<?php

use Hibla\Dns\Enums\Opcode;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;

describe('Models and Enums', function () {

    test('Query model string representation', function () {
        $query = new Query('google.com', RecordType::A, RecordClass::IN);
        
        expect((string) $query)->toBe('Query(google.com: A IN)');
        expect($query->name)->toBe('google.com');
        expect($query->type)->toBe(RecordType::A);
    });

    test('Record model instantiation', function () {
        $record = new Record(
            name: 'example.com',
            type: RecordType::A,
            class: RecordClass::IN,
            ttl: 300,
            data: '127.0.0.1'
        );

        expect($record->name)->toBe('example.com');
        expect($record->ttl)->toBe(300);
        expect($record->data)->toBe('127.0.0.1');
    });

    test('Message model defaults', function () {
        $message = new Message();

        expect($message->id)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(65535);
        expect($message->opcode)->toBe(Opcode::QUERY);
        expect($message->responseCode)->toBe(ResponseCode::OK);
        expect($message->questions)->toBeArray()->toBeEmpty();
    });

    test('Message::createRequest factory', function () {
        $query = new Query('example.com', RecordType::A, RecordClass::IN);
        $message = Message::createRequest($query);

        expect($message->questions)->toHaveCount(1);
        expect($message->questions[0])->toBe($query);
        expect($message->recursionDesired)->toBeTrue(); 
        expect($message->isResponse)->toBeFalse();
    });

    test('Enum values match RFC 1035', function () {
        expect(RecordType::A->value)->toBe(1);
        expect(RecordType::AAAA->value)->toBe(28);
        expect(RecordType::MX->value)->toBe(15);
        
        expect(RecordClass::IN->value)->toBe(1);
        
        expect(ResponseCode::OK->value)->toBe(0);
        expect(ResponseCode::NAME_ERROR->value)->toBe(3);
    });
});