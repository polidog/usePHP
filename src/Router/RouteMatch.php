<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

/**
 * Immutable value object representing a matched route.
 */
final readonly class RouteMatch
{
    /**
     * @param callable|string $handler The route handler (component class or callable)
     * @param array<string, string> $params URL parameters extracted from the route pattern
     * @param SnapshotBehavior $snapshotBehavior How snapshots should be handled for this route
     * @param string|null $sharedGroup Group name for shared snapshot behavior
     */
    public function __construct(
        public mixed $handler,
        public array $params = [],
        public SnapshotBehavior $snapshotBehavior = SnapshotBehavior::Isolated,
        public ?string $sharedGroup = null,
    ) {}

    /**
     * Create a new RouteMatch with modified parameters.
     *
     * @param array<string, string> $params
     */
    public function withParams(array $params): self
    {
        return new self(
            handler: $this->handler,
            params: $params,
            snapshotBehavior: $this->snapshotBehavior,
            sharedGroup: $this->sharedGroup,
        );
    }
}
