# Hibla DNS

**Async, non-blocking DNS resolution for PHP with a full executor pipeline.**

`hiblaphp/dns` provides promise-based DNS resolution built on top of the Hibla
event loop. Queries never block the thread — UDP and TCP transports are driven
entirely by non-blocking I/O watchers. A composable executor pipeline handles
caching, deduplication, retries, timeouts, transport fallback, and hosts file
resolution out of the box.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/dns.svg?style=flat-square)](https://github.com/hiblaphp/dns/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Introduction](#introduction)
- [Quick Start](#quick-start)

**The API**
- [Choosing the Right Entry Point](#choosing-the-right-entry-point)
- [Quick Resolve](#quick-resolve)
- [Reusable Resolver](#reusable-resolver)
- [Building a Resolver](#building-a-resolver)
  - [Nameservers](#nameservers)
  - [Timeout](#timeout)
  - [Retries](#retries)
  - [Caching](#caching)
  - [Reusing Resolvers](#reusing-resolvers)
  - [Cache Key Format](#cache-key-format)
- [Resolving Records](#resolving-records)
  - [`resolve()` — single IP address](#resolve--single-ip-address)
  - [`resolveAll()` — all records of a type](#resolveall--all-records-of-a-type)
  - [Record types and return formats](#record-types-and-return-formats)
- [Raw Executor Usage](#raw-executor-usage)
- [Cancellation](#cancellation)

**Configuration**
- [System Configuration Detection](#system-configuration-detection)
- [Hosts File](#hosts-file)
- [Specifying Transport](#specifying-transport)

**The Executor Pipeline**
- [How the Pipeline Works](#how-the-pipeline-works)
- [Executor Reference](#executor-reference)

**Reference**
- [Exception Reference](#exception-reference)
- [Record Type Reference](#record-type-reference)

**Meta**
- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Installation
```bash
composer require hiblaphp/dns
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/event-loop`
- `hiblaphp/promise`
- `hiblaphp/stream`

---

## Introduction

PHP's built-in `gethostbyname()` and `dns_get_record()` are synchronous — they
block the entire thread while waiting for the DNS server to respond. In an async
application running on the Hibla event loop, a blocking DNS lookup stalls all
timers, fibers, and I/O for the duration of the call.

`hiblaphp/dns` replaces these with non-blocking alternatives. Queries are sent
over UDP or TCP sockets managed by the event loop, and the result is delivered
as a promise that resolves when the DNS server responds — without blocking
anything else.

The library is built around a composable executor pipeline. Each query passes
through a stack of decorators in order: hosts file check, cache lookup, query
deduplication, transport selection, timeout enforcement, and retry logic. The
full pipeline is assembled automatically when you call `Dns::resolve()`,
`Dns::create()`, or `Dns::builder()->build()`.

**`->wait()` vs `await()`**

The examples in this README use `->wait()` to drive the event loop until the
promise settles. This is the correct approach when using `hiblaphp/dns` on its
own — `->wait()` only requires `hiblaphp/event-loop` and `hiblaphp/promise`,
which this package already depends on.

If you are using this library inside a larger Hibla async application where a
fiber is already running — for example inside an `async()` block — you can use
`await()` from `hiblaphp/async` instead. Both resolve the same promise. The
difference is that `->wait()` blocks the calling code until the loop settles,
while `await()` suspends only the current fiber and lets the event loop continue
running other work in the meantime.

---

## Quick Start
```php
require __DIR__ . '/vendor/autoload.php';

use Hibla\Dns\Dns;

// Resolve a domain to a single IPv4 address using system DNS configuration
$ip = Dns::resolve('example.com')->wait();

echo $ip; // 93.184.216.34
```

---

## Choosing the Right Entry Point

There are three ways to use this library, each suited to a different situation:
```
Dns::resolve() / Dns::resolveAll()   ← one-off lookups with system defaults
Dns::create()                         ← multiple lookups with system defaults
Dns::builder()->build()               ← any lookup needing custom configuration
```

**`Dns::resolve()` and `Dns::resolveAll()`** are one-line static shortcuts.
Each call reads the system DNS configuration from disk (`/etc/resolv.conf` on
Unix, `wmic` on Windows) and constructs a fresh resolver. This is fine for a
single lookup but wasteful for multiple lookups — each call pays the system
configuration cost again.

**`Dns::create()`** reads the system DNS configuration once and returns a
reusable resolver. Use this when making multiple lookups with default settings —
you pay the configuration cost once and reuse the resolver for every query:
```php
use Hibla\Dns\Dns;

// System config is read once here
$resolver = Dns::create();

// All three queries reuse the same resolver — no repeated disk reads
$ip1 = $resolver->resolve('example.com')->wait();
$ip2 = $resolver->resolve('google.com')->wait();
$ip3 = $resolver->resolve('github.com')->wait();
```

**`Dns::builder()->build()`** gives you full control over nameservers, timeouts,
retries, and caching. Use this whenever you need anything beyond the defaults:
```php
use Hibla\Dns\Dns;

$resolver = Dns::builder()
    ->withNameservers(['1.1.1.1', '8.8.8.8'])
    ->withTimeout(3.0)
    ->withRetries(2)
    ->withCache()
    ->build();
```

---

## Quick Resolve

The static `Dns::resolve()` and `Dns::resolveAll()` methods are the fastest way
to perform a one-off lookup without any setup:
```php
use Hibla\Dns\Dns;
use Hibla\Dns\Enums\RecordType;

// Resolve to a single IPv4 address
$ip = Dns::resolve('example.com')->wait();

// Resolve all A records
$ips = Dns::resolveAll('example.com', RecordType::A)->wait();

// Resolve MX records
$mx = Dns::resolveAll('example.com', RecordType::MX)->wait();
```

For multiple lookups, use `Dns::create()` instead to avoid re-reading system
configuration on every call.

---

## Reusable Resolver

`Dns::create()` builds a resolver with system defaults and returns it for reuse.
The system DNS configuration is read once at construction time:
```php
use Hibla\Dns\Dns;
use Hibla\Dns\Enums\RecordType;

$resolver = Dns::create();

$ip  = $resolver->resolve('example.com')->wait();
$mx  = $resolver->resolveAll('example.com', RecordType::MX)->wait();
$txt = $resolver->resolveAll('example.com', RecordType::TXT)->wait();
```

---

## Building a Resolver

`Dns::builder()` returns a fluent builder. Every configuration method returns a
new instance — the builder is immutable and safe to reuse across multiple
`build()` calls.
```php
use Hibla\Dns\Dns;

$resolver = Dns::builder()
    ->withNameservers(['1.1.1.1', '8.8.8.8'])
    ->withTimeout(3.0)
    ->withRetries(2)
    ->withCache()
    ->build();
```

### Nameservers

Pass a single IP or an array. When multiple nameservers are provided, the first
is treated as primary and the rest as fallbacks — if the primary fails, the next
is tried automatically.
```php
// Single nameserver
$resolver = Dns::builder()
    ->withNameservers('8.8.8.8')
    ->build();

// Multiple nameservers — first is primary, rest are fallbacks
$resolver = Dns::builder()
    ->withNameservers(['1.1.1.1', '8.8.8.8', '9.9.9.9'])
    ->build();
```

If `withNameservers()` is not called, the builder loads nameservers from the
system configuration automatically. If system configuration cannot be loaded,
Cloudflare (`1.1.1.1`) and Google (`8.8.8.8`) are used as defaults.

When three or more nameservers are configured, the fallback chain is nested.
For three nameservers the structure is effectively
`FallbackExecutor(FallbackExecutor(NS1, NS2), NS3)`. If all servers fail, the
error message will contain each server's error concatenated in the chain — this
is expected and useful for diagnosing which servers were tried.

### Timeout

Sets the maximum time in seconds to wait for a response from a nameserver before
the query is rejected with a `TimeoutException`. Applies per transport attempt —
a query that retries will apply the timeout to each individual attempt.
```php
$resolver = Dns::builder()
    ->withTimeout(3.0) // 3 seconds per attempt
    ->build();
```

The default timeout is 5.0 seconds.

### Retries

Sets the number of times a failed query is retried before the promise is
rejected. A value of `0` disables retries entirely.
```php
$resolver = Dns::builder()
    ->withRetries(3) // try up to 4 times total (1 initial + 3 retries)
    ->build();
```

The default is 2 retries.

Retries apply to network-level failures such as timeouts and connection errors.
DNS protocol-level errors returned by the server — `SERVFAIL`, `REFUSED`,
`FORMAT_ERROR` — arrive as valid responses and are not retried. If a nameserver
returns `SERVFAIL` transiently, configuring multiple nameservers with
`withNameservers()` is a more effective mitigation than increasing the retry
count.

Note that in the default pipeline, `RetryExecutor` sits inside `CoopExecutor`.
When multiple concurrent callers request the same domain simultaneously, they
share a single deduplicated network request and therefore share the same retry
budget. If that single request exhausts its retries, all concurrent callers
receive the failure at the same time.

### Caching

Enables response caching. Successful responses are stored with their DNS TTL as
the cache expiry. Subsequent identical queries are served from cache until the
TTL expires, skipping the network entirely.
```php
// Default cache — in-memory ArrayCache with a 256 entry limit
$resolver = Dns::builder()
    ->withCache()
    ->build();

// Custom cache implementation
$resolver = Dns::builder()
    ->withCache(new MyRedisCache())
    ->build();
```

The custom cache must implement `Hibla\Cache\Interfaces\CacheInterface`. Caching
is disabled by default.

### Reusing Resolvers

Each call to `Dns::builder()->build()` constructs a new executor pipeline,
including a `TcpTransportExecutor` whose open sockets are only cleaned up when
the executor is garbage-collected. In long-running applications, creating a
resolver per request can leave TCP sockets open longer than expected if
references are held anywhere in scope.

Create the resolver once and reuse it for the lifetime of your application or
service:
```php
// Good — one resolver, many queries
$resolver = Dns::builder()
    ->withNameservers(['1.1.1.1', '8.8.8.8'])
    ->withCache()
    ->build();

foreach ($domains as $domain) {
    $resolver->resolve($domain)->then(...);
}

// Avoid — a new TCP executor (and potentially open socket) per iteration
foreach ($domains as $domain) {
    Dns::builder()->build()->resolve($domain)->then(...);
}
```

The same applies to `Dns::create()` — call it once and store the result rather
than calling it inside a loop or request handler.

### Cache Key Format

When using a custom `CacheInterface` implementation, cache keys follow this
format:
```
{name}:{type_value}:{class_value}
```

For example, an A record query for `example.com` produces the key:
```
example.com:1:1
```

Where `1` is the integer value of `RecordType::A` and `1` is the integer value
of `RecordClass::IN`. You can find all type integer values in the
[Record Type Reference](#record-type-reference) table.

This is relevant if your cache implementation needs prefix-based invalidation,
key inspection, or manual eviction. For example, to invalidate all cached
records for a domain in Redis:
```php
// Invalidate all record types for example.com
$types = [1, 2, 5, 6, 12, 15, 16, 28, 33, 35, 44, 255, 257]; // RecordType values
foreach ($types as $type) {
    $redis->del("example.com:{$type}:1");
}
```

The class component is almost always `1` (IN) for standard DNS queries.

---

## Resolving Records

### `resolve()` — single IP address

Performs an A record lookup and returns one IPv4 address as a string. If the
response contains multiple A records, one is chosen at random. Rejects with
`RecordNotFoundException` if no A records are found.
```php
$ip = $resolver->resolve('example.com')->wait();
echo $ip; // "93.184.216.34"
```

### `resolveAll()` — all records of a type

Returns all records of the specified type as an array. The array contents depend
on the record type — see the table below.
```php
use Hibla\Dns\Enums\RecordType;

// All A records
$ips = $resolver->resolveAll('example.com', RecordType::A)->wait();
// ["93.184.216.34"]

// All MX records
$mx = $resolver->resolveAll('example.com', RecordType::MX)->wait();
// [['priority' => 10, 'target' => 'mail.example.com']]

// All TXT records
$txt = $resolver->resolveAll('example.com', RecordType::TXT)->wait();
// [['v=spf1 include:_spf.example.com ~all']]
```

### Record types and return formats

The shape of each element in the `resolveAll()` result depends on the record
type:

| Record type | Element type | Fields |
|---|---|---|
| `A` | `string` | IPv4 address (e.g. `"93.184.216.34"`) |
| `AAAA` | `string` | IPv6 address (e.g. `"2606:2800:220:1:248:1893:25c8:1946"`) |
| `NS` | `string` | Nameserver hostname |
| `CNAME` | `string` | Canonical name |
| `PTR` | `string` | Pointer hostname |
| `MX` | `array` | `['priority' => int, 'target' => string]` |
| `TXT` | `array` | Array of strings per record (e.g. `['v=spf1 ...']`) |
| `SRV` | `array` | `['priority' => int, 'weight' => int, 'port' => int, 'target' => string]` |
| `SOA` | `array` | `['mname' => string, 'rname' => string, 'serial' => int, 'refresh' => int, 'retry' => int, 'expire' => int, 'minimum' => int]` |
| `CAA` | `array` | `['flags' => int, 'tag' => string, 'value' => string]` |
| `SSHFP` | `array` | `['algorithm' => int, 'fptype' => int, 'fingerprint' => string]` |
| `NAPTR` | `array` | `['order' => int, 'preference' => int, 'flags' => string, 'service' => string, 'regexp' => string, 'replacement' => string]` |

CNAME chaining is handled automatically for A and AAAA lookups. If the response
contains a CNAME record pointing to another name, the resolver follows the chain
up to 10 levels deep before returning the final address records.

---

## Raw Executor Usage

`resolve()` and `resolveAll()` cover most application needs, but you can query
the executor pipeline directly when you need the full DNS `Message` — for
example to inspect TTLs, authority records, additional records, or to build
DNS tooling.

To access `query()` you must construct an executor directly. `Dns::builder()->build()`
returns a `ResolverInterface`, not an `ExecutorInterface`, and does not expose
`query()`:
```php
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Queries\UdpTransportExecutor;
use Hibla\Dns\Queries\TcpTransportExecutor;
use Hibla\Dns\Queries\SelectiveTransportExecutor;
use Hibla\Dns\Queries\TimeoutExecutor;
use Hibla\Dns\Queries\RetryExecutor;
use Hibla\Dns\Queries\CoopExecutor;

$executor = new CoopExecutor(
    new RetryExecutor(
        new SelectiveTransportExecutor(
            new TimeoutExecutor(new UdpTransportExecutor('1.1.1.1'), 5.0),
            new TimeoutExecutor(new TcpTransportExecutor('1.1.1.1'), 5.0),
        ),
        retries: 2
    )
);

$query = new Query('example.com', RecordType::A, RecordClass::IN);

$executor->query($query)->then(function (Message $message) {
    // Answer records
    foreach ($message->answers as $record) {
        echo $record->name;        // owner name
        echo $record->type->name;  // 'A', 'MX', etc.
        echo $record->ttl;         // TTL in seconds
        var_dump($record->data);   // string or array depending on type
    }

    // Authority records (NS, SOA) — useful for negative caching and delegation
    foreach ($message->authority as $record) { ... }

    // Additional records — often glue A/AAAA records for nameservers
    foreach ($message->additional as $record) { ... }

    // Message metadata
    var_dump($message->isAuthoritative);
    var_dump($message->recursionAvailable);
    var_dump($message->responseCode); // ResponseCode enum
})->wait();
```

The `data` field on each `Record` follows the same type rules as `resolveAll()`
— see [Record types and return formats](#record-types-and-return-formats).

See the [Executor Reference](#executor-reference) for all available executors
and guidance on assembling a custom pipeline.

---

## Cancellation

Every promise returned by `resolve()` and `resolveAll()` supports cancellation.
Calling `cancel()` on the promise immediately aborts the in-flight query, cleans
up the underlying socket or watcher, and cancels any pending retries or fallback
attempts in the pipeline. No further callbacks fire after cancellation.
```php
use Hibla\Dns\Dns;
use Hibla\EventLoop\Loop;

$promise = Dns::resolve('example.com');

// Cancel the query after 1 second if it has not resolved
$timerId = Loop::addTimer(1.0, function () use ($promise) {
    $promise->cancel();
});

$promise
    ->then(function (string $ip) use ($timerId) {
        Loop::cancelTimer($timerId);
        echo "Resolved: $ip\n";
    })
    ->catch(function (\Throwable $e) {
        echo "Failed or cancelled: " . $e->getMessage() . "\n";
    });

Loop::run();
```

Cancellation propagates through the entire executor pipeline. Cancelling a
promise that is waiting on a retry will cancel the current attempt and prevent
any further retries from starting. Cancelling a promise on the `CoopExecutor`
decrements its reference count — the underlying network query is only cancelled
when every caller that shares it has cancelled their own promise.

If you are running inside a fiber with `hiblaphp/async`, you can use
`CancellationTokenSource` for structured cancellation that applies a timeout
across the entire lookup without managing timers manually:
```php
use Hibla\Dns\Dns;
use Hibla\Cancellation\CancellationTokenSource;
use function Hibla\await;

$cts = new CancellationTokenSource(2.0); // 2 second hard limit

try {
    $ip = await(Dns::resolve('example.com'), $cts->token);
    echo "Resolved: $ip\n";
} catch (\Hibla\Promise\Exceptions\CancelledException $e) {
    echo "DNS lookup timed out\n";
}
```

---

## System Configuration Detection

When no nameservers are explicitly provided, the builder calls
`Config::loadSystemConfigBlocking()` at build time to read the system DNS
configuration. This is a blocking operation — it reads from the filesystem or
executes a shell command — but it runs only once at resolver construction and the
result is used for the lifetime of the resolver.

On **Unix and macOS**, the method parses `/etc/resolv.conf` for `nameserver`
entries. IPv6 zone identifiers are stripped and all addresses are normalized
before use.

On **Windows**, the method executes `wmic NICCONFIG get DNSServerSearchOrder`
and parses the CSV output. Duplicate addresses across multiple adapters are
deduplicated automatically.

If system configuration cannot be read — missing file, permissions error, or
`shell_exec` disabled — an empty configuration is returned and the builder falls
back to Cloudflare (`1.1.1.1`) and Google (`8.8.8.8`) as defaults.

To load the system configuration manually or supply a custom resolv.conf path:
```php
use Hibla\Dns\Configs\Config;
use Hibla\Dns\Dns;

// Load from system default
$config = Config::loadSystemConfigBlocking();

// Load from a specific resolv.conf file
$config = Config::loadResolvConfBlocking('/etc/resolv.conf');

// Use it in the builder
$resolver = Dns::builder()
    ->withConfig($config)
    ->build();
```

---

## Hosts File

The resolver checks the system hosts file before making any network query. On
Unix and macOS this is `/etc/hosts`. On Windows it is
`%SystemRoot%\system32\drivers\etc\hosts`. If the file cannot be read, the
resolver continues with network-based lookups only — no error is thrown.

Hosts file lookups support:
- Forward lookups (A and AAAA) — hostname to IP
- Reverse lookups (PTR) — IP to hostname
- Multiple aliases per line
- Both IPv4 and IPv6 entries
- IPv6 zone identifiers (stripped automatically)
- Case-insensitive hostname matching

Hosts file entries bypass caching, retries, and transport entirely — they resolve
synchronously before the network pipeline is involved. In the default pipeline,
`HostsFileExecutor` is the outermost layer, meaning a hosts file hit
short-circuits before the query ever reaches `CachingExecutor`. If you assemble
a custom pipeline, place `HostsFileExecutor` outside `CachingExecutor` to
preserve this behaviour.

You can also use the `HostsFile` class directly for hostname or IP lookups
against an arbitrary hosts file:
```php
use Hibla\Dns\Configs\HostsFile;

$hosts = HostsFile::loadFromPathBlocking('/etc/hosts');

// Forward lookup — get all IPs for a hostname
$ips = $hosts->getIpsForHost('localhost');
// ["127.0.0.1", "::1"]

// Reverse lookup — get all hostnames for an IP
$names = $hosts->getHostsForIp('127.0.0.1');
// ["localhost", "myapp.local"]
```

---

## Specifying Transport

By default, each nameserver uses UDP first and automatically retries with TCP if
the response is truncated. You can force a specific transport by prefixing the
nameserver address with `udp://` or `tcp://`:
```php
// Force UDP only
$resolver = Dns::builder()
    ->withNameservers('udp://8.8.8.8')
    ->build();

// Force TCP only
$resolver = Dns::builder()
    ->withNameservers('tcp://8.8.8.8')
    ->build();

// Default — UDP with automatic TCP fallback on truncation
$resolver = Dns::builder()
    ->withNameservers('8.8.8.8')
    ->build();
```

TCP is also used automatically for any response whose size exceeds UDP's 512-byte
limit — DNSSEC responses, domains with many MX or NS records, and large TXT
records commonly trigger this fallback.

Both IPv4 and IPv6 nameserver addresses are supported. IPv6 addresses are wrapped
in brackets automatically if not already formatted that way.

### TCP connection reuse and idle period

When using TCP transport, the first query establishes a connection to the
nameserver. Subsequent queries sent while that connection is still open are
multiplexed over the same socket, avoiding the TCP handshake overhead for each
query. Once all pending queries have settled and no new queries arrive within
50ms, the connection is closed automatically.

This idle period means TCP is most efficient for bursts of concurrent queries —
multiple queries issued in rapid succession share one connection. Queries sent
with longer gaps between them will each establish a fresh connection, which
incurs the TCP handshake latency of approximately 10–50ms per query depending
on network conditions. If this matters for your use case, batch queries together
or use UDP transport, which has no connection overhead.

---

## How the Pipeline Works

When you call `Dns::builder()->build()`, the builder assembles an executor
pipeline from the outside in. Each executor wraps the next and either handles
the query itself or delegates to the inner executor. A query entering the top of
the pipeline passes through each layer in order:
```
resolveAll('example.com', A)
        │
        ▼
┌───────────────────┐
│  HostsFileExecutor│  ← checks /etc/hosts first; hit resolves instantly,
└────────┬──────────┘    bypassing all layers below including the cache
         │ no match — pass through
         ▼
┌───────────────────┐
│  CachingExecutor  │  ← only present when .withCache() is called
└────────┬──────────┘    checks cache; stores response on miss
         │ cache miss — pass through
         ▼
┌───────────────────┐
│  CoopExecutor     │  ← deduplicates concurrent identical queries;
└────────┬──────────┘    all concurrent callers share one network request
         │               and therefore share its retry budget
         ▼
┌───────────────────┐
│  RetryExecutor    │  ← retries on network-level failure only (default: 2 retries)
└────────┬──────────┘    DNS protocol errors (SERVFAIL, REFUSED) are not retried
         │
         ▼
┌───────────────────┐
│ FallbackExecutor  │  ← tries primary nameserver, then secondary
└────────┬──────────┘    chains additional executors for 3+ nameservers
         │
         ▼
┌────────────────────────┐
│ SelectiveTransport     │  ← UDP first, TCP on truncation
│  TimeoutExecutor (UDP) │  ← enforces timeout per attempt
│  TimeoutExecutor (TCP) │
└────────────────────────┘
```

The `Resolver` class sits above this stack, translating the raw `Message`
responses from the executor into the typed return values that `resolve()` and
`resolveAll()` expose.

---

## Executor Reference

Each executor in the pipeline is available individually for custom pipelines. All
implement `ExecutorInterface` and accept a `Query`, returning
`PromiseInterface<Message>`. All returned promises support cancellation —
cancelling propagates down through the wrapped executor chain automatically.

| Executor | Purpose |
|---|---|
| `UdpTransportExecutor` | Sends queries over UDP. Fast and connectionless. Does not handle retries or timeouts |
| `TcpTransportExecutor` | Sends queries over TCP. Maintains a persistent connection per nameserver and multiplexes queries over it. Connection closes automatically after 50ms of idle time |
| `SelectiveTransportExecutor` | Tries UDP first, falls back to TCP automatically if the response is truncated |
| `TimeoutExecutor` | Wraps any executor and rejects with `TimeoutException` if the query exceeds the configured duration |
| `RetryExecutor` | Retries a failed query up to N times before rejecting. Only retries on network-level failures — DNS protocol errors are not retried |
| `FallbackExecutor` | Tries a primary executor, falls back to a secondary on failure |
| `CoopExecutor` | Deduplicates concurrent identical queries — only one network request is made regardless of how many callers ask for the same name simultaneously. Cancellation is reference-counted: the network query is only cancelled when every caller has cancelled. All concurrent callers share the same retry budget |
| `CachingExecutor` | Caches successful responses using the minimum TTL from the answer records |
| `HostsFileExecutor` | Checks a `HostsFile` instance before delegating to another executor. Supports A, AAAA, and PTR lookups |

To assemble a custom pipeline, construct the executors from the inside out and
wrap each one:
```php
use Hibla\Dns\Queries\UdpTransportExecutor;
use Hibla\Dns\Queries\TcpTransportExecutor;
use Hibla\Dns\Queries\SelectiveTransportExecutor;
use Hibla\Dns\Queries\TimeoutExecutor;
use Hibla\Dns\Queries\RetryExecutor;
use Hibla\Dns\Queries\CoopExecutor;
use Hibla\Dns\Queries\CachingExecutor;
use Hibla\Dns\Queries\HostsFileExecutor;
use Hibla\Dns\Configs\HostsFile;
use Hibla\Cache\ArrayCache;
use Hibla\Dns\Resolvers\Resolver;

$transport = new SelectiveTransportExecutor(
    new TimeoutExecutor(new UdpTransportExecutor('8.8.8.8'), 5.0),
    new TimeoutExecutor(new TcpTransportExecutor('8.8.8.8'), 5.0),
);

$executor = new CoopExecutor(
    new RetryExecutor($transport, retries: 2)
);

$executor = new CachingExecutor(new ArrayCache(256), $executor);

// HostsFileExecutor must wrap CachingExecutor, not the other way around,
// to ensure hosts file entries are never cached
$executor = new HostsFileExecutor(
    HostsFile::loadFromPathBlocking(),
    $executor
);

$resolver = new Resolver($executor);
```

---

## Exception Reference

All exceptions extend `Hibla\Dns\Exceptions\DnsException`, which extends
`\RuntimeException`. Catch the base class to handle any DNS error generically,
or catch specific types for granular handling.

| Exception | When it is thrown |
|---|---|
| `RecordNotFoundException` | Base class for all "no record found" conditions. Catch this if you do not need to distinguish between NXDOMAIN and NODATA |
| `NxDomainException` | The domain does not exist at all (NXDOMAIN / response code 3). Extends `RecordNotFoundException`. Retrying with a different record type will not help — the domain itself is absent |
| `NoDataException` | The domain exists but has no records of the requested type (NOERROR / NODATA). Extends `RecordNotFoundException`. The domain is alive — it simply has no records of the type you queried |
| `QueryFailedException` | A network or protocol error prevented the query from completing |
| `TimeoutException` | The query exceeded the configured timeout. Extends `QueryFailedException` |
| `ResponseTruncatedException` | The UDP response was truncated. Handled internally by `SelectiveTransportExecutor` — you will not normally see this unless using `UdpTransportExecutor` directly. Extends `QueryFailedException` |

The exception hierarchy:
```
DnsException
├── RecordNotFoundException
│   ├── NxDomainException   ← domain does not exist (NXDOMAIN)
│   └── NoDataException     ← domain exists, no records of this type (NODATA)
└── QueryFailedException
    ├── TimeoutException
    └── ResponseTruncatedException
```
```php
use Hibla\Dns\Dns;
use Hibla\Dns\Exceptions\NxDomainException;
use Hibla\Dns\Exceptions\NoDataException;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\Dns\Exceptions\TimeoutException;
use Hibla\Dns\Exceptions\QueryFailedException;
use Hibla\Dns\Exceptions\DnsException;

// Granular handling — distinguish NXDOMAIN from NODATA
try {
    $ip = Dns::resolve('example.com')->wait();
} catch (NxDomainException $e) {
    // Domain does not exist at all — retrying with a different record type
    // will not help
    echo "Domain does not exist: " . $e->getMessage() . "\n";
} catch (NoDataException $e) {
    // Domain exists but has no A records — may have AAAA or other types
    echo "No A records for this domain: " . $e->getMessage() . "\n";
} catch (TimeoutException $e) {
    echo "DNS server did not respond in time\n";
} catch (QueryFailedException $e) {
    echo "Network or protocol error: " . $e->getMessage() . "\n";
} catch (DnsException $e) {
    echo "DNS error: " . $e->getMessage() . "\n";
}

// Generic handling — treat all "no record" conditions the same way
try {
    $ip = Dns::resolve('example.com')->wait();
} catch (RecordNotFoundException $e) {
    // Catches both NxDomainException and NoDataException
    echo "Could not resolve: " . $e->getMessage() . "\n";
}
```

---

## Record Type Reference

The `RecordType` enum lists all supported DNS record types. Pass any of these to
`resolveAll()` as the second argument.

| Value | Name | Description | RFC |
|---|---|---|---|
| `1` | `A` | IPv4 address | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `2` | `NS` | Authoritative nameserver | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `5` | `CNAME` | Canonical name / alias | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `6` | `SOA` | Start of authority | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `12` | `PTR` | Pointer for reverse DNS | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `15` | `MX` | Mail exchange server | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `16` | `TXT` | Text records | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `28` | `AAAA` | IPv6 address | [RFC 3596](https://datatracker.ietf.org/doc/html/rfc3596) |
| `33` | `SRV` | Service locator | [RFC 2782](https://datatracker.ietf.org/doc/html/rfc2782) |
| `35` | `NAPTR` | Naming authority pointer | [RFC 2915](https://datatracker.ietf.org/doc/html/rfc2915) |
| `44` | `SSHFP` | SSH public key fingerprint | [RFC 4255](https://datatracker.ietf.org/doc/html/rfc4255) |
| `255` | `ANY` | Request for all records | [RFC 1035](https://datatracker.ietf.org/doc/html/rfc1035) |
| `257` | `CAA` | Certification Authority Authorization | [RFC 6844](https://datatracker.ietf.org/doc/html/rfc6844) |

---

## Development
```bash
git clone https://github.com/hiblaphp/dns.git
cd dns
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by [ReactPHP DNS](https://github.com/reactphp/dns).
  If you are familiar with ReactPHP's DNS resolver, the executor pipeline concept
  and the `resolve()`/`resolveAll()` interface will feel immediately familiar —
  with the addition of native promise cancellation and Fiber-aware async
  throughout.
- **Event Loop Integration:** Powered by
  [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Promise Integration:** Built on
  [hiblaphp/promise](https://github.com/hiblaphp/promise).
- **Stream Integration:** Built on
  [hiblaphp/stream](https://github.com/hiblaphp/stream).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.