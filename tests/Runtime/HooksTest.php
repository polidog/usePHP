<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;

use function Polidog\UsePhp\Runtime\useEffect;
use function Polidog\UsePhp\Runtime\useRouter;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\UsePHP;

class HooksTest extends TestCase
{
    private static int $cleanupCalls = 0;

    protected function setUp(): void
    {
        // Start session for tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        RenderContext::clearApp();
    }

    public function testUseEffectRunsOnMount(): void
    {
        $renderer = new Renderer('effect-test-1');
        $effectRan = false;

        $component = function () use (&$effectRan): Element {
            useEffect(function () use (&$effectRan) {
                $effectRan = true;
            }, []);

            return H::div(children: 'Test');
        };

        $renderer->render($component);

        $this->assertTrue($effectRan, 'Effect should run on mount');
    }

    public function testUseEffectWithEmptyDepsRunsOnlyOnce(): void
    {
        $renderer = new Renderer('effect-test-2');
        $runCount = 0;

        $component = function () use (&$runCount): Element {
            useEffect(function () use (&$runCount) {
                $runCount++;
            }, []);

            return H::div(children: 'Test');
        };

        // First render
        $renderer->render($component);
        $this->assertEquals(1, $runCount, 'Effect should run on first render');

        // Second render
        $renderer->render($component);
        $this->assertEquals(1, $runCount, 'Effect should NOT run on second render with empty deps');
    }

    public function testUseEffectWithNullDepsRunsEveryTime(): void
    {
        $renderer = new Renderer('effect-test-3');
        $runCount = 0;

        $component = function () use (&$runCount): Element {
            useEffect(function () use (&$runCount) {
                $runCount++;
            }, null);

            return H::div(children: 'Test');
        };

        // First render
        $renderer->render($component);
        $this->assertEquals(1, $runCount);

        // Second render
        $renderer->render($component);
        $this->assertEquals(2, $runCount);

        // Third render
        $renderer->render($component);
        $this->assertEquals(3, $runCount, 'Effect should run on every render with null deps');
    }

    public function testUseEffectRunsWhenDepsChange(): void
    {
        $renderer = new Renderer('effect-test-4');
        $effectValues = [];

        // We'll simulate state changes by manipulating session directly
        $stateKey = 'usephp:effect-test-4:state:0';

        $component = function () use (&$effectValues): Element {
            [$count, $setCount] = useState(0);

            useEffect(function () use ($count, &$effectValues) {
                $effectValues[] = $count;
            }, [$count]);

            return H::div(children: "Count: $count");
        };

        // First render (count = 0)
        $renderer->render($component);
        $this->assertEquals([0], $effectValues);

        // Second render with same value
        $renderer->render($component);
        $this->assertEquals([0], $effectValues, 'Effect should NOT run when deps unchanged');

        // Change state
        $_SESSION[$stateKey] = 5;

        // Third render (count = 5)
        $renderer->render($component);
        $this->assertEquals([0, 5], $effectValues, 'Effect should run when deps change');
    }

    public function testUseEffectCleanupFunction(): void
    {
        $renderer = new Renderer('effect-test-5');
        $cleanupRan = false;
        $stateKey = 'usephp:effect-test-5:state:0';

        $component = function () use (&$cleanupRan): Element {
            [$count, $setCount] = useState(0);

            useEffect(function () use (&$cleanupRan) {
                return function () use (&$cleanupRan) {
                    $cleanupRan = true;
                };
            }, [$count]);

            return H::div(children: "Count: $count");
        };

        // First render
        $renderer->render($component);
        $this->assertFalse($cleanupRan, 'Cleanup should not run on first render');

        // Change state to trigger re-run
        $_SESSION[$stateKey] = 1;

        // Second render - cleanup from previous effect should run
        $renderer->render($component);
        $this->assertTrue($cleanupRan, 'Cleanup should run before new effect');
    }

    public function testEffectCleanupIsNeverWrittenToStorage(): void
    {
        // A Closure in $_SESSION makes the end-of-request session write fatal.
        $renderer = new Renderer('effect-cleanup-storage');

        $renderer->render(function (): Element {
            useEffect(fn() => static function (): void {}, []);

            return H::div();
        });

        serialize($_SESSION);
        $this->assertArrayNotHasKey('usephp:effect-cleanup-storage:effect_cleanup:0', $_SESSION);
    }

    public function testEffectCleanupRunsOnlyOncePerEffectRun(): void
    {
        $renderer = new Renderer('effect-cleanup-once');
        $cleanups = 0;
        $stateKey = 'usephp:effect-cleanup-once:state:0';
        $effectRuns = 0;

        $component = function () use (&$cleanups, &$effectRuns): Element {
            [$count] = useState(0);
            useEffect(function () use (&$cleanups, &$effectRuns) {
                $effectRuns++;

                // Only the first effect run registers a cleanup.
                return $effectRuns === 1 ? function () use (&$cleanups): void {
                    $cleanups++;
                } : null;
            }, [$count]);

            return H::div();
        };

        $renderer->render($component);
        $_SESSION[$stateKey] = 1;
        $renderer->render($component);
        $_SESSION[$stateKey] = 2;
        $renderer->render($component);

        $this->assertSame(3, $effectRuns);
        $this->assertSame(1, $cleanups);
    }

