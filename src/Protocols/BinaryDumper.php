<?php

declare(strict_types=1);

namespace Hibla\Dns\Protocols;

use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;

/**
 * @internal
 */
final class BinaryDumper
{
    public function toBinary(Message $message): string
    {
        return $this->headerToBinary($message)
            .$this->questionsToBinary($message->questions)
            .$this->recordsToBinary($message->answers)
            .$this->recordsToBinary($message->authority)
            .$this->recordsToBinary($message->additional);
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
        $data .= pack('n', \count($message->questions));
        $data .= pack('n', \count($message->answers));
        $data .= pack('n', \count($message->authority));
        $data .= pack('n', \count($message->additional));

        return $data;
    }

    /**
     * @param  list<Query>  $questions
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
     * @param  list<Record>  $records
     */
    private function recordsToBinary(array $records): string
    {
        $data = '';

        foreach ($records as $record) {
            $binaryData = match ($record->type) {
                RecordType::A, RecordType::AAAA => (string) @inet_pton($this->ensureString($record->data)),
                RecordType::CNAME, RecordType::NS, RecordType::PTR => $this->domainNameToBinary($this->ensureString($record->data)),
                RecordType::TXT => $this->textsToBinary($this->ensureStringArray($record->data)),
                RecordType::MX => $this->mxToBinary($this->ensureMxData($record->data)),
                RecordType::SRV => $this->srvToBinary($this->ensureSrvData($record->data)),
                RecordType::SOA => $this->soaToBinary($this->ensureSoaData($record->data)),
                RecordType::CAA => $this->caaToBinary($this->ensureCaaData($record->data)),
                RecordType::SSHFP => $this->sshfpToBinary($this->ensureSshfpData($record->data)),
                // Fallback for unknown types or simple binary data
                default => $this->ensureString($record->data),
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
            $data .= \chr(\strlen($label)).$label;
        }

        return $data."\0";
    }

    /**
     * @param  array<string>  $texts
     */
    private function textsToBinary(array $texts): string
    {
        $data = '';
        foreach ($texts as $text) {
            $data .= \chr(\strlen($text)).$text;
        }

        return $data;
    }

    /**
     * @param  array{priority: int|string, target: string}  $data
     */
    private function mxToBinary(array $data): string
    {
        return pack('n', (int) $data['priority']).$this->domainNameToBinary($data['target']);
    }

    /**
     * @param  array{priority: int|string, weight: int|string, port: int|string, target: string}  $data
     */
    private function srvToBinary(array $data): string
    {
        return pack('nnn', (int) $data['priority'], (int) $data['weight'], (int) $data['port'])
            .$this->domainNameToBinary($data['target']);
    }

    /**
     * @param  array{mname: string, rname: string, serial: int|string, refresh: int|string, retry: int|string, expire: int|string, minimum: int|string}  $data
     */
    private function soaToBinary(array $data): string
    {
        return $this->domainNameToBinary($data['mname'])
            .$this->domainNameToBinary($data['rname'])
            .pack(
                'NNNNN',
                (int) $data['serial'],
                (int) $data['refresh'],
                (int) $data['retry'],
                (int) $data['expire'],
                (int) $data['minimum']
            );
    }

    /**
     * @param  array{flags: int|string, tag: string, value: string}  $data
     */
    private function caaToBinary(array $data): string
    {
        $tag = $data['tag'];
        $value = $data['value'];

        return \chr((int) $data['flags'])
            .\chr(\strlen($tag))
            .$tag
            .$value;
    }

    /**
     * @param  array{algorithm: int|string, fptype: int|string, fingerprint: string}  $data
     */
    private function sshfpToBinary(array $data): string
    {
        // Convert hex fingerprint back to binary
        $fingerprint = hex2bin($data['fingerprint']);
        if ($fingerprint === false) {
            $fingerprint = '';
        }

        return \chr((int) $data['algorithm'])
            .\chr((int) $data['fptype'])
            .$fingerprint;
    }

    /**
     * @param  mixed  $value
     */
    private function ensureString($value): string
    {
        assert(\is_string($value));

        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array<string>
     */
    private function ensureStringArray($value): array
    {
        assert(is_array($value));

        /** @var array<string> */
        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array{priority: int|string, target: string}
     */
    private function ensureMxData($value): array
    {
        assert(\is_array($value));
        assert(isset($value['priority'], $value['target']));

        /** @var array{priority: int|string, target: string} */
        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array{priority: int|string, weight: int|string, port: int|string, target: string}
     */
    private function ensureSrvData($value): array
    {
        assert(\is_array($value));
        assert(isset($value['priority'], $value['weight'], $value['port'], $value['target']));

        /** @var array{priority: int|string, weight: int|string, port: int|string, target: string} */
        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array{mname: string, rname: string, serial: int|string, refresh: int|string, retry: int|string, expire: int|string, minimum: int|string}
     */
    private function ensureSoaData($value): array
    {
        assert(\is_array($value));
        assert(isset($value['mname'], $value['rname'], $value['serial'], $value['refresh'], $value['retry'], $value['expire'], $value['minimum']));

        /** @var array{mname: string, rname: string, serial: int|string, refresh: int|string, retry: int|string, expire: int|string, minimum: int|string} */
        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array{flags: int|string, tag: string, value: string}
     */
    private function ensureCaaData($value): array
    {
        assert(\is_array($value));
        assert(isset($value['flags'], $value['tag'], $value['value']));

        /** @var array{flags: int|string, tag: string, value: string} */
        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array{algorithm: int|string, fptype: int|string, fingerprint: string}
     */
    private function ensureSshfpData($value): array
    {
        assert(\is_array($value));
        assert(isset($value['algorithm'], $value['fptype'], $value['fingerprint']));

        /** @var array{algorithm: int|string, fptype: int|string, fingerprint: string} */
        return $value;
    }
}
