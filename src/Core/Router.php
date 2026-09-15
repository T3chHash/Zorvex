<?php

declare(strict_types=1);

namespace Zorvex\Core;

use RuntimeException;

/**
 * Minimal static-path web router with middleware support.
 *
 * Routes are registered against an HTTP method + path. Named path parameters use
 * the `{name}` syntax. Middleware run in registration order and may short-cycle
 * the request by returning a Response before reaching the handler.
 *
 * Handlers may be:
 *   - an invokable class name  (e.g. `MyController::class`)
 *   - a `[class, method]` pair (e.g. `[MyController::class, 'index']`)
 *   - a Closure
 *
 * @package Zorvex\Core
 */
final class Router
{
    /** @var array<string, list<array{path: string, handler: string|array|callable, params: list<string>}>> */
    private array $routes = [];

    /** @var list<class-string> */
    private array $middleware = [];

    /** @var array<string, string> Route names → path templates. */
    private array $named = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Register global middleware by class name (resolved via the container).
     *
     * @param class-string $middleware
     */
    public function use(string $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function get(string $path, string|array|callable $handler, string $name = ''): void
    {
        $this->register('GET', $path, $handler, $name);
    }

    public function post(string $path, string|array|callable $handler, string $name = ''): void
    {
        $this->register('POST', $path, $handler, $name);
    }

    public function put(string $path, string|array|callable $handler, string $name = ''): void
    {
        $this->register('PUT', $path, $handler, $name);
    }

    public function delete(string $path, string|array|callable $handler, string $name = ''): void
    {
        $this->register('DELETE', $path, $handler, $name);
    }

    public function register(string $method, string $path, string|array|callable $handler, string $name = ''): void
    {
        $params = [];
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            $path
        );

        $key = strtoupper($method);
        $this->routes[$key][] = [
            'path' => $pattern ?? $path,
            'handler' => $handler,
            'params' => $params,
        ];

        if ($name !== '') {
            $this->named[$name] = $path;
        }
    }

    /**
     * Generate the path for a named route, replacing `{param}` placeholders.
     */
    public function url(string $name, array $params = []): string
    {
        $path = $this->named[$name] ?? '/';

        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        return $path;
    }

    /**
     * Dispatch a request through middleware to the matching handler.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = rtrim($request->path(), '/');
        $path = $path === '' ? '/' : $path;

        $match = $this->match($method, $path);

        if ($match === null) {
            return Response::json(json_fail('Route not found.', 404), 404);
        }

        [$handler, $params] = $match;

        // Clear any stale route params from a previous FPM request, then seed.
        $this->container->get(RouteParams::class)->set([]);
        $this->seedRouteParams($params);

        $kernel = function (Request $req) use ($handler): Response {
            return $this->resolveHandler($handler, $req);
        };

        // Wrap the kernel with middleware in reverse so the first registered
        // middleware runs first.
        foreach (array_reverse($this->middleware) as $middleware) {
            $kernel = $this->wrapMiddleware($middleware, $kernel);
        }

        return $kernel($request);
    }

    /**
     * Find a matching route for a method + real path.
     *
     * @return array{0: string|array|callable, 1: array<string, string>}|null
     */
    private function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match('~^' . $route['path'] . '$~', $path, $matches) === 1) {
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }

                return [$route['handler'], $params];
            }
        }

        return null;
    }

    /**
     * Resolve and invoke any registered handler shape.
     *
     * @param string|array|callable $handler
     */
    private function resolveHandler(string|array|callable $handler, Request $request): Response
    {
        $params = $this->container->get(RouteParams::class)->all();

        // [Class::class, 'method']
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = $this->container->get($class);

            if (!method_exists($instance, $method)) {
                throw new RuntimeException("Route handler [{$class}@{$method}] does not exist.");
            }

            $response = $instance->{$method}($request, $params);

            if (!$response instanceof Response) {
                throw new RuntimeException("Route handler [{$class}@{$method}] must return a Response.");
            }

            return $response;
        }

        // Closure
        if ($handler instanceof \Closure) {
            return $handler($request);
        }

        // Class name or 'Class@method'
        if (str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $instance = $this->container->get($class);

            if (!method_exists($instance, $method)) {
                throw new RuntimeException("Route handler [{$class}@{$method}] does not exist.");
            }

            $response = $instance->{$method}($request, $params);

            if (!$response instanceof Response) {
                throw new RuntimeException("Route handler [{$class}@{$method}] must return a Response.");
            }

            return $response;
        }

        // Invokable class name
        $resolved = $this->container->get($handler);

        if (is_callable($resolved)) {
            $response = $resolved($request);

            if (!$response instanceof Response) {
                throw new RuntimeException("Route handler [{$handler}] must return a Response.");
            }

            return $response;
        }

        throw new RuntimeException("Route handler [{$handler}] is not callable.");
    }

    /**
     * Compose a middleware around a kernel callable.
     *
     * @param class-string $middleware
     */
    private function wrapMiddleware(string $middleware, callable $kernel): callable
    {
        return function (Request $request) use ($middleware, $kernel): Response {
            $instance = $this->container->get($middleware);

            if (!is_callable($instance)) {
                throw new RuntimeException("Middleware [{$middleware}] is not callable.");
            }

            $response = $instance($request, $kernel);

            if (!$response instanceof Response) {
                throw new RuntimeException("Middleware [{$middleware}] must return a Response.");
            }

            return $response;
        };
    }

    /**
     * Expose route params to handlers through the container's RouteParams bag.
     */
    private function seedRouteParams(array $params): void
    {
        if ($params === []) {
            return;
        }

        $this->container->get(RouteParams::class)->set($params);
    }
}

/**
 * Per-request route parameter bag. Binds a mutable value in the container.
 *
 * @internal
 */
final class RouteParams
{
    /** @var array<string, mixed> */
    private array $params = [];

    public function set(array $params): void
    {
        $this->params = $params;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->params;
    }
}