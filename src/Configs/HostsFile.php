<?php

declare(strict_types=1);

namespace Hibla\Dns\Configs;

use RuntimeException;

final class HostsFile
{
    public function __construct(
        private readonly string $contents
    ) {
    }

    /**
     * Loads and parses a hosts file from the filesystem.
     *
     * This method automatically detects the correct hosts file location based on
     * the operating system:
     * - Windows: %SystemRoot%\system32\drivers\etc\hosts
     * - Linux/macOS: /etc/hosts
     *
     * If no path is provided, the system default is used. If the file doesn't exist
     * or is not readable, an empty hosts file is returned rather than throwing an
     * exception, allowing the DNS resolver to continue with network-based lookups.
     *
     * This is a blocking I/O operation as it reads from the filesystem. This method
     * is typically called once during application initialization, where blocking is
     * acceptable and the configuration is cached for subsequent lookups.
     *
     * @param string|null $path Custom path to hosts file, or null to use system default
     * @return self HostsFile instance containing the parsed file contents
     * @throws RuntimeException If the file exists but cannot be read (permissions, I/O error)
     */
    public static function loadFromPathBlocking(?string $path = null): self
    {
        if ($path === null) {
            $path = DIRECTORY_SEPARATOR === '\\'
                ? getenv('SystemRoot').'\\system32\\drivers\\etc\\hosts'
                : '/etc/hosts';
        }

        if (! file_exists($path) || ! is_readable($path)) {
            return new self('');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read hosts file \"$path\"");
        }

        return new self($contents);
    }

    /**
     * Returns all IP addresses mapped to the given hostname.
     *
     * This method performs a forward lookup by parsing the hosts file for entries
     * that match the specified hostname. The lookup is case-insensitive as per
     * RFC standards.
     *
     * Features:
     * - Comments (lines starting with #) are automatically ignored
     * - IPv6 zone identifiers (e.g., fe80::1%lo0) are stripped for compatibility
     * - IP addresses are normalized using inet_pton/inet_ntop for consistent formatting
     * - Multiple IPs can be returned if the hostname appears in multiple entries
     * - Invalid IP addresses are silently skipped
     *
     * Example hosts file entries:
     * ```
     * 127.0.0.1       localhost myapp.local
     * ::1             localhost
     * 192.168.1.100   myapp.local
     * ```
     *
     * Calling `getIpsForHost('myapp.local')` would return ['127.0.0.1', '192.168.1.100']
     *
     * @param string $host The hostname to look up (case-insensitive)
     * @return list<string> Array of normalized IP addresses, empty if hostname not found
     */
    public function getIpsForHost(string $host): array
    {
        $host = strtolower($host);
        $ips = [];

        $lines = preg_split('/\r?\n/', $this->contents);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $cleanedLine = preg_replace('/#.*/', '', $line);
            $parts = preg_split('/\s+/', trim($cleanedLine ?? ''), -1, PREG_SPLIT_NO_EMPTY);

            if ($parts === false || \count($parts) < 2) {
                continue;
            }

            $ip = array_shift($parts);

            // Strip IPv6 zone ID if present (e.g. fe80::1%lo0)
            if (str_contains($ip, ':') && ($pos = strpos($ip, '%')) !== false) {
                $ip = substr($ip, 0, $pos);
            }

            $packed = @inet_pton($ip);
            if ($packed === false) {
                continue;
            }

            // Normalize IPv6 addresses
            $normalizedIp = inet_ntop($packed);
            if ($normalizedIp === false) {
                continue;
            }

            foreach ($parts as $alias) {
                if (strtolower($alias) === $host) {
                    $ips[] = $normalizedIp;

                    break;
                }
            }
        }

        return $ips;
    }

    /**
     * Returns all hostnames mapped to the given IP address (reverse lookup).
     *
     * This method performs a reverse lookup by finding all hostname entries that
     * map to the specified IP address. The IP comparison uses binary representation
     * to handle different IPv6 formats correctly (e.g., "::1" vs "0:0:0:0:0:0:0:1").
     *
     * Features:
     * - Handles both IPv4 and IPv6 addresses
     * - Normalizes IPv6 addresses for accurate comparison across different formats
     * - IPv6 zone identifiers are stripped before comparison
     * - Returns all hostnames/aliases found for the IP
     * - Invalid IP addresses return an empty array
     *
     * Example hosts file entries:
     * ```
     * 127.0.0.1       localhost myapp.local dev.local
     * ::1             localhost ip6-localhost
     * ```
     *
     * Calling `getHostsForIp('127.0.0.1')` would return ['localhost', 'myapp.local', 'dev.local']
     *
     * @param string $ip The IP address to look up (IPv4 or IPv6)
     * @return list<string> Array of hostnames mapped to this IP, empty if IP not found
     */
    public function getHostsForIp(string $ip): array
    {
        // Convert input IP to binary for accurate comparison (handles IPv6 short/long forms)
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return [];
        }

        $names = [];
        $lines = preg_split('/\r?\n/', $this->contents);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $cleanedLine = preg_replace('/#.*/', '', $line);
            $parts = preg_split('/\s+/', trim($cleanedLine ?? ''), -1, PREG_SPLIT_NO_EMPTY);

            if ($parts === false || \count($parts) < 2) {
                continue;
            }

            $foundIp = array_shift($parts);

            if (str_contains($foundIp, ':') && ($pos = strpos($foundIp, '%')) !== false) {
                $foundIp = substr($foundIp, 0, $pos);
            }

            // Compare binary representations
            $packedFound = @inet_pton($foundIp);
            if ($packedFound === $packedIp) {
                foreach ($parts as $host) {
                    $names[] = $host;
                }
            }
        }

        return $names;
    }
}
