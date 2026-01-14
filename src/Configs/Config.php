<?php

declare(strict_types=1);

namespace Hibla\Dns\Configs;

use RuntimeException;

final class Config
{
    /**
     * @param  list<string>  $nameservers  List of NameServer IPs (e.g. ['8.8.8.8', '1.1.1.1'])
     */
    public function __construct(
        public array $nameservers = []
    ) {
    }

    /**
     * Automatically detects and loads DNS nameserver configuration from the system.
     *
     * This method provides cross-platform support by detecting the operating system
     * and using the appropriate method to retrieve DNS nameservers:
     * - Windows: Uses WMIC (Windows Management Instrumentation Command-line)
     * - Linux/macOS: Parses /etc/resolv.conf
     *
     * If the system configuration cannot be loaded (e.g., file permissions, missing tools),
     * an empty configuration is returned rather than throwing an exception, allowing
     * the caller to fall back to default nameservers.
     *
     * @return self Configuration object containing system nameservers, or empty if unavailable
     */
    public static function loadSystemConfigBlocking(): self
    {
        // Windows Support
        if (DIRECTORY_SEPARATOR === '\\') {
            return self::loadWmicBlocking();
        }

        // Linux/macOS Support
        try {
            return self::loadResolvConfBlocking();
        } catch (RuntimeException) {
            // If file missing/unreadable, return empty config (factory will use default)
            return new self();
        }
    }

    /**
     * Loads DNS nameserver configuration from a resolv.conf file.
     *
     * This method parses a standard Unix/Linux resolv.conf file to extract nameserver
     * IP addresses. It handles various edge cases:
     * - Comments (# and ;) are stripped from each line
     * - IPv6 zone identifiers (e.g., fe80::1%lo0) are removed for compatibility
     * - IP addresses are normalized using inet_pton/inet_ntop for consistent formatting
     * - Invalid IP addresses are silently skipped
     *
     * This is a blocking I/O operation as it reads from the filesystem.
     *
     * @param string $path Path to the resolv.conf file (defaults to /etc/resolv.conf)
     * @return self Configuration object containing parsed nameservers
     * @throws RuntimeException If the file doesn't exist, is not readable, or cannot be read
     */
    public static function loadResolvConfBlocking(string $path = '/etc/resolv.conf'): self
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to load resolv.conf file \"$path\"");
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read resolv.conf file \"$path\"");
        }

        $nameservers = [];
        $lines = explode("\n", $contents);

        foreach ($lines as $line) {
            $cleanedLine = preg_replace('/[#;].*/', '', $line);
            $line = trim($cleanedLine ?? '');

            if (str_starts_with($line, 'nameserver')) {
                $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                if (isset($parts[1])) {
                    $ip = $parts[1];
                    // Remove IPv6 zone ID if present (e.g. fe80::1%lo0)
                    if (str_contains($ip, ':') && str_contains($ip, '%')) {
                        $zonePos = strpos($ip, '%');
                        if ($zonePos !== false) {
                            $ip = substr($ip, 0, $zonePos);
                        }
                    }

                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        $packed = @inet_pton($ip);
                        if ($packed !== false) {
                            $normalized = inet_ntop($packed);
                            if (\is_string($normalized)) {
                                $nameservers[] = $normalized;
                            }
                        } else {
                            $nameservers[] = $ip;
                        }
                    }
                }
            }
        }

        return new self($nameservers);
    }

    /**
     * Loads DNS nameserver configuration from Windows using WMIC.
     *
     * This method executes the Windows Management Instrumentation Command-line (WMIC)
     * tool to query network adapter DNS settings. The output is parsed using regex
     * to extract IP addresses from the DNSServerSearchOrder property.
     *
     * Key features:
     * - Returns empty configuration if shell_exec is disabled (safe mode, etc.)
     * - Extracts both IPv4 and IPv6 addresses from WMIC CSV output
     * - Normalizes IP addresses using inet_pton/inet_ntop for consistency
     * - Removes duplicate nameservers that may appear across multiple adapters
     *
     * This is a blocking operation as it executes an external command and waits
     * for its completion. This method is typically called once during application
     * initialization via loadSystemConfigBlocking(), where blocking is acceptable
     * and the configuration is cached for subsequent DNS queries.
     *
     * @return self Configuration object containing Windows DNS servers, or empty if unavailable
     */
    public static function loadWmicBlocking(): self
    {
        if (! function_exists('shell_exec')) {
            return new self();
        }

        // Execute wmic to get DNS servers
        $output = @shell_exec('wmic NICCONFIG get "DNSServerSearchOrder" /format:CSV');

        if ($output === null || $output === false) {
            return new self();
        }

        $nameservers = [];
        // Regex to extract IPs inside brackets {1.2.3.4; 8.8.8.8}
        preg_match_all('/(?<=[{;,"])([\da-f.:]{4,})(?=[};,"])/i', $output, $matches);

        foreach ($matches[1] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $packed = @inet_pton($ip);
                if ($packed !== false) {
                    $normalized = inet_ntop($packed);
                    if (\is_string($normalized)) {
                        $nameservers[] = $normalized;
                    }
                } else {
                    $nameservers[] = $ip;
                }
            }
        }

        // Unique values only
        return new self(array_values(array_unique($nameservers)));
    }
}
