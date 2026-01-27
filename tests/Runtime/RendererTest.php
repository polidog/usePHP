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

        $this->assertStringContainsString('className="container"', $html);
        $this->assertStringContainsString('Hello', $html);
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
}
