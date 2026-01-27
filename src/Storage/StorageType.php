<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Storage type for component state.
 */
enum StorageType: string
{
    /**
     * Session storage - state persists across page navigations.
     */
    case Session = 'session';

    /**
     * Memory storage - state is reset on each page load.
     */
    case Memory = 'memory';
}
