<?php

declare(strict_types=1);

use Hibla\Dns\Configs\HostsFile;

describe('HostsFile', function () {

    describe('getIpsForHost (Forward Lookup)', function () {
        it('returns IPs for standard entries', function () {
            $content = '127.0.0.1 localhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1']);
        });

        it('supports multiple aliases per line', function () {
            $content = '127.0.0.1 localhost myapp.local api.local';
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('myapp.local'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('api.local'))->toBe(['127.0.0.1']);
        });

        it('is case insensitive', function () {
            $content = '127.0.0.1 LocalHost';
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('LOCALHOST'))->toBe(['127.0.0.1']);
        });

        it('strips IPv6 zone IDs', function () {
            $content = 'fe80::1%lo0 localhost';
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('localhost'))->toBe(['fe80::1']);
        });

        it('ignores invalid IPs in the file', function () {
            $content = '999.999.999.999 localhost';
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('localhost'))->toBeEmpty();
        });

        it('handles tab separation and extra whitespace', function () {
            $content = "127.0.0.1\t\tlocalhost    \t   app";
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('app'))->toBe(['127.0.0.1']);
        });
    });

    describe('getHostsForIp (Reverse Lookup)', function () {
        it('returns hosts for exact IP match', function () {
            $content = '10.0.0.1 server.local';
            $hosts = new HostsFile($content);

            expect($hosts->getHostsForIp('10.0.0.1'))->toBe(['server.local']);
        });

        it('matches IPv6 using binary comparison (Long vs Short form)', function () {
            $content = '2001:0db8:0000:0000:0000:0000:0000:0001 myhost';
            $hosts = new HostsFile($content);

            expect($hosts->getHostsForIp('2001:db8::1'))->toBe(['myhost']);
        });

        it('matches IPv6 using binary comparison (Short vs Long form)', function () {
            $content = '2001:db8::1 myhost';
            $hosts = new HostsFile($content);

            expect($hosts->getHostsForIp('2001:0db8:0000:0000:0000:0000:0000:0001'))->toBe(['myhost']);
        });

        it('matches IPv6 with Zone IDs', function () {
            $content = 'fe80::1%eth0 router';
            $hosts = new HostsFile($content);

            expect($hosts->getHostsForIp('fe80::1'))->toBe(['router']);
        });

        it('returns multiple hosts for one IP', function () {
            $content = '127.0.0.1 localhost myapp';
            $hosts = new HostsFile($content);

            expect($hosts->getHostsForIp('127.0.0.1'))->toBe(['localhost', 'myapp']);
        });

        it('returns empty for unknown IP', function () {
            $hosts = new HostsFile('127.0.0.1 localhost');
            expect($hosts->getHostsForIp('192.168.1.1'))->toBeEmpty();
        });

        it('returns empty for invalid IP input', function () {
            $hosts = new HostsFile('127.0.0.1 localhost');
            expect($hosts->getHostsForIp('invalid-ip'))->toBeEmpty();
        });
    });

    it('loads from file path correctly', function () {
        $file = sys_get_temp_dir().'/hosts_'.uniqid();
        file_put_contents($file, '192.168.1.50 db-prod');

        try {
            $hosts = HostsFile::loadFromPathBlocking($file);
            expect($hosts->getIpsForHost('db-prod'))->toBe(['192.168.1.50']);
        } finally {
            unlink($file);
        }
    });

    it('returns empty container if file does not exist', function () {
        $hosts = HostsFile::loadFromPathBlocking('/tmp/non_existent_hosts_'.uniqid());
        expect($hosts)->toBeInstanceOf(HostsFile::class);
        expect($hosts->getIpsForHost('localhost'))->toBeEmpty();
    });

    describe('Edge Cases - Forward Lookup', function () {
        it('handles empty file', function () {
            $hosts = new HostsFile('');
            expect($hosts->getIpsForHost('localhost'))->toBeEmpty();
        });

        it('handles file with only comments', function () {
            $content = <<<'EOT'
            # Comment 1
            # Comment 2
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBeEmpty();
        });

        it('handles file with only whitespace', function () {
            $hosts = new HostsFile("   \n\t\n   ");
            expect($hosts->getIpsForHost('anything'))->toBeEmpty();
        });

        it('handles entries with inline comments', function () {
            $content = '127.0.0.1 localhost # This is localhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('this'))->toBeEmpty();
        });

        it('handles multiple IPs for same hostname', function () {
            $content = <<<'EOT'
            127.0.0.1 localhost
            ::1 localhost
            192.168.1.1 localhost
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1', '::1', '192.168.1.1']);
        });

        it('handles hostname-only lines (malformed)', function () {
            $content = <<<'EOT'
            localhost
            127.0.0.1 valid
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBeEmpty();
            expect($hosts->getIpsForHost('valid'))->toBe(['127.0.0.1']);
        });

        it('handles IP-only lines (malformed)', function () {
            $content = '127.0.0.1';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('anything'))->toBeEmpty();
        });

        it('handles very long hostname lists', function () {
            $aliases = implode(' ', array_map(fn ($i) => "alias$i", range(1, 100)));
            $content = "127.0.0.1 $aliases";
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('alias1'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('alias100'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('alias50'))->toBe(['127.0.0.1']);
        });

        it('handles hostnames with special characters', function () {
            $content = <<<'EOT'
            127.0.0.1 my-app.local
            127.0.0.1 app_test.local
            127.0.0.1 app123.local
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('my-app.local'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('app_test.local'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('app123.local'))->toBe(['127.0.0.1']);
        });

        it('handles mixed case hostnames consistently', function () {
            $content = '127.0.0.1 MyApp.LOCAL';
            $hosts = new HostsFile($content);

            expect($hosts->getIpsForHost('myapp.local'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('MYAPP.LOCAL'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('MyApp.Local'))->toBe(['127.0.0.1']);
        });

        it('handles IPv6 full notation', function () {
            $content = '2001:0db8:0000:0000:0000:0000:0000:0001 myhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('myhost'))->toBe(['2001:db8::1']);
        });

        it('handles IPv6 with multiple consecutive zero groups', function () {
            $content = '2001:db8:0:0:0:0:0:1 myhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('myhost'))->toBe(['2001:db8::1']);
        });

        it('handles IPv4-mapped IPv6 addresses', function () {
            $content = '::ffff:192.0.2.1 ipv4mapped';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('ipv4mapped'))->toBe(['::ffff:192.0.2.1']);
        });

        it('handles link-local IPv6 with various zone IDs', function () {
            $content = <<<'EOT'
            fe80::1%lo0 router1
            fe80::2%eth0 router2
            fe80::3%12 router3
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('router1'))->toBe(['fe80::1']);
            expect($hosts->getIpsForHost('router2'))->toBe(['fe80::2']);
            expect($hosts->getIpsForHost('router3'))->toBe(['fe80::3']);
        });

        it('handles Windows line endings', function () {
            $content = "127.0.0.1 localhost\r\n192.168.1.1 router\r\n";
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1']);
            expect($hosts->getIpsForHost('router'))->toBe(['192.168.1.1']);
        });

        it('handles Mac legacy line endings', function () {
            $content = "127.0.0.1 localhost\r192.168.1.1 router";
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1']);
        });

        it('handles missing trailing newline', function () {
            $content = '127.0.0.1 localhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1']);
        });

        it('handles duplicate entries', function () {
            $content = <<<'EOT'
            127.0.0.1 localhost
            127.0.0.1 localhost
            192.168.1.1 localhost
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('localhost'))->toBe(['127.0.0.1', '127.0.0.1', '192.168.1.1']);
        });

        it('handles entries with no aliases after IP', function () {
            $content = <<<'EOT'
            127.0.0.1
            192.168.1.1 router
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('router'))->toBe(['192.168.1.1']);
        });

        it('rejects completely invalid IPs', function () {
            $content = <<<'EOT'
            not-an-ip hostname
            999.999.999.999 badhost
            gggg::hhhh invalidipv6
            127.0.0.1 validhost
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('hostname'))->toBeEmpty();
            expect($hosts->getIpsForHost('badhost'))->toBeEmpty();
            expect($hosts->getIpsForHost('invalidipv6'))->toBeEmpty();
            expect($hosts->getIpsForHost('validhost'))->toBe(['127.0.0.1']);
        });

        it('handles extremely long lines', function () {
            $longAlias = str_repeat('a', 1000).'.com';
            $content = "127.0.0.1 $longAlias";
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost($longAlias))->toBe(['127.0.0.1']);
        });

        it('handles empty hostname queries', function () {
            $content = '127.0.0.1 localhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost(''))->toBeEmpty();
        });

        it('handles whitespace-only hostname queries', function () {
            $content = '127.0.0.1 localhost';
            $hosts = new HostsFile($content);
            expect($hosts->getIpsForHost('   '))->toBeEmpty();
        });
    });

    describe('Edge Cases - Reverse Lookup', function () {
        it('handles empty file for reverse lookup', function () {
            $hosts = new HostsFile('');
            expect($hosts->getHostsForIp('127.0.0.1'))->toBeEmpty();
        });

        it('handles IPv6 full vs compressed notation matching', function () {
            $content = '2001:0db8:0000:0000:0000:0000:0000:0001 fullhost';
            $hosts = new HostsFile($content);

            // Should match both ways
            expect($hosts->getHostsForIp('2001:db8::1'))->toBe(['fullhost']);
            expect($hosts->getHostsForIp('2001:0db8:0000:0000:0000:0000:0000:0001'))->toBe(['fullhost']);
        });

        it('handles IPv6 loopback variations', function () {
            $content = '::1 localhost6';
            $hosts = new HostsFile($content);

            expect($hosts->getHostsForIp('::1'))->toBe(['localhost6']);
            expect($hosts->getHostsForIp('0000:0000:0000:0000:0000:0000:0000:0001'))->toBe(['localhost6']);
        });

        it('handles case sensitivity in reverse lookup hostnames', function () {
            $content = '127.0.0.1 LocalHost MyApp';
            $hosts = new HostsFile($content);

            // Returns as-is from file, no case normalization
            expect($hosts->getHostsForIp('127.0.0.1'))->toBe(['LocalHost', 'MyApp']);
        });

        it('handles multiple entries for same IP across lines', function () {
            $content = <<<'EOT'
            127.0.0.1 localhost
            127.0.0.1 myapp
            127.0.0.1 another
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getHostsForIp('127.0.0.1'))->toBe(['localhost', 'myapp', 'another']);
        });

        it('handles zone IDs in reverse lookup', function () {
            $content = <<<'EOT'
            fe80::1%lo0 router1
            fe80::2%eth0 router2
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getHostsForIp('fe80::1'))->toBe(['router1']);
            expect($hosts->getHostsForIp('fe80::1%lo0'))->toBeEmpty(); // Query includes zone ID
        });

        it('handles private IP ranges in reverse lookup', function () {
            $content = <<<'EOT'
            10.0.0.1 server1
            172.16.0.1 server2
            192.168.1.1 router
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getHostsForIp('10.0.0.1'))->toBe(['server1']);
            expect($hosts->getHostsForIp('172.16.0.1'))->toBe(['server2']);
            expect($hosts->getHostsForIp('192.168.1.1'))->toBe(['router']);
        });

        it('handles empty IP query', function () {
            $hosts = new HostsFile('127.0.0.1 localhost');
            expect($hosts->getHostsForIp(''))->toBeEmpty();
        });

        it('handles whitespace-only IP query', function () {
            $hosts = new HostsFile('127.0.0.1 localhost');
            expect($hosts->getHostsForIp('   '))->toBeEmpty();
        });

        it('handles malformed IPv4 addresses', function () {
            $hosts = new HostsFile('127.0.0.1 localhost');

            expect($hosts->getHostsForIp('256.256.256.256'))->toBeEmpty();
            expect($hosts->getHostsForIp('192.168.1'))->toBeEmpty();
            expect($hosts->getHostsForIp('192.168.1.1.1'))->toBeEmpty();
        });

        it('handles malformed IPv6 addresses', function () {
            $hosts = new HostsFile('::1 localhost');

            expect($hosts->getHostsForIp('gggg::1'))->toBeEmpty();
            expect($hosts->getHostsForIp(':::'))->toBeEmpty();
            expect($hosts->getHostsForIp('1::2::3'))->toBeEmpty();
        });

        it('handles IPv4-mapped IPv6 in reverse lookup', function () {
            $content = '::ffff:192.0.2.1 mappedhost';
            $hosts = new HostsFile($content);
            expect($hosts->getHostsForIp('::ffff:192.0.2.1'))->toBe(['mappedhost']);
        });

        it('differentiates between similar IPs', function () {
            $content = <<<'EOT'
            192.168.1.1 router1
            192.168.1.10 router2
            192.168.1.100 router3
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getHostsForIp('192.168.1.1'))->toBe(['router1']);
            expect($hosts->getHostsForIp('192.168.1.10'))->toBe(['router2']);
            expect($hosts->getHostsForIp('192.168.1.100'))->toBe(['router3']);
        });

        it('handles broadcast and network addresses', function () {
            $content = <<<'EOT'
            0.0.0.0 default
            255.255.255.255 broadcast
            EOT;

            $hosts = new HostsFile($content);
            expect($hosts->getHostsForIp('0.0.0.0'))->toBe(['default']);
            expect($hosts->getHostsForIp('255.255.255.255'))->toBe(['broadcast']);
        });
    });

    describe('File Loading Edge Cases', function () {
        it('returns empty HostsFile for non-existent path', function () {
            $hosts = HostsFile::loadFromPathBlocking('/nonexistent/path/hosts');
            expect($hosts)->toBeInstanceOf(HostsFile::class);
            expect($hosts->getIpsForHost('anything'))->toBeEmpty();
        });

        it('loads from custom path successfully', function () {
            $file = sys_get_temp_dir().'/custom_hosts_'.uniqid();
            file_put_contents($file, '10.0.0.1 customhost');

            try {
                $hosts = HostsFile::loadFromPathBlocking($file);
                expect($hosts->getIpsForHost('customhost'))->toBe(['10.0.0.1']);
            } finally {
                unlink($file);
            }
        });

        it('handles large hosts files', function () {
            $lines = [];

            for ($i = 0; $i < 1000; $i++) {
                $subnet = 1 + floor($i / 254);
                $host = 1 + ($i % 254);
                $lines[] = "192.168.$subnet.$host host$i";
            }

            $content = implode("\n", $lines);

            $file = sys_get_temp_dir().'/large_hosts_'.uniqid();
            file_put_contents($file, $content);

            try {
                $hosts = HostsFile::loadFromPathBlocking($file);

                expect($hosts->getIpsForHost('host0'))->toBe(['192.168.1.1']);
                expect($hosts->getIpsForHost('host254'))->toBe(['192.168.2.1']);
                expect($hosts->getIpsForHost('host999'))->toBe(['192.168.4.238']);
            } finally {
                unlink($file);
            }
        });
    });
});
