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
     * Add middleware to this route: guards that run, in order, before anything
     * else happens for the route. That covers GET and HEAD, and also action
     * POSTs to the page, which are dispatched before GET routing.
     *
     * A middleware is a callable or the name of an invokable class with a
     * no-argument constructor, and is called like a route handler:
     * `function (array $params, RequestContext $request)`. `$params` are the
     * route's parameters and `$request` is the actual request, so `method` is
     * `POST` for an action. What it returns decides what happens next:
     *
     * - `null` (or nothing) or `true`: continue with the next middleware,
     *   then the handler.
     * - `false`: end the request with `403 Forbidden`.
     * - a string or `Element`: end the request with that as the body. The
     *   status stays whatever the middleware set with `http_response_code()`.
     *
     * It can also end the request itself with `exit` or `UsePHP::redirect()`,
     * or throw; the exception propagates out of `run()` and the handler does
     * not run. Any other return value, or a middleware that can't be resolved,
     * is a `LogicException`, so a broken guard never leaves the route open.
     *
     * Guards belong to the URL they are attached to. Not covered:
     * - deferred component endpoints (`GET /_defer/{name}`), which have no
     *   route. A deferred component on a protected page has to check access
     *   itself.
     * - a custom front controller that invokes handlers on its own, unless it
     *   calls `UsePHP::runRouteMiddleware()`.
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
