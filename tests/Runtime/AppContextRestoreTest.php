<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\RenderContext;

use function Polidog\UsePhp\Runtime\useRouter;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'AppContextChild')]
final class AppContextChild extends BaseComponent
{
    public function render(): Element
    {
        return H::span(children: 'child');
    }
}

#[Component(name: 'AppContextThrowingChild')]
final class AppContextThrowingChild extends BaseComponent
{
    public function render(): Element
    {
        throw new \RuntimeException('child failed');
    }
}

/**
 * The public render entry points can be called while another render is in
 * progress, so leaving one must put back the app it found, not clear it.
 */
final class AppContextRestoreTest extends TestCase
{
    private UsePHP $app;

    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
        RenderContext::beginRender();

        $this->app = new UsePHP();
        $this->app->setSnapshotSecret('test-secret-key-for-app-context-restore-tests');
        $this->app->register(AppContextChild::class);
        $this->app->register(AppContextThrowingChild::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
    }

    public function testCreateElementKeepsTheAppOfAnOngoingRender(): void
    {
        RenderContext::setApp($this->app);

        $this->app->createElement('AppContextChild');

        self::assertSame($this->app, RenderContext::getApp());
    }

    public function testRenderElementKeepsTheAppOfAnOngoingRender(): void
    {
        RenderContext::setApp($this->app);

        $this->app->renderElement(H::div());

        self::assertSame($this->app, RenderContext::getApp());
    }

    public function testRenderKeepsTheAppOfAnOngoingRender(): void
    {
        RenderContext::setApp($this->app);

        $this->app->render('AppContextChild');

        self::assertSame($this->app, RenderContext::getApp());
    }

    public function testEntryPointsLeaveNoAppWhenCalledOutsideARender(): void
    {
        $this->app->createElement('AppContextChild');
        self::assertNull(RenderContext::getApp());

        $this->app->renderElement(H::div());
        self::assertNull(RenderContext::getApp());

        $this->app->render('AppContextChild');
        self::assertNull(RenderContext::getApp());
    }

    public function testAnotherAppsEntryPointRestoresTheOuterApp(): void
    {
        $other = new UsePHP();
        RenderContext::setApp($this->app);

        $other->renderElement(H::div());

        self::assertSame($this->app, RenderContext::getApp());
    }

    public function testThrowingChildStillRestoresTheApp(): void
    {
        RenderContext::setApp($this->app);

        try {
            $this->app->createElement('AppContextThrowingChild');
            self::fail('the child should have thrown');
        } catch (\RuntimeException) {
            self::assertSame($this->app, RenderContext::getApp());
        }
    }

    public function testUseRouterAfterCreateElementInARouteHandler(): void
    {
        $this->app->setRouter(new SimpleRouter());
        RenderContext::setApp($this->app);

        $this->app->createElement('AppContextChild');

        self::assertIsString(useRouter()['currentUrl']);
    }

    public function testSnapshotFcAfterCreateElementInARouteHandler(): void
    {
        $Snapshot = fc(function (array $props): Element {
            [$value] = useState('snap');

            return H::span(children: $value);
        }, 'after-create', StorageType::Snapshot);

        $this->app->getRouter()->get('/x', fn(): Element => H::div(children: [
            $this->app->createElement('AppContextChild', 'x'),
            $Snapshot([]),
        ]));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        \ob_start();
        try {
            $this->app->run(new RequestContext(method: 'GET', path: '/x'));
        } finally {
            $html = (string) \ob_get_clean();
        }

        self::assertStringContainsString('data-usephp-snapshot', $html);
        self::assertStringContainsString('snap', $html);
        self::assertNull(RenderContext::getApp(), 'run() itself still clears the context when it ends');
    }
}
