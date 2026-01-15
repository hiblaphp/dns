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

/**
 * @internal
 */
final class Parser
{
    public function parseMessage(string $data): Message
    {
        // Header is 12 bytes
        if (! isset($data[11])) {
            throw new InvalidArgumentException('Message too short');
        }

        // Unpack header fields (big-endian 16-bit unsigned shorts)
        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $data);
        if ($header === false) {
            throw new InvalidArgumentException('Invalid message header');
        }

        $message = new Message();
        $message->id = $this->ensureInt($header['id']);

        $flags = $this->ensureInt($header['flags']);
        $message->isResponse = (($flags >> 15) & 1) === 1;
        $message->opcode = Opcode::tryFrom(($flags >> 11) & 0xF) ?? Opcode::QUERY;
        $message->isAuthoritative = (($flags >> 10) & 1) === 1;
        $message->isTruncated = (($flags >> 9) & 1) === 1;
        $message->recursionDesired = (($flags >> 8) & 1) === 1;
        $message->recursionAvailable = (($flags >> 7) & 1) === 1;
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

    /**
     * @return array{0: Query, 1: int}
     */
    private function parseQuery(string $data, int $offset): array
    {
        [$name, $offset] = $this->readName($data, $offset);

        if (! isset($data[$offset + 3])) {
            throw new InvalidArgumentException('Query too short');
        }

        $meta = unpack('ntype/nclass', substr($data, $offset, 4));
        if ($meta === false) {
            throw new InvalidArgumentException('Invalid query metadata');
        }

        $offset += 4;

        $type = RecordType::tryFrom($this->ensureInt($meta['type'])) ?? RecordType::ANY;
        $class = RecordClass::tryFrom($this->ensureInt($meta['class'])) ?? RecordClass::IN;

        return [new Query($name, $type, $class), $offset];
    }

