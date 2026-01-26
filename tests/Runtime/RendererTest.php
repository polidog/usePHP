<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

use function Polidog\UsePhp\Html\button;
use function Polidog\UsePhp\Html\div;
use function Polidog\UsePhp\Html\input;
use function Polidog\UsePhp\Html\span;
use function Polidog\UsePhp\Runtime\useState;

class RendererTest extends TestCase
{
    public function testRenderSimpleElement(): void
    {
        $renderer = new Renderer('test');

        $component = function (): Element {
            return div(className: 'container', children: 'Hello');
        };

        $html = $renderer->render($component);

        $this->assertStringContainsString('data-usephp-component="test"', $html);
        $this->assertStringContainsString('className="container"', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function testRenderNestedElements(): void
    {
        $renderer = new Renderer('test');

        $component = function (): Element {
            return div(
                children: [
                    span(children: 'First'),
                    span(children: 'Second'),
                ]
            );
        };

        $html = $renderer->render($component);

        $this->assertStringContainsString('<span>First</span>', $html);
        $this->assertStringContainsString('<span>Second</span>', $html);
    }

    public function testRenderWithUseState(): void
    {
        $renderer = new Renderer('counter');

        $component = function (): Element {
            [$count, $setCount] = useState(42);

            return div(children: "Count: {$count}");
        };

        $html = $renderer->render($component);

        $this->assertStringContainsString('Count: 42', $html);
    }

    public function testRenderButtonWithOnClick(): void
    {
        $renderer = new Renderer('test');

        $component = function (): Element {
            [$count, $setCount] = useState(0);

            return button(
                onClick: fn() => $setCount($count + 1),
                children: 'Click'
            );
        };

        $html = $renderer->render($component);

        $this->assertStringContainsString('data-usephp-action=', $html);
        $this->assertStringContainsString('data-usephp-event="click"', $html);
        // JSON is HTML-encoded in attributes
        $this->assertStringContainsString('&quot;type&quot;:&quot;setState&quot;', $html);
        $this->assertStringContainsString('&quot;value&quot;:1', $html);
    }

    public function testRenderSelfClosingTag(): void
    {
        $renderer = new Renderer('test');

        $component = function (): Element {
            return input(type: 'text', placeholder: 'Enter text');
        };

        $html = $renderer->render($component);

        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('/>', $html);
    }

    public function testRenderEscapesHtml(): void
    {
        $renderer = new Renderer('test');

        $component = function (): Element {
            return div(children: '<script>alert("XSS")</script>');
        };

        $html = $renderer->render($component);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
