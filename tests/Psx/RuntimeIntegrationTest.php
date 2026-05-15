<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Psx\StackTraceRewriter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

/**
 * Memory-storage class component whose render() output contains a defer
 * placeholder. Used by the regression test that exercises the partial render
 * path: a non-Snapshot component re-rendered via handleAction() must still
 * emit a deferred URL placeholder without crashing.
 */
#[Component(name: 'memory-with-defer', storage: 'memory')]
class MemoryComponentWithDefer extends BaseComponent
{
    public function render(): Element
    {
        return H::div(children: [
            H::span(children: 'wrapper'),
            H::defer('deferred-header', [], H::span(children: 'loading')),
        ]);
    }
}

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

    public function testHandleDeferredReturnsNullWhenNotADeferRequest(): void
    {
        $app = new UsePHP();
        self::assertNull($app->handleDeferred(
            new RequestContext(method: 'GET', path: '/'),
        ));
        self::assertNull($app->handleDeferred(
            new RequestContext(method: 'POST', path: '/_defer/user-header'),
        ));
    }

    public function testHandleDeferredRendersRegisteredComponentForGetRequest(): void
    {
        $app = new UsePHP();
        $app->registerComponent(
            'App\\Header',
            static fn(array $props): Element => new Element('header', [], [(string) ($props['name'] ?? 'guest')]),
        );
        $app->registerDeferred('user-header', 'App\\Header');

        $html = $app->handleDeferred(new RequestContext(
            method: 'GET',
            path: '/_defer/user-header',
            query: ['name' => 'alice'],
        ));

        self::assertNotNull($html);
        self::assertStringContainsString('<header>alice</header>', $html);
    }

    public function testHandleDeferredReturns404ForUnregisteredName(): void
    {
        $app = new UsePHP();

        $savedStatus = \http_response_code();
        try {
            $html = $app->handleDeferred(new RequestContext(
                method: 'GET',
                path: '/_defer/missing',
            ));
            self::assertNotNull($html);
            self::assertStringContainsString('not registered', $html);
            self::assertSame(404, \http_response_code());
        } finally {
            \http_response_code($savedStatus === false ? 200 : $savedStatus);
        }
    }

    public function testHandleDeferredReturns404ForMalformedName(): void
    {
        $app = new UsePHP();

        $savedStatus = \http_response_code();
        try {
            $html = $app->handleDeferred(new RequestContext(
                method: 'GET',
                path: '/_defer/' . \rawurlencode('not/allowed'),
            ));
            self::assertNotNull($html);
            self::assertSame(404, \http_response_code());
        } finally {
            \http_response_code($savedStatus === false ? 200 : $savedStatus);
        }
    }

    public function testHandleDeferredUsesCustomPrefix(): void
    {
        $app = new UsePHP();
        $app->setDeferPrefix('/api/_d');
        $app->registerComponent(
            'App\\Header',
            static fn(array $props): Element => new Element('header', [], ['ok']),
        );
        $app->registerDeferred('hdr', 'App\\Header');

        $html = $app->handleDeferred(new RequestContext(
            method: 'GET',
            path: '/api/_d/hdr',
        ));

        self::assertNotNull($html);
        self::assertStringContainsString('<header>ok</header>', $html);
        // Default prefix must no longer match.
        self::assertNull($app->handleDeferred(new RequestContext(
            method: 'GET',
            path: '/_defer/hdr',
        )));
    }

    public function testPartialRenderOfNonSnapshotComponentEmitsDeferPlaceholder(): void
    {
        // Regression: a class component using non-Snapshot storage that
        // renders a defer placeholder must still produce a URL-bearing
        // placeholder when invoked from the partial render path.
        $app = new UsePHP();
        $app->registerComponent(
            'App\\DeferredHeader',
            static fn(array $props): Element => new Element('header', [], ['hi']),
        );
        $app->registerDeferred('deferred-header', 'App\\DeferredHeader');
        $app->register(MemoryComponentWithDefer::class);

        $action = [
            'type' => 'setState',
            'payload' => ['index' => 0, 'value' => 'x'],
            'componentId' => 'memory-with-defer#0',
            'storageType' => StorageType::Memory->value,
        ];

        $savedPost = $_POST;
        $savedServer = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
            $_POST = [
                '_usephp_component' => 'memory-with-defer#0',
                '_usephp_action' => \json_encode($action, \JSON_THROW_ON_ERROR),
            ];

            $html = $app->handleAction();
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }

        self::assertNotNull($html);
        self::assertStringContainsString('data-usephp-defer-url="/_defer/deferred-header"', $html);
    }

    public function testRegisterDeferredRejectsInvalidName(): void
    {
        $app = new UsePHP();
        $this->expectException(\InvalidArgumentException::class);
        $app->registerDeferred('not/allowed', 'App\\X');
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
