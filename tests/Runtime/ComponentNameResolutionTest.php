<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'ResolutionBoard')]
final class ResolutionBoard extends BaseComponent
{
    public function render(): Element
    {
        [$count] = $this->useState(0);

        return H::p(children: "board={$count}");
    }
}

/**
 * Shares the custom name of {@see ResolutionBoard}.
 */
#[Component(name: 'ResolutionBoard')]
final class ResolutionImpostor extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'impostor');
    }
}

final class ResolutionPlain extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'plain');
    }
}

#[Component(name: 'ResolutionSnapshot', storage: 'snapshot')]
final class ResolutionSnapshot extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'snapshot');
    }
}

#[Component(name: 'ResolutionDeferred')]
#[Defer(name: 'resolution-deferred')]
final class ResolutionDeferred extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'deferred');
    }
}

final class ResolutionNotAComponent {}

/**
 * #111: createElement() and render() take the class or the name, and a string
 * that resolves to nothing fails instead of rendering nothing.
 */
final class ComponentNameResolutionTest extends TestCase
{
    private UsePHP $app;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();

        $this->app = new UsePHP();
        $this->app->setSnapshotSecret('component-name-resolution-secret');
        $this->app->withHeaderEmitter(static function (string $header): void {});
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_USEPHP_PARTIAL']);
        ComponentState::clearInstances();
        RenderContext::clearApp();
        http_response_code(200);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function entryPointProvider(): iterable
    {
        yield 'createElement()' => ['createElement'];
        yield 'render()' => ['render'];
    }

    /**
     * What an entry point produces, as HTML.
     */
    private function html(string $entryPoint, string $component, ?string $key = null): string
    {
        RenderContext::beginRender();
        $result = $this->app->{$entryPoint}($component, $key);

        return $result instanceof Element ? $this->app->renderElement($result) : $result;
    }

    #[DataProvider('entryPointProvider')]
    public function testClassAndNameRenderTheSameInstance(string $entryPoint): void
    {
        $this->app->register(ResolutionBoard::class);

        $html = $this->html($entryPoint, ResolutionBoard::class);

        self::assertStringContainsString('data-usephp="ResolutionBoard#', $html);
        self::assertStringContainsString('<p>board=0</p>', $html);
        self::assertSame($this->html($entryPoint, 'ResolutionBoard'), $html);
    }

    #[DataProvider('entryPointProvider')]
    public function testAKeyGivesTheSameInstanceIdThroughEither(string $entryPoint): void
    {
        $this->app->register(ResolutionBoard::class);

        $byClass = $this->html($entryPoint, ResolutionBoard::class, 'task-1');
        $byName = $this->html($entryPoint, 'ResolutionBoard', 'task-1');

        self::assertStringContainsString('data-usephp="ResolutionBoard#task-1"', $byClass);
        self::assertStringContainsString('data-usephp="ResolutionBoard#task-1"', $byName);
    }

    #[DataProvider('entryPointProvider')]
    public function testStateIsSharedWithTheName(string $entryPoint): void
    {
        $this->app->register(ResolutionBoard::class);
        ComponentState::getInstance('ResolutionBoard#k', StorageType::Session)->setState(0, 5);
        ComponentState::clearInstances();

        self::assertStringContainsString('board=5', $this->html($entryPoint, ResolutionBoard::class, 'k'));
        self::assertStringContainsString('board=5', $this->html($entryPoint, 'ResolutionBoard', 'k'));
    }

    #[DataProvider('entryPointProvider')]
    public function testTheClassGetsTheSettingsItsNameWasRegisteredWith(string $entryPoint): void
    {
        $this->app->register(ResolutionSnapshot::class);

        self::assertStringContainsString('data-usephp-snapshot=', $this->html($entryPoint, ResolutionSnapshot::class));
    }

