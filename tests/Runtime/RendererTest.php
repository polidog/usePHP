<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

use Polidog\UsePhp\Html\H;

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
}
