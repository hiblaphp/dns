<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final class MockCache implements CacheInterface
{
    private array $store = [];
    private ?\Throwable $getError = null;
    private ?\Throwable $setError = null;
    private ?PromiseInterface $getOverride = null;

    public bool $wasSet = false;
    public mixed $lastSetKey = null;
    public mixed $lastSetValue = null;
    public ?float $lastSetTtl = null;

    public function primeWith(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function failGetWith(\Throwable $error): void
    {
        $this->getError = $error;
    }

    public function failSetWith(\Throwable $error): void
    {
        $this->setError = $error;
    }

    public function overrideGetPromise(PromiseInterface $promise): void
    {
        $this->getOverride = $promise;
    }

    public function recoverGet(): void
    {
        $this->getError = null;
        $this->getOverride = null;
    }

    public function get(string $key, mixed $default = null): PromiseInterface
    {
        if ($this->getOverride !== null) {
            return $this->getOverride;
        }

        if ($this->getError !== null) {
            return Promise::rejected($this->getError);
        }

        return Promise::resolved($this->store[$key] ?? $default);
    }

    public function set(string $key, mixed $value, mixed $ttl = null): PromiseInterface
    {
        $this->wasSet = true;
        $this->lastSetKey = $key;
        $this->lastSetValue = $value;
        $this->lastSetTtl = $ttl !== null ? (float) $ttl : null;

        if ($this->setError !== null) {
            return Promise::rejected($this->setError);
        }

        $this->store[$key] = $value;

        return Promise::resolved(true);
    }

    public function delete(string $key): PromiseInterface
    {
        unset($this->store[$key]);

        return Promise::resolved(true);
    }

    public function clear(): PromiseInterface
    {
        $this->store = [];

        return Promise::resolved(true);
    }

    public function getMultiple(iterable $keys, mixed $default = null): PromiseInterface
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->store[$key] ?? $default;
        }

        return Promise::resolved($result);
    }

    public function setMultiple(iterable $values, mixed $ttl = null): PromiseInterface
    {
        foreach ($values as $key => $value) {
            $this->store[$key] = $value;
        }

        return Promise::resolved(true);
    }

    public function deleteMultiple(iterable $keys): PromiseInterface
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }

        return Promise::resolved(true);
    }

    public function has(string $key): PromiseInterface
    {
        return Promise::resolved(array_key_exists($key, $this->store));
    }
}
