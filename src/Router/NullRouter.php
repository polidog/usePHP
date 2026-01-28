<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

use Polidog\UsePhp\Runtime\Snapshot;

/**
 * Null router for framework integration (Laravel, Symfony, etc.).
 *
 * When using usePHP within a framework that has its own router,
 * use NullRouter to disable usePHP's routing functionality.
 *
 * Usage with Laravel:
 *   Route::get('/counter', function () {
 *       UsePHP::disableRouter();  // Sets NullRouter
 *       return UsePHP::render(Counter::class);
 *   });
 */
final class NullRouter implements RouterInterface
{
    private ?RequestContext $currentRequest = null;
    private ?RouteMatch $currentMatch = null;

    public function __construct(
        ?RouteMatch $currentMatch = null,
    ) {
        $this->currentMatch = $currentMatch;
    }

    public function addRoute(string $method, string $pattern, callable|string $handler): RouteBuilder
    {
        // Return a no-op builder that doesn't register anything
        return new RouteBuilder(
            method: $method,
            pattern: $pattern,
            handler: $handler,
            registerCallback: fn(Route $route) => null,
        );
    }

    public function match(RequestContext $request): ?RouteMatch
    {
        $this->currentRequest = $request;

        // Return pre-configured match if set
        return $this->currentMatch;
    }

    public function getCurrentUrl(): string
    {
        if ($this->currentRequest !== null) {
            return $this->currentRequest->getUrl();
        }

        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public function createRedirectUrl(string $url, ?Snapshot $snapshot = null): string
    {
        // NullRouter doesn't handle snapshot passing
        return $url;
    }

    public function extractSnapshot(RequestContext $request): ?string
    {
        // Check common parameter names
        return $request->getQuery('_snapshot')
            ?? $request->getQuery('snapshot')
            ?? null;
    }

    /**
     * Set the current route match (for framework integration).
     */
    public function setCurrentMatch(?RouteMatch $match): self
    {
        $this->currentMatch = $match;

        return $this;
    }

    /**
     * Create a NullRouter with a specific component as the handler.
     */
    public static function forComponent(
        string $componentClass,
        SnapshotBehavior $snapshotBehavior = SnapshotBehavior::Isolated,
    ): self {
        return new self(
            currentMatch: new RouteMatch(
                handler: $componentClass,
                snapshotBehavior: $snapshotBehavior,
            ),
        );
    }
}
