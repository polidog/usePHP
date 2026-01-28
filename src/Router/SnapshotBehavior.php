<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

/**
 * Defines how snapshots are handled during page transitions.
 */
enum SnapshotBehavior: string
{
    /**
     * Isolated - Page-level state, not shared across routes.
     * Each page maintains its own independent state.
     * This is the current default behavior.
     */
    case Isolated = 'isolated';

    /**
     * Persistent - State is passed via URL query parameter.
     * State is preserved when navigating between routes.
     * Useful for multi-step forms or shopping carts.
     */
    case Persistent = 'persistent';

    /**
     * Shared - State is shared between specific routes.
     * Multiple routes can access the same snapshot.
     */
    case Shared = 'shared';

    /**
     * Session - State is stored in the session.
     * State persists across all page navigations within the session.
     */
    case Session = 'session';
}