    /**
     * @return array{0: Record, 1: int}
     */
    private function parseRecord(string $data, int $offset): array
    {
        [$name, $offset] = $this->readName($data, $offset);

        if (! isset($data[$offset + 9])) {
            throw new InvalidArgumentException('Record header too short');
        }

        $meta = unpack('ntype/nclass/Nttl/nlength', substr($data, $offset, 10));
        if ($meta === false) {
            throw new InvalidArgumentException('Invalid record metadata');
        }

        $offset += 10;

        $type = RecordType::tryFrom($this->ensureInt($meta['type'])) ?? RecordType::ANY;
        $class = RecordClass::tryFrom($this->ensureInt($meta['class'])) ?? RecordClass::IN;
        $ttl = $this->ensureInt($meta['ttl']) & 0x7FFFFFFF;
        $length = $this->ensureInt($meta['length']);

        if (! isset($data[$offset + $length - 1]) && $length > 0) {
            throw new InvalidArgumentException('Record data truncated');
        }

        $rdataRaw = substr($data, $offset, $length);
        $rdataOffset = $offset; // Store the starting offset for parsing
        $offset += $length;

        $rdata = match ($type) {
            RecordType::A => $this->parseIpAddress($rdataRaw),
            RecordType::AAAA => $this->parseIpAddress($rdataRaw),
            RecordType::CNAME, RecordType::NS, RecordType::PTR => $this->readName($data, $rdataOffset)[0],
            RecordType::TXT => $this->parseTxt($rdataRaw),
            RecordType::MX => $this->parseMx($data, $rdataOffset),
            RecordType::SOA => $this->parseSoa($data, $rdataOffset),
            RecordType::SRV => $this->parseSrv($data, $rdataOffset),
            RecordType::CAA => $this->parseCaa($rdataRaw),
            RecordType::SSHFP => $this->parseSshfp($rdataRaw),
            default => $rdataRaw
        };

        return [new Record($name, $type, $class, $ttl, $rdata), $offset];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readName(string $data, int $offset): array
    {
        $labels = [];
        $jumped = false;
        $finalOffset = $offset;
        $jumps = 0; // SAFETY: Counter to prevent infinite loops

        while (true) {
            if (! isset($data[$offset])) {
                throw new InvalidArgumentException('Packet ended while reading name');
            }

            $len = \ord($data[$offset]);

            // End of name (0 byte)
            if ($len === 0) {
                $offset++;
                if (! $jumped) {
                    $finalOffset = $offset;
                }

                break;
            }

            // Compression pointer (11xxxxxx)
            if (($len & 0xC0) === 0xC0) {
                if ($jumps > 5) { // RFC doesn't specify a limit, but 5 is safe for recursion
                    throw new InvalidArgumentException('Too many compression pointers (possible infinite loop)');
                }

                if (! isset($data[$offset + 1])) {
                    throw new InvalidArgumentException('Invalid pointer');
                }

                $pointer = (($len & 0x3F) << 8) | ord($data[$offset + 1]);
                $offset += 2;

                if (! $jumped) {
                    $finalOffset = $offset;
                }

                $offset = $pointer;
                $jumped = true;
                $jumps++;

                continue;
            }

            // Normal label (00xxxxxx)
            // Mask out the high bits to be safe, though strict DNS implies this
            $labelLen = $len & 0x3F;

            $offset++;
            if (! isset($data[$offset + $labelLen - 1])) {
                throw new InvalidArgumentException('Label truncated');
            }

            $labels[] = substr($data, $offset, $labelLen);
            $offset += $labelLen;

            if (! $jumped) {
                $finalOffset = $offset;
            }
        }

        return [implode('.', $labels), $finalOffset];
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return array{priority: int, target: string}
     */
    private function parseMx(string $data, int $offset): array
    {
        // MX record must be at least 2 bytes (priority) + 1 byte (root name)
        $unpacked = unpack('n', substr($data, $offset, 2));
        if ($unpacked === false) {
            throw new InvalidArgumentException('Invalid MX priority');
        }

        $priority = $this->ensureInt($unpacked[1]);
        [$target] = $this->readName($data, $offset + 2);

        return ['priority' => $priority, 'target' => $target];
    }

    /**
     * @return array{mname: string, rname: string, serial: int, refresh: int, retry: int, expire: int, minimum: int}
     */
    private function parseSoa(string $data, int $offset): array
    {
        // Parse MNAME (primary nameserver)
        [$mname, $offset] = $this->readName($data, $offset);

        // Parse RNAME (responsible party email)
        [$rname, $offset] = $this->readName($data, $offset);

        // Parse 5 32-bit integers: serial, refresh, retry, expire, minimum
        if (! isset($data[$offset + 19])) {
            throw new InvalidArgumentException('SOA record too short');
        }

        $values = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr($data, $offset, 20));
        if ($values === false) {
            throw new InvalidArgumentException('Invalid SOA values');
        }

        return [
            'mname' => $mname,
            'rname' => $rname,
            'serial' => $this->ensureInt($values['serial']),
            'refresh' => $this->ensureInt($values['refresh']),
            'retry' => $this->ensureInt($values['retry']),
            'expire' => $this->ensureInt($values['expire']),
            'minimum' => $this->ensureInt($values['minimum']),
        ];
    }

    /**
     * Parse SRV record (RFC 2782)
     * Format: priority (2 bytes) + weight (2 bytes) + port (2 bytes) + target (domain name)
     *
     * @return array{priority: int, weight: int, port: int, target: string}
     */
    private function parseSrv(string $data, int $offset): array
    {
        // SRV record must be at least 6 bytes (priority + weight + port) + domain name
        if (! isset($data[$offset + 5])) {
            throw new InvalidArgumentException('SRV record too short');
        }

        $values = unpack('npriority/nweight/nport', substr($data, $offset, 6));
        if ($values === false) {
            throw new InvalidArgumentException('Invalid SRV values');
        }

        [$target] = $this->readName($data, $offset + 6);

        return [
            'priority' => $this->ensureInt($values['priority']),
            'weight' => $this->ensureInt($values['weight']),
            'port' => $this->ensureInt($values['port']),
            'target' => $target,
        ];
    }

    /**
     * Parse CAA record (RFC 6844/8659)
     * Format: flags (1 byte) + tag length (1 byte) + tag (variable) + value (variable)
     *
     * @return array{flags: int, tag: string, value: string}
     */
    private function parseCaa(string $data): array
    {
        if (! isset($data[1])) {
            throw new InvalidArgumentException('CAA record too short');
        }

        $flags = \ord($data[0]);
        $tagLength = \ord($data[1]);

        if (! isset($data[2 + $tagLength - 1])) {
            throw new InvalidArgumentException('CAA tag truncated');
        }

        $tag = substr($data, 2, $tagLength);
        $value = substr($data, 2 + $tagLength);

        return [
            'flags' => $flags,
            'tag' => $tag,
            'value' => $value,
        ];
    }

    /**
     * Parse SSHFP record (RFC 4255)
     * Format: algorithm (1 byte) + fingerprint type (1 byte) + fingerprint (variable, hex)
     *
     * @return array{algorithm: int, fptype: int, fingerprint: string}
     */
    private function parseSshfp(string $data): array
    {
        if (! isset($data[1])) {
            throw new InvalidArgumentException('SSHFP record too short');
        }

        $algorithm = \ord($data[0]);
        $fpType = \ord($data[1]);
        $fingerprint = bin2hex(substr($data, 2));

        return [
            'algorithm' => $algorithm,
            'fptype' => $fpType,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param mixed $value
     */
    private function ensureInt($value): int
    {
        assert(\is_int($value));

        return $value;
    }

    private function parseIpAddress(string $binary): string
    {
        $result = @inet_ntop($binary);
        if ($result === false) {
            throw new InvalidArgumentException('Invalid IP address binary data');
        }

        return $result;
    }
}
