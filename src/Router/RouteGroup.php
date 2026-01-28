<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

/**
 * Route group for organizing routes with a common prefix.
 */
final class RouteGroup
{
    public function __construct(
        private readonly string $prefix,
        private readonly SimpleRouter $router,
    ) {}

    /**
     * Add a GET route to the group.
     */
    public function get(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Add a POST route to the group.
     */
    public function post(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Add a PUT route to the group.
     */
    public function put(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * Add a DELETE route to the group.
     */
    public function delete(string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Add a PATCH route to the group.
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
     * Add a route to the group with the prefix applied.
     */
    private function addRoute(string $method, string $pattern, callable|string $handler): RouteBuilder
    {
        $fullPattern = rtrim($this->prefix, '/') . '/' . ltrim($pattern, '/');
        if ($fullPattern !== '/') {
            $fullPattern = rtrim($fullPattern, '/');
        }

        return $this->router->addRoute($method, $fullPattern, $handler);
    }
}
