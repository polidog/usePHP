<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Psx\StackTraceRewriter;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

class RuntimeIntegrationTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/psx-runtime-test-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testLoadComponentManifestThrowsWhenPathMissing(): void
    {
        $app = new UsePHP();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX manifest not found');
        $app->loadComponentManifest($this->workDir . '/missing.php');
    }

    public function testLoadComponentManifestRejectsNonArrayReturn(): void
    {
        $manifest = $this->workDir . '/bad-manifest.php';
        \file_put_contents($manifest, "<?php\nreturn 'not an array';\n");
        $app = new UsePHP();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an array');
        $app->loadComponentManifest($manifest);
    }

    public function testRenderPsxComponentThrowsWhenFqcnUnknown(): void
    {
        $app = new UsePHP();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX component not registered: App\\Missing');
        RenderContext::beginRender();
        $app->renderPsxComponent('App\\Missing', []);
    }

    public function testRenderPsxComponentThrowsWhenCompiledFileMissing(): void
    {
        $manifest = $this->workDir . '/manifest.php';
        $missingPath = $this->workDir . '/no-such-file.psx.php';
        \file_put_contents(
            $manifest,
            "<?php\nreturn " . \var_export(['App\\Foo' => $missingPath], true) . ";\n",
        );

        $app = new UsePHP();
        $app->loadComponentManifest($manifest);
        RenderContext::beginRender();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compiled PSX file not found');
        $app->renderPsxComponent('App\\Foo', []);
    }

    public function testRenderPsxComponentThrowsWhenCompiledFileIsInvalidPhp(): void
    {
        $broken = $this->workDir . '/Broken.psx.php';
        \file_put_contents($broken, "<?php\nthis is not valid php;\n");
        $manifest = $this->workDir . '/manifest.php';
        \file_put_contents(
            $manifest,
            "<?php\nreturn " . \var_export(['App\\Broken' => $broken], true) . ";\n",
        );

        $app = new UsePHP();
        $app->loadComponentManifest($manifest);
        RenderContext::beginRender();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compiled PSX file is invalid PHP');
        $app->renderPsxComponent('App\\Broken', []);
    }

    public function testRenderPsxComponentThrowsWhenReturnIsNotCallable(): void
    {
        $bad = $this->workDir . '/NoCallable.psx.php';
        \file_put_contents($bad, "<?php\nreturn 42;\n");
        $manifest = $this->workDir . '/manifest.php';
        \file_put_contents(
            $manifest,
            "<?php\nreturn " . \var_export(['App\\NoCallable' => $bad], true) . ";\n",
        );

        $app = new UsePHP();
        $app->loadComponentManifest($manifest);
        RenderContext::beginRender();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return a callable');
        $app->renderPsxComponent('App\\NoCallable', []);
    }

    public function testEndToEndCompileAndRenderProducesElement(): void
    {
        $psx = $this->workDir . '/Hello.psx';
        \file_put_contents(
            $psx,
            "<?php\nnamespace App;\n"
            . "use Polidog\\UsePhp\\Html\\H;\n"
            . "use function Polidog\\UsePhp\\Runtime\\fc;\n"
            . "return fc(fn(array \$props) => <div className=\"greet\"><span>Hello {\$props['name']}</span></div>, 'hello');\n",
        );

        $compiled = new Compiler()->compile(\file_get_contents($psx));
        $compiledPath = $psx . '.php';
        \file_put_contents($compiledPath, $compiled);

        $manifest = $this->workDir . '/manifest.php';
        \file_put_contents(
            $manifest,
            "<?php\nreturn " . \var_export(['App\\Hello' => $compiledPath], true) . ";\n",
        );

        $app = new UsePHP();
        $app->loadComponentManifest($manifest);
        RenderContext::setApp($app);
        RenderContext::beginRender();
        try {
            $element = $app->renderPsxComponent('App\\Hello', ['name' => 'world']);
            self::assertInstanceOf(Element::class, $element);
            // The fc() wrapper produces an outer <div data-usephp> wrapper with
            // a single child Element produced by the user's function.
            self::assertSame('div', $element->type);
        } finally {
            RenderContext::clearApp();
        }
    }

    public function testRegisterComponentBridgesRuntimeCallable(): void
    {
        $app = new UsePHP();
        $app->registerComponent(
            'App\\Inline',
            static fn(array $props): Element => new Element('p', [], [($props['text'] ?? 'x')]),
        );
        RenderContext::beginRender();
        $element = $app->renderPsxComponent('App\\Inline', ['text' => 'hello']);
        self::assertSame('p', $element->type);
    }

    public function testInstallPsxErrorHandlerWritesRewrittenTrace(): void
    {
        $app = new UsePHP();
        $previous = $app->installPsxErrorHandler();
        try {
            $tmp = \tempnam(\sys_get_temp_dir(), 'psx-throw-') . '.psx.php';
            \file_put_contents($tmp, "<?php\nthrow new \\RuntimeException('boom');\n");
            try {
                require $tmp;
                self::fail('Expected throw');
            } catch (\RuntimeException $e) {
                $formatted = StackTraceRewriter::formatException($e);
                self::assertStringContainsString('boom', $formatted);
                $expectedSourceFile = \substr($tmp, 0, -\strlen('.php'));
                self::assertStringContainsString($expectedSourceFile, $formatted);
                self::assertStringNotContainsString($tmp, $formatted);
            } finally {
                @\unlink($tmp);
            }
        } finally {
            \set_exception_handler($previous);
        }
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        $entries = \scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
