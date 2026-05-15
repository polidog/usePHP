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

    public function testRegisterAutoRegistersDeferredEndpoint(): void
    {
        $app = new UsePHP();
        // Bridge the class component as a renderable so the defer endpoint
        // has something to render (class components don't auto-register as
        // PSX callables otherwise).
        $app->registerComponent(
            DeferAttributeTestComponent::class,
            fn(array $props) => H::header(children: 'rendered'),
        );
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
        self::assertStringContainsString('<header>rendered</header>', $html);
        // The Cache-Control on the registration must travel into the response.
        self::assertContains('Cache-Control: private, no-store', $headers);
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
}
