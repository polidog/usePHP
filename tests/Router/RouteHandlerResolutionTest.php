<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'HandlerResolutionBoard')]
final class HandlerResolutionBoard extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'board');
    }
}

final class HandlerResolutionPlain extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'plain');
    }
}

final class HandlerResolutionNotAComponent {}

/**
 * Handler that shares the custom name of {@see HandlerResolutionBoard}.
 */
#[Component(name: 'HandlerResolutionBoard')]
final class HandlerResolutionImpostor extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'impostor');
    }
}

/**
 * #74: a string route handler is resolved to a registered component or fails
 * loudly. It used to fall through to an empty 200 with nothing in the log.
 */
final class RouteHandlerResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
    }

    public function testClassWithCustomNameIsResolvedFromItsFqcn(): void
    {
        $app = new UsePHP();
        $app->register(HandlerResolutionBoard::class);
        $app->getRouter()->get('/board', HandlerResolutionBoard::class);

        $html = $this->renderRoute($app, '/board');

        self::assertStringContainsString('<p>board</p>', $html);
        self::assertStringContainsString('data-usephp="HandlerResolutionBoard#', $html);
    }

    public function testCustomNameCanBeUsedAsTheHandler(): void
    {
        $app = new UsePHP();
        $app->register(HandlerResolutionBoard::class);
        $app->getRouter()->get('/board', 'HandlerResolutionBoard');

        self::assertStringContainsString('<p>board</p>', $this->renderRoute($app, '/board'));
    }

    public function testRegisteredClassWithoutCustomNameStillRenders(): void
    {
        $app = new UsePHP();
        $app->register(HandlerResolutionPlain::class);
        $app->getRouter()->get('/plain', HandlerResolutionPlain::class);

        self::assertStringContainsString('<p>plain</p>', $this->renderRoute($app, '/plain'));
    }

    public function testUnregisteredComponentClassThrowsInsteadOfRenderingNothing(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/x', HandlerResolutionPlain::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(HandlerResolutionPlain::class . ' is not registered');

        $this->renderRoute($app, '/x');
    }

    public function testUnregisteredClassIsNotRegisteredAsASideEffect(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/x', HandlerResolutionPlain::class);

        try {
            $this->renderRoute($app, '/x');
            self::fail('Expected LogicException');
        } catch (\LogicException) {
            self::assertFalse($app->getRegistry()->has(HandlerResolutionPlain::getComponentName()));
        }
    }

    public function testClassThatLostItsNameToAnotherRegistrationThrows(): void
    {
        $app = new UsePHP();
        $app->register(HandlerResolutionBoard::class);
        $app->getRouter()->get('/x', HandlerResolutionImpostor::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(HandlerResolutionImpostor::class . ' is not registered');

        $this->renderRoute($app, '/x');
    }

    public function testUnknownStringHandlerThrows(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/x', 'NoSuchComponentOrFunction');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NoSuchComponentOrFunction');

        $this->renderRoute($app, '/x');
    }

    public function testClassThatIsNotAComponentThrows(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/x', HandlerResolutionNotAComponent::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(HandlerResolutionNotAComponent::class);

        $this->renderRoute($app, '/x');
    }

    public function testCallableStringHandlerStillWorks(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/x', __NAMESPACE__ . '\\routeHandlerResolutionCallable');

        self::assertSame('from callable', $this->renderRoute($app, '/x'));
    }

    private function renderRoute(UsePHP $app, string $path): string
    {
        ob_start();
        try {
            $app->run(new RequestContext('GET', $path));
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}

function routeHandlerResolutionCallable(): string
{
    return 'from callable';
}
