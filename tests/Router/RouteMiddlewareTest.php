<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\NullRouter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\RouteMatch;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Router\SnapshotBehavior;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'MiddlewareCounter')]
final class MiddlewareCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count] = $this->useState(0);

        return H::p(children: "count={$count}");
    }
}

final class InvokableAllowMiddleware
{
    public static int $calls = 0;

    public function __invoke(): void
    {
        self::$calls++;
    }
}

final class InvokableDenyMiddleware
{
    public function __invoke(): bool
    {
        return false;
    }
}

final class NotInvokableMiddleware {}

final class MiddlewareSentinel extends \RuntimeException {}

function routeMiddlewareDenyFunction(): bool
{
    return false;
}

/**
 * #56: RouteBuilder::middleware() used to be stored and never run, so a route
 * "protected" by a guard was open to everyone. The guard must hold on every way
 * into the page: GET, HEAD, and action POSTs, which dispatch before GET routing.
 */
final class RouteMiddlewareTest extends TestCase
{
    private const string INSTANCE = 'MiddlewareCounter#0';

    /** @var array<string, mixed> */
    private array $savedServer;

    /** @var array<string, mixed> */
    private array $savedPost;

    private bool $handlerRan = false;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();
        $this->savedServer = $_SERVER;
        $this->savedPost = $_POST;
        $this->handlerRan = false;
        InvokableAllowMiddleware::$calls = 0;
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_POST = $this->savedPost;
        $_SESSION = [];
        ComponentState::clearInstances();
        StorageFactory::reset();
        http_response_code(200);
    }

    // ------------------------------------------------------------------
    // GET: what a middleware's return value means
    // ------------------------------------------------------------------

    public function testMiddlewareRunsAndContinuesWhenItReturnsNothing(): void
    {
        $calls = [];
        $app = new UsePHP();
        $app->getRouter()->get('/users/{name}', $this->handler())->middleware(
            static function (array $params, RequestContext $request) use (&$calls): void {
                $calls[] = [$params, $request->method];
            },
        );

        self::assertSame('<p>secret</p>', $this->get($app, '/users/%E6%97%A5%E6%9C%AC'));
        self::assertSame([[['name' => '日本'], 'GET']], $calls);
        self::assertTrue($this->handlerRan);
    }

    public function testMiddlewareReturningTrueContinues(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static fn(): bool => true);

        self::assertSame('<p>secret</p>', $this->get($app, '/admin'));
    }

    public function testMiddlewareReturningFalseIsAForbiddenResponse(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static fn(): bool => false);

        self::assertSame('Forbidden', $this->get($app, '/admin'));
        self::assertSame(403, http_response_code());
        self::assertFalse($this->handlerRan);
    }

    public function testMiddlewareReturningAStringReplacesTheResponse(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static function (): string {
            http_response_code(401);

            return 'log in first';
        });

        self::assertSame('log in first', $this->get($app, '/admin'));
        self::assertSame(401, http_response_code());
        self::assertFalse($this->handlerRan);
    }

    public function testMiddlewareReturningAnElementReplacesTheResponse(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(
            static fn(): Element => H::p(children: 'denied'),
        );

        self::assertSame('<p>denied</p>', $this->get($app, '/admin'));
        self::assertFalse($this->handlerRan);
    }

    /**
     * A guard whose return value we can't interpret must not fail open.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function uninterpretableResultProvider(): iterable
    {
        yield 'int' => [1];
        yield 'zero' => [0];
        yield 'array' => [[]];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('uninterpretableResultProvider')]
    public function testUninterpretableMiddlewareResultFailsClosed(mixed $result): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static fn(): mixed => $result);

        try {
            $this->get($app, '/admin');
            self::fail('Expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('middleware', $e->getMessage());
        }
        self::assertFalse($this->handlerRan);
    }

    public function testMiddlewareRunsInOrderAndStopsAtTheFirstThatEndsTheRequest(): void
    {
        $calls = [];
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())
            ->middleware(static function () use (&$calls): void {
                $calls[] = 'first';
            })
            ->middleware(
                static function () use (&$calls): bool {
                    $calls[] = 'second';

                    return false;
                },
                static function () use (&$calls): void {
                    $calls[] = 'third';
                },
            );

        self::assertSame('Forbidden', $this->get($app, '/admin'));
        self::assertSame(['first', 'second'], $calls);
    }

    public function testExceptionFromMiddlewarePropagatesAndNothingRenders(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static function (): void {
            throw new MiddlewareSentinel('nope');
        });

        ob_start();
        try {
            $app->run(new RequestContext('GET', '/admin'));
            self::fail('Expected the middleware exception to propagate');
        } catch (MiddlewareSentinel) {
            self::assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        self::assertFalse($this->handlerRan);
    }

    public function testMiddlewareRunsBeforeASnapshotIsRestored(): void
    {
        $serializedSnapshot = new SnapshotSerializer('s3cret')->serialize(
            new Snapshot('MiddlewareCounter', '0', [0 => 5]),
        );
        $stateSeenByGuard = 'guard did not run';
        $stateSeenByHandler = null;

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/allowed', static function () use (&$stateSeenByHandler): string {
            $stateSeenByHandler = ComponentState::current();

            return '';
        })->persistentSnapshot()->middleware(static function () use (&$stateSeenByGuard): void {
            $stateSeenByGuard = ComponentState::current();
        });
        $app->getRouter()->get('/denied', $this->handler())->persistentSnapshot()->middleware(
            static fn(): bool => false,
        );

        ComponentState::clearInstances();
        $this->get($app, '/denied', ['_snapshot' => $serializedSnapshot]);
        self::assertNull(ComponentState::current(), 'A blocked request must not restore state');

        $this->get($app, '/allowed', ['_snapshot' => $serializedSnapshot]);
        self::assertNull($stateSeenByGuard);
        self::assertNotNull($stateSeenByHandler);
    }

    // ------------------------------------------------------------------
    // GET: every spelling of a route keeps its guard
    // ------------------------------------------------------------------

    public function testHeadRequestIsGuarded(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static fn(): bool => false);

        ob_start();
        $app->run(new RequestContext('HEAD', '/admin'));
        ob_end_clean();

        self::assertSame(403, http_response_code());
        self::assertFalse($this->handlerRan);
    }

    public function testTrailingSlashIsGuarded(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static fn(): bool => false);

        self::assertSame('Forbidden', $this->get($app, '/admin/'));
    }

    public function testGroupedRouteIsGuarded(): void
    {
        $app = new UsePHP();
        $router = $app->getRouter();
        self::assertInstanceOf(SimpleRouter::class, $router);
        $handler = $this->handler();
        $router->group('/admin', static function ($group) use ($handler): void {
            $group->get('/users', $handler)->middleware(static fn(): bool => false);
        });

        self::assertSame('Forbidden', $this->get($app, '/admin/users'));
    }

    public function testMatchMethodsGuardsEveryMethodNotJustTheFirst(): void
    {
        $app = new UsePHP();
        $router = $app->getRouter();
        self::assertInstanceOf(SimpleRouter::class, $router);
        $router->matchMethods(['POST', 'GET'], '/admin', $this->handler())->middleware(static fn(): bool => false);

        self::assertSame('Forbidden', $this->get($app, '/admin'));
        self::assertFalse($this->handlerRan);

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());
        self::assertFalse($this->handlerRan);
    }

    public function testMatchMethodsAppliesBuilderSettingsToEveryMethod(): void
    {
        $router = new SimpleRouter();
        $router->matchMethods(['GET', 'POST'], '/page', 'Page')->sessionSnapshot();

        foreach (['GET', 'POST'] as $method) {
            $match = $router->match(new RequestContext($method, '/page'));
            self::assertSame(SnapshotBehavior::Session, $match?->snapshotBehavior, $method);
        }
    }

    public function testNonGetRouteIsGuarded(): void
    {
        $app = new UsePHP();
        $app->getRouter()->addRoute('PUT', '/admin', $this->handler())->middleware(static fn(): bool => false);

        ob_start();
        $app->run(new RequestContext('PUT', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());
        self::assertFalse($this->handlerRan);
    }

    public function testWithParamsKeepsMiddleware(): void
    {
        $guard = static fn(): bool => false;
        $match = new RouteMatch(handler: 'H', middleware: [$guard])->withParams(['a' => 'b']);

        self::assertSame([$guard], $match->middleware);
    }

    public function testComponentRouteIsGuarded(): void
    {
        $app = new UsePHP();
        $app->register(MiddlewareCounter::class);
        $app->getRouter()->get('/admin', MiddlewareCounter::class)->middleware(static fn(): bool => false);

        self::assertSame('Forbidden', $this->get($app, '/admin'));
    }

    public function testMatchPresetOnNullRouterIsGuarded(): void
    {
        $app = new UsePHP();
        $app->setRouter(new NullRouter(new RouteMatch(
            handler: $this->handler(),
            middleware: [static fn(): bool => false],
        )));

        self::assertSame('Forbidden', $this->get($app, '/anything'));
    }

    // ------------------------------------------------------------------
    // String middleware
    // ------------------------------------------------------------------

    public function testFunctionNameMiddlewareIsCalled(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())
            ->middleware(__NAMESPACE__ . '\\routeMiddlewareDenyFunction');

        self::assertSame('Forbidden', $this->get($app, '/admin'));
    }

    public function testInvokableClassNameMiddlewareIsInstantiatedAndCalled(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/open', $this->handler())->middleware(InvokableAllowMiddleware::class);
        $app->getRouter()->get('/admin', $this->handler())->middleware(InvokableDenyMiddleware::class);

        self::assertSame('<p>secret</p>', $this->get($app, '/open'));
        self::assertSame(1, InvokableAllowMiddleware::$calls);

        self::assertSame('Forbidden', $this->get($app, '/admin'));
    }

    /**
     * A misspelled guard must not silently leave the route open.
     *
     * @return iterable<string, array{string}>
     */
    public static function unresolvableMiddlewareProvider(): iterable
    {
        yield 'unknown name' => ['NoSuchMiddleware'];
        yield 'class that is not invokable' => [NotInvokableMiddleware::class];
    }

    #[DataProvider('unresolvableMiddlewareProvider')]
    public function testUnresolvableMiddlewareFailsClosed(string $middleware): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware($middleware);

        try {
            $this->get($app, '/admin');
            self::fail('Expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString($middleware, $e->getMessage());
        }
        self::assertFalse($this->handlerRan);
    }

    // ------------------------------------------------------------------
    // Action POST: dispatched before GET routing, must not skip the guard
    // ------------------------------------------------------------------

    public function testBlockedActionPostToGuardedPageDoesNotRunTheAction(): void
    {
        $app = $this->counterApp(static fn(): bool => false);
        $this->prepareAction($app, '/admin');

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());

        self::assertSame(403, http_response_code());
        self::assertSame(0, $this->counterValue());
    }

    public function testBlockedPartialActionPostDoesNotRunTheActionOrRender(): void
    {
        $app = $this->counterApp(static fn(): bool => false);
        $this->prepareAction($app, '/admin');
        $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());

        self::assertSame(0, $this->counterValue());
    }

    public function testHandleActionHonorsTheGuardToo(): void
    {
        $app = $this->counterApp(static fn(): bool => false);
        $this->prepareAction($app, '/admin');

        self::assertSame('Forbidden', $app->handleAction());
        self::assertSame(0, $this->counterValue());
    }

    public function testAllowedActionPostRunsTheAction(): void
    {
        $seen = [];
        $app = $this->counterApp(static function (array $params, RequestContext $request) use (&$seen): void {
            $seen[] = $request->method;
        });
        $this->prepareAction($app, '/admin');

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        ob_end_clean();

        self::assertSame(['POST'], $seen);
        self::assertSame(303, http_response_code());
        self::assertSame(1, $this->counterValue());
    }

    public function testExceptionFromGuardOnActionPostLeavesStateUntouched(): void
    {
        $app = $this->counterApp(static function (): void {
            throw new MiddlewareSentinel('nope');
        });
        $this->prepareAction($app, '/admin');

        ob_start();
        try {
            $app->run(new RequestContext('POST', '/admin'));
            self::fail('Expected the middleware exception to propagate');
        } catch (MiddlewareSentinel) {
            self::assertSame(0, $this->counterValue());
        } finally {
            ob_end_clean();
        }
    }

    public function testGuardUsesTheRequestPassedToRunNotTheRawRequestUri(): void
    {
        // A front controller mounted under a prefix strips it before run().
        $app = $this->counterApp(static fn(): bool => false);
        $this->prepareAction($app, '/mounted/admin');

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());

        self::assertSame(0, $this->counterValue());
    }

    public function testMatchCachedByAnEarlierRequestDoesNotSpareTheGuard(): void
    {
        $app = $this->counterApp(static fn(): bool => false);
        $app->getRouter()->get('/public', $this->handler());
        self::assertSame('<p>secret</p>', $this->get($app, '/public'));

        $this->prepareAction($app, '/admin');

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());

        self::assertSame(0, $this->counterValue());
    }

    public function testGuardedPageIsCheckedBeforeTheActionIsValidated(): void
    {
        $app = $this->counterApp(static fn(): bool => false);
        $this->prepareAction($app, '/admin');
        unset($_POST['_usephp_action_sig'], $_POST['_usephp_csrf']);

        ob_start();
        $app->run(new RequestContext('POST', '/admin'));
        self::assertSame('Forbidden', ob_get_clean());
    }

    public function testActionPostToAnUnguardedPageIsUnaffected(): void
    {
        $app = $this->counterApp(static fn(): bool => false);
        $app->getRouter()->get('/open', MiddlewareCounter::class);
        $this->prepareAction($app, '/open');

        ob_start();
        $app->run(new RequestContext('POST', '/open'));
        ob_end_clean();

        self::assertSame(303, http_response_code());
        self::assertSame(1, $this->counterValue());
    }

    // ------------------------------------------------------------------
    // Custom front controllers
    // ------------------------------------------------------------------

    public function testRunRouteMiddlewareLetsAFrontControllerApplyTheGuard(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/admin', $this->handler())->middleware(static fn(): bool => false);
        $app->getRouter()->get('/open', $this->handler())->middleware(static fn(): bool => true);

        $request = new RequestContext('GET', '/admin');
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);
        self::assertSame('Forbidden', $app->runRouteMiddleware($match, $request));

        $request = new RequestContext('GET', '/open');
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);
        self::assertNull($app->runRouteMiddleware($match, $request));
    }

    // ------------------------------------------------------------------
    // A real `exit`, which would take PHPUnit down, runs in its own process
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exitScenarioProvider(): iterable
    {
        yield 'GET, exit' => ['get-exit', 'blocked by middleware'];
        yield 'action POST, exit' => ['action-exit', 'blocked by middleware'];
        yield 'HEAD, exit' => ['head-exit', 'blocked by middleware'];
    }

    #[DataProvider('exitScenarioProvider')]
    public function testMiddlewareThatExitsEndsTheRequest(string $scenario, string $expected): void
    {
        $output = $this->runFixture($scenario);

        self::assertStringContainsString($expected, $output);
        self::assertStringContainsString('handler-ran=no', $output);
        self::assertStringNotContainsString('secret', $output);
    }

    public function testMiddlewareThatRedirectsEndsTheRequest(): void
    {
        $output = $this->runFixture('get-redirect');

        self::assertStringContainsString('handler-ran=no', $output);
        self::assertStringNotContainsString('secret', $output);
    }

    public function testFixtureRendersThePageWhenTheGuardLetsItThrough(): void
    {
        // Guards the tests above: "handler-ran=no" must be able to say yes.
        $output = $this->runFixture('get-allow');

        self::assertStringContainsString('secret', $output);
        self::assertStringContainsString('handler-ran=yes', $output);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function runFixture(string $scenario): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/middleware_exit_app.php', $scenario],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);

        return $output;
    }

    private function handler(): \Closure
    {
        return function (): Element {
            $this->handlerRan = true;

            return H::p(children: 'secret');
        };
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(UsePHP $app, string $path, array $query = []): string
    {
        ob_start();
        try {
            $app->run(new RequestContext('GET', $path, query: $query));
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function counterApp(callable $guard): UsePHP
    {
        $app = new UsePHP();
        $app->register(MiddlewareCounter::class);
        $app->withHeaderEmitter(static function (string $header): void {});
        $app->withExitHandler(static function (): void {});
        $app->getRouter()->get('/admin', MiddlewareCounter::class)->middleware($guard);

        return $app;
    }

    /**
     * Set up a valid, signed "set the counter to 1" action POST.
     */
    private function prepareAction(UsePHP $app, string $requestUri): void
    {
        $json = json_encode([
            'type' => 'setState',
            'payload' => ['index' => 0, 'value' => 1],
            'componentId' => self::INSTANCE,
        ], JSON_THROW_ON_ERROR);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
        unset($_SERVER['HTTP_X_USEPHP_PARTIAL']);
        $_POST = [
            '_usephp_component' => self::INSTANCE,
            '_usephp_action' => $json,
            '_usephp_action_sig' => (string) $app->signAction(self::INSTANCE, $json),
            '_usephp_csrf' => $app->getCsrfToken(),
        ];
    }

    private function counterValue(): mixed
    {
        ComponentState::clearInstances();

        return ComponentState::getInstance(self::INSTANCE, StorageType::Session)->getState(0, 0);
    }
}
