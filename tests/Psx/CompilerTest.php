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

    public function testCompilesCounterPsxFixtureToValidPhp(): void
    {
        $sourcePath = \dirname(__DIR__, 2) . '/examples/components/psx/Counter.psx';
        $compiled = $this->compiler->compile(\file_get_contents($sourcePath));

        // token_get_all with TOKEN_PARSE throws ParseError on invalid PHP syntax.
        try {
            \token_get_all($compiled, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            self::fail("Compiled PSX produced invalid PHP: " . $e->getMessage());
        }
        self::assertTrue(true);
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
