<?php

declare(strict_types=1);

namespace Hibla\Dns\Protocols;

use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;

final class BinaryDumper
{
    public function toBinary(Message $message): string
    {
        return $this->headerToBinary($message)
            . $this->questionsToBinary($message->questions)
            . $this->recordsToBinary($message->answers)
            . $this->recordsToBinary($message->authority)
            . $this->recordsToBinary($message->additional);
    }

    private function headerToBinary(Message $message): string
    {
        $data = pack('n', $message->id);

        $flags = 0x00;
        // 1 bit: QR (Query/Response)
        $flags = ($flags << 1) | ($message->isResponse ? 1 : 0);
        // 4 bits: Opcode
        $flags = ($flags << 4) | $message->opcode->value;
        // 1 bit: AA (Authoritative Answer)
        $flags = ($flags << 1) | ($message->isAuthoritative ? 1 : 0);
        // 1 bit: TC (Truncated)
        $flags = ($flags << 1) | ($message->isTruncated ? 1 : 0);
        // 1 bit: RD (Recursion Desired)
        $flags = ($flags << 1) | ($message->recursionDesired ? 1 : 0);
        // 1 bit: RA (Recursion Available)
        $flags = ($flags << 1) | ($message->recursionAvailable ? 1 : 0);
        // 3 bits: Z (Reserved, must be 0)
        $flags = ($flags << 3) | 0;
        // 4 bits: RCODE
        $flags = ($flags << 4) | $message->responseCode->value;

        $data .= pack('n', $flags);
        $data .= pack('n', count($message->questions));
        $data .= pack('n', count($message->answers));
        $data .= pack('n', count($message->authority));
        $data .= pack('n', count($message->additional));

        return $data;
    }

    /**
     * @param list<Query> $questions
     */
    private function questionsToBinary(array $questions): string
    {
        $data = '';
        foreach ($questions as $question) {
            $data .= $this->domainNameToBinary($question->name);
            $data .= pack('nn', $question->type->value, $question->class->value);
        }
        return $data;
    }

    /**
     * @param list<Record> $records
     */
    private function recordsToBinary(array $records): string
    {
        $data = '';

        foreach ($records as $record) {
            $binaryData = match ($record->type) {
                RecordType::A, RecordType::AAAA => (string) @inet_pton((string) $record->data),
                RecordType::CNAME, RecordType::NS, RecordType::PTR => $this->domainNameToBinary((string) $record->data),
                RecordType::TXT => $this->textsToBinary((array) $record->data),
                RecordType::MX => $this->mxToBinary((array) $record->data),
                RecordType::SRV => $this->srvToBinary((array) $record->data),
                RecordType::SOA => $this->soaToBinary((array) $record->data),
                // Fallback for unknown types or simple binary data
                default => (string) $record->data,
            };

            $data .= $this->domainNameToBinary($record->name);
            // Type (n), Class (n), TTL (N), RDLENGTH (n)
            $data .= pack('nnNn', $record->type->value, $record->class->value, $record->ttl, strlen($binaryData));
            $data .= $binaryData;
        }

        return $data;
    }

    private function domainNameToBinary(string $host): string
    {
        if ($host === '' || $host === '.') {
            return "\0";
        }

        $host = rtrim($host, '.');
        $labels = explode('.', $host);
        $data = '';

        foreach ($labels as $label) {
            $data .= \chr(\strlen($label)) . $label;
        }

        return $data . "\0";
    }

    /**
     * @param array<string> $texts
     */
    private function textsToBinary(array $texts): string
    {
        $data = '';
        foreach ($texts as $text) {
            $data .= \chr(\strlen($text)) . $text;
        }
        return $data;
    }

    private function mxToBinary(array $data): string
    {
        return pack('n', (int) $data['priority']) . $this->domainNameToBinary((string) $data['target']);
    }

    private function srvToBinary(array $data): string
    {
        return pack('nnn', (int) $data['priority'], (int) $data['weight'], (int) $data['port'])
            . $this->domainNameToBinary((string) $data['target']);
    }

    private function soaToBinary(array $data): string
    {
        return $this->domainNameToBinary((string) $data['mname'])
            . $this->domainNameToBinary((string) $data['rname'])
            . pack(
                'NNNNN',
                (int) $data['serial'],
                (int) $data['refresh'],
                (int) $data['retry'],
                (int) $data['expire'],
                (int) $data['minimum']
            );
    }
}
