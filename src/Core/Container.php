<?php

declare(strict_types=1);

namespace Zorvex\Core;

use Psr\Container\ContainerInterface;
use RuntimeException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Lightweight PSR-11 compatible dependency injection container.
 *
 * Supports:
 *  - shared (singleton) bindings
 *  - non-shared factories with $(Container $c) signature
 *  - automatic constructor resolution with reflection
 *  - interface → concrete class aliasing
 *
 * @package Zorvex\Core
 */
final class Container implements ContainerInterface
{
    /** @var array<string, object> Shared singleton instances. */
    private array $instances = [];

    /** @var array<string, callable> Service factories / definitions. */
    private array $bindings = [];

    /** @var array<string, bool> Whether a binding resolves as a singleton. */
    private array $shared = [];

    /** @var array<string, true> Keys currently being resolved (circular guard). */
    private array $resolving = [];

    private static ?Container $instance = null;

    private function __construct()
    {
    }

    /**
     * Get the global application container.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Register a factory binding. The factory receives the container as its only
     * argument and must return the service. Pass $shared = true to cache the
     * produced instance as a singleton.
     */
    public function bind(string $abstract, callable $factory, bool $shared = false): void
    {
        $this->bindings[$abstract] = $factory;
        $this->shared[$abstract] = $shared;
        unset($this->instances[$abstract]);
    }

    /**
     * Register a shared (singleton) factory binding.
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bind($abstract, $factory, true);
    }

    /**
     * Alias an abstract (interface) to a concrete resollable name.
     */
    public function alias(string $abstract, string $concrete): void
    {
        $this->bind($abstract, static fn (Container $c): mixed => $c->get($concrete), true);
    }

    /**
     * Bind an already-constructed instance.
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        unset($this->bindings[$abstract], $this->shared[$abstract]);
    }

    /**
     * Resolve a service from the container.
     */
    public function get(string $id): mixed
    {
        // Instantiated shared instance.
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if ($this->isResolving($id)) {
            throw new RuntimeException("Circular dependency detected while resolving [{$id}].");
        }

        $this->markResolving($id);

        try {
            if (array_key_exists($id, $this->bindings)) {
                $resolved = ($this->bindings[$id])($this);

                if ($this->shared[$id]) {
                    $this->instances[$id] = $resolved;
                }

                return $resolved;
            }

            // Fall through to autowiring.
            $resolved = $this->autowire($id);
            $this->instances[$id] = $resolved;

            return $resolved;
        } finally {
            $this->unmarkResolving($id);
        }
    }

    /**
     * Whether the container can resolve the given identifier.
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->instances[$id])
            || class_exists($id)
            || interface_exists($id);
    }

    /**
     * Build an object reflectively, resolving constructor dependencies.
     *
     * @param class-string $concrete
     * @throws RuntimeException
     */
    private function autowire(string $concrete): object
    {
        if (!class_exists($concrete) && !interface_exists($concrete)) {
            throw new RuntimeException("Cannot resolve [{$concrete}]: class does not exist.");
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Cannot instantiate [{$concrete}] — it is not concrete.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $parameter) {
            $args[] = $this->resolveParameter($parameter, $concrete);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Resolve a single constructor parameter.
     *
     * @throws RuntimeException
     */
    private function resolveParameter(ReflectionParameter $parameter, string $class): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            if ($name === ContainerInterface::class || $name === self::class) {
                return $this;
            }

            if ($this->has($name)) {
                return $this->get($name);
            }

            if ($type->allowsNull()) {
                return null;
            }

            if ($parameter->isVariadic()) {
                return [];
            }

            throw new RuntimeException(
                "Cannot resolve parameter [\${$parameter->getName()}] of [{$class}]: no binding for [{$name}]."
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->isVariadic()) {
            return [];
        }

        throw new RuntimeException(
            "Cannot resolve parameter [\${$parameter->getName()}] of [{$class}]: primitive without default value."
        );
    }

    private function isResolving(string $key): bool
    {
        return isset($this->resolving[$key]);
    }

    private function markResolving(string $key): void
    {
        $this->resolving[$key] = true;
    }

    private function unmarkResolving(string $key): void
    {
        unset($this->resolving[$key]);
    }
}