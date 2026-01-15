<?php

declare(strict_types=1);

namespace Hibla\Dns\Resolvers;

use Hibla\Dns\Enums\RecordClass;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Enums\ResponseCode;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Dns\Models\Record;
use Hibla\Promise\Interfaces\PromiseInterface;
use Random\Randomizer;

final class Resolver implements ResolverInterface
{
    private const int MAX_CNAME_DEPTH = 10;

    public function __construct(
        private readonly ExecutorInterface $executor
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(string $domain): PromiseInterface
    {
        return $this->resolveAll($domain, RecordType::A)
            ->then(function (array $ips): string {
                if (\count($ips) === 0) {
                    throw new RecordNotFoundException('No IP addresses found');
                }

                $ip = $ips[(new Randomizer())->getInt(0, \count($ips) - 1)];

                assert(\is_string($ip), 'A record should return string IP address');

                return $ip;
            })
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveAll(string $domain, RecordType $type): PromiseInterface
    {
        $query = new Query($domain, $type, RecordClass::IN);

        return $this->executor->query($query)
            ->then(
                fn (Message $response) => $this->extractValues($query, $response),
                function (mixed $error) {
                    if ($error instanceof \Throwable) {
                        throw $error;
                    }

                    throw new \RuntimeException('Unknown error occurred');
                }
            )
        ;
    }

    /**
     * @return list<mixed>
     *
     * @throws RecordNotFoundException
     */
    private function extractValues(Query $query, Message $response): array
    {
        if ($response->responseCode !== ResponseCode::OK) {
            $errorMsg = match ($response->responseCode) {
                ResponseCode::FORMAT_ERROR => 'Format Error',
                ResponseCode::SERVER_FAILURE => 'Server Failure',
                ResponseCode::NAME_ERROR => 'Non-Existent Domain / NXDOMAIN',
                ResponseCode::NOT_IMPLEMENTED => 'Not Implemented',
                ResponseCode::REFUSED => 'Refused',
            };

            throw new RecordNotFoundException(
                \sprintf('DNS query for %s returned an error response (%s)', $query->name, $errorMsg),
                $response->responseCode->value
            );
        }

        $results = $this->findAnswers($response->answers, $query->name, $query->type);

        if (\count($results) === 0) {
            throw new RecordNotFoundException(
                \sprintf('DNS query for %s did not return a valid answer (NOERROR / NODATA)', $query->name)
            );
        }

        return $results;
    }

    /**
     * @param  list<Record>  $answers
     * @return list<mixed>
     */
    private function findAnswers(array $answers, string $name, RecordType $type, int $depth = 0): array
    {
        // Prevent infinite CNAME loops
        if ($depth >= self::MAX_CNAME_DEPTH) {
            return [];
        }

        // 1. Direct match?
        $directMatches = array_filter(
            $answers,
            fn (Record $r) => strcasecmp($r->name, $name) === 0 && $r->type === $type
        );

        if (\count($directMatches) > 0) {
            return array_values(array_map(fn (Record $r) => $r->data, $directMatches));
        }

        // 2. CNAME Chaining (only for A, AAAA, and similar record types)
        if ($this->shouldFollowCNAME($type)) {
            $cnameMatches = array_filter(
                $answers,
                fn (Record $r) => strcasecmp($r->name, $name) === 0 && $r->type === RecordType::CNAME
            );

            /** @var list<mixed> $results */
            $results = [];
            foreach ($cnameMatches as $cnameRecord) {
                $data = $cnameRecord->data;

                if (! \is_string($data)) {
                    continue;
                }

                $aliasTarget = $data;

                // Prevent self-referencing CNAME
                if (strcasecmp($aliasTarget, $name) === 0) {
                    continue;
                }

                array_push($results, ...$this->findAnswers($answers, $aliasTarget, $type, $depth + 1));
            }

            if (\count($results) > 0) {
                return $results;
            }
        }

        return [];
    }

    private function shouldFollowCNAME(RecordType $type): bool
    {
        return match ($type) {
            RecordType::A,
            RecordType::AAAA => true,
            default => false,
        };
    }
}
