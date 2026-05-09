<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\NamespaceContext;

class NamespaceContextTest extends TestCase
{
    public function testParsesNamespace(): void
    {
        $ctx = $this->parse("<?php\nnamespace App\\Pages;\n");
        self::assertSame('App\\Pages', $ctx->getNamespace());
    }

    public function testParsesUseStatement(): void
    {
        $ctx = $this->parse("<?php\nuse App\\Components\\Counter;\n");
        self::assertSame('App\\Components\\Counter', $ctx->resolve('Counter'));
    }

    public function testParsesUseAlias(): void
    {
        $ctx = $this->parse("<?php\nuse App\\Mobile\\Counter as MobileCounter;\n");
        self::assertSame('App\\Mobile\\Counter', $ctx->resolve('MobileCounter'));
    }

    public function testFallsBackToCurrentNamespace(): void
    {
        $ctx = $this->parse("<?php\nnamespace App\\Pages;\n");
        self::assertSame('App\\Pages\\Unknown', $ctx->resolve('Unknown'));
    }

    public function testReturnsBareNameWhenNoNamespace(): void
    {
        $ctx = $this->parse('<?php');
        self::assertSame('Counter', $ctx->resolve('Counter'));
    }

    public function testCollectsRuntimeDeclarations(): void
    {
        $ctx = $this->parse("<?php\n// @psx-runtime App\\Legacy\\Counter\n// @psx-runtime App\\Legacy\\Card\n");
        self::assertSame(
            ['App\\Legacy\\Counter', 'App\\Legacy\\Card'],
            $ctx->getRuntimeDeclarations()
        );
    }

    public function testTracksResolvedReferences(): void
    {
        $ctx = $this->parse("<?php\nnamespace App;\nuse App\\Comp\\Foo;\n");
        $ctx->resolve('Foo');
        $ctx->resolve('Bar');
        self::assertSame(
            ['App\\Comp\\Foo', 'App\\Bar'],
            $ctx->getResolvedReferences()
        );
    }

    public function testIgnoresUseFunctionAndUseConst(): void
    {
        $ctx = $this->parse("<?php\nuse function Polidog\\UsePhp\\Runtime\\fc;\nuse const Polidog\\UsePhp\\FOO;\n");
        // `fc` should not be resolvable as a class via the use map.
        self::assertSame('fc', $ctx->resolve('fc'));
    }

    private function parse(string $source): NamespaceContext
    {
        return NamespaceContext::parse(\token_get_all($source));
    }
}
