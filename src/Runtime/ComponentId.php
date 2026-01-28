<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Value object representing a component identifier.
 *
 * Supports two formats:
 * - New format (hierarchical): "Counter@main" or "Page@home/Counter@item-1"
 * - Legacy format (flat): "Counter#0"
 */
final readonly class ComponentId
{
    public function __construct(
        public string $componentName,
        public string $key,
        public ?self $parent = null,
    ) {}

    /**
     * Create a new ComponentId with the given key.
     */
    public static function create(string $componentName, string $key, ?self $parent = null): self
    {
        return new self($componentName, $key, $parent);
    }

    /**
     * Create a ComponentId with auto-generated numeric key.
     */
    public static function createWithIndex(string $componentName, int $index, ?self $parent = null): self
    {
        return new self($componentName, (string) $index, $parent);
    }

    /**
     * Convert to string representation.
     *
     * @return string "Counter@main" or "Page@home/Counter@item-1"
     */
    public function toString(): string
    {
        $current = $this->componentName . '@' . $this->key;

        if ($this->parent !== null) {
            return $this->parent->toString() . '/' . $current;
        }

        return $current;
    }

    /**
     * Convert to legacy format string.
     *
     * @return string "Counter#main" (for backward compatibility)
     */
    public function toLegacyString(): string
    {
        return $this->componentName . '#' . $this->key;
    }

    /**
     * Parse a string representation into ComponentId.
     *
     * @param string $id e.g., "Counter@main" or "Page@home/Counter@item-1"
     */
    public static function parse(string $id): self
    {
        // Check if it's legacy format
        if (str_contains($id, '#') && !str_contains($id, '@')) {
            return self::fromLegacy($id);
        }

        $parts = explode('/', $id);
        /** @var string|null $firstPart */
        $firstPart = array_shift($parts);

        if ($firstPart === null || $firstPart === '' || !str_contains($firstPart, '@')) {
            throw new \InvalidArgumentException("Invalid component ID format: {$id}");
        }

        [$componentName, $key] = explode('@', $firstPart, 2);
        $result = new self($componentName, $key);

        foreach ($parts as $part) {
            if (!str_contains($part, '@')) {
                throw new \InvalidArgumentException("Invalid component ID format: {$id}");
            }

            [$componentName, $key] = explode('@', $part, 2);
            $result = new self($componentName, $key, $result);
        }

        return $result;
    }

    /**
     * Convert a legacy ID format to ComponentId.
     *
     * @param string $legacyId e.g., "Counter#0" or "Counter#my-key"
     */
    public static function fromLegacy(string $legacyId): self
    {
        if (!str_contains($legacyId, '#')) {
            throw new \InvalidArgumentException("Invalid legacy component ID format: {$legacyId}");
        }

        [$componentName, $key] = explode('#', $legacyId, 2);

        return new self($componentName, $key);
    }

    /**
     * Check if this ID uses the legacy format (numeric key).
     */
    public function isLegacyFormat(): bool
    {
        return ctype_digit($this->key);
    }

    /**
     * Get the full path as an array of component names.
     *
     * @return array<string> e.g., ['Page', 'Counter']
     */
    public function getPath(): array
    {
        $path = [];
        $current = $this;

        while ($current !== null) {
            array_unshift($path, $current->componentName);
            $current = $current->parent;
        }

        return $path;
    }

    /**
     * Get the depth of nesting (0 = root level).
     */
    public function getDepth(): int
    {
        $depth = 0;
        $current = $this->parent;

        while ($current !== null) {
            $depth++;
            $current = $current->parent;
        }

        return $depth;
    }

    /**
     * Check equality with another ComponentId.
     */
    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
