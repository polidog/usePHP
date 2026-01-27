<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * VirtualDOM element class representing a React-like element.
 */
final readonly class Element
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
    ) {}

    /**
     * Create a new Element with updated props (PHP 8.5 Clone With).
     *
     * @param array<string, mixed> $props
     */
    public function withProps(array $props): self
    {
        return clone($this, ['props' => $props]);
    }

    /**
     * Create a new Element with updated children (PHP 8.5 Clone With).
     *
     * @param array<Element|string> $children
     */
    public function withChildren(array $children): self
    {
        return clone($this, ['children' => $children]);
    }
}
