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
     * @param array<mixed> $children Child elements, text, or nested arrays of them.
     *        The Renderer rejects types it cannot render.
     */
    public function __construct(
        public string $type,
        public array $props = [],
        public array $children = []
    ) {}

    /**
     * Create a new Element with updated props.
     *
     * @param array<string, mixed> $props
     */
    public function withProps(array $props): self
    {
        return new self($this->type, $props, $this->children);
    }

    /**
     * Create a new Element with updated children.
     *
     * @param array<mixed> $children
     */
    public function withChildren(array $children): self
    {
        return new self($this->type, $this->props, $children);
    }
}
