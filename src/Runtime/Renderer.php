<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Renders Element tree to HTML string.
 * Supports partial updates with minimal JavaScript.
 */
final class Renderer
{
    private const SELF_CLOSING_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private string $componentId;
    private ?SnapshotSerializer $snapshotSerializer;
    private ?StorageType $storageType;

    public function __construct(
        string $componentId,
        ?SnapshotSerializer $snapshotSerializer = null,
        ?StorageType $storageType = null,
    ) {
        $this->componentId = $componentId;
        $this->snapshotSerializer = $snapshotSerializer;
        $this->storageType = $storageType;
    }

    /**
     * Render a component function with wrapper.
     *
     * @param callable(): Element $component
     */
    public function render(callable $component): string
    {
        $state = ComponentState::getInstance($this->componentId, $this->storageType);
        ComponentState::reset();

        $element = $component();
        $inner = $this->renderElement($element);

        // Build attributes
        $attrs = sprintf('data-usephp="%s"', htmlspecialchars($this->componentId, ENT_QUOTES, 'UTF-8'));

        // Add snapshot attribute if using snapshot storage
        if ($this->shouldEmbedSnapshot($state)) {
            $snapshotJson = $this->serializeSnapshot($state);
            $attrs .= sprintf(' data-usephp-snapshot=\'%s\'', htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8'));
        }

        // Wrap with component container for partial updates
        return sprintf('<div %s>%s</div>', $attrs, $inner);
    }

    /**
     * Render a component without wrapper (for partial updates).
     *
     * @param callable(): Element $component
     */
    public function renderPartial(callable $component): string
    {
        $state = ComponentState::getInstance($this->componentId, $this->storageType);
        ComponentState::reset();

        $element = $component();
        $inner = $this->renderElement($element);

        // For snapshot storage, include hidden field with updated snapshot
        if ($this->shouldEmbedSnapshot($state)) {
            $snapshotJson = $this->serializeSnapshot($state);
            $inner .= sprintf(
                '<input type="hidden" name="_usephp_snapshot" value="%s" data-usephp-snapshot-update />',
                htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8')
            );
        }

        return $inner;
    }

    /**
     * Render an element to HTML.
     */
    public function renderElement(Element|string $element): string
    {
        if (is_string($element)) {
            return htmlspecialchars($element, ENT_QUOTES, 'UTF-8');
        }

        // Handle Fragment
        if ($element->type === 'Fragment') {
            return $this->renderChildren($element->children);
        }

        $tag = $element->type;
        $props = $element->props;
        $hasAction = isset($props['wire:click']);

        // If element has an action, wrap it in a form
        if ($hasAction) {
            return $this->renderWithForm($element);
        }

        $attributes = $this->renderAttributes($props);

        // Self-closing tags
        if (in_array($tag, self::SELF_CLOSING_TAGS, true)) {
            return "<{$tag}{$attributes} />";
        }

        $children = $this->renderChildren($element->children);

        return "<{$tag}{$attributes}>{$children}</{$tag}>";
    }

    /**
     * Render an element wrapped in a form for action handling.
     */
    private function renderWithForm(Element $element): string
    {
        $action = $element->props['wire:click'];
        $tag = $element->type;

        // Remove wire:click from props
        $props = $element->props;
        unset($props['wire:click']);

        // For button, make it a submit button
        if ($tag === 'button') {
            $props['type'] = 'submit';
        }

        $attributes = $this->renderAttributes($props);
        $children = $this->renderChildren($element->children);

        // Build the form with hidden action data
        $actionJson = htmlspecialchars($action->toJson(), ENT_QUOTES, 'UTF-8');
        // Prefer componentId from Action, fall back to Renderer's componentId
        $componentId = $action->componentId ?? $this->componentId;
        $componentIdEscaped = htmlspecialchars($componentId, ENT_QUOTES, 'UTF-8');

        $innerElement = in_array($tag, self::SELF_CLOSING_TAGS, true)
            ? "<{$tag}{$attributes} />"
            : "<{$tag}{$attributes}>{$children}</{$tag}>";

        // Add snapshot hidden field if using snapshot storage
        // Use action's componentId to get the correct state from cache
        $snapshotField = '';
        $state = ComponentState::getInstance($componentId);
        if ($this->shouldEmbedSnapshot($state)) {
            $snapshotJson = $this->serializeSnapshot($state);
            $snapshotField = sprintf(
                '<input type="hidden" name="_usephp_snapshot" value="%s" />',
                htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8')
            );
        }

        // data-usephp-form enables JS enhancement, falls back to normal form if no JS
        return <<<HTML
            <form method="post" data-usephp-form style="display:inline;">
            <input type="hidden" name="_usephp_component" value="{$componentIdEscaped}" />
            <input type="hidden" name="_usephp_action" value="{$actionJson}" />
            {$snapshotField}{$innerElement}
            </form>
            HTML;
    }

    /**
     * Render element attributes.
     *
     * @param array<string, mixed> $props
     */
    private function renderAttributes(array $props): string
    {
        $attributes = [];

        foreach ($props as $key => $value) {
            // Skip wire:* attributes (handled separately)
            if (str_starts_with($key, 'wire:')) {
                continue;
            }

            // Handle boolean attributes
            if (is_bool($value)) {
                if ($value) {
                    $attributes[] = $key;
                }
                continue;
            }

            // Skip non-scalar values
            if (!is_scalar($value)) {
                continue;
            }

            // Handle regular attributes
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $attributes[] = sprintf('%s="%s"', $key, $escapedValue);
        }

        return $attributes ? ' ' . implode(' ', $attributes) : '';
    }

    /**
     * Render children elements.
     *
     * @param array<Element|string|int|float> $children
     */
    private function renderChildren(array $children): string
    {
        $html = '';

        foreach ($children as $child) {
            if ($child instanceof Element) {
                $html .= $this->renderElement($child);
            } elseif (is_string($child)) {
                $html .= htmlspecialchars($child, ENT_QUOTES, 'UTF-8');
            } elseif (is_numeric($child)) {
                $html .= (string) $child;
            }
        }

        return $html;
    }

    /**
     * Check if snapshot should be embedded in the output.
     */
    private function shouldEmbedSnapshot(ComponentState $state): bool
    {
        // State must actually be using snapshot storage to create snapshots
        return $state->isSnapshotStorage();
    }

    /**
     * Serialize the current state as a snapshot JSON.
     */
    private function serializeSnapshot(ComponentState $state): string
    {
        $snapshot = $state->createSnapshot();

        if ($this->snapshotSerializer !== null) {
            return $this->snapshotSerializer->serialize($snapshot);
        }

        // Use default serializer without secret key
        return $snapshot->toJson();
    }
}
