<?php

declare(strict_types=1);

namespace Hibla\Dns\Interfaces;

use Hibla\Dns\Enums\RecordType;
use Hibla\Promise\Interfaces\PromiseInterface;

interface ResolverInterface
{
    /**
     * Resolves a domain name to a single IPv4 address (A record).
     *
     * @param string $domain
     * @return PromiseInterface<string> Resolves with the IP address.
     */
    public function resolve(string $domain): PromiseInterface;

    /**
     * Resolves all record values for the given domain and type.
     *
     * @param string $domain
     * @param RecordType $type
     * @return PromiseInterface<list<mixed>> Resolves with an array of record data.
     */
    public function resolveAll(string $domain, RecordType $type): PromiseInterface;
}