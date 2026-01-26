<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Html;

use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\Element;

/**
 * Create a div element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function div(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    Action|callable|null $onClick = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('div', get_defined_vars());
}

/**
 * Create a span element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function span(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    Action|callable|null $onClick = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('span', get_defined_vars());
}

/**
 * Create a button element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function button(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    ?string $type = null,
    ?bool $disabled = null,
    Action|callable|null $onClick = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('button', get_defined_vars());
}

/**
 * Create an h1 element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function h1(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('h1', get_defined_vars());
}

/**
 * Create an h2 element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function h2(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('h2', get_defined_vars());
}

/**
 * Create an h3 element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function h3(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('h3', get_defined_vars());
}

/**
 * Create a p (paragraph) element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function p(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('p', get_defined_vars());
}

/**
 * Create an a (anchor) element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function a(
    ?string $href = null,
    ?string $target = null,
    ?string $rel = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    Action|callable|null $onClick = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('a', get_defined_vars());
}

/**
 * Create an input element.
 */
function input(
    ?string $type = null,
    ?string $name = null,
    ?string $value = null,
    ?string $placeholder = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    ?bool $disabled = null,
    ?bool $readOnly = null,
    Action|callable|null $onChange = null,
    Action|callable|null $onInput = null,
    Action|callable|null $onFocus = null,
    Action|callable|null $onBlur = null,
): Element {
    return createElement('input', get_defined_vars());
}

/**
 * Create a textarea element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function textarea(
    ?string $name = null,
    ?string $placeholder = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    ?int $rows = null,
    ?int $cols = null,
    ?bool $disabled = null,
    ?bool $readOnly = null,
    Action|callable|null $onChange = null,
    Action|callable|null $onInput = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('textarea', get_defined_vars());
}

/**
 * Create a form element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function form(
    ?string $action = null,
    ?string $method = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    Action|callable|null $onSubmit = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('form', get_defined_vars());
}

/**
 * Create a label element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function label(
    ?string $for = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    $props = get_defined_vars();
    if (isset($props['for'])) {
        $props['htmlFor'] = $props['for'];
        unset($props['for']);
    }
    return createElement('label', $props);
}

/**
 * Create an img element.
 */
function img(
    ?string $src = null,
    ?string $alt = null,
    ?int $width = null,
    ?int $height = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    Action|callable|null $onLoad = null,
    Action|callable|null $onError = null,
): Element {
    return createElement('img', get_defined_vars());
}

/**
 * Create a ul (unordered list) element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function ul(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('ul', get_defined_vars());
}

/**
 * Create an ol (ordered list) element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function ol(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('ol', get_defined_vars());
}

/**
 * Create an li (list item) element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function li(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    Action|callable|null $onClick = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('li', get_defined_vars());
}

/**
 * Create a select element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function select(
    ?string $name = null,
    ?string $value = null,
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    ?bool $disabled = null,
    ?bool $multiple = null,
    Action|callable|null $onChange = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('select', get_defined_vars());
}

/**
 * Create an option element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function option(
    ?string $value = null,
    ?bool $selected = null,
    ?bool $disabled = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('option', get_defined_vars());
}

/**
 * Create a section element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function section(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('section', get_defined_vars());
}

/**
 * Create a header element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function header(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('header', get_defined_vars());
}

/**
 * Create a footer element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function footer(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('footer', get_defined_vars());
}

/**
 * Create a nav element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function nav(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('nav', get_defined_vars());
}

/**
 * Create a main element.
 *
 * @param array<Element|string>|Element|string|null $children
 */
function main(
    ?string $className = null,
    ?string $id = null,
    ?string $style = null,
    array|Element|string|null $children = null,
): Element {
    return createElement('main', get_defined_vars());
}

/**
 * Create a Fragment (groups elements without extra DOM node).
 *
 * @param array<Element|string> $children
 */
function Fragment(array $children): Element
{
    return new Element('Fragment', [], $children);
}

/**
 * Internal helper to create an element from parameters.
 *
 * @param array<string, mixed> $params
 */
function createElement(string $type, array $params): Element
{
    $children = [];
    $props = [];

    // Event handlers that map to wire:* attributes
    $eventHandlers = [
        'onClick' => 'click',
        'onChange' => 'change',
        'onInput' => 'input',
        'onSubmit' => 'submit',
        'onFocus' => 'focus',
        'onBlur' => 'blur',
        'onLoad' => 'load',
        'onError' => 'error',
    ];

    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }

        if ($key === 'children') {
            if (is_array($value)) {
                $children = $value;
            } else {
                $children = [$value];
            }
        } elseif (isset($eventHandlers[$key])) {
            // Handle event handlers
            $action = $value;

            // If it's a callable, execute it to get the Action
            if (is_callable($value) && !($value instanceof Action)) {
                $action = $value();
            }

            if ($action instanceof Action) {
                $wireKey = 'wire:' . $eventHandlers[$key];
                $props[$wireKey] = $action;
            }
        } else {
            $props[$key] = $value;
        }
    }

    return new Element($type, $props, $children);
}
