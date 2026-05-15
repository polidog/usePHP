<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Attribute;
use Polidog\UsePhp\UsePHP;

/**
 * Marks a component as a deferred endpoint.
 *
 * Doubles as:
 *   - a class attribute on `BaseComponent` subclasses (`#[Defer(name: '...')]`)
 *   - a plain value object passed to `fc(..., defer: new Defer(...))` for
 *     closure-based components in .psx files
 *
 * Carrying the registration on the component itself (instead of the call site)
 * means the framework can enumerate every deferred endpoint at compile time
 * and auto-register them — removing the manual `UsePHP::registerDeferred()`
 * wiring.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Defer
{
    /**
     * @param string $name URL-safe registration name (`[A-Za-z0-9_-]+`).
     *        Becomes the path segment under the defer prefix, so it appears
     *        in client URLs and must be unique across the application.
     * @param string|null $cacheControl Optional Cache-Control header for the
     *        defer endpoint response. Omit to fall back to the framework
     *        default (`private, max-age=0`).
     */
    public function __construct(
        public string $name,
        public ?string $cacheControl = null,
    ) {
        if (!UsePHP::isValidDeferName($name)) {
            throw new \InvalidArgumentException(
                'Deferred component name must match `' . UsePHP::DEFER_NAME_PATTERN . "`, got: '$name'",
            );
        }
    }
}
