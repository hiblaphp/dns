<?php

declare(strict_types=1);

namespace Hibla\Dns\Interfaces;

use Hibla\Dns\Enums\RecordType;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * High-level DNS resolution interface for application use.
 *
 * While ExecutorInterface handles low-level DNS protocol mechanics (queries, transport,
 * caching), ResolverInterface provides a simplified, application-friendly API for common
 * DNS lookups. It abstracts away DNS message structures, record classes, and protocol
 * details, returning just the data applications need.
 *
 * Key differences from ExecutorInterface:
 * - Resolver: High-level, returns simple data types (strings, arrays)
 * - Executor: Low-level, returns full DNS Message objects
 *
 * Typical usage:
 * - Use Resolver for application logic (connecting to services, validating domains)
 * - Use Executor for DNS tooling, debugging, or when you need full DNS message details
 *
 * Example:
 * <code>
 * $resolver->resolve('example.com')
 *     ->then(fn($ip) => echo "IP: $ip");  // "IP: 93.184.216.34"
 *
 * $resolver->resolveAll('example.com', RecordType::MX)
 *     ->then(fn($servers) => print_r($servers));  // ["10 mail.example.com", ...]
 * </code>
 */
interface ResolverInterface
{
    /**
     * Resolves a domain name to a single IPv4 address.
     *
     * This is the most common DNS operation - looking up where to connect to a service.
     * It performs an A record query and returns the first IP address found.
     *
     * Why just one IP?
     * Many applications only need one address to establish a connection. For load
     * balancing or failover scenarios where you need all IPs, use resolveAll() instead.
     *
     * Behavior:
     * - Queries for A records (IPv4 only)
     * - Returns the first available IP address from the response
     * - Rejects if no A records are found
     * - Rejects on network errors, timeouts, or DNS server failures
     *
     * Common use cases:
     * - Establishing HTTP/TCP connections to a domain
     * - Simple IP lookups for logging or validation
     * - Quick checks for domain existence
     *
     * @param string $domain The domain name to resolve (e.g., "example.com", "api.github.com")
     *                       Must be a valid DNS name; does not support IP addresses as input
     *
     * @return PromiseInterface<string> A promise that:
     *                                   - Resolves with an IPv4 address string (e.g., "93.184.216.34")
     *                                   - Rejects with QueryFailedException on network/DNS errors
     *                                   - Rejects with TimeoutException if query exceeds time limit
     *                                   - Rejects if no A records are found for the domain
     *
     * @see resolveAll() For retrieving all IP addresses or other record types
     */
    public function resolve(string $domain): PromiseInterface;

    /**
     * Resolves all DNS records of a specific type for a domain.
     *
     * This method provides complete DNS record data for any record type, making it
     * suitable for scenarios requiring multiple records or non-standard record types.
     *
     * Why use this over resolve()?
     * - You need ALL IP addresses (for load balancing, failover)
     * - You're querying non-A records (MX, TXT, AAAA, CNAME, NS, etc.)
     * - You need complete record data, not just the first result
     *
     * Return value format varies by record type:
     * - A/AAAA: ["93.184.216.34", "2606:2800:220:1:248:1893:25c8:1946"]
     * - MX: ["10 mail.example.com", "20 mail2.example.com"] (priority + server)
     * - TXT: ["v=spf1 include:_spf.example.com ~all", "google-site-verification=..."]
     * - CNAME: ["target.example.com"]
     * - NS: ["ns1.example.com", "ns2.example.com"]
     *
     * The returned data is extracted from DNS record RDATA fields and formatted as
     * human-readable strings. For raw binary data or when you need access to TTL,
     * record class, or other DNS message fields, use ExecutorInterface instead.
     *
     * Behavior:
     * - Returns ALL matching records (not just the first)
     * - Returns empty array if no records of the specified type exist
     * - Rejects only on network/protocol errors, not on NXDOMAIN
     *
     * Common use cases:
     * - Load balancing across multiple IPs (A/AAAA records)
     * - Mail server discovery (MX records)
     * - Domain ownership verification (TXT records)
     * - IPv6 address resolution (AAAA records)
     * - Nameserver lookups (NS records)
     *
     * @param string $domain The domain name to query (e.g., "example.com")
     * @param RecordType $type The DNS record type to retrieve (A, AAAA, MX, TXT, CNAME, NS, etc.)
     *
     * @return PromiseInterface<list<mixed>> A promise that:
     *                                        - Resolves with an array of record values as strings
     *                                        - Array is empty if no records of that type exist
     *                                        - Rejects with QueryFailedException on network/DNS errors
     *                                        - Rejects with TimeoutException if query exceeds time limit
     *                                        - Values are formatted as human-readable strings, not raw bytes
     *
     * @see RecordType For available DNS record types
     * @see resolve() For simple single-IP lookups
     */
    public function resolveAll(string $domain, RecordType $type): PromiseInterface;
}
