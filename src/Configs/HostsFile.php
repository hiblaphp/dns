<?php

declare(strict_types=1);

namespace Hibla\Dns\Configs;

use RuntimeException;

final class HostsFile
{
    public function __construct(
        private readonly string $contents
    ) {}

    public static function loadFromPathBlocking(?string $path = null): self
    {
        if ($path === null) {
            $path = DIRECTORY_SEPARATOR === '\\'
                ? getenv('SystemRoot') . '\\system32\\drivers\\etc\\hosts'
                : '/etc/hosts';
        }

        if (!file_exists($path) || !is_readable($path)) {
            return new self('');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read hosts file \"$path\"");
        }

        return new self($contents);
    }

    /**
     * Returns all IPs for the given hostname.
     *
     * @return list<string>
     */
    public function getIpsForHost(string $host): array
    {
        $host = strtolower($host);
        $ips = [];

        $lines = preg_split('/\r?\n/', $this->contents);
        if ($lines === false) return [];

        foreach ($lines as $line) {
            $line = preg_replace('/#.*/', '', $line); 
            $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
            
            if (\count($parts) < 2) continue;

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
     * Returns all hostnames for the given IP address (Reverse Lookup).
     * 
     * @return list<string>
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
        if ($lines === false) return [];

        foreach ($lines as $line) {
            $line = preg_replace('/#.*/', '', $line);
            $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
            
            if (\count($parts) < 2) continue;

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