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

use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

/**
 * A defer endpoint serves exactly one component, under that component's own
 * Cache-Control. Anything deferred inside it must stay a placeholder: inlining
 * it would ship session-dependent output under the parent's (possibly public)
 * policy. See https://github.com/polidog/usePHP/issues/59.
 */
final class NestedDeferTest extends TestCase
{
    /** @var list<string> */
    private array $headers = [];

    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
        RenderContext::beginRender();
        $this->headers = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
    }

    private function newApp(): UsePHP
    {
        $app = new UsePHP();
        $app->withHeaderEmitter(function (string $header): void {
            $this->headers[] = $header;
        });

        return $app;
    }

    private function nestedApp(): UsePHP
    {
        $app = $this->newApp();

        $app->registerComponent('Inner', fc(
            fn(array $p): Element => H::div(children: 'inner:' . ($_SESSION['who'] ?? 'guest')),
            defer: new Defer('inner', cacheControl: 'private, no-store'),
        ));
        $app->registerDeferred('inner', 'Inner', 'private, no-store');

        $app->registerComponent('Outer', fc(
            fn(array $p): Element => H::div(children: [
                'outer ',
                $app->renderPsxComponent('Inner', ['fallback' => H::span(children: 'loading-inner')]),
            ]),
            defer: new Defer('outer', cacheControl: 'public, s-maxage=60'),
        ));
        $app->registerDeferred('outer', 'Outer', 'public, s-maxage=60');

        return $app;
    }

    private function fetchEndpoint(UsePHP $app, string $name): string
    {
        $this->headers = [];
        $html = $app->handleDeferred(new RequestContext(method: 'GET', path: '/_defer/' . $name));
        self::assertNotNull($html);

        return $html;
    }

    public function testOuterEndpointLeavesNestedDeferAsPlaceholder(): void
    {
        $_SESSION['who'] = 'alice';

        $html = $this->fetchEndpoint($this->nestedApp(), 'outer');

        self::assertStringContainsString('outer ', $html);
        self::assertStringNotContainsString('inner:alice', $html, 'session-bound content leaked into the public fragment');
        self::assertStringContainsString('data-usephp-defer-url="/_defer/inner"', $html);
        self::assertStringContainsString('loading-inner', $html);
        self::assertSame(['Cache-Control: public, s-maxage=60'], $this->headers);
    }

    public function testNestedDeferHydratesThroughItsOwnEndpointAndCachePolicy(): void
    {
        $_SESSION['who'] = 'alice';
        $app = $this->nestedApp();

        $this->fetchEndpoint($app, 'outer');
        $html = $this->fetchEndpoint($app, 'inner');

        self::assertStringContainsString('inner:alice', $html);
        self::assertStringNotContainsString('data-usephp-defer-url', $html);
        self::assertSame(['Cache-Control: private, no-store'], $this->headers);
    }

    public function testThreeLevelsEachResolveOneLevelPerRequest(): void
    {
        $app = $this->newApp();
        $app->registerComponent('C', fc(fn(array $p): Element => H::div(children: 'level-c'), defer: new Defer('c')));
        $app->registerDeferred('c', 'C');
        $app->registerComponent('B', fc(
            fn(array $p): Element => H::div(children: ['level-b', $app->renderPsxComponent('C')]),
            defer: new Defer('b'),
        ));
        $app->registerDeferred('b', 'B');
        $app->registerComponent('A', fc(
            fn(array $p): Element => H::div(children: ['level-a', $app->renderPsxComponent('B')]),
            defer: new Defer('a'),
        ));
        $app->registerDeferred('a', 'A');

        $a = $this->fetchEndpoint($app, 'a');
        self::assertStringContainsString('level-a', $a);
        self::assertStringContainsString('data-usephp-defer-url="/_defer/b"', $a);
        self::assertStringNotContainsString('level-b', $a);

        $b = $this->fetchEndpoint($app, 'b');
        self::assertStringContainsString('level-b', $b);
        self::assertStringContainsString('data-usephp-defer-url="/_defer/c"', $b);
        self::assertStringNotContainsString('level-c', $b);

        self::assertStringContainsString('level-c', $this->fetchEndpoint($app, 'c'));
    }

    public function testPlainCallableTargetDoesNotUnlockNestedDefer(): void
    {
        $app = $this->newApp();
        $inner = fc(fn(array $p): Element => H::div(children: 'secret-inner'), defer: new Defer('inner'));
        $app->registerComponent('Inner', $inner);
        $app->registerDeferred('inner', 'Inner');
        // The target has no Defer of its own, so a "first deferred wrapper
        // wins" rule would wrongly treat `Inner` as the endpoint's target.
        $app->registerComponent('Plain', fn(array $p): Element => H::div(children: [$inner()]));
        $app->registerDeferred('plain', 'Plain', 'public, s-maxage=60');

        $html = $this->fetchEndpoint($app, 'plain');

        self::assertStringNotContainsString('secret-inner', $html);
        self::assertStringContainsString('data-usephp-defer-url="/_defer/inner"', $html);
    }

    public function testTargetRendersWhenRegisteredUnderDifferentNameThanItsDefer(): void
    {
        $app = $this->newApp();
        $app->registerComponent('Comp', fc(
            fn(array $p): Element => H::div(children: 'real-content'),
            defer: new Defer('declared-name'),
        ));
        $app->registerDeferred('alias', 'Comp');

        $html = $this->fetchEndpoint($app, 'alias');

        self::assertStringContainsString('real-content', $html);
        self::assertStringNotContainsString('data-usephp-defer-url', $html);
    }

    public function testPlainCallableDelegatingToItsOwnDeferStillRenders(): void
    {
        // A thin wrapper around the deferred component must not answer with a
        // placeholder for its own URL: the client would refetch it forever.
        $app = $this->newApp();
        $inner = fc(fn(array $p): Element => H::div(children: 'real-content'), defer: new Defer('self'));
        $app->registerComponent('Wrapper', fn(array $p): Element => $inner($p));
        $app->registerDeferred('self', 'Wrapper');

        $html = $this->fetchEndpoint($app, 'self');

        self::assertStringContainsString('real-content', $html);
        self::assertStringNotContainsString('data-usephp-defer-url', $html);
    }

    public function testPageRenderAfterEndpointEmitsPlaceholderAgain(): void
    {
        $app = $this->nestedApp();
        $this->fetchEndpoint($app, 'outer');

        RenderContext::setApp($app);
        $element = $app->renderPsxComponent('Outer');

        self::assertSame('__defer__', $element->type);
    }
}
