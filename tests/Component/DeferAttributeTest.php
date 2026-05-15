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
