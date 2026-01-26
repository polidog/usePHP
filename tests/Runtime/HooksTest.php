<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

use Polidog\UsePhp\Html\H;

use function Polidog\UsePhp\Runtime\useEffect;
use function Polidog\UsePhp\Runtime\useState;

class HooksTest extends TestCase
{
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
}
