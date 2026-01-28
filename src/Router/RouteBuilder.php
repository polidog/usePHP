<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

/**
 * Fluent builder for configuring routes.
 *
 * Usage:
 *   $router->get('/cart', Cart::class)
 *       ->persistentSnapshot();
 */
final class RouteBuilder
{
    private SnapshotBehavior $snapshotBehavior = SnapshotBehavior::Isolated;
    private ?string $sharedGroup = null;

    /** @var array<callable|string> */
    private array $middleware = [];

    /**
     * @param \Closure(Route): void $registerCallback Callback to register the final route
     */
    public function __construct(
        private readonly string $method,
        private readonly string $pattern,
        private readonly mixed $handler,
        private readonly \Closure $registerCallback,
    ) {}

    /**
     * Set snapshot behavior to Isolated (default).
     * State is page-specific and not shared.
     */
    public function isolatedSnapshot(): self
    {
        $this->snapshotBehavior = SnapshotBehavior::Isolated;
        $this->register();

        return $this;
    }

    /**
     * Set snapshot behavior to Persistent.
     * State is passed via URL when navigating.
     */
    public function persistentSnapshot(): self
    {
        $this->snapshotBehavior = SnapshotBehavior::Persistent;
        $this->register();

        return $this;
    }

    /**
     * Set snapshot behavior to Shared within a group.
     * Routes in the same group share state.
     */
    public function sharedSnapshot(string $group): self
    {
        $this->snapshotBehavior = SnapshotBehavior::Shared;
        $this->sharedGroup = $group;
        $this->register();

        return $this;
    }

    /**
     * Set snapshot behavior to Session.
     * State is stored in session across all navigations.
     */
    public function sessionSnapshot(): self
    {
        $this->snapshotBehavior = SnapshotBehavior::Session;
        $this->register();

        return $this;
    }

    /**
     * Add middleware to this route.
     *
     * @param callable|string ...$middleware
     */
    public function middleware(callable|string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        $this->register();

        return $this;
    }

    /**
     * Build the final Route object.
     */
    public function build(): Route
    {
        return new Route(
            method: $this->method,
            pattern: $this->pattern,
            handler: $this->handler,
            snapshotBehavior: $this->snapshotBehavior,
            sharedGroup: $this->sharedGroup,
            middleware: $this->middleware,
        );
    }

    /**
     * Register the route with the router.
     */
    private function register(): void
    {
        ($this->registerCallback)($this->build());
    }
}
