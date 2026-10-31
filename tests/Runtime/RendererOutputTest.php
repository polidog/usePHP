<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

class RendererOutputTest extends TestCase
{
    private function render(Element $element): string
    {
        return (new Renderer('test'))->renderElement($element);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousUrlProvider(): iterable
    {
        yield 'tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'newline inside the scheme' => ["java\nscript:alert(1)"];
        yield 'carriage return inside the scheme' => ["java\rscript:alert(1)"];
        yield 'tab before the colon' => ["javascript\t:alert(1)"];
        yield 'mixed with leading controls' => ["\x01 \tj\ra\nvascript:alert(1)"];
        yield 'data scheme with a tab' => ["da\tta:text/html,x"];
        yield 'vbscript with a newline' => ["vb\nscript:x"];
    }

    #[DataProvider('dangerousUrlProvider')]
    public function testUrlWithStrippedCharactersIsBlocked(string $url): void
    {
        self::assertSame('<a>x</a>', $this->render(H::a(href: $url, children: 'x')));
    }

    public function testUrlAttributeLookupIgnoresCase(): void
    {
        self::assertSame(
            '<a>x</a>',
            $this->render(new Element('a', ['HREF' => 'javascript:alert(1)'], ['x'])),
        );
    }

    public function testHarmlessUrlWithTabIsLeftAlone(): void
    {
        self::assertSame(
            "<a href=\"/pa\tth?x=a:b\">x</a>",
            $this->render(H::a(href: "/pa\tth?x=a:b", children: 'x')),
        );
    }
}
