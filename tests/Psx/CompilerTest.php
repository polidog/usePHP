<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\Compiler;

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

    public function testCompiledHasExpectedStructure(): void
    {
        $sourcePath = \dirname(__DIR__, 2) . '/examples/components/psx/Counter.psx';
        $compiled = $this->compiler->compile(\file_get_contents($sourcePath));

        self::assertStringContainsString("H::div(className: 'counter'", $compiled);
        self::assertStringContainsString("H::span(children: ['Count: ', \$count])", $compiled);
        self::assertStringContainsString('onClick: fn() => $setCount($count + 1)', $compiled);
        self::assertStringContainsString('StorageType::Snapshot', $compiled);
    }

    private function compileExpression(string $psxFragment): string
    {
        $source = "<?php\nreturn $psxFragment;\n";
        return $this->compiler->compile($source);
    }
}
