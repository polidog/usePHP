<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

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

    public function __construct(string $componentId)
    {
        $this->componentId = $componentId;
    }

    /**
     * Render a component function with wrapper.
     *
     * @param callable(): Element $component
     */
    public function render(callable $component): string
    {
        $state = ComponentState::getInstance($this->componentId);
        ComponentState::reset();

        $element = $component();
        $inner = $this->renderElement($element);

        // Wrap with component container for partial updates
        return sprintf(
            '<div data-usephp="%s">%s</div>',
            htmlspecialchars($this->componentId, ENT_QUOTES, 'UTF-8'),
            $inner
        );
    }

    /**
     * Render a component without wrapper (for partial updates).
     *
     * @param callable(): Element $component
     */
    public function renderPartial(callable $component): string
    {
        $state = ComponentState::getInstance($this->componentId);
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
        $componentId = htmlspecialchars($this->componentId, ENT_QUOTES, 'UTF-8');

        $innerElement = in_array($tag, self::SELF_CLOSING_TAGS, true)
            ? "<{$tag}{$attributes} />"
            : "<{$tag}{$attributes}>{$children}</{$tag}>";

        // data-usephp-form enables JS enhancement, falls back to normal form if no JS
        return <<<HTML
            <form method="post" data-usephp-form style="display:inline;">
            <input type="hidden" name="_usephp_component" value="{$componentId}" />
            <input type="hidden" name="_usephp_action" value="{$actionJson}" />
            {$innerElement}
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
}
