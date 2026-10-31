<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Html;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderable;
use Polidog\UsePhp\Runtime\RenderContext;

/**
 * H class - Hyperscript-style static methods for all HTML elements.
 *
 * Usage:
 *   H::article(className: 'post', children: [...])
 *   H::video(src: 'movie.mp4', controls: true)
 *   H::table(children: [...])
 *
 * Every tag method also accepts arbitrary extra attributes as named arguments.
 * Names PHP can't spell as a named argument go through spread:
 *   H::div(title: 'x', role: 'alert')
 *   H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x')
 * Attribute names are validated by the Renderer when the element is rendered.
 */
final class H
{
    // =========================================================================
    // Text Content
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function div(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onClick = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('div', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function span(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onClick = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('span', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function p(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('p', get_defined_vars());
    }

    // =========================================================================
    // Headings
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function h1(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h1', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function h2(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h2', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function h3(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h3', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function h4(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h4', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function h5(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h5', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function h6(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('h6', get_defined_vars());
    }

    // =========================================================================
    // Inline Text Semantics
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function a(
        ?string $href = null,
        ?string $target = null,
        ?string $rel = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onClick = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('a', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function strong(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('strong', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function em(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('em', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function small(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('small', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function mark(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('mark', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function del(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('del', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function ins(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('ins', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function sub(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('sub', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function sup(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('sup', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function code(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('code', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function pre(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('pre', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function blockquote(
        ?string $cite = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('blockquote', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function q(
        ?string $cite = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('q', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function abbr(
        ?string $title = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('abbr', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function cite(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('cite', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function time(
        ?string $datetime = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('time', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function kbd(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('kbd', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function samp(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('samp', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function varElement(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('var', get_defined_vars());
    }

    public static function br(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('br', get_defined_vars());
    }

    public static function wbr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('wbr', get_defined_vars());
    }

    public static function hr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('hr', get_defined_vars());
    }

    // =========================================================================
    // Lists
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function ul(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('ul', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function ol(
        string|int|float|null $start = null,
        ?string $type = null,
        ?bool $reversed = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('ol', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function li(
        string|int|float|null $value = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onClick = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('li', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function dl(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dl', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function dt(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dt', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function dd(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dd', get_defined_vars());
    }

    // =========================================================================
    // Tables
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function table(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('table', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function thead(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('thead', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function tbody(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('tbody', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function tfoot(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('tfoot', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function tr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('tr', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function th(
        string|int|float|null $colspan = null,
        string|int|float|null $rowspan = null,
        ?string $scope = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('th', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function td(
        string|int|float|null $colspan = null,
        string|int|float|null $rowspan = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('td', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function caption(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('caption', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function colgroup(
        string|int|float|null $span = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('colgroup', get_defined_vars());
    }

    public static function col(
        string|int|float|null $span = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('col', get_defined_vars());
    }

    // =========================================================================
    // Forms
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function form(
        ?string $action = null,
        ?string $method = null,
        ?string $enctype = null,
        ?string $name = null,
        ?bool $novalidate = null,
        ?string $target = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onSubmit = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('form', get_defined_vars());
    }

    public static function input(
        ?string $type = null,
        ?string $name = null,
        string|int|float|null $value = null,
        ?string $placeholder = null,
        ?bool $checked = null,
        ?bool $disabled = null,
        ?bool $readOnly = null,
        ?bool $required = null,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        string|int|float|null $minLength = null,
        string|int|float|null $maxLength = null,
        ?string $pattern = null,
        ?string $accept = null,
        ?bool $multiple = null,
        string|int|float|null $step = null,
        ?string $autocomplete = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onChange = null,
        Action|\Closure|null $onInput = null,
        Action|\Closure|null $onFocus = null,
        Action|\Closure|null $onBlur = null,
        mixed ...$attrs,
    ): Element {
        return self::element('input', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function textarea(
        ?string $name = null,
        string|int|float|null $value = null,
        ?string $placeholder = null,
        string|int|float|null $rows = null,
        string|int|float|null $cols = null,
        ?bool $disabled = null,
        ?bool $readOnly = null,
        ?bool $required = null,
        string|int|float|null $minLength = null,
        string|int|float|null $maxLength = null,
        ?string $wrap = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onChange = null,
        Action|\Closure|null $onInput = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('textarea', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function button(
        ?string $type = null,
        ?string $name = null,
        string|int|float|null $value = null,
        ?bool $disabled = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onClick = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('button', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function select(
        ?string $name = null,
        string|int|float|null $value = null,
        ?bool $disabled = null,
        ?bool $multiple = null,
        ?bool $required = null,
        string|int|float|null $size = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onChange = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('select', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function option(
        string|int|float|null $value = null,
        ?bool $selected = null,
        ?bool $disabled = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('option', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function optgroup(
        ?string $label = null,
        ?bool $disabled = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('optgroup', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function label(
        ?string $for = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        $props = get_defined_vars();
        if (isset($props['for'])) {
            $props['htmlFor'] = $props['for'];
            unset($props['for']);
        }
        return self::element('label', $props);
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function fieldset(
        ?bool $disabled = null,
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('fieldset', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function legend(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('legend', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function datalist(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('datalist', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function output(
        ?string $for = null,
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('output', get_defined_vars());
    }

    public static function progress(
        string|int|float|null $value = null,
        string|int|float|null $max = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('progress', get_defined_vars());
    }

    public static function meter(
        string|int|float|null $value = null,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        string|int|float|null $low = null,
        string|int|float|null $high = null,
        string|int|float|null $optimum = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('meter', get_defined_vars());
    }

    // =========================================================================
    // Semantic / Sectioning
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function header(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('header', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function footer(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('footer', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function nav(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('nav', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function main(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('main', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function article(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('article', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function section(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('section', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function aside(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('aside', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function figure(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('figure', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function figcaption(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('figcaption', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function details(
        ?bool $open = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onClick = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('details', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function summary(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('summary', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function dialog(
        ?bool $open = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('dialog', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function address(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('address', get_defined_vars());
    }

    // =========================================================================
    // Media
    // =========================================================================

    public static function img(
        ?string $src = null,
        ?string $alt = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $loading = null,
        ?string $decoding = null,
        ?string $srcset = null,
        ?string $sizes = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|\Closure|null $onLoad = null,
        Action|\Closure|null $onError = null,
        mixed ...$attrs,
    ): Element {
        return self::element('img', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function audio(
        ?string $src = null,
        ?bool $controls = null,
        ?bool $autoplay = null,
        ?bool $loop = null,
        ?bool $muted = null,
        ?string $preload = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('audio', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function video(
        ?string $src = null,
        ?string $poster = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?bool $controls = null,
        ?bool $autoplay = null,
        ?bool $loop = null,
        ?bool $muted = null,
        ?string $preload = null,
        ?bool $playsinline = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('video', get_defined_vars());
    }

    public static function source(
        ?string $src = null,
        ?string $type = null,
        ?string $srcset = null,
        ?string $sizes = null,
        ?string $media = null,
        mixed ...$attrs,
    ): Element {
        return self::element('source', get_defined_vars());
    }

    public static function track(
        ?string $src = null,
        ?string $kind = null,
        ?string $srclang = null,
        ?string $label = null,
        ?bool $default = null,
        mixed ...$attrs,
    ): Element {
        return self::element('track', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function picture(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('picture', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function canvas(
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('canvas', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function svg(
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $viewBox = null,
        ?string $fill = null,
        ?string $stroke = null,
        ?string $xmlns = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('svg', get_defined_vars());
    }

    // =========================================================================
    // Embedded Content
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function iframe(
        ?string $src = null,
        ?string $srcdoc = null,
        ?string $name = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $sandbox = null,
        ?string $allow = null,
        ?string $loading = null,
        ?string $referrerpolicy = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('iframe', get_defined_vars());
    }

    public static function embed(
        ?string $src = null,
        ?string $type = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('embed', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function object(
        ?string $data = null,
        ?string $type = null,
        ?string $name = null,
        string|int|float|null $width = null,
        string|int|float|null $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('object', get_defined_vars());
    }

    public static function param(
        ?string $name = null,
        string|int|float|null $value = null,
        mixed ...$attrs,
    ): Element {
        return self::element('param', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function map(
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('map', get_defined_vars());
    }

    public static function area(
        ?string $shape = null,
        ?string $coords = null,
        ?string $href = null,
        ?string $alt = null,
        ?string $target = null,
        ?string $rel = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        mixed ...$attrs,
    ): Element {
        return self::element('area', get_defined_vars());
    }

    // =========================================================================
    // Document Metadata (use with caution)
    // =========================================================================

    public static function link(
        ?string $href = null,
        ?string $rel = null,
        ?string $type = null,
        ?string $media = null,
        ?string $sizes = null,
        ?string $crossorigin = null,
        mixed ...$attrs,
    ): Element {
        return self::element('link', get_defined_vars());
    }

    public static function meta(
        ?string $name = null,
        ?string $content = null,
        ?string $charset = null,
        ?string $httpEquiv = null,
        ?string $property = null,
        mixed ...$attrs,
    ): Element {
        return self::element('meta', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function title(
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('title', get_defined_vars());
    }

    public static function base(
        ?string $href = null,
        ?string $target = null,
        mixed ...$attrs,
    ): Element {
        return self::element('base', get_defined_vars());
    }

    // =========================================================================
    // Scripting (use with caution)
    // =========================================================================

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function script(
        ?string $src = null,
        ?string $type = null,
        ?bool $async = null,
        ?bool $defer = null,
        ?string $crossorigin = null,
        ?string $integrity = null,
        ?bool $nomodule = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('script', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function noscript(
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('noscript', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function template(
        ?string $className = null,
        ?string $id = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('template', get_defined_vars());
    }

    /** @param array<Element|string|int|float>|Element|string|int|float|null $children */
    public static function slot(
        ?string $name = null,
        array|Element|string|int|float|null $children = null,
        mixed ...$attrs,
    ): Element {
        return self::element('slot', get_defined_vars());
    }

    // =========================================================================
    // Fragment (special)
    // =========================================================================

    /** @param array<Element|string> $children */
    public static function Fragment(array $children): Element
    {
        return new Element('Fragment', [], resolveRenderables($children));
    }

    // =========================================================================
    // Function Component
    // =========================================================================

    /**
     * Create an element from a function component.
     *
     * @param callable(array<string, mixed>): Element $component
     * @param array<string, mixed> $props
     */
    public static function component(
        callable $component,
        array $props = [],
    ): Element {
        $key = $props['key'] ?? null;
        unset($props['key']);

        return new Element('__function_component__', [
            '__component' => $component,
            '__props' => $props,
            '__key' => $key,
        ], []);
    }

    // =========================================================================
    // Deferred Component
    // =========================================================================

    /**
     * Create a deferred component placeholder.
     *
     * `$name` is the registration name passed to UsePHP::registerDeferred(...).
     * At SSR time only `$fallback` is rendered; usephp.js then issues a GET
     * request to `/_defer/{name}` (with `$params` as query string) after the
     * page loads, and replaces the placeholder with the response body.
     *
     * Because the request goes to a dedicated, name-addressed URL (not back
     * to the page URL), each deferred component can carry its own
     * Cache-Control policy and is independently cacheable by a CDN.
     *
     * @param string $name Registered defer name (URL-safe: `[a-zA-Z0-9_-]+`).
     * @param array<string, scalar> $params URL-safe params forwarded to the
     *        deferred endpoint as query string. Values are stringified.
     * @param Element|null $fallback Fallback element rendered immediately.
     * @param bool $localCache Opt-in client-side (localStorage) caching,
     *        surfaced to usephp.js via a bare placeholder data attribute.
     *        `false` keeps the fragment in the per-page in-memory cache
     *        only — usephp.js never reads the HTTP `Cache-Control` header
     *        to make this decision, and there is no time expiry by default
     *        (see `$localCacheTtl` below to bound the entry by age).
     * @param bool $reloadable Opt-in explicit reload. When `true` the
     *        rendered placeholder also carries `data-usephp-defer-name`, so
     *        usephp.js keeps the wrapper after resolving and the region can
     *        be re-fetched via `window.usePHP.reloadDefer()` or a form's
     *        `data-usephp-reload-defer`. `false` is byte-identical to the
     *        pre-feature markup (placeholder is replaced away on resolve).
     * @param int $localCacheTtl Optional localStorage cache lifetime in
     *        seconds, surfaced via `data-usephp-defer-cache-ttl` (emitted
     *        only when positive and `$localCache` is on). `0` (default) =
     *        no time expiry. A positive value makes usephp.js discard the
     *        persisted entry once it is older than this and re-fetch
     *        (hard discard, not stale-while-revalidate). L2 tier only.
     */
    public static function defer(
        string $name,
        array $params = [],
        ?Element $fallback = null,
        bool $localCache = false,
        bool $reloadable = false,
        int $localCacheTtl = 0,
    ): Element {
        return new Element('__defer__', [
            '__name' => $name,
            '__params' => $params,
            '__fallback' => $fallback,
            '__localCache' => $localCache,
            '__reloadable' => $reloadable,
            '__localCacheTtl' => $localCacheTtl,
        ], []);
    }

    /**
     * Build an element from a tag method's get_defined_vars(), folding the
     * variadic `$attrs` into the regular props.
     *
     * @param array<string, mixed> $vars
     */
    private static function element(string $type, array $vars): Element
    {
        /** @var array<int|string, mixed> $attrs */
        $attrs = $vars['attrs'] ?? [];
        unset($vars['attrs']);

        foreach ($attrs as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'H::%s() takes extra attributes as named arguments; got a positional argument.',
                    $type,
                ));
            }
            $vars[$name] = $value;
        }

        return createElement($type, $vars);
    }

    // =========================================================================
    // Fallback for any undefined element
    // =========================================================================

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): Element
    {
        $params = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return createElement($name, $params);
    }
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

    // <textarea> has no value attribute: its value is its text content.
    if ($type === 'textarea' && is_scalar($params['value'] ?? null)) {
        $params['children'] = (string) $params['value'];
        unset($params['value']);
    }

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
                $children = resolveRenderables($value);
            } else {
                $children = resolveRenderables([$value]);
            }
        } elseif (isset($eventHandlers[$key])) {
            // Handle event handlers. Only Action instances and Closures are
            // accepted: is_callable() would also accept strings such as
            // 'phpinfo' and [$obj, 'method'] arrays, which turns any
            // untrusted value forwarded into an event prop into arbitrary
            // function execution (GH-40).
            if ($value instanceof Action) {
                $action = $value;
            } elseif ($value instanceof \Closure) {
                // Execute the Closure to get the Action
                $action = $value();
            } else {
                throw new \InvalidArgumentException(sprintf(
                    '%s must be an Action or a Closure; got %s',
                    $key,
                    get_debug_type($value),
                ));
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

/**
 * Resolve Renderable objects in children array.
 *
 * This allows components implementing Renderable to be used directly
 * in children arrays without explicitly calling ->render().
 *
 * For BaseComponent instances, this function automatically:
 * - Tracks the component in RenderContext's component stack
 * - Sets up ComponentState if not already set
 *
 * @param array<Element|Renderable|string> $children
 * @return array<Element|string>
 */
function resolveRenderables(array $children): array
{
    $resolved = [];
    foreach ($children as $child) {
        if ($child instanceof Element && $child->type === '__function_component__') {
            // Function component Element
            $resolved[] = resolveFunctionComponent($child);
        } elseif ($child instanceof BaseComponent) {
            // Handle BaseComponent with automatic state and context setup
            if ($child->getComponentState() === null) {
                $componentName = $child::getComponentName();
                $instanceId = RenderContext::beginComponent($componentName);
                $state = ComponentState::getInstance($instanceId);
                $child->setComponentState($state);
                ComponentState::reset();

                $resolved[] = $child->render();

                RenderContext::endComponent();
            } else {
                // Already has state set (e.g., from UsePHP::render())
                $resolved[] = $child->render();
            }
        } elseif ($child instanceof Renderable) {
            $resolved[] = $child->render();
        } else {
            $resolved[] = $child;
        }
    }
    return $resolved;
}

/**
 * Resolve a function component Element with proper state setup.
 */
function resolveFunctionComponent(Element $element): Element
{
    /** @var callable(array<string, mixed>): Element $component */
    $component = $element->props['__component'];
    /** @var array<string, mixed> $props */
    $props = $element->props['__props'];
    /** @var string|null $key */
    $key = $element->props['__key'];

    $componentName = getFunctionComponentName($component);
    $instanceId = RenderContext::beginComponent($componentName, $key);
    ComponentState::getInstance($instanceId);
    ComponentState::reset();

    $result = $component($props);

    RenderContext::endComponent();

    return $result;
}

/**
 * Get a descriptive name for a function component.
 */
function getFunctionComponentName(callable $component): string
{
    if ($component instanceof \Closure) {
        $ref = new \ReflectionFunction($component);
        $line = $ref->getStartLine();
        return 'FC@' . basename($ref->getFileName() ?: 'unknown') . ':' . ($line !== false ? $line : 0);
    }
    if (is_array($component)) {
        return is_object($component[0])
            ? get_class($component[0]) . '::' . $component[1]
            : $component[0] . '::' . $component[1];
    }
    return is_string($component) ? $component : 'AnonymousFC';
}
