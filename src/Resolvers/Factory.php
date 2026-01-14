<?php

declare(strict_types=1);

namespace Hibla\Dns\Resolvers;

use Hibla\Cache\ArrayCache;
use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Dns\Configs\Config;
use Hibla\Dns\Configs\HostsFile;
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
use RuntimeException;
use UnderflowException;

final class Factory
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

    public function withConfig(Config $config): self
    {
        $clone = clone $this;
        $clone->config = $config;
        return $clone;
    }

    public function withTimeout(float $timeout): self
    {
        $clone = clone $this;
        $clone->timeout = $timeout;
        return $clone;
    }

    public function withRetries(int $retries): self
    {
        $clone = clone $this;
        $clone->retries = $retries;
        return $clone;
    }

    public function withCache(?CacheInterface $cache = null): self
    {
        $clone = clone $this;
        $clone->enableCache = true;
        $clone->cache = $cache;
        return $clone;
    }

    public function create(): ResolverInterface
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