    #[DataProvider('entryPointProvider')]
    public function testAClassWithoutACustomNameKeepsWorking(string $entryPoint): void
    {
        $this->app->register(ResolutionPlain::class);

        self::assertStringContainsString('<p>plain</p>', $this->html($entryPoint, ResolutionPlain::class));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unresolvableProvider(): iterable
    {
        foreach (self::entryPointProvider() as $label => [$entryPoint]) {
            yield "{$label}: unknown name" => [$entryPoint, 'NoSuchComponent'];
            yield "{$label}: class that is not a component" => [$entryPoint, ResolutionNotAComponent::class];
            yield "{$label}: unregistered component class" => [$entryPoint, ResolutionPlain::class];
            yield "{$label}: class that lost its name to another registration" => [$entryPoint, ResolutionImpostor::class];
        }
    }

    #[DataProvider('unresolvableProvider')]
    public function testWhatDoesNotResolveThrows(string $entryPoint, string $component): void
    {
        $this->app->register(ResolutionBoard::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($component . ' is not registered');

        $this->html($entryPoint, $component);
    }

    #[DataProvider('entryPointProvider')]
    public function testAFailedLookupLeavesTheRenderContextAlone(string $entryPoint): void
    {
        RenderContext::clearApp();
        $this->app->register(ResolutionBoard::class);
        $current = ComponentState::current();

        try {
            $this->app->{$entryPoint}('NoSuchComponent');
            self::fail('Expected LogicException');
        } catch (\LogicException) {
            self::assertNull(RenderContext::getApp());
            self::assertSame($current, ComponentState::current());
        }
    }

    public function testAnUnregisteredClassIsNotRegisteredAsASideEffect(): void
    {
        try {
            $this->app->createElement(ResolutionPlain::class);
            self::fail('Expected LogicException');
        } catch (\LogicException) {
            self::assertFalse($this->app->getRegistry()->has(ResolutionPlain::class));
        }
    }

    public function testADeferEndpointResolvesAClassWithACustomName(): void
    {
        $this->app->register(ResolutionDeferred::class);

        $html = $this->app->handleDeferred(new RequestContext(method: 'GET', path: '/_defer/resolution-deferred'));

        self::assertStringContainsString('data-usephp="ResolutionDeferred#0"', (string) $html);
        self::assertStringContainsString('<p>deferred</p>', (string) $html);
    }

    public function testADeferEndpointCanNameItsComponentByCustomName(): void
    {
        $this->app->register(ResolutionBoard::class);
        $this->app->registerDeferred('by-name', 'ResolutionBoard');

        $html = $this->app->handleDeferred(new RequestContext(method: 'GET', path: '/_defer/by-name'));

        self::assertStringContainsString('<p>board=0</p>', (string) $html);
    }

    /**
     * Before #111 the other class rendered under this endpoint's Cache-Control.
     */
    public function testADeferEndpointDoesNotServeTheClassThatTookItsTargetsName(): void
    {
        $this->app->register(ResolutionBoard::class);
        $this->app->registerDeferred('board', ResolutionBoard::class);
        $this->app->register(ResolutionImpostor::class);

        $log = ini_set('error_log', '/dev/null');
        try {
            $html = $this->app->handleDeferred(new RequestContext(method: 'GET', path: '/_defer/board'));
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(500, http_response_code());
        self::assertSame('Failed to render deferred component.', $html);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function signedForeignInstanceProvider(): iterable
    {
        yield 'unknown name' => ['ResolutionProbe\\NoSuch#0'];
        yield 'unregistered class' => [ResolutionPlain::class . '#0'];
    }

    /**
     * Even a correctly signed action can't turn its instance ID into a class
     * lookup: the action entry reads the registry directly, so it neither throws
     * nor autoloads what the client wrote.
     */
    #[DataProvider('signedForeignInstanceProvider')]
    public function testAnActionDoesNotResolveItsInstanceIdToAClass(string $instanceId): void
    {
        $this->app->disableCsrfProtection();
        $this->app->withExitHandler(static function (): void {});

        $json = json_encode(['type' => 'setState', 'payload' => ['index' => 0, 'value' => 1], 'componentId' => $instanceId], JSON_THROW_ON_ERROR);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
        $_POST = [
            '_usephp_component' => $instanceId,
            '_usephp_action' => $json,
            '_usephp_action_sig' => (string) $this->app->signAction($instanceId, $json, '/'),
        ];

        $loaded = [];
        $probe = static function (string $class) use (&$loaded): void {
            $loaded[] = $class;
        };
        spl_autoload_register($probe);
        ob_start();
        try {
            $body = $this->app->handleAction();
        } finally {
            ob_end_clean();
            spl_autoload_unregister($probe);
        }

        // No in-place re-render for it, so the PRG fallback redirects.
        self::assertSame('', (string) $body);
        self::assertSame(303, http_response_code());
        self::assertSame([], array_values(array_filter($loaded, static fn(string $class): bool => str_contains($class, 'ResolutionProbe'))));
    }
}
