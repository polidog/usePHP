<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'UserHeaderDeferred')]
#[Defer(name: 'user-header', cacheControl: 'private, no-store')]
final class DeferAttributeTestComponent extends BaseComponent
{
    public function render(): Element
    {
        return H::header(children: 'static');
    }
}

#[Component(name: 'PlainComponent')]
final class PlainAttributeTestComponent extends BaseComponent
{
    public function render(): Element
    {
        return H::div();
    }
}

class DeferAttributeTest extends TestCase
{
    public function testAttributeIsReadableByRegistry(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(DeferAttributeTestComponent::class);

        $defer = $registry->getDefer('UserHeaderDeferred');
        self::assertNotNull($defer);
        self::assertSame('user-header', $defer->name);
        self::assertSame('private, no-store', $defer->cacheControl);
    }

    public function testRegistryReturnsNullForUndecoratedComponent(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(PlainAttributeTestComponent::class);

        self::assertNull($registry->getDefer('PlainComponent'));
    }

    public function testRejectsInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('match');
        new Defer(name: 'not/url-safe');
    }

    public function testLocalCacheDefaultsToFalseAndPropagatesAsFalse(): void
    {
        // The `__localCache` prop is always present on the placeholder;
        // it carries `false` by default. (The renderer is what omits the
        // data-usephp-defer-cache attribute when false — see RendererTest.)
        $defer = new Defer(name: 'x');
        self::assertFalse($defer->localCache);

        $element = $defer->buildPlaceholder(['fallback' => H::span()]);
        self::assertArrayHasKey('__localCache', $element->props);
        self::assertFalse($element->props['__localCache']);
    }

    public function testLocalCachePropagatesIntoPlaceholder(): void
    {
        // The component decides client persistence explicitly; the opt-in
        // flag must ride through buildPlaceholder() onto the H::defer
        // element so the renderer can emit data-usephp-defer-cache.
        $defer = new Defer(name: 'x', localCache: true);
        self::assertTrue($defer->localCache);

        $element = $defer->buildPlaceholder(['fallback' => H::span()]);
        self::assertTrue($element->props['__localCache']);
    }

    public function testReloadableDefaultsToFalseAndPropagatesAsFalse(): void
    {
        // Mirrors localCache: the `__reloadable` prop is always present and
        // defaults to false, so a component that didn't opt in renders the
        // pre-feature markup (no data-usephp-defer-name, replaced on
        // resolve).
        $defer = new Defer(name: 'x');
        self::assertFalse($defer->reloadable);

        $element = $defer->buildPlaceholder(['fallback' => H::span()]);
        self::assertArrayHasKey('__reloadable', $element->props);
        self::assertFalse($element->props['__reloadable']);
    }

    public function testReloadablePropagatesIntoPlaceholder(): void
    {
        // The explicit reload opt-in must ride through buildPlaceholder()
        // onto the H::defer element so the renderer can emit
        // data-usephp-defer-name and usephp.js keeps the wrapper.
        $defer = new Defer(name: 'x', reloadable: true);
        self::assertTrue($defer->reloadable);

        $element = $defer->buildPlaceholder(['fallback' => H::span()]);
        self::assertTrue($element->props['__reloadable']);
    }

    public function testLocalCacheTtlDefaultsToZeroAndPropagatesAsZero(): void
    {
        // 0 = no time bound: behaviour and (per RendererTest) markup are
        // byte-identical to a plain `localCache: true` / no opt-in at all.
        $defer = new Defer(name: 'x');
        self::assertSame(0, $defer->localCacheTtl);

        $element = $defer->buildPlaceholder(['fallback' => H::span()]);
        self::assertArrayHasKey('__localCacheTtl', $element->props);
        self::assertSame(0, $element->props['__localCacheTtl']);
    }

    public function testLocalCacheTtlPropagatesIntoPlaceholder(): void
    {
        // A positive TTL must ride through buildPlaceholder() onto the
        // H::defer element so the renderer can emit
        // data-usephp-defer-cache-ttl for usephp.js to age the L2 entry.
        $defer = new Defer(name: 'x', localCache: true, localCacheTtl: 60);
        self::assertSame(60, $defer->localCacheTtl);

        $element = $defer->buildPlaceholder(['fallback' => H::span()]);
        self::assertSame(60, $element->props['__localCacheTtl']);
    }

    public function testNonPositiveLocalCacheTtlIsNormalisedToZero(): void
    {
        // A nonsensical negative (or 0) is "no time bound", not an error —
        // normalised to 0 so the property reads back as its effective
        // value rather than a stored -1.
        self::assertSame(0, new Defer(name: 'x', localCache: true, localCacheTtl: -1)->localCacheTtl);
        self::assertSame(0, new Defer(name: 'x', localCache: true, localCacheTtl: 0)->localCacheTtl);

        // ...and a clamped-away negative no longer trips the
        // positive-TTL-without-localCache guard.
        self::assertSame(0, new Defer(name: 'x', localCacheTtl: -5)->localCacheTtl);
    }

    public function testRejectsPositiveLocalCacheTtlWithoutLocalCache(): void
    {
        // A *positive* TTL only bounds the localStorage entry, which is
        // never written unless localCache opted in — so that combination
        // is a real misconfiguration worth surfacing eagerly rather than
        // silently ignoring. (A non-positive TTL is fine: it just means
        // no bound.)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no effect without localCache');
        new Defer(name: 'x', localCacheTtl: 60);
    }

    public function testRegisterAutoRendersClassEndpoint(): void
    {
        // Regression for Copilot review on PR #17: registering a #[Defer]
        // class component must make the defer endpoint render the class's
        // own render() output — no extra wiring required.
        $app = new UsePHP();
        $app->register(DeferAttributeTestComponent::class);

        $headers = [];
        $app->withHeaderEmitter(function (string $header) use (&$headers): void {
            $headers[] = $header;
        });

        $html = $app->handleDeferred(new RequestContext(
            method: 'GET',
            path: '/_defer/user-header',
        ));

        self::assertNotNull($html, 'auto-registered endpoint should resolve');
        self::assertStringContainsString('<header>static</header>', $html);
        // The Cache-Control on the registration must travel into the response.
        self::assertContains('Cache-Control: private, no-store', $headers);
    }

    public function testPsxBridgeEmitsPlaceholderOnPageSide(): void
    {
        // Page-side: PSX <UserHeaderDeferred fallback={…} /> compiles to
        // renderPsxComponent($className, $props). The bridge installed by
        // register() must turn that into an H::defer placeholder (NOT the
        // class's render() output, that's the endpoint side).
        $app = new UsePHP();
        $app->register(DeferAttributeTestComponent::class);

        $fallback = H::span(children: 'loading');
        $element = $app->renderPsxComponent(
            DeferAttributeTestComponent::class,
            ['fallback' => $fallback, 'post_id' => 5],
        );

        self::assertSame('__defer__', $element->type);
        self::assertSame('user-header', $element->props['__name']);
        self::assertSame(['post_id' => 5], $element->props['__params']);
        self::assertSame($fallback, $element->props['__fallback']);
    }

    public function testRegisterIsIdempotentForIdenticalConfig(): void
    {
        $app = new UsePHP();
        $app->register(DeferAttributeTestComponent::class);
        // A second register() must not throw; auto-registration is idempotent
        // when the (component, cacheControl) tuple matches.
        $app->register(DeferAttributeTestComponent::class);
        $this->expectNotToPerformAssertions();
    }

    public function testRegistryClearsStaleDeferOnReRegister(): void
    {
        // Regression for Copilot review on PR #17: re-registering a name
        // with a class that no longer carries #[Defer] must drop the
        // previously resolved Defer config (register() overwrites by name).
        $registry = new \Polidog\UsePhp\Component\ComponentRegistry();
        $registry->register(DeferAttributeTestComponent::class);
        self::assertNotNull($registry->getDefer('UserHeaderDeferred'));

        $registry->register(NonDeferReplacementComponent::class);
        self::assertNull(
            $registry->getDefer('UserHeaderDeferred'),
            'Re-registering the same name with a plain class must drop the stale Defer.',
        );
    }
}

#[Component(name: 'UserHeaderDeferred')]
final class NonDeferReplacementComponent extends BaseComponent
{
    public function render(): Element
    {
        return H::header(children: 'replacement');
    }
}
