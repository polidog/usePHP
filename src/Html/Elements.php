<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Html;

use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\Element;

/**
 * H class - Hyperscript-style static methods for all HTML elements.
 *
 * Usage:
 *   H::article(className: 'post', children: [...])
 *   H::video(src: 'movie.mp4', controls: true)
 *   H::table(children: [...])
 */
final class H
{
    // =========================================================================
    // Text Content
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function div(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onClick = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('div', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function span(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onClick = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('span', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function p(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('p', get_defined_vars());
    }

    // =========================================================================
    // Headings
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function h1(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('h1', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function h2(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('h2', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function h3(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('h3', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function h4(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('h4', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function h5(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('h5', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function h6(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('h6', get_defined_vars());
    }

    // =========================================================================
    // Inline Text Semantics
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function a(
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

    /** @param array<Element|string>|Element|string|null $children */
    public static function strong(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('strong', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function em(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('em', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function small(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('small', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function mark(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('mark', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function del(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('del', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function ins(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('ins', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function sub(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('sub', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function sup(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('sup', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function code(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('code', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function pre(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('pre', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function blockquote(
        ?string $cite = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('blockquote', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function q(
        ?string $cite = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('q', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function abbr(
        ?string $title = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('abbr', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function cite(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('cite', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function time(
        ?string $datetime = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('time', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function kbd(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('kbd', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function samp(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('samp', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function varElement(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('var', get_defined_vars());
    }

    public static function br(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('br', get_defined_vars());
    }

    public static function wbr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('wbr', get_defined_vars());
    }

    public static function hr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('hr', get_defined_vars());
    }

    // =========================================================================
    // Lists
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function ul(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('ul', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function ol(
        ?string $start = null,
        ?string $type = null,
        ?bool $reversed = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('ol', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function li(
        ?string $value = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onClick = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('li', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function dl(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('dl', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function dt(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('dt', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function dd(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('dd', get_defined_vars());
    }

    // =========================================================================
    // Tables
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function table(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('table', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function thead(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('thead', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function tbody(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('tbody', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function tfoot(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('tfoot', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function tr(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('tr', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function th(
        ?int $colspan = null,
        ?int $rowspan = null,
        ?string $scope = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('th', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function td(
        ?int $colspan = null,
        ?int $rowspan = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('td', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function caption(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('caption', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function colgroup(
        ?int $span = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('colgroup', get_defined_vars());
    }

    public static function col(
        ?int $span = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('col', get_defined_vars());
    }

    // =========================================================================
    // Forms
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
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
        Action|callable|null $onSubmit = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('form', get_defined_vars());
    }

    public static function input(
        ?string $type = null,
        ?string $name = null,
        ?string $value = null,
        ?string $placeholder = null,
        ?bool $checked = null,
        ?bool $disabled = null,
        ?bool $readOnly = null,
        ?bool $required = null,
        ?string $min = null,
        ?string $max = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?string $accept = null,
        ?bool $multiple = null,
        ?int $step = null,
        ?string $autocomplete = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onChange = null,
        Action|callable|null $onInput = null,
        Action|callable|null $onFocus = null,
        Action|callable|null $onBlur = null,
    ): Element {
        return createElement('input', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function textarea(
        ?string $name = null,
        ?string $placeholder = null,
        ?int $rows = null,
        ?int $cols = null,
        ?bool $disabled = null,
        ?bool $readOnly = null,
        ?bool $required = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $wrap = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onChange = null,
        Action|callable|null $onInput = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('textarea', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function button(
        ?string $type = null,
        ?string $name = null,
        ?string $value = null,
        ?bool $disabled = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onClick = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('button', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function select(
        ?string $name = null,
        ?string $value = null,
        ?bool $disabled = null,
        ?bool $multiple = null,
        ?bool $required = null,
        ?int $size = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onChange = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('select', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function option(
        ?string $value = null,
        ?bool $selected = null,
        ?bool $disabled = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('option', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function optgroup(
        ?string $label = null,
        ?bool $disabled = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('optgroup', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function label(
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

    /** @param array<Element|string>|Element|string|null $children */
    public static function fieldset(
        ?bool $disabled = null,
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('fieldset', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function legend(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('legend', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function datalist(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('datalist', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function output(
        ?string $for = null,
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('output', get_defined_vars());
    }

    public static function progress(
        ?float $value = null,
        ?float $max = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('progress', get_defined_vars());
    }

    public static function meter(
        ?float $value = null,
        ?float $min = null,
        ?float $max = null,
        ?float $low = null,
        ?float $high = null,
        ?float $optimum = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('meter', get_defined_vars());
    }

    // =========================================================================
    // Semantic / Sectioning
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function header(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('header', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function footer(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('footer', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function nav(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('nav', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function main(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('main', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function article(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('article', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function section(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('section', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function aside(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('aside', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function figure(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('figure', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function figcaption(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('figcaption', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function details(
        ?bool $open = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onClick = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('details', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function summary(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('summary', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function dialog(
        ?bool $open = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('dialog', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function address(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('address', get_defined_vars());
    }

    // =========================================================================
    // Media
    // =========================================================================

    public static function img(
        ?string $src = null,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?string $loading = null,
        ?string $decoding = null,
        ?string $srcset = null,
        ?string $sizes = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        Action|callable|null $onLoad = null,
        Action|callable|null $onError = null,
    ): Element {
        return createElement('img', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
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
        array|Element|string|null $children = null,
    ): Element {
        return createElement('audio', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function video(
        ?string $src = null,
        ?string $poster = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $controls = null,
        ?bool $autoplay = null,
        ?bool $loop = null,
        ?bool $muted = null,
        ?string $preload = null,
        ?bool $playsinline = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('video', get_defined_vars());
    }

    public static function source(
        ?string $src = null,
        ?string $type = null,
        ?string $srcset = null,
        ?string $sizes = null,
        ?string $media = null,
    ): Element {
        return createElement('source', get_defined_vars());
    }

    public static function track(
        ?string $src = null,
        ?string $kind = null,
        ?string $srclang = null,
        ?string $label = null,
        ?bool $default = null,
    ): Element {
        return createElement('track', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function picture(
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('picture', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function canvas(
        ?int $width = null,
        ?int $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('canvas', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function svg(
        ?int $width = null,
        ?int $height = null,
        ?string $viewBox = null,
        ?string $fill = null,
        ?string $stroke = null,
        ?string $xmlns = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('svg', get_defined_vars());
    }

    // =========================================================================
    // Embedded Content
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function iframe(
        ?string $src = null,
        ?string $srcdoc = null,
        ?string $name = null,
        ?int $width = null,
        ?int $height = null,
        ?string $sandbox = null,
        ?string $allow = null,
        ?string $loading = null,
        ?string $referrerpolicy = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('iframe', get_defined_vars());
    }

    public static function embed(
        ?string $src = null,
        ?string $type = null,
        ?int $width = null,
        ?int $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
    ): Element {
        return createElement('embed', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function object(
        ?string $data = null,
        ?string $type = null,
        ?string $name = null,
        ?int $width = null,
        ?int $height = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('object', get_defined_vars());
    }

    public static function param(
        ?string $name = null,
        ?string $value = null,
    ): Element {
        return createElement('param', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function map(
        ?string $name = null,
        ?string $className = null,
        ?string $id = null,
        ?string $style = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('map', get_defined_vars());
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
    ): Element {
        return createElement('area', get_defined_vars());
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
    ): Element {
        return createElement('link', get_defined_vars());
    }

    public static function meta(
        ?string $name = null,
        ?string $content = null,
        ?string $charset = null,
        ?string $httpEquiv = null,
        ?string $property = null,
    ): Element {
        return createElement('meta', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function title(
        array|Element|string|null $children = null,
    ): Element {
        return createElement('title', get_defined_vars());
    }

    public static function base(
        ?string $href = null,
        ?string $target = null,
    ): Element {
        return createElement('base', get_defined_vars());
    }

    // =========================================================================
    // Scripting (use with caution)
    // =========================================================================

    /** @param array<Element|string>|Element|string|null $children */
    public static function script(
        ?string $src = null,
        ?string $type = null,
        ?bool $async = null,
        ?bool $defer = null,
        ?string $crossorigin = null,
        ?string $integrity = null,
        ?bool $nomodule = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('script', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function noscript(
        array|Element|string|null $children = null,
    ): Element {
        return createElement('noscript', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function template(
        ?string $className = null,
        ?string $id = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('template', get_defined_vars());
    }

    /** @param array<Element|string>|Element|string|null $children */
    public static function slot(
        ?string $name = null,
        array|Element|string|null $children = null,
    ): Element {
        return createElement('slot', get_defined_vars());
    }

    // =========================================================================
    // Fragment (special)
    // =========================================================================

    /** @param array<Element|string> $children */
    public static function Fragment(array $children): Element
    {
        return new Element('Fragment', [], $children);
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
            $action = null;

            if ($value instanceof Action) {
                $action = $value;
            } elseif (is_callable($value)) {
                // Execute callable to get the Action
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
