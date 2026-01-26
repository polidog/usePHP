<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Renders Element tree to HTML string.
 */
class Renderer
{
    private const SELF_CLOSING_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private string $componentId;

    public function __construct(string $componentId)
    {
        $this->componentId = $componentId;
    }

    /**
     * Render a component function.
     *
     * @param callable(): Element $component
     */
    public function render(callable $component): string
    {
        // Initialize component state
        $state = ComponentState::getInstance($this->componentId);
        ComponentState::reset();

        // Execute the component to get the Element tree
        $element = $component();

        // Render the element tree to HTML
        $html = $this->renderElement($element);

        // Wrap with component container
        return sprintf(
            '<div data-usephp-component="%s">%s</div>',
            htmlspecialchars($this->componentId, ENT_QUOTES, 'UTF-8'),
            $html
        );
    }

    /**
     * Re-render a component after an action.
     *
     * @param callable(): Element $component
     */
    public function rerender(callable $component): string
    {
        ComponentState::reset();

        $element = $component();

        return $this->renderElement($element);
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
        $attributes = $this->renderAttributes($element->props);

        // Self-closing tags
        if (in_array($tag, self::SELF_CLOSING_TAGS, true)) {
            return "<{$tag}{$attributes} />";
        }

        $children = $this->renderChildren($element->children);

        return "<{$tag}{$attributes}>{$children}</{$tag}>";
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
            // Handle wire:* attributes (Actions)
            if (str_starts_with($key, 'wire:') && $value instanceof Action) {
                $eventType = substr($key, 5); // Remove 'wire:' prefix
                $actionJson = htmlspecialchars($value->toJson(), ENT_QUOTES, 'UTF-8');
                $attributes[] = sprintf('data-usephp-action="%s"', $actionJson);
                $attributes[] = sprintf('data-usephp-event="%s"', $eventType);
                continue;
            }

            // Handle boolean attributes
            if (is_bool($value)) {
                if ($value) {
                    $attributes[] = $key;
                }
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
     * @param array<Element|string> $children
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
}
