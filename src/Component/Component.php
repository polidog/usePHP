<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Attribute;

/**
 * Attribute to mark a class as a usePHP component.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Component
{
    public function __construct(
        public string $name,
    ) {
    }
}
