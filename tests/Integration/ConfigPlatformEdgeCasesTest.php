<?php

declare(strict_types=1);

use Hibla\Dns\Configs\Config;

describe('Windows WMIC Edge Cases', function () {

    it('handles empty WMIC output when no network adapters have DNS', function () {
        $config = Config::loadWmicBlocking();

        expect($config->nameservers)->toBeArray();
    });

    it('returns valid nameservers from actual WMIC command', function () {
        $config = Config::loadWmicBlocking();

        expect($config->nameservers)->toBeArray();

        foreach ($config->nameservers as $ns) {
            expect(filter_var($ns, FILTER_VALIDATE_IP))->not->toBeFalse();
        }
    });

    it('deduplicates nameservers from real WMIC output', function () {
        $config = Config::loadWmicBlocking();

        $unique = array_unique($config->nameservers);
        expect(count($config->nameservers))->toBe(count($unique));
    });

    it('validates IPv4 addresses from WMIC', function () {
        $config = Config::loadWmicBlocking();

        $ipv4Pattern = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';
        $ipv4Servers = array_filter($config->nameservers, fn ($ns) => preg_match($ipv4Pattern, $ns));

        foreach ($ipv4Servers as $ns) {
            expect(filter_var($ns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                ->not->toBeFalse()
            ;
        }
    })->skip(function () {
        $config = Config::loadWmicBlocking();
        $ipv4Pattern = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';
        $ipv4Servers = array_filter($config->nameservers, fn ($ns) => preg_match($ipv4Pattern, $ns));

        return empty($ipv4Servers);
    }, 'No IPv4 nameservers configured on this system');

    it('validates IPv6 addresses from WMIC if present', function () {
        $config = Config::loadWmicBlocking();

        $ipv6Servers = array_filter($config->nameservers, fn ($ns) => str_contains($ns, ':'));

        foreach ($ipv6Servers as $ns) {
            expect(filter_var($ns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
                ->not->toBeFalse()
            ;
        }
    })->skip(function () {
        $config = Config::loadWmicBlocking();
        $ipv6Servers = array_filter($config->nameservers, fn ($ns) => str_contains($ns, ':'));

        return empty($ipv6Servers);
    }, 'No IPv6 nameservers configured on this system');

    it('returns consistent results on multiple calls', function () {
        $config1 = Config::loadWmicBlocking();
        $config2 = Config::loadWmicBlocking();

        expect($config1->nameservers)->toBe($config2->nameservers);
    });

    it('integrates with loadSystemConfigBlocking on Windows', function () {
        $config = Config::loadSystemConfigBlocking();

        expect($config)->toBeInstanceOf(Config::class);
        expect($config->nameservers)->toBeArray();

        foreach ($config->nameservers as $ns) {
            expect(filter_var($ns, FILTER_VALIDATE_IP))->not->toBeFalse();
        }
    });
})->skipOnMac()->skipOnLinux()->skipOnCI();

describe('Unix resolv.conf Integration Tests', function () {

    it('loads actual system DNS configuration', function () {
        $config = Config::loadSystemConfigBlocking();

        expect($config)->toBeInstanceOf(Config::class);
        expect($config->nameservers)->toBeArray();
    });

    it('reads from /etc/resolv.conf if it exists', function () {
        if (! file_exists('/etc/resolv.conf')) {
            $this->markTestSkipped('/etc/resolv.conf not found');
        }

        $config = Config::loadResolvConfBlocking();

        expect($config->nameservers)->toBeArray();

        foreach ($config->nameservers as $ns) {
            expect(filter_var($ns, FILTER_VALIDATE_IP))->not->toBeFalse();
        }
    });

    it('returns consistent results on multiple reads', function () {
        if (! file_exists('/etc/resolv.conf')) {
            $this->markTestSkipped('/etc/resolv.conf not found');
        }

        $config1 = Config::loadResolvConfBlocking();
        $config2 = Config::loadResolvConfBlocking();

        expect($config1->nameservers)->toBe($config2->nameservers);
    });
})->skipOnWindows()->skipOnCI();

describe('Platform-Specific Integration Tests', function () {

    describe('Windows', function () {

        it('uses WMIC correctly', function () {
            $config = Config::loadWmicBlocking();
            expect($config->nameservers)->toBeArray();
        });
    })->skipOnMac()->skipOnLinux();

    describe('Unix', function () {

        it('uses resolv.conf correctly', function () {
            $config = Config::loadResolvConfBlocking();
            expect($config->nameservers)->toBeArray();
        });
    })->skipOnWindows();
})->skipOnCI();
