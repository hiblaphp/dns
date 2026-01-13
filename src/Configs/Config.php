<?php

declare(strict_types=1);

namespace Hibla\Dns\Configs;

use RuntimeException;

final class Config
{
    /**
     * @param list<string> $nameservers List of NameServer IPs (e.g. ['8.8.8.8', '1.1.1.1'])
     */
    public function __construct(
        public array $nameservers = []
    ) {}

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

    public static function loadResolvConfBlocking(string $path = '/etc/resolv.conf'): self
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new RuntimeException("Unable to load resolv.conf file \"$path\"");
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read resolv.conf file \"$path\"");
        }

        $nameservers = [];
        $lines = explode("\n", $contents);

        foreach ($lines as $line) {
            $line = trim(preg_replace('/[#;].*/', '', $line));

            if (str_starts_with($line, 'nameserver')) {
                $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                if (isset($parts[1])) {
                    $ip = $parts[1];
                    // Remove IPv6 zone ID if present (e.g. fe80::1%lo0)
                    if (str_contains($ip, ':') && str_contains($ip, '%')) {
                        $ip = substr($ip, 0, strpos($ip, '%'));
                    }

                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        // Normalize IPv6 addresses using inet_pton/inet_ntop
                        $packed = @inet_pton($ip);
                        if ($packed !== false) {
                            $normalized = inet_ntop($packed);
                            $nameservers[] = $normalized;
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
     * Parses Windows WMIC output
     */
    public static function loadWmicBlocking(): self
    {
        if (!function_exists('shell_exec')) {
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

        if (isset($matches[1])) {
            foreach ($matches[1] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $nameservers[] = $ip;
                }
            }
        }

        // Unique values only
        return new self(array_values(array_unique($nameservers)));
    }
}
