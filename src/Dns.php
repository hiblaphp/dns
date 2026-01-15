<?php

declare(strict_types=1);

namespace Hibla\Dns;

use Hibla\Cache\ArrayCache;
use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Dns\Configs\Config;
use Hibla\Dns\Configs\HostsFile;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Interfaces\ExecutorInterface;
use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\Dns\Queries\CachingExecutor;
use Hibla\Dns\Queries\CoopExecutor;
use Hibla\Dns\Queries\FallbackExecutor;
use Hibla\Dns\Queries\HostsFileExecutor;
use Hibla\Dns\Queries\RetryExecutor;
use Hibla\Dns\Queries\SelectiveTransportExecutor;
use Hibla\Dns\Queries\TcpTransportExecutor;
use Hibla\Dns\Queries\TimeoutExecutor;
use Hibla\Dns\Queries\UdpTransportExecutor;
use Hibla\Dns\Resolvers\Resolver;
use Hibla\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use UnderflowException;

/**
 * DNS Resolver - Main entry point for DNS resolution.
 *
 * @example Quick resolve with defaults
 * ```php
 * DNS::resolve('google.com')->then(fn($ip) => echo $ip);
 * ```
 * @example Custom nameserver
 * ```php
 * $resolver = DNS::new()->withNameservers('8.8.8.8')->build();
 * ```
 * @example Full configuration
 * ```php
 * $resolver = DNS::new()
 *     ->withNameservers(['1.1.1.1', '8.8.8.8'])
 *     ->withTimeout(3.0)
 *     ->withRetries(2)
 *     ->withCache()
 *     ->build();
 * ```
 */
final class Dns
{
    private const array DEFAULT_NAMESERVERS = [
        '1.1.1.1', // Cloudflare (Primary)
        '8.8.8.8', // Google (Secondary)
    ];

    private ?Config $config = null;

    private ?CacheInterface $cache = null;

    private float $timeout = 5.0;

    private int $retries = 2;

    private bool $enableCache = false;

    /**
     * Private constructor - use DNS::new() to create instances.
     */
    private function __construct()
    {
        // Intentionally private - forces use of static factory methods
    }

    /**
     * Create a new DNS resolver builder.
     */
    public static function new(): static
    {
        return new self();
    }

    /**
     * Create a resolver with default system configuration.
     */
    public static function create(): ResolverInterface
    {
        return static::new()->build();
    }

    /**
     * Quick resolve with default settings.
     *
     * @param  string  $domain  Domain name to resolve
     * @return PromiseInterface<string> Promise that resolves to an IP address
     */
    public static function resolve(string $domain): PromiseInterface
    {
        return static::create()->resolve($domain);
    }

    /**
     * Quick resolveAll with default settings.
     *
     * @param  string  $domain  Domain name to resolve
     * @param  RecordType  $type  Record type to query
     * @return PromiseInterface<list<mixed>> Promise that resolves to a list of records
     */
    public static function resolveAll(string $domain, RecordType $type = RecordType::A): PromiseInterface
    {
        return static::create()->resolveAll($domain, $type);
    }

    /**
     * Set custom DNS configuration.
     *
     * @param  Config  $config  DNS configuration
     * @return static New instance with config applied
     */
    public function withConfig(Config $config): static
    {
        $clone = clone $this;
        $clone->config = $config;

        return $clone;
    }

    /**
     * Set nameservers.
     *
     * @param  string|array<string>  $nameservers  Single nameserver or array of nameservers
     * @return static New instance with nameservers applied
     */
    public function withNameservers(string|array $nameservers): static
    {
        $nameservers = \is_string($nameservers) ? [$nameservers] : array_values($nameservers);

        return $this->withConfig(new Config($nameservers));
    }

    /**
     * Set query timeout in seconds.
     *
     * @param  float  $timeout  Timeout in seconds
     * @return static New instance with timeout applied
     */
    public function withTimeout(float $timeout): static
    {
        $clone = clone $this;
        $clone->timeout = $timeout;

        return $clone;
    }

    /**
     * Set number of retry attempts on failure.
     *
     * @param  int  $retries  Number of retries (0 to disable retries)
     * @return static New instance with retries applied
     */
    public function withRetries(int $retries): static
    {
        $clone = clone $this;
        $clone->retries = $retries;

        return $clone;
    }

    /**
     * Enable caching with optional custom cache implementation.
     *
     * @param  CacheInterface|null  $cache  Custom cache implementation (defaults to ArrayCache with 256 entries)
     * @return static New instance with cache enabled
     */
    public function withCache(?CacheInterface $cache = null): static
    {
        $clone = clone $this;
        $clone->enableCache = true;
        $clone->cache = $cache;

        return $clone;
    }

    /**
     * Build and return the configured DNS resolver.
     */
    public function build(): ResolverInterface
    {
        $config = $this->config ?? Config::loadSystemConfigBlocking();

        if (\count($config->nameservers) === 0) {
            $config = new Config(self::DEFAULT_NAMESERVERS);
        }

        $executor = $this->createExecutorStack($config);

        if ($this->enableCache) {
            $cache = $this->cache ?? new ArrayCache(256);
            $executor = new CachingExecutor($cache, $executor);
        }

        $executor = $this->addHostsFileSupport($executor);

        return new Resolver($executor);
    }

    private function createExecutorStack(Config $config): ExecutorInterface
    {
        $executors = [];
        foreach ($config->nameservers as $nameserver) {
            $executors[] = $this->createTransportForNameserver($nameserver);
        }

        if (\count($executors) === 0) {
            throw new UnderflowException('No DNS servers configured');
        }

        $primary = array_shift($executors);
        foreach ($executors as $secondary) {
            $primary = new FallbackExecutor($primary, $secondary);
        }

        $executor = new RetryExecutor($primary, $this->retries);

        return new CoopExecutor($executor);
    }

    private function createTransportForNameserver(string $nameserver): ExecutorInterface
    {
        if (str_starts_with($nameserver, 'tcp://')) {
            return $this->createTcp($nameserver);
        }

        if (str_starts_with($nameserver, 'udp://')) {
            return $this->createUdp($nameserver);
        }

        return new SelectiveTransportExecutor(
            $this->createUdp($nameserver),
            $this->createTcp($nameserver)
        );
    }

    private function createUdp(string $nameserver): ExecutorInterface
    {
        return new TimeoutExecutor(
            new UdpTransportExecutor($nameserver),
            $this->timeout
        );
    }

    private function createTcp(string $nameserver): ExecutorInterface
    {
        return new TimeoutExecutor(
            new TcpTransportExecutor($nameserver),
            $this->timeout
        );
    }

    private function addHostsFileSupport(ExecutorInterface $executor): ExecutorInterface
    {
        try {
            $hosts = HostsFile::loadFromPathBlocking();

            return new HostsFileExecutor($hosts, $executor);
        } catch (RuntimeException) {
            return $executor;
        }
    }
}