    public function testEffectCleanupDoesNotSurviveIntoTheNextRequest(): void
    {
        $renderer = new Renderer('effect-cleanup-request');
        $cleanupRan = false;
        $stateKey = 'usephp:effect-cleanup-request:state:0';

        $component = function () use (&$cleanupRan): Element {
            [$count] = useState(0);
            useEffect(function () use (&$cleanupRan) {
                return function () use (&$cleanupRan): void {
                    $cleanupRan = true;
                };
            }, [$count]);

            return H::div();
        };

        $renderer->render($component);

        // A new request starts with no in-memory ComponentState.
        ComponentState::clearInstances();
        $_SESSION[$stateKey] = 1;
        $renderer->render($component);

        $this->assertFalse($cleanupRan);
    }

    public function testClearStateDropsPendingEffectCleanup(): void
    {
        $state = ComponentState::getInstance('effect-cleanup-clear');
        $cleanupRan = false;
        $state->setEffectCleanup(0, function () use (&$cleanupRan): void {
            $cleanupRan = true;
        });

        $state->clearState();
        $state->runEffectCleanup(0);

        $this->assertFalse($cleanupRan);
    }

    public static function recordCleanupCall(): void
    {
        self::$cleanupCalls++;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonCleanupEffectReturnValues(): iterable
    {
        yield 'int' => [1];
        yield 'text' => ['text'];
        yield 'bool' => [true];
        yield 'plain array' => [['a', 'b']];
        yield 'object' => [new \stdClass()];
        // Callable-shaped data the application merely returned. Running it
        // later would let an effect's result decide what gets executed.
        yield 'function name' => ['system'];
        yield 'static method string' => [self::class . '::recordCleanupCall'];
        yield 'static method array' => [[self::class, 'recordCleanupCall']];
        yield 'invokable object' => [new class {
            public function __invoke(): void
            {
                HooksTest::recordCleanupCall();
            }
        }];
    }

    #[DataProvider('nonCleanupEffectReturnValues')]
    public function testEffectReturnValueThatIsNotAClosureIsIgnored(mixed $returned): void
    {
        self::$cleanupCalls = 0;
        $id = 'effect-return-' . uniqid();
        $renderer = new Renderer($id);
        $stateKey = "usephp:{$id}:state:0";
        $component = function () use ($returned): Element {
            [$count] = useState(0);
            useEffect(fn() => $returned, [$count]);

            return H::div();
        };

        $renderer->render($component);
        // Re-running the effect would invoke anything kept as a cleanup; for
        // 'system' that throws an ArgumentCountError.
        $_SESSION[$stateKey] = 1;
        $renderer->render($component);

        $this->assertSame(0, self::$cleanupCalls);
    }

    public function testUseEffectToleratesDepsChangingFromNullToArray(): void
    {
        $renderer = new Renderer('effect-null-deps');
        $runs = 0;
        $deps = null;
        $component = function () use (&$runs, &$deps): Element {
            useEffect(function () use (&$runs): void {
                $runs++;
            }, $deps);

            return H::div();
        };

        $renderer->render($component);
        $deps = ['a'];
        $renderer->render($component);
        $renderer->render($component);

        $this->assertSame(2, $runs);
    }

    public function testUseEffectDepsTreatNullValueAsAValue(): void
    {
        $renderer = new Renderer('effect-null-dep-value');
        $runs = 0;
        $deps = [null];
        $component = function () use (&$runs, &$deps): Element {
            useEffect(function () use (&$runs): void {
                $runs++;
            }, $deps);

            return H::div();
        };

        $renderer->render($component);
        $renderer->render($component);
        $this->assertSame(1, $runs);

        $deps = ['x'];
        $renderer->render($component);
        $this->assertSame(2, $runs);
    }

    public function testSetStateNullIsKeptInSessionStorage(): void
    {
        ComponentState::clearInstances();
        $state = ComponentState::getInstance('set-null-session');

        $this->assertSame('initial', $state->getState(0, 'initial'));
        $state->setState(0, null);

        $this->assertNull($state->getState(0, 'initial'));
    }

    public function testNullInitialStateIsStoredOnce(): void
    {
        ComponentState::clearInstances();
        $state = ComponentState::getInstance('null-initial');

        $this->assertNull($state->getState(0, null));
        $this->assertArrayHasKey('usephp:null-initial:state:0', $_SESSION);
    }

    public function testMultipleUseEffects(): void
    {
        $renderer = new Renderer('effect-test-6');
        $effect1Ran = false;
        $effect2Ran = false;

        $component = function () use (&$effect1Ran, &$effect2Ran): Element {
            useEffect(function () use (&$effect1Ran) {
                $effect1Ran = true;
            }, []);

            useEffect(function () use (&$effect2Ran) {
                $effect2Ran = true;
            }, []);

            return H::div(children: 'Test');
        };

        $renderer->render($component);

        $this->assertTrue($effect1Ran, 'First effect should run');
        $this->assertTrue($effect2Ran, 'Second effect should run');
    }

    public function testUseRouterReturnsRouterFunctions(): void
    {
        // Set up a router with routes
        $router = new SimpleRouter();
        $router->get('/', 'HomeComponent');
        $router->get('/users/{id}', 'UserComponent');

        $app = new UsePHP();
        $app->setRouter($router);
        RenderContext::setApp($app);

        $result = useRouter();

        $this->assertArrayHasKey('currentUrl', $result);
        $this->assertArrayHasKey('params', $result);

        $this->assertIsString($result['currentUrl']);
        $this->assertIsArray($result['params']);
    }
}
