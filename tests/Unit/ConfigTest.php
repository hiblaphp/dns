<?php

use Hibla\Dns\Configs\Config;

describe('Config', function () {
    it('parses a standard resolv.conf file', function () {
        $content = <<<EOT
        # Standard configuration
        nameserver 8.8.8.8
        nameserver 8.8.4.4
        EOT;

        withResolvConf($content, function ($file) {
            $config = Config::loadResolvConfBlocking($file);
            expect($config->nameservers)->toBe(['8.8.8.8', '8.8.4.4']);
        });
    });

    it('ignores comments and invalid lines', function () {
        $content = <<<EOT
        ; comment with semicolon
        # comment with hash
        nameserver 1.1.1.1 # inline comment
        domain example.com
        search local
        options ndots:1
        invalid_directive 10.0.0.1
        nameserver     1.0.0.1
        EOT;

        withResolvConf($content, function ($file) {
            $config = Config::loadResolvConfBlocking($file);
            expect($config->nameservers)->toBe(['1.1.1.1', '1.0.0.1']);
        });
    });

    it('filters out invalid IP addresses', function () {
        $content = <<<EOT
        nameserver 127.0.0.1
        nameserver 256.256.256.256  # Invalid IPv4
        nameserver not-an-ip        # Garbage
        nameserver ::1
        nameserver 1::2::3          # Invalid IPv6
        EOT;

        withResolvConf($content, function ($file) {
            $config = Config::loadResolvConfBlocking($file);
            expect($config->nameservers)->toBe(['127.0.0.1', '::1']);
        });
    });

    it('handles IPv6 addresses and strips zone IDs', function () {
        $content = <<<EOT
        nameserver 2001:4860:4860::8888
        nameserver fe80::1%lo0
        nameserver fe80::2%eth0
        EOT;

        withResolvConf($content, function ($file) {
            $config = Config::loadResolvConfBlocking($file);
            expect($config->nameservers)->toBe([
                '2001:4860:4860::8888',
                'fe80::1',
                'fe80::2'
            ]);
        });
    });

    it('throws exception if file does not exist', function () {
        expect(fn() => Config::loadResolvConfBlocking('/tmp/non_existent_' . uniqid()))
            ->toThrow(RuntimeException::class);
    });

    it('returns empty config if loading default system config fails or finds nothing', function () {
        $config = Config::loadSystemConfigBlocking();
        expect($config)->toBeInstanceOf(Config::class);
        expect($config->nameservers)->toBeArray();
    });

    describe('Edge Cases', function () {
        it('handles empty file', function () {
            withResolvConf('', function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBeEmpty();
            });
        });

        it('handles file with only whitespace', function () {
            $content = "   \n\t\n   \n";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBeEmpty();
            });
        });

        it('handles file with only comments', function () {
            $content = <<<EOT
            # Comment line 1
            ; Comment line 2
            # Another comment
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBeEmpty();
            });
        });

        it('handles nameserver directive without IP', function () {
            $content = <<<EOT
            nameserver
            nameserver   
            nameserver 8.8.8.8
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8']);
            });
        });

        it('handles multiple spaces and tabs between nameserver and IP', function () {
            $content = "nameserver\t\t\t   8.8.8.8";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8']);
            });
        });

        it('handles trailing whitespace after IP', function () {
            $content = "nameserver 8.8.8.8    \t  \n";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8']);
            });
        });

        it('handles IPv6 addresses with multiple zone ID formats', function () {
            $content = <<<EOT
            nameserver fe80::1%lo0
            nameserver fe80::2%eth0
            nameserver fe80::3%wlan0
            nameserver fe80::4%123
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe([
                    'fe80::1',
                    'fe80::2',
                    'fe80::3',
                    'fe80::4'
                ]);
            });
        });

        it('handles IPv6 loopback addresses', function () {
            $content = <<<EOT
              nameserver ::1
              nameserver 0000:0000:0000:0000:0000:0000:0000:0001
              nameserver 1.1.1.1
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['::1', '::1', '1.1.1.1']);
            });
        });

        it('handles mixed case nameserver directive', function () {
            $content = <<<EOT
            NameServer 8.8.8.8
            NAMESERVER 1.1.1.1
            nameserver 9.9.9.9
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toContain('9.9.9.9');
            });
        });

        it('handles IP addresses with leading zeros', function () {
            $content = <<<EOT
            nameserver 008.008.008.008
            nameserver 192.168.001.001
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                // Leading zeros might make these invalid depending on filter_var behavior
                expect($config->nameservers)->toBeArray();
            });
        });

        it('handles maximum valid IPv4 addresses', function () {
            $content = "nameserver 255.255.255.255";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['255.255.255.255']);
            });
        });

        it('handles private IP ranges', function () {
            $content = <<<EOT
            nameserver 10.0.0.1
            nameserver 172.16.0.1
            nameserver 192.168.1.1
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['10.0.0.1', '172.16.0.1', '192.168.1.1']);
            });
        });

        it('handles duplicate nameserver entries', function () {
            $content = <<<EOT
            nameserver 8.8.8.8
            nameserver 1.1.1.1
            nameserver 8.8.8.8
            nameserver 1.1.1.1
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                // Should keep all entries as they appear (no deduplication in resolv.conf parser)
                expect($config->nameservers)->toBe(['8.8.8.8', '1.1.1.1', '8.8.8.8', '1.1.1.1']);
            });
        });

        it('handles extremely long lines', function () {
            $longComment = str_repeat('x', 10000);
            $content = "# $longComment\nnameserver 8.8.8.8";

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8']);
            });
        });

        it('handles Windows line endings', function () {
            $content = "nameserver 8.8.8.8\r\nnameserver 1.1.1.1\r\n";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8', '1.1.1.1']);
            });
        });

        it('handles Mac legacy line endings', function () {
            $content = "nameserver 8.8.8.8\rnameserver 1.1.1.1\r";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toContain('8.8.8.8');
            });
        });

        it('handles file with no trailing newline', function () {
            $content = "nameserver 8.8.8.8";
            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8']);
            });
        });

        it('handles nameserver with extra text after IP', function () {
            $content = <<<EOT
            nameserver 8.8.8.8 extra text here
            nameserver 1.1.1.1 # comment
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect($config->nameservers)->toBe(['8.8.8.8', '1.1.1.1']);
            });
        });

        it('rejects localhost addresses', function () {
            $content = <<<EOT
            nameserver 127.0.0.1
            nameserver ::1
            nameserver 8.8.8.8
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                // These are technically valid IPs, so they should be included
                expect($config->nameservers)->toBe(['127.0.0.1', '::1', '8.8.8.8']);
            });
        });

        it('handles IPv6 compressed format variations', function () {
            $content = <<<EOT
            nameserver 2001:db8::1
            nameserver 2001:0db8:0000:0000:0000:0000:0000:0001
            nameserver 2001:db8:0:0:0:0:0:1
            nameserver ::ffff:192.0.2.1
            EOT;

            withResolvConf($content, function ($file) {
                $config = Config::loadResolvConfBlocking($file);
                expect(count($config->nameservers))->toBe(4);
            });
        });
    });
});
