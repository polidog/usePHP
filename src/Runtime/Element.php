<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * VirtualDOM element class representing a React-like element.
 */
class Element
{
    /**
     * @param string $type Element type (e.g., 'div', 'span', 'button')
     * @param array<string, mixed> $props Element properties/attributes
     * @param array<Element|string> $children Child elements or text content
     */
    public function __construct(
        public string $type,
        public array $props = [],
        public array $children = []
    ) {
    }
}
