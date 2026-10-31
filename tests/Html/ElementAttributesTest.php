<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Html;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

final class ElementAttributesTest extends TestCase
{
    private function render(Element $element): string
    {
        return new Renderer('test#0')->renderElement($element);
    }

    public function testNamedAttributesOutsideTheSignaturePassThrough(): void
    {
        self::assertSame('<div title="x" role="alert"></div>', $this->render(H::div(title: 'x', role: 'alert')));
        self::assertSame('<button title="x"></button>', $this->render(H::button(title: 'x')));
    }

    public function testHyphenatedAttributesPassThroughSpread(): void
    {
        self::assertSame(
            '<div data-x="1" aria-label="y"></div>',
            $this->render(H::div(...['data-x' => '1', 'aria-label' => 'y'])),
        );
        self::assertSame('<button aria-label="x"></button>', $this->render(H::button(...['aria-label' => 'x'])));
    }

    public function testBooleanExtraAttributeRendersBare(): void
    {
        self::assertSame('<a href="/x" download></a>', $this->render(H::a(href: '/x', download: true)));
    }

    public function testJsxNamedExtraAttributeIsMapped(): void
    {
        self::assertSame('<p class="c" tabindex="0"></p>', $this->render(H::p(tabIndex: 0, className: 'c')));
    }

    public function testChildrenStillWorkAlongsideExtras(): void
    {
        self::assertSame('<div title="t">x</div>', $this->render(H::div(title: 't', children: 'x')));
    }

    public function testLabelKeepsForAndAcceptsExtras(): void
    {
        self::assertSame('<label for="a" title="t"></label>', $this->render(H::label(for: 'a', title: 't')));
    }

    public function testTextareaValueBecomesItsContent(): void
    {
        self::assertSame('<textarea>x &lt;y&gt;</textarea>', $this->render(H::textarea(value: 'x <y>')));
        self::assertSame('<textarea>0</textarea>', $this->render(H::__callStatic('textarea', ['value' => 0])));
    }

    public function testExtraAttributeNamesStillGoThroughTheRendererValidation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        $this->render(H::div(...['x onmouseover=alert(1)' => 'y']));
    }

    public function testExtraInlineEventHandlersAreStillRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler attribute "onclick" is not allowed');
        $this->render(H::div(onclick: 'alert(1)'));
    }

    public function testExtraPositionalArgumentsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('named arguments');
        H::param('a', 'b', 'stray');
    }
}
