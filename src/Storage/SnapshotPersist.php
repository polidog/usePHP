<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Client-side persistence for Snapshot-storage components.
 *
 * Snapshot storage keeps state in the page (the signed `data-usephp-snapshot`
 * attribute), so a reload starts over from the initial state. Opting a
 * component into client persistence makes usephp.js mirror the latest
 * snapshot into Web Storage after every partial update and, on the next
 * page load, POST it back as a `restore` action so the component re-renders
 * with the saved state. The server stays stateless: the snapshot is still
 * HMAC-verified on the way back in, exactly like a normal action.
 *
 * Meant for Isolated routes. Persistent/Session/Shared routes already carry
 * the snapshot in the URL or the PHP session, and combining them with client
 * persistence gives two sources of truth.
 */
enum SnapshotPersist: string
{
    /** Per-tab: survives reloads, cleared when the tab closes. */
    case SessionStorage = 'sessionStorage';

    /** Shared across tabs and browser restarts. */
    case LocalStorage = 'localStorage';
}
