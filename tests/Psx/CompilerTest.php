<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Psx\NamespaceContext;
use Polidog\UsePhp\Psx\PsxParserFactoryInterface;
use Polidog\UsePhp\Psx\PsxParserInterface;
use Polidog\UsePhp\Psx\PsxPreProcessorInterface;

class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testSimpleHtmlTag(): void
    {
        $result = $this->compileExpression('<div></div>');
        self::assertStringContainsString('H::div()', $result);
    }

    public function testTagWithStringAttribute(): void
    {
        $result = $this->compileExpression('<div className="counter"></div>');
        self::assertStringContainsString("H::div(className: 'counter')", $result);
    }

    public function testTagWithExpressionAttribute(): void
    {
        $result = $this->compileExpression('<div className={$cls}></div>');
        self::assertStringContainsString('H::div(className: $cls)', $result);
    }

    public function testTextChild(): void
    {
        $result = $this->compileExpression('<span>Hello</span>');
        self::assertStringContainsString("H::span(children: 'Hello')", $result);
    }

    public function testInterpolatedTextChild(): void
    {
        $result = $this->compileExpression('<span>Count: {$count}</span>');
        self::assertStringContainsString("H::span(children: ['Count: ', \$count])", $result);
    }

    public function testSelfClosingTag(): void
    {
        $result = $this->compileExpression('<input type="text" />');
        self::assertStringContainsString("H::input(type: 'text')", $result);
    }

    public function testEventHandler(): void
    {
        $result = $this->compileExpression('<button onClick={fn() => $inc()}>+</button>');
        self::assertStringContainsString('H::button(onClick: fn() => $inc(), children: \'+\')', $result);
    }

    public function testNestedElements(): void
    {
        $result = $this->compileExpression('<div><span>x</span></div>');
        self::assertStringContainsString("H::div(children: H::span(children: 'x'))", $result);
    }

    public function testMultipleChildrenBecomeArray(): void
    {
        $result = $this->compileExpression('<ul><li>1</li><li>2</li></ul>');
        self::assertStringContainsString("H::ul(children: [H::li(children: '1'), H::li(children: '2')])", $result);
    }

    public function testFragment(): void
    {
        $result = $this->compileExpression('<><li>One</li><li>Two</li></>');
        self::assertStringContainsString(
            "H::Fragment([H::li(children: 'One'), H::li(children: 'Two')])",
            $result
        );
    }

    public function testPrivateHelperViaLocalClosure(): void
    {
        $source = "<?php\n"
            . "namespace App;\n"
            . "use Polidog\\UsePhp\\Html\\H;\n"
            . "\n"
            . "// Private helper — local closure, not a PSX tag.\n"
            . "\$row = fn(array \$item) => <li className=\"row\">{\$item['text']}</li>;\n"
            . "\n"
            . "return fn(array \$items) => <ul>\n"
            . "    {array_map(\$row, \$items)}\n"
            . "</ul>;\n";

        $compiled = $this->compiler->compile($source);

        self::assertStringContainsString(
            "\$row = fn(array \$item) => H::li(className: 'row', children: \$item['text'])",
            $compiled
        );
        self::assertStringContainsString('array_map($row, $items)', $compiled);
        self::assertStringContainsString('H::ul(children:', $compiled);

        // Helper closure should not be added to the manifest reference list.
        self::assertSame([], $this->compiler->getLastReferences());
    }

    public function testFragmentInsideArrayMap(): void
    {
        $source = "<?php\nreturn fn(\$items) => <ul>\n  {array_map(fn(\$t) => <li>{\$t}</li>, \$items)}\n</ul>;\n";
        $result = $this->compiler->compile($source);
        self::assertStringContainsString('array_map(fn($t) => H::li(children: $t), $items)', $result);
    }

    public function testBooleanAttribute(): void
    {
        $result = $this->compileExpression('<input disabled />');
        self::assertStringContainsString('H::input(disabled: true)', $result);
    }

    public function testComponentTag(): void
    {
        $result = $this->compileExpression('<Counter initial={5} />');
        self::assertStringContainsString("renderPsxComponent('Counter', ['initial' => 5])", $result);
    }

    public function testComponentTagResolvesViaUseStatement(): void
    {
        $source = "<?php\nnamespace App\\Pages;\nuse App\\Components\\Counter;\nreturn fn() => <Counter initial={5} />;";
        $result = $this->compiler->compile($source);
        self::assertStringContainsString("renderPsxComponent('App\\\\Components\\\\Counter', ['initial' => 5])", $result);
    }

    public function testComponentTagResolvesViaUseAlias(): void
    {
        $source = "<?php\nuse App\\Mobile\\Counter as MobileCounter;\nreturn fn() => <MobileCounter />;";
        $result = $this->compiler->compile($source);
        self::assertStringContainsString("renderPsxComponent('App\\\\Mobile\\\\Counter'", $result);
    }

    public function testComponentTagFallsBackToCurrentNamespace(): void
    {
        $source = "<?php\nnamespace App\\Pages;\nreturn fn() => <Unknown />;";
        $result = $this->compiler->compile($source);
        self::assertStringContainsString("renderPsxComponent('App\\\\Pages\\\\Unknown'", $result);
    }

    public function testDeferAttributeCompilesToHDefer(): void
    {
        $result = $this->compileExpression('<UserHeader defer />');
        self::assertStringContainsString("\\Polidog\\UsePhp\\Html\\H::defer('UserHeader', [], null)", $result);
        self::assertStringNotContainsString('renderPsxComponent', $result);
    }

    public function testDeferAttributeWithFallback(): void
    {
        $result = $this->compileExpression('<UserHeader defer fallback={<HeaderSkeleton />} />');
        self::assertStringContainsString("\\Polidog\\UsePhp\\Html\\H::defer('UserHeader', [],", $result);
        self::assertStringContainsString('renderPsxComponent(\'HeaderSkeleton\'', $result);
    }

    public function testDeferAttributePreservesOtherProps(): void
    {
        $result = $this->compileExpression('<UserHeader defer initial={5} title="x" />');
        self::assertStringContainsString(
            "\\Polidog\\UsePhp\\Html\\H::defer('UserHeader', ['initial' => 5, 'title' => 'x'], null)",
            $result,
        );
    }

    public function testDeferResolvesFqcnViaUseStatement(): void
    {
        $source = "<?php\nnamespace App\\Pages;\nuse App\\Components\\UserHeader;\nreturn fn() => <UserHeader defer />;";
        $result = $this->compiler->compile($source);
        self::assertStringContainsString(
            "\\Polidog\\UsePhp\\Html\\H::defer('App\\\\Components\\\\UserHeader'",
            $result,
        );
    }

    public function testCompilesCounterPsxFixtureToValidPhp(): void
    {
        $sourcePath = \dirname(__DIR__, 2) . '/examples/components/psx/Counter.psx';
        $compiled = $this->compiler->compile(\file_get_contents($sourcePath));

        // token_get_all with TOKEN_PARSE throws ParseError on invalid PHP syntax.
        try {
            \token_get_all($compiled, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            self::fail('Compiled PSX produced invalid PHP: ' . $e->getMessage());
        }
        self::assertTrue(true);
    }

    public function testDataAttributeUsesCallStaticDispatch(): void
    {
        $result = $this->compileExpression('<div className="x" data-id={$id}>hi</div>');
        self::assertStringContainsString(
            "H::__callStatic('div', ['className' => 'x', 'data-id' => \$id, 'children' => 'hi'])",
            $result
        );
    }

    public function testAriaAttributeUsesCallStaticDispatch(): void
    {
        $result = $this->compileExpression('<button aria-label="close">x</button>');
        self::assertStringContainsString("H::__callStatic('button',", $result);
        self::assertStringContainsString("'aria-label' => 'close'", $result);
    }

    public function testKnownAttributesUseNamedArgs(): void
    {
        $result = $this->compileExpression('<div className="x" id="y">hi</div>');
        self::assertStringContainsString("H::div(className: 'x', id: 'y', children: 'hi')", $result);
    }

    public function testCallStaticDispatchProducesCorrectElementAtRuntime(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $compiled = $this->compiler->compile(
            "<?php\nrequire " . \var_export($autoload, true) . ";\nuse Polidog\\UsePhp\\Html\\H;\nreturn (fn() => <div className=\"x\" data-id=\"42\">hi</div>)();\n"
        );
        $tmp = \tempnam(\sys_get_temp_dir(), 'psx-') . '.php';
        \file_put_contents($tmp, $compiled);
        try {
            /** @var \Polidog\UsePhp\Runtime\Element $element */
            $element = require $tmp;
            self::assertSame('div', $element->type);
            self::assertSame('x', $element->props['className']);
            self::assertSame('42', $element->props['data-id']);
        } finally {
            @\unlink($tmp);
        }
    }

    public function testCompilationPreservesLineCount(): void
    {
        $source = "<?php\n\nreturn fn() => <div>\n    <span>line 4</span>\n    <span>line 5</span>\n</div>;\n\n// trailing line 8\n";
        $compiled = $this->compiler->compile($source);

        self::assertSame(
            \substr_count($source, "\n"),
            \substr_count($compiled, "\n"),
            'Compiled output should preserve line count so error line numbers stay aligned.'
        );
    }

    public function testTrailingCodeStaysOnOriginalLine(): void
    {
        $source = "<?php\n\nreturn fn() => <div>\n    <span>x</span>\n    <span>y</span>\n</div>;\nthrow new \\Exception('on line 7');\n";
        $compiled = $this->compiler->compile($source);

        $compiledLines = \explode("\n", $compiled);
        self::assertStringContainsString("throw new \\Exception('on line 7');", $compiledLines[6] ?? '');
    }

    public function testParseErrorIncludesLineColumnAndSourceCaret(): void
    {
        $source = "<?php\nreturn fn() => <div>\n    <p>oops\n</div>;\n";
        try {
            $this->compiler->compile($source);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('line 4', $msg);
            self::assertStringContainsString('column', $msg);
            self::assertStringContainsString('Mismatched closing tag', $msg);
            // The caret line should be present.
            self::assertMatchesRegularExpression('/\n {4,} *\^/', $msg);
        }
    }

    public function testBraceExpressionIgnoresClosingBraceInLineComment(): void
    {
        // The `}` inside the // comment must NOT close the brace expression.
        $result = $this->compileExpression("<div>{ /* keep */ \$x // hi }\n}</div>");
        self::assertStringContainsString('H::div(', $result);
        self::assertStringContainsString('$x', $result);
    }

    public function testBraceExpressionIgnoresClosingBraceInBlockComment(): void
    {
        $result = $this->compileExpression('<div>{ /* } closing brace inside comment */ $x }</div>');
        self::assertStringContainsString('H::div(', $result);
        self::assertStringContainsString('$x', $result);
    }

    public function testBraceExpressionIgnoresClosingBraceInDoubleQuotedString(): void
    {
        $result = $this->compileExpression('<div>{($s = "}"); $s }</div>');
        self::assertStringContainsString('H::div(', $result);
        self::assertStringContainsString('"}"', $result);
    }

    public function testUnclosedAttributeStringRaisesParseError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unterminated attribute string literal');
        $this->compileExpression('<div className="missing-close>x</div>');
    }

    public function testUnclosedPhpStringInBraceRaisesParseError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unterminated PHP string literal');
        $this->compileExpression('<div>{ "unterminated }</div>');
    }

    public function testCustomElementWithDataAttrUsesCallStaticDispatch(): void
    {
        $result = $this->compileExpression('<my-widget data-x="1" />');
        // Custom HTML element: not in H's named methods, so __callStatic.
        self::assertStringContainsString("H::__callStatic('my-widget',", $result);
        self::assertStringContainsString("'data-x' => '1'", $result);
    }

    public function testTwoLevelNestedPsxInsideArrayMap(): void
    {
        $source = "<?php\nreturn fn(\$groups) => <ul>"
            . '{array_map(fn($g) => array_map(fn($i) => <li>{$i}</li>, $g), $groups)}'
            . "</ul>;\n";
        $result = $this->compiler->compile($source);
        // The inner H::li(...) call should appear, demonstrating that nested
        // brace expressions still see PSX tags compiled.
        self::assertStringContainsString('H::li(children: $i)', $result);
    }

    public function testCompiledHasExpectedStructure(): void
    {
        $sourcePath = \dirname(__DIR__, 2) . '/examples/components/psx/Counter.psx';
        $compiled = $this->compiler->compile(\file_get_contents($sourcePath));

        self::assertStringContainsString("H::div(className: 'counter'", $compiled);
        self::assertStringContainsString("H::span(children: ['Count: ', \$count])", $compiled);
        self::assertStringContainsString('onClick: fn() => $setCount($count + 1)', $compiled);
        self::assertStringContainsString('StorageType::Snapshot', $compiled);
    }

    public function testInjectedPreProcessorIsUsed(): void
    {
        $spy = new class implements PsxPreProcessorInterface {
            public int $processCalls = 0;
            public int $placeholderCalls = 0;

            public function process(string $source): array
            {
                $this->processCalls++;
                // Replace the PSX region with a placeholder, leaving the rest
                // intact so nikic can parse the result.
                $replaced = \str_replace(
                    '<div>hi</div>',
                    '__psx_placeholder_0__()',
                    $source,
                );

                return [
                    $replaced,
                    [['source' => '<div>hi</div>', 'start' => 0, 'end' => 0]],
                ];
            }

            public function placeholder(int $index): string
            {
                $this->placeholderCalls++;
                return '__psx_placeholder_' . $index . '__()';
            }
        };

        $compiler = new Compiler($spy);
        $compiler->compile("<?php\nreturn <div>hi</div>;\n");

        self::assertSame(1, $spy->processCalls);
        self::assertSame(1, $spy->placeholderCalls, 'Compiler must route placeholder lookups through the injected pre-processor.');
    }

    public function testInjectedParserFactoryIsUsed(): void
    {
        $factory = new class implements PsxParserFactoryInterface {
            /** @var list<array{source: string, start: int, namespaceContext: ?NamespaceContext}> */
            public array $calls = [];

            public function create(string $source, int $start, ?NamespaceContext $namespaceContext = null): PsxParserInterface
            {
                $this->calls[] = [
                    'source' => $source,
                    'start' => $start,
                    'namespaceContext' => $namespaceContext,
                ];

                return new class ($source) implements PsxParserInterface {
                    public function __construct(private readonly string $source) {}

                    public function parseElement(): array
                    {
                        return ['php' => '/* stub */ null', 'end' => \strlen($this->source)];
                    }
                };
            }
        };

        $compiler = new Compiler(parserFactory: $factory);
        $compiler->compile("<?php\nreturn <div>a</div> . <span>b</span>;\n");

        self::assertCount(2, $factory->calls, 'Each PSX region must go through the injected factory.');
        self::assertStringContainsString('<div>a</div>', $factory->calls[0]['source']);
        self::assertStringContainsString('<span>b</span>', $factory->calls[1]['source']);
    }

    private function compileExpression(string $psxFragment): string
    {
        $source = "<?php\nreturn $psxFragment;\n";
        return $this->compiler->compile($source);
    }
}
