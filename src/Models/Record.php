<?php

declare(strict_types=1);

namespace Hibla\Dns\Models;

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;

final readonly class Record
{
    /**
     * @param  string  $name  The domain name owner of this record (e.g., "example.com" or "sub.example.com").
     * @param  RecordType  $type  The Resource Record type (A, AAAA, MX, etc.), defining how to interpret the $data.
     * @param  RecordClass  $class  The network class (almost always IN for Internet).
     * @param  int  $ttl  The Time-To-Live in seconds. This tells the resolver how long it may cache this record.
     * @param  string|array<string, mixed>|list<string>  $data  The record payload. The format depends on $type:
     *                                                          - A/AAAA: IP address string (e.g. "192.168.1.1").
     *                                                          - CNAME/NS/PTR: Hostname string.
     *                                                          - TXT: List of strings.
     *                                                          - MX: Array ['priority' => int, 'target' => string].
     *                                                          - SOA: Array with fields (mname, rname, serial, etc.).
     */
    public function __construct(
        public string $name,
        public RecordType $type,
        public RecordClass $class,
        public int $ttl,
        public string|array $data
    ) {
    }
}
