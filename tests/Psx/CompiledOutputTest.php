<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Compiles PSX, loads the output under declare(strict_types=1) (as the examples
 * do) and checks the element or HTML it produces.
 */
class CompiledOutputTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentOnlyContainers(): iterable
    {
        yield 'before text' => ['<p>{/* c */}after</p>', '<p>after</p>'];
        yield 'only child' => ['<p>{/* c */}</p>', '<p></p>'];
        yield 'between elements' => ['<div><b>a</b>{/* c */}<i>b</i></div>', '<div><b>a</b><i>b</i></div>'];
        yield 'line comment' => ["<p>{// c\n}after</p>", '<p>after</p>'];
        yield 'hash comment' => ["<p>{# c\n}after</p>", '<p>after</p>'];
        yield 'doc comment' => ['<p>{/** c */}after</p>', '<p>after</p>'];
        yield 'several comments' => ["<p>{/* a */ // b\n /* c */}after</p>", '<p>after</p>'];
        yield 'tag inside comment' => ['<p>{/* <b>x</b> */}after</p>', '<p>after</p>'];
        yield 'empty container' => ['<p>{}after</p>', '<p>after</p>'];
        yield 'whitespace container' => ['<p>{   }after</p>', '<p>after</p>'];
        yield 'in fragment' => ['<>{/* c */}<b>a</b></>', '<b>a</b>'];
        yield 'multi-line markup' => ["<div>\n  {/* one\n     two */}\n  <b>a</b>\n</div>", '<div><b>a</b></div>'];
    }

    #[DataProvider('commentOnlyContainers')]
    public function testCommentOnlyContainerProducesNoChild(string $psx, string $expected): void
    {
        self::assertSame($expected, $this->renderHtml($psx));
    }

    public function testCommentNextToAnExpressionIsKept(): void
    {
        self::assertSame('<p>v</p>', $this->renderHtml('<p>{/* c */ $p[\'x\']}</p>', ['x' => 'v']));
    }

    public function testExpressionFollowedByLineCommentCompiles(): void
    {
        self::assertSame('<p>v</p>', $this->renderHtml("<p>{\$p['x'] // note\n}</p>", ['x' => 'v']));
        self::assertSame('<p>va</p>', $this->renderHtml("<p>{\$p['x'] // note\n}a</p>", ['x' => 'v']));
        self::assertSame('<p id="v"></p>', $this->renderHtml("<p id={\$p['x'] // note\n}></p>", ['x' => 'v']));
    }

    public function testCommentOnlyContainerPreservesLineNumbers(): void
    {
        $source = "<?php\nreturn fn() => <div>\n  {/* one\n     two */}\n  <b>a</b>\n</div>;\nthrow new \\Exception('line 7');\n";
        $compiled = new Compiler()->compile($source);

        self::assertSame(\substr_count($source, "\n"), \substr_count($compiled, "\n"));
        self::assertStringContainsString('line 7', \explode("\n", $compiled)[6] ?? '');
    }

    public function testCommentOnlyAttributeValueIsACompileError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Attribute 'id' has an empty expression");
        new Compiler()->compile('<?php return <div id={/* c */}></div>;');
    }

    public function testEmptyAttributeValueIsACompileError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Attribute 'id' has an empty expression");
        new Compiler()->compile('<?php return <Foo id={} />;');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entities(): iterable
    {
        yield 'named entities in text' => [
            '<p>a &amp; b &lt; c &nbsp; d &copy;</p>',
            "<p>a &amp; b &lt; c \u{A0} d \u{A9}</p>",
        ];
        yield 'quotes in an attribute' => [
            '<a title="it\'s &quot;q&quot;">a</a>',
            '<a title="it&#039;s &quot;q&quot;">a</a>',
        ];
        yield 'single quote entity next to a backslash' => ['<p>&apos;\\&#39;</p>', '<p>&#039;\\&#039;</p>'];
        yield 'numeric entities' => ['<p>&#x3042;&#12354;</p>', "<p>\u{3042}\u{3042}</p>"];
        yield 'decoded only once' => ['<p>&amp;lt;</p>', '<p>&amp;lt;</p>'];
        yield 'unknown entity stays literal' => ['<p>&nope;</p>', '<p>&amp;nope;</p>'];
        yield 'bare ampersand stays literal' => ['<p>a & b</p>', '<p>a &amp; b</p>'];
        yield 'nbsp survives whitespace collapsing' => ['<p>a&nbsp;&nbsp;b</p>', "<p>a\u{A0}\u{A0}b</p>"];
        yield 'nbsp at the edge of multi-line text' => ["<p>\n  &nbsp;x\n</p>", "<p>\u{A0}x</p>"];
        yield 'escaped markup stays text' => ['<p>&lt;b&gt;</p>', '<p>&lt;b&gt;</p>'];
        yield 'PHP strings are not decoded' => ["<p>{'&amp;'}</p>", '<p>&amp;amp;</p>'];
        yield 'fragment text' => ['<>&copy;</>', "\u{A9}"];
    }

    #[DataProvider('entities')]
    public function testHtmlEntitiesAreDecodedAtCompileTime(string $psx, string $expected): void
    {
        self::assertSame($expected, $this->renderHtml($psx));
    }

    public function testEntitiesInComponentPropsAndChildrenAreDecoded(): void
    {
        $compiled = new Compiler()->compile('<?php return <Foo title="a &amp; b">&lt;</Foo>;');

        self::assertStringContainsString("'title' => 'a & b'", $compiled);
        self::assertStringContainsString("'children' => '<'", $compiled);
    }

    public function testEncodedJavascriptUrlIsStillBlockedAfterDecoding(): void
    {
        self::assertSame('<a>x</a>', $this->renderHtml('<a href="javascript&colon;alert(1)">x</a>'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderHtml(string $psx, array $props = []): string
    {
        return new Renderer('test')->renderElement($this->element($psx, $props));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function element(string $psx, array $props = []): Element
    {
        $source = "<?php\ndeclare(strict_types=1);\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn(array \$p) => $psx;\n";
        $tmp = \tempnam(\sys_get_temp_dir(), 'psx-output-');
        self::assertIsString($tmp);
        $file = $tmp . '.php';
        \file_put_contents($file, new Compiler()->compile($source));
        try {
            /** @var \Closure(array<string, mixed>): Element $component */
            $component = require $file;
            return $component($props);
        } finally {
            @\unlink($file);
            @\unlink($tmp);
        }
    }
}
