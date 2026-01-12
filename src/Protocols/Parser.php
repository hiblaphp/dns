<?php

declare(strict_types=1);

namespace Hibla\Dns\Protocols;

use Hibla\Dns\Enums\Opcode;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;
use InvalidArgumentException;

final class Parser
{
    public function parseMessage(string $data): Message
    {
        // Header is 12 bytes
        if (!isset($data[11])) {
            throw new InvalidArgumentException('Message too short');
        }

        // Unpack header fields (big-endian 16-bit unsigned shorts)
        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $data);
        if ($header === false) {
            throw new InvalidArgumentException('Invalid message header');
        }

        $message = new Message();
        $message->id = $header['id'];

        $flags = $header['flags'];
        $message->isResponse = ($flags >> 15) & 1 ? true : false;
        $message->opcode = Opcode::tryFrom(($flags >> 11) & 0xF) ?? Opcode::QUERY;
        $message->isAuthoritative = ($flags >> 10) & 1 ? true : false;
        $message->isTruncated = ($flags >> 9) & 1 ? true : false;
        $message->recursionDesired = ($flags >> 8) & 1 ? true : false;
        $message->recursionAvailable = ($flags >> 7) & 1 ? true : false;
        $message->responseCode = ResponseCode::tryFrom($flags & 0xF) ?? ResponseCode::OK;

        $offset = 12;

        try {
            // Parse Questions
            for ($i = 0; $i < $header['qdcount']; $i++) {
                [$query, $offset] = $this->parseQuery($data, $offset);
                $message->questions[] = $query;
            }

            // Parse Answers
            for ($i = 0; $i < $header['ancount']; $i++) {
                [$record, $offset] = $this->parseRecord($data, $offset);
                $message->answers[] = $record;
            }

            // Parse Authority
            for ($i = 0; $i < $header['nscount']; $i++) {
                [$record, $offset] = $this->parseRecord($data, $offset);
                $message->authority[] = $record;
            }

            // Parse Additional
            for ($i = 0; $i < $header['arcount']; $i++) {
                [$record, $offset] = $this->parseRecord($data, $offset);
                $message->additional[] = $record;
            }
        } catch (\Throwable $e) {
            // Re-throw any parsing errors as InvalidArgumentException for consistency
            throw new InvalidArgumentException('Failed to parse packet: ' . $e->getMessage(), 0, $e);
        }

        return $message;
    }

    private function parseQuery(string $data, int $offset): array
    {
        [$name, $offset] = $this->readName($data, $offset);
        
        if (!isset($data[$offset + 3])) throw new InvalidArgumentException('Query too short');

        $meta = unpack('ntype/nclass', substr($data, $offset, 4));
        $offset += 4;

        $type = RecordType::tryFrom($meta['type']) ?? RecordType::ANY;
        $class = RecordClass::tryFrom($meta['class']) ?? RecordClass::IN;

        return [new Query($name, $type, $class), $offset];
    }

    private function parseRecord(string $data, int $offset): array
    {
        [$name, $offset] = $this->readName($data, $offset);

        if (!isset($data[$offset + 9])) throw new InvalidArgumentException('Record header too short');

        $meta = unpack('ntype/nclass/Nttl/nlength', substr($data, $offset, 10));
        $offset += 10;
        
        $type = RecordType::tryFrom($meta['type']) ?? RecordType::ANY;
        $class = RecordClass::tryFrom($meta['class']) ?? RecordClass::IN;
        $ttl = $meta['ttl'] & 0x7FFFFFFF;
        $length = $meta['length'];

        if (!isset($data[$offset + $length - 1]) && $length > 0) {
             throw new InvalidArgumentException('Record data truncated');
        }

        $rdataRaw = substr($data, $offset, $length);
        $offset += $length;

        $rdata = match ($type) {
            RecordType::A => inet_ntop($rdataRaw),
            RecordType::AAAA => inet_ntop($rdataRaw),
            RecordType::CNAME, RecordType::NS, RecordType::PTR => $this->readName($data, $offset - $length)[0],
            RecordType::TXT => $this->parseTxt($rdataRaw),
            RecordType::MX => $this->parseMx($data, $offset - $length),
            default => $rdataRaw
        };

        return [new Record($name, $type, $class, $ttl, $rdata), $offset];
    }

    /**
     * Reads a domain name, handling compression pointers.
     * Includes protection against infinite loops.
     */
    private function readName(string $data, int $offset): array
    {
        $labels = [];
        $jumped = false;
        $finalOffset = $offset;
        $jumps = 0; // SAFETY: Counter to prevent infinite loops

        while (true) {
            if (!isset($data[$offset])) {
                throw new InvalidArgumentException('Packet ended while reading name');
            }

            $len = ord($data[$offset]);

            // End of name (0 byte)
            if ($len === 0) {
                $offset++;
                if (!$jumped) $finalOffset = $offset;
                break;
            }

            // Compression pointer (11xxxxxx)
            if (($len & 0xC0) === 0xC0) {
                if ($jumps > 5) { // RFC doesn't specify a limit, but 5 is safe for recursion
                    throw new InvalidArgumentException('Too many compression pointers (possible infinite loop)');
                }

                if (!isset($data[$offset + 1])) throw new InvalidArgumentException('Invalid pointer');
                
                $pointer = (($len & 0x3F) << 8) | ord($data[$offset + 1]);
                $offset += 2;
                
                if (!$jumped) $finalOffset = $offset;
                
                $offset = $pointer;
                $jumped = true;
                $jumps++;
                continue;
            }

            // Normal label (00xxxxxx)
            // Mask out the high bits to be safe, though strict DNS implies this
            $labelLen = $len & 0x3F; 
            
            $offset++;
            if (!isset($data[$offset + $labelLen - 1])) {
                 throw new InvalidArgumentException('Label truncated');
            }

            $labels[] = substr($data, $offset, $labelLen);
            $offset += $labelLen;
            
            if (!$jumped) $finalOffset = $offset;
        }

        return [implode('.', $labels), $finalOffset];
    }

    private function parseTxt(string $data): array
    {
        $parts = [];
        $len = \strlen($data);
        $i = 0;
        while ($i < $len) {
            $partLen = \ord($data[$i]);
            $i++;
            // Safety check for malformed TXT length
            if ($i + $partLen > $len) {
                 break; 
            }
            $parts[] = substr($data, $i, $partLen);
            $i += $partLen;
        }
        return $parts;
    }

    private function parseMx(string $data, int $offset): array
    {
        // MX record must be at least 2 bytes (priority) + 1 byte (root name)
        $priority = unpack('n', substr($data, $offset, 2))[1];
        [$target] = $this->readName($data, $offset + 2);
        return ['priority' => $priority, 'target' => $target];
    }
}