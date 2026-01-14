<?php

declare(strict_types=1);

namespace Hibla\Dns\Interfaces;

use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for DNS query execution strategies.
 *
 * Executors form the core of the DNS resolution pipeline, handling everything from
 * transport-level communication to query optimization and reliability improvements.
 * Different implementations provide distinct capabilities:
 *
 * Transport Layer:
 * - UdpTransportExecutor: Fast, connectionless queries (standard DNS)
 * - TcpTransportExecutor: Reliable, connection-based queries (large responses)
 * - SelectiveTransportExecutor: Automatic UDP→TCP fallback on truncation
 *
 * Optimization & Caching:
 * - CachingExecutor: Reduces network traffic by storing responses per TTL
 * - CoopExecutor: Deduplicates concurrent identical queries to prevent storms
 * - HostsFileExecutor: Local resolution with network fallback (/etc/hosts)
 *
 * Reliability & Resilience:
 * - RetryExecutor: Automatic retry logic for transient failures
 * - TimeoutExecutor: Prevents queries from hanging indefinitely
 * - FallbackExecutor: Multi-nameserver resilience (primary→secondary)
 *
 * Executors are designed to be composable via the decorator pattern, allowing
 * you to build sophisticated resolution pipelines like:
 * HostsFile → Cache → Coop → Timeout → Retry → SelectiveTransport → UDP/TCP
 */
interface ExecutorInterface
{
    /**
     * Executes a DNS query asynchronously and returns a promise.
     *
     * This method initiates the DNS resolution process according to the executor's
     * specific strategy. The returned promise represents the eventual outcome of
     * the query, which may involve network I/O, cache lookups, retries, or other
     * operations depending on the implementation.
     *
     * Cancellation support:
     * All executors must properly handle cancellation to:
     * - Clean up network resources (close sockets, remove watchers)
     * - Cancel dependent operations in decorator chains
     * - Prevent memory leaks from abandoned queries
     *
     * @param Query $query The DNS query to execute, specifying the domain name,
     *                     record type (A, AAAA, MX, etc.), and record class (usually IN)
     *
     * @return PromiseInterface<Message> A promise that:
     *                                    - Resolves with a Message containing the DNS response
     *                                      (answer records, authority records, additional records)
     *                                    - Rejects with QueryFailedException on network/protocol errors
     *                                    - Rejects with TimeoutException if query exceeds time limit
     *                                    - Rejects with ResponseTruncatedException if UDP response is too large
     *                                    - Supports cancellation to abort in-flight operations
     *
     * @see Message The DNS response structure containing answer/authority/additional sections
     * @see Query The query parameters (name, type, class)
     */
    public function query(Query $query): PromiseInterface;
}
