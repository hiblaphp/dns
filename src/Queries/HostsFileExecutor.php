<?php

declare(strict_types=1);

namespace Hibla\Dns\Queries;

use Hibla\Dns\Configs\HostsFile;
use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final class HostsFileExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly HostsFile $hosts,
        private readonly ExecutorInterface $fallback
    ) {}

    /**
     * @inheritDoc
     */
    public function query(Query $query): PromiseInterface
    {
        // 1. Handle Forward Lookups (A / AAAA)
        if ($query->class === RecordClass::IN && ($query->type === RecordType::A || $query->type === RecordType::AAAA)) {
            $ips = $this->hosts->getIpsForHost($query->name);
            $answers = [];

            foreach ($ips as $ip) {
                // Check if IP format matches query type (IPv4 vs IPv6)
                $isIpv6 = str_contains($ip, ':');
                $wantsIpv6 = $query->type === RecordType::AAAA;

                if ($isIpv6 === $wantsIpv6) {
                    $answers[] = new Record(
                        name: $query->name,
                        type: $query->type,
                        class: $query->class,
                        ttl: 0, // Hosts file entries have effectively 0 TTL (or infinite, but 0 ensures no caching downstream)
                        data: $ip
                    );
                }
            }

            if (\count($answers) > 0) {
                return Promise::resolved($this->createResponse($query, $answers));
            }
        }

        // 2. Handle Reverse Lookups (PTR)
        if ($query->class === RecordClass::IN && $query->type === RecordType::PTR) {
            $ip = $this->extractIpFromPtr($query->name);

            if ($ip !== null) {
                $hostnames = $this->hosts->getHostsForIp($ip);
                $answers = [];

                foreach ($hostnames as $host) {
                    $answers[] = new Record(
                        name: $query->name,
                        type: RecordType::PTR,
                        class: $query->class,
                        ttl: 0,
                        data: $host
                    );
                }

                if (\count($answers) > 0) {
                    return Promise::resolved($this->createResponse($query, $answers));
                }
            }
        }

        // 3. Fallback to network if no match found
        return $this->fallback->query($query);
    }

    /**
     * Synthesizes a valid DNS Message object.
     *
     * @param  list<Record>  $answers
     */
    private function createResponse(Query $query, array $answers): Message
    {
        $message = new Message();
        $message->isResponse = true;
        $message->isAuthoritative = true; // Local hosts file is authoritative
        $message->recursionAvailable = true;
        $message->responseCode = ResponseCode::OK;
        $message->questions[] = $query;
        $message->answers = $answers;

        return $message;
    }

    private function extractIpFromPtr(string $name): ?string
    {
        if (str_ends_with($name, '.in-addr.arpa')) {
            // IPv4: 4.3.2.1.in-addr.arpa -> 1.2.3.4
            $ipPart = substr($name, 0, -13);
            $ip = @inet_pton($ipPart);
            if ($ip !== false && \strlen($ip) === 4) {
                // Only valid if we can reverse it successfully
                // this logic is actually reversing the string segments
                return implode('.', array_reverse(explode('.', $ipPart)));
            }
        } elseif (str_ends_with($name, '.ip6.arpa')) {
            // IPv6: b.a.9.8....ip6.arpa -> 2001:db8...
            // Remove suffix
            $hexPart = substr($name, 0, -9);
            // Remove dots and reverse
            $hex = strrev(str_replace('.', '', $hexPart));

            if (ctype_xdigit($hex) && \strlen($hex) === 32) {
                // Pack into binary string
                $bin = pack('H*', $hex);
                $result = inet_ntop($bin);

                return $result !== false ? $result : null;
            }
        }

        return null;
    }
}
