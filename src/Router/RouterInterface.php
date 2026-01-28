<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

use Polidog\UsePhp\Runtime\Snapshot;

/**
 * Interface for URL routing in usePHP applications.
 *
 * Implementations can range from simple pattern matching (SimpleRouter)
 * to integration with framework routers (NullRouter for Laravel/Symfony).
 */
interface RouterInterface
{
    /**
     * Add a route definition.
     *
     * @param string $method HTTP method (GET, POST, etc.) or '*' for any
     * @param string $pattern URL pattern with optional parameters (e.g., '/users/{id}')
     * @param callable|string $handler Component class name or callable
     */
    public function addRoute(string $method, string $pattern, callable|string $handler): RouteBuilder;

    /**
     * Match a request against registered routes.
     *
     * @param RequestContext $request The incoming request
     * @return RouteMatch|null The matched route or null if no match
     */
    public function match(RequestContext $request): ?RouteMatch;

    /**
     * Generate a URL for a named route.
     *
     * @param string $name Route name
     * @param array<string, string> $params URL parameters
     * @return string Generated URL
     * @throws \InvalidArgumentException If route name is not found
     */
    public function generate(string $name, array $params = []): string;

    /**
     * Get the current request URL.
     */
    public function getCurrentUrl(): string;

    /**
     * Create a redirect URL, optionally including snapshot data.
     *
     * @param string $url Target URL
     * @param Snapshot|null $snapshot Optional snapshot to include in the URL
     * @return string URL with optional snapshot parameter
     */
    public function createRedirectUrl(string $url, ?Snapshot $snapshot = null): string;

    /**
     * Extract snapshot data from a request if present.
     *
     * @param RequestContext $request The incoming request
     * @return string|null Encoded snapshot string or null
     */
    public function extractSnapshot(RequestContext $request): ?string;
}
