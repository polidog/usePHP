<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

use function Polidog\UsePhp\Runtime\useState;

class RendererTest extends TestCase
{
    public function testRenderSimpleElement(): void
    {
        $renderer = new Renderer('test');

        $element = H::div(className: 'container', children: 'Hello');

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('class="container"', $html);
        $this->assertStringNotContainsString('className=', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    /**
     * @return iterable<string, array{string, string|bool, string}>
     */
    public static function jsxAttrCases(): iterable
    {
        // HTML attributes
        yield 'className → class' => ['className', 'card', 'class="card"'];
        yield 'htmlFor → for' => ['htmlFor', 'email', 'for="email"'];
        yield 'tabIndex → tabindex' => ['tabIndex', '0', 'tabindex="0"'];
        yield 'readOnly → readonly (bool)' => ['readOnly', true, ' readonly'];
        yield 'autoFocus → autofocus (bool)' => ['autoFocus', true, ' autofocus'];
        yield 'autoComplete → autocomplete' => ['autoComplete', 'off', 'autocomplete="off"'];
        yield 'autoPlay → autoplay (bool)' => ['autoPlay', true, ' autoplay'];
        yield 'crossOrigin → crossorigin' => ['crossOrigin', 'anonymous', 'crossorigin="anonymous"'];
        yield 'contentEditable → contenteditable' => ['contentEditable', 'true', 'contenteditable="true"'];
        yield 'spellCheck → spellcheck' => ['spellCheck', 'false', 'spellcheck="false"'];
        yield 'srcSet → srcset' => ['srcSet', 'a.png 1x', 'srcset="a.png 1x"'];
        yield 'maxLength → maxlength' => ['maxLength', '10', 'maxlength="10"'];
        yield 'minLength → minlength' => ['minLength', '2', 'minlength="2"'];
        yield 'colSpan → colspan' => ['colSpan', '2', 'colspan="2"'];
        yield 'rowSpan → rowspan' => ['rowSpan', '3', 'rowspan="3"'];
        yield 'dateTime → datetime' => ['dateTime', '2026-01-01', 'datetime="2026-01-01"'];
        yield 'encType → enctype' => ['encType', 'multipart/form-data', 'enctype="multipart/form-data"'];
        yield 'httpEquiv → http-equiv' => ['httpEquiv', 'refresh', 'http-equiv="refresh"'];
        yield 'acceptCharset → accept-charset' => ['acceptCharset', 'utf-8', 'accept-charset="utf-8"'];
        yield 'noValidate → novalidate (bool)' => ['noValidate', true, ' novalidate'];
        yield 'formNoValidate → formnovalidate (bool)' => ['formNoValidate', true, ' formnovalidate'];
        yield 'referrerPolicy → referrerpolicy' => ['referrerPolicy', 'no-referrer', 'referrerpolicy="no-referrer"'];
        yield 'accessKey → accesskey' => ['accessKey', 's', 'accesskey="s"'];
        yield 'inputMode → inputmode' => ['inputMode', 'numeric', 'inputmode="numeric"'];

        // SVG hyphenated attributes
        yield 'strokeLinecap → stroke-linecap' => ['strokeLinecap', 'round', 'stroke-linecap="round"'];
        yield 'strokeLinejoin → stroke-linejoin' => ['strokeLinejoin', 'round', 'stroke-linejoin="round"'];
        yield 'strokeWidth → stroke-width' => ['strokeWidth', '2', 'stroke-width="2"'];
        yield 'strokeDasharray → stroke-dasharray' => ['strokeDasharray', '4 2', 'stroke-dasharray="4 2"'];
        yield 'strokeMiterlimit → stroke-miterlimit' => ['strokeMiterlimit', '4', 'stroke-miterlimit="4"'];
        yield 'fillRule → fill-rule' => ['fillRule', 'evenodd', 'fill-rule="evenodd"'];
        yield 'clipRule → clip-rule' => ['clipRule', 'evenodd', 'clip-rule="evenodd"'];
        yield 'clipPath → clip-path' => ['clipPath', 'url(#a)', 'clip-path="url(#a)"'];
        yield 'fillOpacity → fill-opacity' => ['fillOpacity', '0.5', 'fill-opacity="0.5"'];
        yield 'strokeOpacity → stroke-opacity' => ['strokeOpacity', '0.8', 'stroke-opacity="0.8"'];
        yield 'stopColor → stop-color' => ['stopColor', '#fff', 'stop-color="#fff"'];
        yield 'stopOpacity → stop-opacity' => ['stopOpacity', '1', 'stop-opacity="1"'];
        yield 'textAnchor → text-anchor' => ['textAnchor', 'middle', 'text-anchor="middle"'];
        yield 'dominantBaseline → dominant-baseline' => ['dominantBaseline', 'central', 'dominant-baseline="central"'];
        yield 'pointerEvents → pointer-events' => ['pointerEvents', 'none', 'pointer-events="none"'];
        yield 'markerEnd → marker-end' => ['markerEnd', 'url(#a)', 'marker-end="url(#a)"'];
        yield 'fontFamily → font-family' => ['fontFamily', 'sans', 'font-family="sans"'];
        yield 'shapeRendering → shape-rendering' => ['shapeRendering', 'auto', 'shape-rendering="auto"'];

        // XLink / XML namespaces
        yield 'xlinkHref → xlink:href' => ['xlinkHref', '#a', 'xlink:href="#a"'];
        yield 'xlinkRole → xlink:role' => ['xlinkRole', 'link', 'xlink:role="link"'];
        yield 'xlinkShow → xlink:show' => ['xlinkShow', 'new', 'xlink:show="new"'];
        yield 'xlinkTitle → xlink:title' => ['xlinkTitle', 't', 'xlink:title="t"'];
        yield 'xlinkActuate → xlink:actuate' => ['xlinkActuate', 'onLoad', 'xlink:actuate="onLoad"'];
        yield 'xmlLang → xml:lang' => ['xmlLang', 'ja', 'xml:lang="ja"'];
        yield 'xmlSpace → xml:space' => ['xmlSpace', 'preserve', 'xml:space="preserve"'];
        yield 'xmlnsXlink → xmlns:xlink' => [
            'xmlnsXlink', 'http://www.w3.org/1999/xlink', 'xmlns:xlink="http://www.w3.org/1999/xlink"',
        ];
    }

    /**
     * @param string|bool $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsxAttrCases')]
    public function testRenderMapsJsxAttributeNamesToHtml(string $jsxName, $value, string $expected): void
    {
        $renderer = new Renderer('test');

        $element = H::__callStatic('div', [$jsxName => $value]);
        $html = $renderer->renderElement($element);

        $this->assertStringContainsString($expected, $html);
        $this->assertStringNotContainsString($jsxName . '=', $html);
    }

    public function testCanonicalAttributesPassThroughUnchanged(): void
    {
        $renderer = new Renderer('test');

        // viewBox / preserveAspectRatio / gradientUnits are canonical camelCase
        // in SVG and must NOT be rewritten by the JSX→HTML map.
        $element = H::__callStatic('svg', [
            'viewBox' => '0 0 24 24',
            'preserveAspectRatio' => 'xMidYMid meet',
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
        $this->assertStringContainsString('preserveAspectRatio="xMidYMid meet"', $html);
    }

    public function testDataAndAriaAttributesPassThroughUnchanged(): void
    {
        $renderer = new Renderer('test');

        $element = H::__callStatic('div', [
            'data-id' => '42',
            'aria-label' => 'close',
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('data-id="42"', $html);
        $this->assertStringContainsString('aria-label="close"', $html);
    }

    public function testRenderNestedElements(): void
    {
        $renderer = new Renderer('test');

        $element = H::div(
            children: [
                H::span(children: 'First'),
                H::span(children: 'Second'),
            ]
        );

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('<span>First</span>', $html);
        $this->assertStringContainsString('<span>Second</span>', $html);
    }

    public function testRenderWithUseState(): void
    {
        $renderer = new Renderer('counter');

        // Simulate component rendering
        $component = function (): Element {
            [$count, $setCount] = useState(42);
            return H::div(children: "Count: {$count}");
        };

        $html = $renderer->render($component);

        $this->assertStringContainsString('Count: 42', $html);
    }

    public function testRenderButtonWithOnClickGeneratesForm(): void
    {
        $renderer = new Renderer('test');

        // Simulate component rendering
        $component = function (): Element {
            [$count, $setCount] = useState(0);
            return H::button(
                onClick: fn() => $setCount($count + 1),
                children: 'Click'
            );
        };

        $html = $renderer->render($component);

        // Should generate a form with hidden inputs
        $this->assertStringContainsString('<form method="post"', $html);
        $this->assertStringContainsString('name="_usephp_component"', $html);
        $this->assertStringContainsString('name="_usephp_action"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('>Click</button>', $html);
    }

    public function testRenderSelfClosingTag(): void
    {
        $renderer = new Renderer('test');

        $element = H::input(type: 'text', placeholder: 'Enter text');

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('/>', $html);
    }

    public function testRenderEscapesHtml(): void
    {
        $renderer = new Renderer('test');

        $element = H::div(children: '<script>alert("XSS")</script>');

        $html = $renderer->renderElement($element);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testNoJavaScriptInOutput(): void
    {
        $renderer = new Renderer('test');

        $component = function (): Element {
            [$count, $setCount] = useState(0);
            return H::div(
                children: [
                    H::span(children: "Count: {$count}"),
                    H::button(onClick: fn() => $setCount($count + 1), children: '+'),
                ]
            );
        };

        $html = $renderer->render($component);

        // No JavaScript attributes
        $this->assertStringNotContainsString('onclick=', strtolower($html));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testConditionalRenderingWithTernary(): void
    {
        $renderer = new Renderer('test');

        $isLoggedIn = true;
        $element = H::div(children: [
            $isLoggedIn ? H::span(children: 'Welcome') : H::span(children: 'Please login'),
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringNotContainsString('Please login', $html);
    }

    public function testConditionalRenderingWithNull(): void
    {
        $renderer = new Renderer('test');

        $showModal = false;
        $element = H::div(children: [
            H::span(children: 'Always visible'),
            $showModal ? H::div(children: 'Modal content') : null,
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('Always visible', $html);
        $this->assertStringNotContainsString('Modal content', $html);
    }

    public function testConditionalRenderingShowsElementWhenTrue(): void
    {
        $renderer = new Renderer('test');

        $showModal = true;
        $element = H::div(children: [
            H::span(children: 'Always visible'),
            $showModal ? H::div(children: 'Modal content') : null,
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('Always visible', $html);
        $this->assertStringContainsString('Modal content', $html);
    }

    public function testMultipleConditionalChildren(): void
    {
        $renderer = new Renderer('test');

        $hasItems = true;
        $isAdmin = false;
        $showFooter = true;

        $element = H::div(children: [
            $hasItems ? H::ul(children: H::li(children: 'Item 1')) : null,
            $isAdmin ? H::div(children: 'Admin panel') : null,
            $showFooter ? H::footer(children: 'Footer') : null,
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('Item 1', $html);
        $this->assertStringNotContainsString('Admin panel', $html);
        $this->assertStringContainsString('<footer>', $html);
    }

    public function testNullAndFalseAreIgnoredInChildren(): void
    {
        $renderer = new Renderer('test');

        $element = H::div(children: [
            null,
            H::span(children: 'Visible'),
            false,
            null,
        ]);

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('<span>Visible</span>', $html);
        // Should not contain "null" or "false" as text
        $this->assertStringNotContainsString('null', $html);
        $this->assertStringNotContainsString('false', $html);
    }

    public function testRenderDeferEmitsPlaceholderWithFallback(): void
    {
        $renderer = new Renderer('test');

        $element = H::defer(
            'user-header',
            [],
            H::div(className: 'skeleton', children: 'Loading...'),
        );

        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('data-usephp-defer-url="/_defer/user-header"', $html);
        // Fallback is rendered inline.
        $this->assertStringContainsString('<div class="skeleton">Loading...</div>', $html);
        // No signed payload — names are public identifiers.
        $this->assertStringNotContainsString('data-usephp-defer-sig', $html);
        $this->assertStringNotContainsString('data-usephp-defer-payload', $html);
    }

    public function testRenderDeferEmptyFallback(): void
    {
        $renderer = new Renderer('test');

        $element = H::defer('foo');

        $html = $renderer->renderElement($element);

        // No inner content between the wrapper tags.
        $this->assertSame('<div data-usephp-defer-url="/_defer/foo"></div>', $html);
    }

    public function testRenderDeferEncodesScalarParamsAsQueryString(): void
    {
        $renderer = new Renderer('test');

        $element = H::defer('post-comments', ['post_id' => 123, 'sort' => 'new']);
        $html = $renderer->renderElement($element);

        $this->assertStringContainsString(
            'data-usephp-defer-url="/_defer/post-comments?post_id=123&amp;sort=new"',
            $html,
        );
    }

    public function testRenderDeferUsesCustomPrefix(): void
    {
        $renderer = new Renderer('test', deferPrefix: '/api/_d');

        $element = H::defer('user-header');
        $html = $renderer->renderElement($element);

        $this->assertStringContainsString('data-usephp-defer-url="/api/_d/user-header"', $html);
    }

    public function testRenderDeferThrowsForInvalidName(): void
    {
        $renderer = new Renderer('test');
        $element = H::defer('not/allowed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL-safe');
        $renderer->renderElement($element);
    }

    public function testRenderDeferRejectsNonScalarParams(): void
    {
        $renderer = new Renderer('test');
        $element = H::defer('x', ['bad' => ['nested' => true]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be scalar');
        $renderer->renderElement($element);
    }

    public function testRenderDeferEmitsClientCacheAttributeWhenOptedIn(): void
    {
        $renderer = new Renderer('test');

        // localCache is the explicit, component-side opt-in. usephp.js keys
        // off the bare attribute's presence (no value, no TTL) and never
        // reads the Cache-Control header.
        $element = H::defer('announcement-bar', [], null, true);
        $html = $renderer->renderElement($element);

        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/announcement-bar" data-usephp-defer-cache></div>',
            $html,
        );
    }

    public function testRenderDeferOmitsClientCacheAttributeWhenNotOptedIn(): void
    {
        $renderer = new Renderer('test');

        // No localCache → byte-for-byte identical to the pre-feature
        // markup, so non-opted-in components keep the old L1-only behaviour.
        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/x"></div>',
            $renderer->renderElement(H::defer('x')),
        );
        $this->assertStringNotContainsString(
            'data-usephp-defer-cache',
            $renderer->renderElement(H::defer('y', [], null, false)),
        );
    }

    public function testRenderDeferEmitsNameAttributeWhenReloadable(): void
    {
        $renderer = new Renderer('test');

        // reloadable is the explicit, component-side opt-in for keeping a
        // re-targetable wrapper. usephp.js keys off the presence of
        // data-usephp-defer-name (and uses its value as the reload handle).
        $element = H::defer('todo-list', [], null, false, true);
        $html = $renderer->renderElement($element);

        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/todo-list" data-usephp-defer-name="todo-list"></div>',
            $html,
        );
    }

    public function testRenderDeferOmitsNameAttributeWhenNotReloadable(): void
    {
        $renderer = new Renderer('test');

        // No reloadable → byte-for-byte identical to the pre-feature
        // markup, so the placeholder is still replaced away on resolve.
        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/x"></div>',
            $renderer->renderElement(H::defer('x')),
        );
        $this->assertStringNotContainsString(
            'data-usephp-defer-name',
            $renderer->renderElement(H::defer('y', [], null, false, false)),
        );
    }

    public function testRenderDeferEmitsCacheAndNameTogether(): void
    {
        $renderer = new Renderer('test');

        // The two opt-ins are independent and must compose without
        // clobbering each other or the query string. Attribute order is
        // url, cache, name (asserted exactly so the markup contract is
        // pinned).
        $element = H::defer('todo-list', ['page' => 2], null, true, true);
        $html = $renderer->renderElement($element);

        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/todo-list?page=2"'
            . ' data-usephp-defer-cache data-usephp-defer-name="todo-list"></div>',
            $html,
        );
    }

    public function testRenderDeferEmitsCacheTtlAttributeWhenSet(): void
    {
        $renderer = new Renderer('test');

        // A positive localCacheTtl is surfaced as its own attribute,
        // sitting right after the bare opt-in it refines. Pinned exactly
        // so the markup contract usephp.js parses can't drift.
        $element = H::defer('feed', [], null, true, false, 60);
        $html = $renderer->renderElement($element);

        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/feed"'
            . ' data-usephp-defer-cache data-usephp-defer-cache-ttl="60"></div>',
            $html,
        );
    }

    public function testRenderDeferOmitsCacheTtlAttributeWhenZero(): void
    {
        $renderer = new Renderer('test');

        // ttl 0 (the default) → no attribute, so an opted-in component
        // with no TTL is byte-for-byte identical to before this feature.
        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/x" data-usephp-defer-cache></div>',
            $renderer->renderElement(H::defer('x', [], null, true, false, 0)),
        );
        $this->assertStringNotContainsString(
            'data-usephp-defer-cache-ttl',
            $renderer->renderElement(H::defer('y', [], null, true)),
        );
    }

    public function testRenderDeferTreatsNonPositiveCacheTtlAsNoBound(): void
    {
        $renderer = new Renderer('test');

        // Defer::__construct normalises a negative away, but a raw
        // H::defer() can still pass one. The renderer's `> 0` test is the
        // single decision point: a non-positive TTL deliberately omits the
        // attribute (no bound) rather than being an error or emitting one
        // usephp.js would ignore.
        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/x" data-usephp-defer-cache></div>',
            $renderer->renderElement(H::defer('x', [], null, true, false, -1)),
        );
    }

    public function testRenderDeferOmitsCacheTtlAttributeWithoutLocalCache(): void
    {
        $renderer = new Renderer('test');

        // The Defer constructor rejects ttl-without-localCache, but a raw
        // H::defer() call can still pass it; the renderer must not emit a
        // dangling attribute usephp.js would ignore (no opt-in → no L2).
        $html = $renderer->renderElement(H::defer('x', [], null, false, false, 60));
        $this->assertSame('<div data-usephp-defer-url="/_defer/x"></div>', $html);
    }

    public function testRenderDeferEmitsCacheTtlAndNameTogether(): void
    {
        $renderer = new Renderer('test');

        // All three opt-ins compose; attribute order is pinned as
        // url, cache, cache-ttl, name.
        $element = H::defer('todo-list', ['page' => 2], null, true, true, 30);
        $html = $renderer->renderElement($element);

        $this->assertSame(
            '<div data-usephp-defer-url="/_defer/todo-list?page=2"'
            . ' data-usephp-defer-cache data-usephp-defer-cache-ttl="30"'
            . ' data-usephp-defer-name="todo-list"></div>',
            $html,
        );
    }
}
