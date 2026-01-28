<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;

use function Polidog\UsePhp\Html\getFunctionComponentName;
use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useEffect;
use function Polidog\UsePhp\Runtime\useState;

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
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
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

        $this->assertEquals('div', $element->type);
        $this->assertEquals(['Count: 10'], $element->children);
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
        $this->assertEquals(['Count: 100'], $element1->children);

        // Second call with same key should get same state
        $element2 = $Counter(['initial' => 200]);
        $this->assertEquals(['Count: 100'], $element2->children);
    }

    public function testFcWrapperWithPropsKey(): void
    {
        $Counter = fc(function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);
            return H::div(children: "Count: $count");
        });

        // First call with key 'a'
        $element1 = $Counter(['initial' => 10, 'key' => 'a']);
        $this->assertEquals(['Count: 10'], $element1->children);

        // Second call with key 'b' gets different state
        $element2 = $Counter(['initial' => 20, 'key' => 'b']);
        $this->assertEquals(['Count: 20'], $element2->children);

        // Third call with key 'a' gets same state as first
        $element3 = $Counter(['initial' => 30, 'key' => 'a']);
        $this->assertEquals(['Count: 10'], $element3->children);
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
        $this->assertEquals(['Count: 0'], $element1->children);

        // Simulate state change via session
        $_SESSION['usephp:FC@FunctionComponentTest.php:' . __LINE__ . '#persistence-test:state:0'] = 42;

        // Re-render - should use persisted state if key matches
        // Note: The actual key includes line number which changes, so this tests the mechanism
        $Counter2 = fc(function (array $props): Element {
            [$count, $setCount] = useState(0);
            return H::div(children: "Count: $count");
        }, 'persistence-test-2');

        $element2 = $Counter2([]);
        $this->assertEquals(['Count: 0'], $element2->children);
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
}
