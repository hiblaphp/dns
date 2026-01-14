<?php

declare(strict_types=1);

namespace Hibla\Dns\Models;

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;

final readonly class Query
{
    /**
     * @param  string  $name  The domain name being queried (e.g., "google.com").
     * @param  RecordType  $type  The type of record being requested (e.g., A for IPv4, AAAA for IPv6, MX for Mail).
     * @param  RecordClass  $class  The network class of the query (almost always IN for Internet).
     */
    public function __construct(
        public string $name,
        public RecordType $type,
        public RecordClass $class
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('Query(%s: %s IN)', $this->name, $this->type->name);
    }
}
