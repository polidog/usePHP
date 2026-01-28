<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;

/**
 * Simple pattern-based router implementation.
 *
 * Provides basic routing functionality for standalone usePHP applications.
 * For framework integration (Laravel, Symfony), use NullRouter instead.
 *
 * Usage:
 *   $router = new SimpleRouter();
 *   $router->get('/', HomePage::class)->name('home');
 *   $router->get('/users/{id}', UserPage::class)->name('user');
 *   $router->post('/cart/add', CartAddAction::class);
 */
final class SimpleRouter implements RouterInterface
{
    /** @var array<string, Route> Routes indexed by internal key */
    private array $routes = [];

    /** @var array<string, Route> Routes indexed by name */
    private array $namedRoutes = [];

    private ?RequestContext $currentRequest = null;

    private string $snapshotParam = '_snapshot';

    public function __construct(
        private readonly ?SnapshotSerializer $serializer = null,
    ) {}

    /**
     * Add a GET route.
     */
    public function get(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Add a POST route.
     */
    public function post(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Add a PUT route.
     */
    public function put(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * Add a DELETE route.
     */
    public function delete(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Add a PATCH route.
     */
    public function patch(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('PATCH', $pattern, $handler);
    }

    /**
     * Add a route that matches any HTTP method.
     */
    public function any(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('*', $pattern, $handler);
    }

    /**
     * Add a route that matches multiple HTTP methods.
     *
     * @param array<string> $methods
     */
    public function matchMethods(array $methods, string $pattern, callable|string $handler): RouteBuilder
    {
        if (count($methods) === 0) {
            throw new \InvalidArgumentException('At least one HTTP method is required');
        }

        $builder = $this->addRoute(strtoupper($methods[0]), $pattern, $handler);
        for ($i = 1; $i < count($methods); $i++) {
            $this->addRoute(strtoupper($methods[$i]), $pattern, $handler);
        }

        return $builder;
    }

    /**
     * Group routes with a common prefix.
     *
     * @param string $prefix URL prefix for all routes in the group
     * @param callable(RouteGroup): void $callback
     */
    public function group(string $prefix, callable $callback): void
    {
        $group = new RouteGroup($prefix, $this);
        $callback($group);
    }

    public function addRoute(string $method, string $pattern, callable|string $handler): RouteBuilder
    {
        $builder = new RouteBuilder(
            method: strtoupper($method),
            pattern: $pattern,
            handler: $handler,
            registerCallback: function (Route $route) use ($method, $pattern) {
                $key = strtoupper($method) . ':' . $pattern;
                $this->routes[$key] = $route;

                if ($route->name !== null) {
                    $this->namedRoutes[$route->name] = $route;
                }
            },
        );

        // Register the route immediately (can be updated by builder methods)
        $key = strtoupper($method) . ':' . $pattern;
        $this->routes[$key] = $builder->build();

        return $builder;
    }

    /**
     * Match a request against registered routes.
     */
    public function match(RequestContext $request): ?RouteMatch
    {
        $this->currentRequest = $request;

        return $this->matchPath($request->method, $request->path);
    }

    /**
     * Match a path against registered routes.
     */
    private function matchPath(string $method, string $path): ?RouteMatch
    {
        // Normalize path
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            // Check method
            if ($route->method !== '*' && $route->method !== $method) {
                continue;
            }

            // Try to match pattern
            $params = $route->match($path);
            if ($params !== null) {
                return $route->toRouteMatch($params);
            }
        }

        return null;
    }

    public function generate(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route not found: {$name}");
        }

        return $this->namedRoutes[$name]->generate($params);
    }

    public function getCurrentUrl(): string
    {
        if ($this->currentRequest === null) {
            return $_SERVER['REQUEST_URI'] ?? '/';
        }

        return $this->currentRequest->getUrl();
    }

    public function createRedirectUrl(string $url, ?Snapshot $snapshot = null): string
    {
        if ($snapshot === null || $this->serializer === null) {
            return $url;
        }

        $encoded = $this->serializer->serialize($snapshot);

        // Parse URL and add snapshot parameter
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '/';
        $query = [];

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }

        $query[$this->snapshotParam] = $encoded;

        return $path . '?' . http_build_query($query);
    }

    public function extractSnapshot(RequestContext $request): ?string
    {
        return $request->getQuery($this->snapshotParam);
    }

    /**
     * Set the query parameter name used for snapshot data.
     */
    public function setSnapshotParam(string $param): self
    {
        $this->snapshotParam = $param;

        return $this;
    }

    /**
     * Get all registered routes.
     *
     * @return array<string, Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get a route by name.
     */
    public function getRoute(string $name): ?Route
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Check if a named route exists.
     */
    public function hasRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }
}
