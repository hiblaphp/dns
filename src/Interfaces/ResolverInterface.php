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
 * - Resolver: High-level, returns record data (strings or structured arrays)
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
 *     ->then(fn($servers) => print_r($servers));  // [['priority' => 10, 'target' => 'mail.example.com'], ...]
 * </code>
 */
interface ResolverInterface
{
    /**
     * Resolves a domain name to a single IPv4 address.
     *
     * This is the most common DNS operation - looking up where to connect to a service.
     * It performs an A record query and returns one IP address.
     *
     * Why just one IP?
     * Many applications only need one address to establish a connection. For load
     * balancing or failover scenarios where you need all IPs, use resolveAll() instead.
     *
     * Behavior:
     * - Queries for A records (IPv4 only)
     * - Returns a random IP address from the response
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
     *                                   - Rejects with RecordNotFoundException on network/DNS errors
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
     * - A/AAAA/NS/PTR/CNAME: string[] - Simple strings
     *   Example: ["93.184.216.34", "2606:2800:220:1:248:1893:25c8:1946"]
     *
     * - MX: array[] - Structured data with priority and target
     *   Example: [['priority' => 10, 'target' => 'mail.example.com'], ['priority' => 20, 'target' => 'mail2.example.com']]
     *
     * - TXT: array[] - Arrays of text strings
     *   Example: [['v=spf1 include:_spf.example.com ~all'], ['google-site-verification=...']]
     *
     * - SRV: array[] - Structured data with priority, weight, port, and target
     *   Example: [['priority' => 10, 'weight' => 5, 'port' => 5222, 'target' => 'xmpp.example.com']]
     *
     * - SOA: array[] - Structured data with all SOA fields
     *   Example: [['mname' => 'ns1.example.com', 'rname' => 'admin.example.com', 'serial' => 2024011501, ...]]
     *
     * - CAA: array[] - Structured data with flags, tag, and value
     *   Example: [['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']]
     *
     * The returned data format matches the DNS record's RDATA structure. Simple record types
     * (A, AAAA, NS, PTR, CNAME) return strings, while complex types (MX, SRV, SOA, CAA, TXT)
     * return structured arrays with their relevant fields.
     *
     * Behavior:
     * - Returns ALL matching records (not just the first)
     * - Rejects if no records of the specified type exist (throws RecordNotFoundException)
     * - Rejects on network/protocol errors
     *
     * Common use cases:
     * - Load balancing across multiple IPs (A/AAAA records)
     * - Mail server discovery (MX records)
     * - Domain ownership verification (TXT records)
     * - IPv6 address resolution (AAAA records)
     * - Nameserver lookups (NS records)
     * - Service discovery (SRV records)
     *
     * @param string $domain The domain name to query (e.g., "example.com")
     * @param RecordType $type The DNS record type to retrieve (A, AAAA, MX, TXT, CNAME, NS, etc.)
     *
     * @return PromiseInterface<list<mixed>> A promise that:
     *                                        - Resolves with an array of record values (strings or arrays depending on type)
     *                                        - Rejects with RecordNotFoundException if no records found
     *                                        - Rejects with QueryFailedException on network/DNS errors
     *                                        - Rejects with TimeoutException if query exceeds time limit
     */
    public function resolveAll(string $domain, RecordType $type): PromiseInterface;
}
