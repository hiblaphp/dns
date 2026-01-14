<?php

declare(strict_types=1);

use Hibla\Dns\Configs\HostsFile;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Queries\HostsFileExecutor;
use Tests\Helpers\MockExecutor;

describe('HostsFileExecutor', function () {
    $hostsContent = <<<'EOT'
    127.0.0.1       localhost
    127.0.0.2       localhost
    ::1             localhost ip6-localhost
    192.168.1.10    dev.local
    2001:db8::5     ipv6.dev.local
    2001:db8::1     expanded.ipv6.local
    # This is a comment
    # 192.168.1.99  commented.local
    192.168.1.20    multi.alias.local alias1.local alias2.local
    EOT;

    $hostsFile = new HostsFile($hostsContent);

    it('returns A records from hosts file without hitting network', function () use ($hostsFile) {
        $fallback = new MockExecutor(); // Should NOT be called
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();

        $message = $promise->getValue();
        expect($message->answers)->toHaveCount(2);

        $ips = array_map(fn ($r) => $r->data, $message->answers);
        expect($ips)->toContain('127.0.0.1');
        expect($ips)->toContain('127.0.0.2');

        expect($fallback->wasCalled)->toBeFalse();
    });

    it('returns AAAA records from hosts file', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::AAAA, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();

        $message = $promise->getValue();
        expect($message->answers[0]->data)->toBe('::1');

        expect($fallback->wasCalled)->toBeFalse();
    });

    it('falls back to network if host is not in file', function () use ($hostsFile) {
        $fallback = new MockExecutor(); // Should be called
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('google.com', RecordType::A, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('falls back to network if record type is not supported by hosts file (e.g. MX)', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::MX, RecordClass::IN);
        $promise = $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('falls back to network if IP version mismatch (A query for IPv6-only host)', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('ipv6.dev.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles Reverse DNS (PTR) for IPv4', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('10.1.168.192.in-addr.arpa', RecordType::PTR, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('dev.local');
        expect($fallback->wasCalled)->toBeFalse();
    });

    it('handles Reverse DNS (PTR) for IPv6', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $arpa = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa';

        $query = new Query($arpa, RecordType::PTR, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        $names = array_map(fn ($r) => $r->data, $promise->getValue()->answers);
        expect($names)->toContain('localhost');

        expect($fallback->wasCalled)->toBeFalse();
    });

    it('falls back for invalid PTR queries', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('999.888.777.in-addr.arpa', RecordType::PTR, RecordClass::IN);
        $promise = $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('returns all IPs for a host defined multiple times', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        $message = $promise->getValue();
        // Should find 127.0.0.1 AND 127.0.0.2
        expect($message->answers)->toHaveCount(2);

        $ips = array_map(fn ($r) => $r->data, $message->answers);
        expect($ips)->toContain('127.0.0.1');
        expect($ips)->toContain('127.0.0.2');
    });

    it('matches hostnames case-insensitively', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('DEV.LOCAL', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.10');
    });

    it('ignores queries with non-IN class', function () use ($hostsFile) {
        $fallback = new MockExecutor(); // Should be called
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::A, RecordClass::CH);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles IPv6 PTR lookups with full nibble expansion', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        // The hosts file has "2001:db8::1"
        // The query comes in as full expanded nibbles reversed
        // 2001:0db8:0000:0000:0000:0000:0000:0001
        // Reversed: 1.0.0.0...8.b.d.0.1.0.0.2.ip6.arpa

        $arpa = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';

        $query = new Query($arpa, RecordType::PTR, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('expanded.ipv6.local');
    });

    it('propagates cancellation to fallback executor', function () use ($hostsFile) {
        $fallback = new MockExecutor(shouldHang: true);
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('google.com', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        $promise->cancel();

        expect($fallback->wasCancelled)->toBeTrue();
    });

    it('handles malformed PTR domains gracefully', function () use ($hostsFile) {
        $fallback = new MockExecutor(); // Should be called
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        // Malformed hex in ip6.arpa
        $query = new Query('z.z.z.z.ip6.arpa', RecordType::PTR, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });
});

describe('HostsFileExecutor - Edge Cases', function () {
    $hostsContent = <<<'EOT'
    127.0.0.1       localhost
    127.0.0.2       localhost
    ::1             localhost ip6-localhost
    192.168.1.10    dev.local
    2001:db8::5     ipv6.dev.local
    2001:db8::1     expanded.ipv6.local
    # This is a comment
    # 192.168.1.99  commented.local
    192.168.1.20    multi.alias.local alias1.local alias2.local
    EOT;

    $hostsFile = new HostsFile($hostsContent);

    it('handles empty hostname query', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('', RecordType::A, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles hostname with only whitespace', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('   ', RecordType::A, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles hostname with trailing dot (FQDN format)', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        // DNS allows trailing dots: "localhost." is same as "localhost"
        $query = new Query('localhost.', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
    });

    it('matches mixed case hostnames', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('DeV.LoCaL', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.10');
    });

    it('resolves hosts with multiple aliases', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('alias1.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.20');
    });

    it('resolves second alias correctly', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('alias2.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.20');
    });

    it('handles compressed IPv6 addresses (::)', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::AAAA, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('::1');
    });

    it('handles IPv6 with different compression formats', function () {
        $hostsContent = <<<'EOT'
        2001:db8:0:0:0:0:0:1    full.ipv6.local
        2001:db8::1             compressed.ipv6.local
        EOT;

        $hostsFile = new HostsFile($hostsContent);
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('compressed.ipv6.local', RecordType::AAAA, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('2001:db8::1');
    });

    it('handles PTR query for localhost (127.0.0.1)', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('1.0.0.127.in-addr.arpa', RecordType::PTR, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        $hostnames = array_map(fn ($r) => $r->data, $promise->getValue()->answers);
        expect($hostnames)->toContain('localhost');
    });

    it('handles PTR with uppercase hex digits in IPv6', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $arpa = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.B.D.0.1.0.0.2.ip6.arpa';
        $query = new Query($arpa, RecordType::PTR, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
    });

    it('handles PTR query with too few octets', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        // Invalid: only 3 octets instead of 4
        $query = new Query('1.168.192.in-addr.arpa', RecordType::PTR, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles PTR query with too many octets', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        // Invalid: 5 octets
        $query = new Query('1.2.3.4.5.in-addr.arpa', RecordType::PTR, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles IPv6 PTR with incorrect nibble count', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        // Too short: missing nibbles
        $arpa = '1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $query = new Query($arpa, RecordType::PTR, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles loopback range IPs', function () {
        $hostsContent = '127.0.0.2    loopback2.local';
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('loopback2.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('127.0.0.2');
    });

    it('handles private network IPs (10.x.x.x)', function () {
        $hostsContent = '10.0.0.1    internal.local';
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('internal.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('10.0.0.1');
    });

    it('handles link-local IPv6 addresses', function () {
        $hostsContent = 'fe80::1    linklocal.local';
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('linklocal.local', RecordType::AAAA, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('fe80::1');
    });

    it('sets correct flags in response message', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        $message = $promise->getValue();
        expect($message->isResponse)->toBeTrue();
        expect($message->isAuthoritative)->toBeTrue();
        expect($message->recursionAvailable)->toBeTrue();
        expect($message->responseCode)->toBe(ResponseCode::OK);
    });

    it('includes query in response message', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        $message = $promise->getValue();
        expect($message->questions)->toHaveCount(1);
        expect($message->questions[0]->name)->toBe('localhost');
        expect($message->questions[0]->type)->toBe(RecordType::A);
    });

    it('sets TTL to 0 for hosts file entries', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        $message = $promise->getValue();
        foreach ($message->answers as $answer) {
            expect($answer->ttl)->toBe(0);
        }
    });

    it('falls back for NS record type', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::NS, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('falls back for SOA record type', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('localhost', RecordType::SOA, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('falls back for SRV record type', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('_http._tcp.localhost', RecordType::SRV, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles punycode domains if supported', function () {
        $hostsContent = '192.168.1.100    xn--n3h.local';
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('xn--n3h.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.100');
    });

    it('does not match subdomains wildcard-style', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('sub.dev.local', RecordType::A, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('does not match parent domain', function () use ($hostsFile) {
        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('local', RecordType::A, RecordClass::IN);
        $executor->query($query);

        expect($fallback->wasCalled)->toBeTrue();
    });

    it('handles very long hostnames', function () {
        $longHostname = str_repeat('a', 63).'.local';
        $hostsContent = "192.168.1.200    $longHostname";
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query($longHostname, RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.200');
    });

    it('handles numeric-only hostnames', function () {
        $hostsContent = '192.168.1.250    12345';
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('12345', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.250');
    });

    it('handles hostnames with hyphens', function () {
        $hostsContent = '192.168.1.150    my-host-name.local';
        $hostsFile = new HostsFile($hostsContent);

        $fallback = new MockExecutor();
        $executor = new HostsFileExecutor($hostsFile, $fallback);

        $query = new Query('my-host-name.local', RecordType::A, RecordClass::IN);
        $promise = $executor->query($query);

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->getValue()->answers[0]->data)->toBe('192.168.1.150');
    });
});
