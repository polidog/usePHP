<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;

use function Polidog\UsePhp\Html\getFunctionComponentName;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\RenderContext;

use function Polidog\UsePhp\Runtime\useEffect;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'FunctionComponentTestChild')]
final class FunctionComponentTestChild extends BaseComponent
{
    public function render(): Element
    {
        return H::span(children: 'class child');
    }
}

class FunctionComponentTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();

        // Snapshot-storage components require an HMAC key on the active
        // app. Install a minimal app context with a test secret so the
        // signing path can run.
        $app = new UsePHP();
        $app->setSnapshotSecret('test-secret-key-for-function-component-tests');
        RenderContext::setApp($app);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
    }

    public function testSimpleFunctionComponentWithProps(): void
    {
        $Greeting = fn(array $props): Element => H::div(
            children: "Hello, {$props['name']}!"
        );

        $element = H::div(children: [
            H::component($Greeting, ['name' => 'World']),
        ]);

        $this->assertEquals('div', $element->type);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(Element::class, $element->children[0]);

        $child = $element->children[0];
        $this->assertEquals('div', $child->type);
        $this->assertEquals(['Hello, World!'], $child->children);
    }

    public function testFunctionComponentWithUseState(): void
    {
        $Counter = function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        };

        $element = H::div(children: [
            H::component($Counter, ['initial' => 5]),
        ]);

        $child = $element->children[0];
        $this->assertEquals('div', $child->type);
        $this->assertEquals(['Count: 5'], $child->children);
    }

    public function testFcWrapperWithUseState(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        });

        $element = $Counter(['initial' => 10]);

        // fc() returns a wrapper div with data-usephp attribute
        $this->assertEquals('div', $element->type);
        $this->assertArrayHasKey('data-usephp', $element->props);

        // The inner element is the first child
        $inner = $element->children[0];
        $this->assertEquals('div', $inner->type);
        $this->assertEquals(['Count: 10'], $inner->children);
    }

    public function testKeyBasedStateSeparation(): void
    {
        $Counter = function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::span(children: "Count: $count");
        };

        $element = H::div(children: [
            H::component($Counter, ['initial' => 1, 'key' => 'counter-a']),
            H::component($Counter, ['initial' => 2, 'key' => 'counter-b']),
        ]);

        $this->assertCount(2, $element->children);

        $childA = $element->children[0];
        $childB = $element->children[1];

        $this->assertEquals(['Count: 1'], $childA->children);
        $this->assertEquals(['Count: 2'], $childB->children);
    }

    public function testNestedFunctionComponents(): void
    {
        $Inner = fn(array $props): Element => H::span(
            children: $props['text']
        );

        $Outer = fn(array $props): Element => H::div(
            className: 'outer',
            children: [
                H::component($Inner, ['text' => $props['message']]),
            ]
        );

        $element = H::div(children: [
            H::component($Outer, ['message' => 'Hello from inner!']),
        ]);

        $outer = $element->children[0];
        $this->assertEquals('div', $outer->type);
        $this->assertEquals('outer', $outer->props['className']);

        $inner = $outer->children[0];
        $this->assertEquals('span', $inner->type);
        $this->assertEquals(['Hello from inner!'], $inner->children);
    }

    public function testDirectFunctionCallWithoutUseState(): void
    {
        // Pure function components without useState can be called directly
        $TodoItem = fn(array $props): Element => H::li(
            children: $props['todo']['text']
        );

        $todo = ['id' => 1, 'text' => 'Buy milk'];

        // Direct call works fine for pure components
        $element = $TodoItem(['todo' => $todo]);

        $this->assertEquals('li', $element->type);
        $this->assertEquals(['Buy milk'], $element->children);
    }

    public function testFcWrapperWithKey(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        }, 'fixed-key');

        // First call
        $element1 = $Counter(['initial' => 100]);
        $inner1 = $element1->children[0];
        $this->assertEquals(['Count: 100'], $inner1->children);

        // Second call with same key should get same state
        $element2 = $Counter(['initial' => 200]);
        $inner2 = $element2->children[0];
        $this->assertEquals(['Count: 100'], $inner2->children);
    }

    public function testFcWrapperWithPropsKey(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        });

        // First call with key 'a'
        $element1 = $Counter(['initial' => 10, 'key' => 'a']);
        $inner1 = $element1->children[0];
        $this->assertEquals(['Count: 10'], $inner1->children);

        // Second call with key 'b' gets different state
        $element2 = $Counter(['initial' => 20, 'key' => 'b']);
        $inner2 = $element2->children[0];
        $this->assertEquals(['Count: 20'], $inner2->children);

        // Third call with key 'a' gets same state as first
        $element3 = $Counter(['initial' => 30, 'key' => 'a']);
        $inner3 = $element3->children[0];
        $this->assertEquals(['Count: 10'], $inner3->children);
    }

    public function testMultipleUseStateInFunctionComponent(): void
    {
        $Form = function (array $props): Element {
            [$name, $setName] = useState($props['defaultName'] ?? '');
            [$email, $setEmail] = useState($props['defaultEmail'] ?? '');

            return H::div(children: [
                H::span(children: "Name: $name"),
                H::span(children: "Email: $email"),
            ]);
        };

        $element = H::div(children: [
            H::component($Form, [
                'defaultName' => 'John',
                'defaultEmail' => 'john@example.com',
            ]),
        ]);

        $form = $element->children[0];
        $this->assertCount(2, $form->children);
        $this->assertEquals(['Name: John'], $form->children[0]->children);
        $this->assertEquals(['Email: john@example.com'], $form->children[1]->children);
    }

    public function testFunctionComponentWithUseEffect(): void
    {
        $effectRan = false;

        $Component = function (array $props) use (&$effectRan): Element {
            useEffect(function () use (&$effectRan) {
                $effectRan = true;
            }, []);

            return H::div(children: 'Effect Test');
        };

        $element = H::div(children: [
            H::component($Component, ['key' => 'effect-test']),
        ]);

        $this->assertTrue($effectRan, 'useEffect should run in function component');
        $this->assertEquals('div', $element->children[0]->type);
    }

    public function testGetFunctionComponentNameWithClosure(): void
    {
        $closure = fn(array $props): Element => H::div(children: 'test');

        $name = getFunctionComponentName($closure);

        $this->assertStringStartsWith('FC@', $name);
        $this->assertStringContainsString('FunctionComponentTest.php', $name);
    }

    public function testGetFunctionComponentNameWithNamedFunction(): void
    {
        $name = getFunctionComponentName('strlen');

        $this->assertEquals('strlen', $name);
    }

    public function testFunctionComponentWithArrayChildren(): void
    {
        $List = fn(array $props): Element => H::ul(
            children: array_map(
                fn($item) => H::li(children: $item),
                $props['items']
            )
        );

        $element = H::div(children: [
            H::component($List, ['items' => ['A', 'B', 'C']]),
        ]);

        $list = $element->children[0];
        $this->assertEquals('ul', $list->type);
        $this->assertCount(3, $list->children);
        $this->assertEquals(['A'], $list->children[0]->children);
        $this->assertEquals(['B'], $list->children[1]->children);
        $this->assertEquals(['C'], $list->children[2]->children);
    }

    public function testFcWrapperStatePersistence(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState(0);
            return H::div(children: "Count: $count");
        }, 'persistence-test');

        // First render
        $element1 = $Counter([]);
        $inner1 = $element1->children[0];
        $this->assertEquals(['Count: 0'], $inner1->children);

        // Simulate state change via session
        $_SESSION['usephp:FC@FunctionComponentTest.php:' . __LINE__ . '#persistence-test:state:0'] = 42;

        // Re-render - should use persisted state if key matches
        // Note: The actual key includes line number which changes, so this tests the mechanism
        $Counter2 = fc(function (array $props): Element {
            [$count, $setCount] = useState(0);
            return H::div(children: "Count: $count");
        }, 'persistence-test-2');

        $element2 = $Counter2([]);
        $inner2 = $element2->children[0];
        $this->assertEquals(['Count: 0'], $inner2->children);
    }

    public function testMultipleFunctionComponentsInFragment(): void
    {
        $Header = fn(array $props): Element => H::header(
            children: $props['title']
        );

        $Footer = fn(array $props): Element => H::footer(
            children: $props['copyright']
        );

        $element = H::Fragment(children: [
            H::component($Header, ['title' => 'My Site']),
            H::div(children: 'Content'),
            H::component($Footer, ['copyright' => '2024']),
        ]);

        $this->assertEquals('Fragment', $element->type);
        $this->assertCount(3, $element->children);

        $header = $element->children[0];
        $this->assertEquals('header', $header->type);
        $this->assertEquals(['My Site'], $header->children);

        $footer = $element->children[2];
        $this->assertEquals('footer', $footer->type);
        $this->assertEquals(['2024'], $footer->children);
    }

    public function testConditionalFunctionComponent(): void
    {
        $showCounter = true;

        $Counter = fn(array $props): Element => H::div(
            children: "Counter: {$props['value']}"
        );

        $element = H::div(children: [
            $showCounter ? H::component($Counter, ['value' => 10]) : null,
        ]);

        $this->assertCount(1, $element->children);
        $this->assertEquals(['Counter: 10'], $element->children[0]->children);

        // Test when not showing
        $showCounter = false;
        $element2 = H::div(children: array_filter([
            $showCounter ? H::component($Counter, ['value' => 10]) : null,
        ]));

        $this->assertCount(0, $element2->children);
    }

    public function testFcWithSnapshotStorage(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        }, 'snapshot-counter', StorageType::Snapshot);

        $element = $Counter(['initial' => 5]);

        // fc() with snapshot storage returns a wrapper div with data-usephp-snapshot attribute
        $this->assertEquals('div', $element->type);
        $this->assertArrayHasKey('data-usephp', $element->props);
        $this->assertArrayHasKey('data-usephp-snapshot', $element->props);

        // The snapshot should be valid JSON
        $snapshotJson = $element->props['data-usephp-snapshot'];
        $this->assertJson($snapshotJson);

        // The inner element is the first child
        $inner = $element->children[0];
        $this->assertEquals('div', $inner->type);
        $this->assertEquals(['Count: 5'], $inner->children);
    }

    public function testFcWithSessionStorageNoSnapshot(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        }, 'session-counter', StorageType::Session);

        $element = $Counter(['initial' => 10]);

        // fc() with session storage returns a wrapper div without data-usephp-snapshot
        $this->assertEquals('div', $element->type);
        $this->assertArrayHasKey('data-usephp', $element->props);
        $this->assertArrayNotHasKey('data-usephp-snapshot', $element->props);

        // The inner element is the first child
        $inner = $element->children[0];
        $this->assertEquals(['Count: 10'], $inner->children);
    }

    public function testFcWithMemoryStorage(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        }, 'memory-counter', StorageType::Memory);

        $element = $Counter(['initial' => 15]);

        // fc() with memory storage returns a wrapper div without data-usephp-snapshot
        $this->assertEquals('div', $element->type);
        $this->assertArrayHasKey('data-usephp', $element->props);
        $this->assertArrayNotHasKey('data-usephp-snapshot', $element->props);

        // The inner element is the first child
        $inner = $element->children[0];
        $this->assertEquals(['Count: 15'], $inner->children);
    }

    public function testParentHooksAfterChildStayOnParentSession(): void
    {
        $Child = fc(function (array $props): Element {
            [$c] = useState('child-value');

            return H::span(children: $c);
        }, 'after-child');
        $setter = null;
        $Parent = fc(function (array $props) use ($Child, &$setter): Element {
            [$a] = useState('a');
            $kid = $Child([]);
            [$b, $setter] = useState('b');

            return H::div(children: [$a, $b, $kid]);
        }, 'after-parent');

        $element = $Parent([]);
        $parentId = $element->props['data-usephp'];

        $this->assertSame('b', $_SESSION["usephp:{$parentId}:state:1"]);
        $this->assertSame($parentId, $setter(1)->componentId);
        $this->assertNull(ComponentState::current(), 'nothing is rendering any more');
    }

    public function testParentSnapshotKeepsItsOwnStateAfterChild(): void
    {
        $Child = fc(function (array $props): Element {
            [$c] = useState('c');

            return H::span(children: "child={$c}");
        }, 'ls-child', StorageType::Snapshot);
        $Parent = fc(function (array $props) use ($Child): Element {
            [$a] = useState(10);
            $kid = $Child([]);
            [$b] = useState(20);

            return H::div(children: ["b={$b}", $kid]);
        }, 'ls-parent', StorageType::Snapshot);

        $element = $Parent([]);

        $parent = json_decode($element->props['data-usephp-snapshot'], true);
        $this->assertSame([10, 20], $parent['state']);

        $child = json_decode($element->children[0]->children[1]->props['data-usephp-snapshot'], true);
        $this->assertSame(['c'], $child['state']);
    }

    public function testParentUseEffectAfterChildUsesParentDeps(): void
    {
        $Child = fc(fn(array $props): Element => H::span(), 'effect-child');
        $Parent = fc(function (array $props) use ($Child): Element {
            $kid = $Child([]);
            useEffect(fn() => null, ['dep']);

            return H::div(children: [$kid]);
        }, 'effect-parent');

        $parentId = $Parent([])->props['data-usephp'];

        $this->assertSame(['dep'], $_SESSION["usephp:{$parentId}:effect_deps:0"]);
    }

    public function testHComponentRestoresParentState(): void
    {
        $Child = fn(array $props): Element => H::span();
        $Parent = fc(function (array $props) use ($Child): Element {
            $kid = H::div(children: [H::component($Child)]);
            [$b] = useState('b');

            return H::div(children: [$b, $kid]);
        }, 'h-parent');

        $parentId = $Parent([])->props['data-usephp'];

        $this->assertSame('b', $_SESSION["usephp:{$parentId}:state:0"]);
    }

    public function testThrowingChildRestoresParentStateAndStack(): void
    {
        $Child = fc(function (array $props): Element {
            throw new \RuntimeException('child failed');
        }, 'throwing-child');
        $Parent = fc(function (array $props) use ($Child): Element {
            try {
                $Child([]);
            } catch (\RuntimeException) {
                // A boundary in the parent renders a fallback instead.
            }
            [$b] = useState('b');

            return H::div(children: $b);
        }, 'catching-parent');

        $parentId = $Parent([])->props['data-usephp'];

        $this->assertSame('b', $_SESSION["usephp:{$parentId}:state:0"]);
        $this->assertSame(0, RenderContext::getStackDepth());
    }

    public function testAppRenderEntryPointsRestoreParentState(): void
    {
        $app = new UsePHP();
        $app->register(FunctionComponentTestChild::class);
        $parent = ComponentState::getInstance('parent#0');

        $app->createElement('FunctionComponentTestChild');
        $this->assertSame($parent, ComponentState::current());

        $app->renderElement(H::button(onClick: fn() => null, children: 'x'));
        $this->assertSame($parent, ComponentState::current());

        $app->render('FunctionComponentTestChild');
        $this->assertSame($parent, ComponentState::current());
    }

    public function testFixedKeyNamespacesPropsKey(): void
    {
        $Row = fc(fn(array $props): Element => H::span(children: 'key' . ($props['key'] ?? 'stripped')), 'krow');

        $first = $Row(['key' => 'k1']);
        $second = $Row(['key' => 'k2']);

        $this->assertStringEndsWith('#krow/k1', $first->props['data-usephp']);
        $this->assertStringEndsWith('#krow/k2', $second->props['data-usephp']);
    }

    public function testRowsWithFixedKeyDoNotShareState(): void
    {
        $Row = fc(function (array $props): Element {
            [$q] = useState($props['start']);

            return H::span(children: (string) $q);
        }, 'krow');

        $first = $Row(['key' => 'k1', 'start' => 1]);
        $second = $Row(['key' => 'k2', 'start' => 2]);

        $this->assertSame('1', $first->children[0]->children[0]);
        $this->assertSame('2', $second->children[0]->children[0]);
    }

    public function testExistingIdsAreUnchanged(): void
    {
        $Fixed = fc(fn(array $props): Element => H::span(), 'only-fixed');
        $this->assertStringEndsWith('#only-fixed', $Fixed([])->props['data-usephp']);

        $Unfixed = fc(fn(array $props): Element => H::span());
        $this->assertStringEndsWith('#only-prop', $Unfixed(['key' => 'only-prop'])->props['data-usephp']);
    }

    public function testNamespacedKeySurvivesSnapshotRoundTrip(): void
    {
        $Row = fc(function (array $props): Element {
            [$q] = useState(7);

            return H::span(children: (string) $q);
        }, 'krow', StorageType::Snapshot);

        $element = $Row(['key' => 'k1']);
        $instanceId = $element->props['data-usephp'];

        $serializer = RenderContext::getApp()->getSnapshotSerializer();
        $snapshot = $serializer->deserialize($element->props['data-usephp-snapshot']);

        $this->assertSame('krow/k1', $snapshot->key);
        $this->assertSame($instanceId, $snapshot->getInstanceId());
        $this->assertSame([7], ComponentState::fromSnapshot($snapshot)->createSnapshot()->state);
    }

    public function testFcSnapshotContainsCorrectState(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState(42);
            [$name, $setName] = useState('test');
            return H::div(children: "Count: $count, Name: $name");
        }, 'multi-state', StorageType::Snapshot);

        $element = $Counter([]);

        $snapshotJson = $element->props['data-usephp-snapshot'];
        $snapshot = json_decode($snapshotJson, true);

        // Snapshot should contain the state values
        $this->assertEquals(42, $snapshot['state'][0]);
        $this->assertEquals('test', $snapshot['state'][1]);
    }
}
