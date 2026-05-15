<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\FunctionComponent;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

class FcDeferTest extends TestCase
{
    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
    }

    public function testFcReturnsFunctionComponent(): void
    {
        $fc = fc(fn(array $props): Element => H::div(), defer: new Defer(name: 'plain'));
        self::assertInstanceOf(FunctionComponent::class, $fc);
        self::assertNotNull($fc->defer);
        self::assertSame('plain', $fc->defer->name);
    }

    public function testFcWithoutDeferStillCallableAndWraps(): void
    {
        $wrapped = fc(fn(array $props): Element => H::span(children: 'hi'));
        $element = $wrapped([]);
        self::assertSame('div', $element->type);
        self::assertArrayHasKey('data-usephp', $element->props);
    }

    public function testInvocationEmitsDeferPlaceholderByDefault(): void
    {
        $app = new UsePHP();
        RenderContext::setApp($app);

        $wrapped = fc(
            fn(array $props): Element => H::header(children: 'real content'),
            defer: new Defer(name: 'user-header'),
        );

        $element = $wrapped(['fallback' => H::span(children: 'loading')]);

        self::assertSame('__defer__', $element->type);
        self::assertSame('user-header', $element->props['__name']);
        self::assertInstanceOf(Element::class, $element->props['__fallback']);
        self::assertSame('span', $element->props['__fallback']->type);
    }

    public function testLocalCacheRidesThroughFcPlaceholder(): void
    {
        $app = new UsePHP();
        RenderContext::setApp($app);

        $wrapped = fc(
            fn(array $props): Element => H::header(children: 'real content'),
            defer: new Defer(name: 'user-header', localCache: true),
        );

        $element = $wrapped(['fallback' => H::span(children: 'loading')]);

        self::assertSame('__defer__', $element->type);
        self::assertTrue($element->props['__localCache']);
    }

    public function testScalarPropsForwardAsQueryParams(): void
    {
        $app = new UsePHP();
        RenderContext::setApp($app);

        $wrapped = fc(
            fn(array $props): Element => H::div(),
            defer: new Defer(name: 'q'),
        );

        $element = $wrapped(['post_id' => 5, 'sort' => 'new']);

        self::assertSame(['post_id' => 5, 'sort' => 'new'], $element->props['__params']);
    }

    public function testNonScalarPropRaises(): void
    {
        $app = new UsePHP();
        RenderContext::setApp($app);

        $wrapped = fc(
            fn(array $props): Element => H::div(),
            defer: new Defer(name: 'q'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalar');
        $wrapped(['nested' => ['x' => 1]]);
    }

    public function testNonElementFallbackRaises(): void
    {
        $app = new UsePHP();
        RenderContext::setApp($app);

        $wrapped = fc(
            fn(array $props): Element => H::div(),
            defer: new Defer(name: 'q'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fallback');
        $wrapped(['fallback' => 'just a string']);
    }

    public function testInvocationRendersInlineWhenEndpointFlagSet(): void
    {
        // End-to-end: a fc()-wrapped deferred component registered via the
        // sidecar manifest renders the real content when the framework is
        // serving its /_defer endpoint, not the placeholder.
        $workDir = \sys_get_temp_dir() . '/fc-defer-' . \uniqid();
        \mkdir($workDir, 0o777, true);
        try {
            $compiled = $workDir . '/Header.psx.php';
            \file_put_contents(
                $compiled,
                <<<'PHP'
                    <?php
                    use Polidog\UsePhp\Component\Defer;
                    use Polidog\UsePhp\Html\H;
                    use function Polidog\UsePhp\Runtime\fc;
                    return fc(
                        fn(array $props) => H::header(children: 'real-' . ($props['who'] ?? 'guest')),
                        defer: new Defer(name: 'header-q', cacheControl: 'private, max-age=10'),
                    );
                    PHP,
            );
            $manifest = $workDir . '/manifest.php';
            \file_put_contents(
                $manifest,
                "<?php\nreturn " . \var_export(['App\\HeaderQ' => $compiled], true) . ";\n",
            );
            $deferredManifest = $workDir . '/deferred-manifest.php';
            \file_put_contents(
                $deferredManifest,
                "<?php\nreturn [\n"
                . "    'header-q' => ['component' => 'App\\\\HeaderQ', 'cacheControl' => 'private, max-age=10'],\n"
                . "];\n",
            );

            $app = new UsePHP();
            $app->loadComponentManifest($manifest);
            $headers = [];
            $app->withHeaderEmitter(function (string $h) use (&$headers): void {
                $headers[] = $h;
            });

            $html = $app->handleDeferred(new RequestContext(
                method: 'GET',
                path: '/_defer/header-q',
                query: ['who' => 'alice'],
            ));

            self::assertNotNull($html);
            self::assertStringContainsString('<header>real-alice</header>', $html);
            self::assertStringNotContainsString('data-usephp-defer-url', $html);
            self::assertContains('Cache-Control: private, max-age=10', $headers);
        } finally {
            $this->rmrf($workDir);
        }
    }

    private function rmrf(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            if ($entry->isDir()) {
                @\rmdir($entry->getPathname());
            } else {
                @\unlink($entry->getPathname());
            }
        }
        @\rmdir($dir);
    }
}
