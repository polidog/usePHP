<?php

declare(strict_types=1);

namespace App\Components;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Storage\StorageType;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

/**
 * Function component examples.
 *
 * Function components are simple PHP callables that return Elements.
 * They can use hooks like useState when wrapped with H::component() or fc().
 */

/**
 * Simple greeting component (pure function, no state).
 *
 * @param array{name: string} $props
 */
function Greeting(array $props): Element
{
    return H::div(
        className: 'greeting',
        children: "Hello, {$props['name']}!"
    );
}

/**
 * Counter as a function component with useState.
 *
 * @param array{initial?: int} $props
 */
function FunctionCounter(array $props): Element
{
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return H::div(
        className: 'counter',
        children: [
            H::h1(children: 'Function Counter'),
            H::div(
                className: 'counter-display',
                children: "Count: {$count}"
            ),
            H::div(
                className: 'counter-buttons',
                children: [
                    H::button(
                        className: 'btn btn-decrement',
                        onClick: fn() => $setCount($count - 1),
                        children: '-'
                    ),
                    H::button(
                        className: 'btn btn-increment',
                        onClick: fn() => $setCount($count + 1),
                        children: '+'
                    ),
                    H::button(
                        className: 'btn btn-reset',
                        onClick: fn() => $setCount(0),
                        children: 'Reset'
                    ),
                ]
            ),
        ]
    );
}

/**
 * Todo item component (pure function).
 *
 * @param array{todo: array{id: int, text: string, done: bool}, onToggle: callable} $props
 */
function TodoItem(array $props): Element
{
    $todo = $props['todo'];
    $onToggle = $props['onToggle'];

    return H::li(
        className: $todo['done'] ? 'todo-item done' : 'todo-item',
        children: [
            H::span(children: $todo['text']),
            H::button(
                className: 'btn-toggle',
                onClick: fn() => $onToggle($todo['id']),
                children: $todo['done'] ? '✓' : '○'
            ),
        ]
    );
}

/**
 * Todo list using function components.
 *
 * @param array<string, mixed> $props
 */
function FunctionTodoList(array $props): Element
{
    [$todos, $setTodos] = useState([
        ['id' => 1, 'text' => 'Learn function components', 'done' => false],
        ['id' => 2, 'text' => 'Build with fc()', 'done' => false],
        ['id' => 3, 'text' => 'Enjoy PHP!', 'done' => true],
    ]);

    $toggle = fn(int $id) => $setTodos(
        array_map(
            fn($t) => $t['id'] === $id
                ? [...$t, 'done' => !$t['done']]
                : $t,
            $todos
        )
    );

    // Using pure function components for items (no useState needed)
    $items = array_map(
        fn($todo) => TodoItem(['todo' => $todo, 'onToggle' => $toggle]),
        $todos
    );

    $doneCount = count(array_filter($todos, fn($t) => $t['done']));

    return H::div(
        className: 'todo-app',
        children: [
            H::h1(children: 'Function Todo List'),
            H::ul(className: 'todo-list', children: $items),
            H::div(
                className: 'todo-stats',
                children: "{$doneCount} / " . count($todos) . ' completed'
            ),
        ]
    );
}

// ============================================================================
// fc() wrapped components - Direct invocation style
// ============================================================================
//
// fc() wraps a function component to handle state management automatically.
// This allows you to call the component like a regular function while
// still having access to useState and other hooks.
//
// Usage:
//   $element = $FcCounter(['initial' => 10]);
//   $element = $FcTodoList([]);

/**
 * Counter defined with fc() - can be called directly with useState support.
 *
 * @var callable(array<string, mixed>): Element
 */
$FcCounter = fc(function (array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return H::div(
        className: 'counter',
        children: [
            H::h1(children: 'fc() Counter'),
            H::p(
                style: 'text-align:center;color:#666;font-size:14px;',
                children: 'Defined with fc() - direct invocation'
            ),
            H::div(
                className: 'counter-display',
                children: "Count: {$count}"
            ),
            H::div(
                className: 'counter-buttons',
                children: [
                    H::button(
                        className: 'btn btn-decrement',
                        onClick: fn() => $setCount($count - 1),
                        children: '-'
                    ),
                    H::button(
                        className: 'btn btn-increment',
                        onClick: fn() => $setCount($count + 1),
                        children: '+'
                    ),
                    H::button(
                        className: 'btn btn-reset',
                        onClick: fn() => $setCount(0),
                        children: 'Reset'
                    ),
                ]
            ),
        ]
    );
}, 'fc-counter');

/**
 * Todo list defined with fc() - demonstrates nested components.
 *
 * @var callable(array<string, mixed>): Element
 */
$FcTodoList = fc(function (array $props): Element {
    [$todos, $setTodos] = useState([
        ['id' => 1, 'text' => 'Learn fc() wrapper', 'done' => true],
        ['id' => 2, 'text' => 'Build stateful components', 'done' => false],
        ['id' => 3, 'text' => 'Direct invocation FTW!', 'done' => false],
    ]);

    $toggle = fn(int $id) => $setTodos(
        array_map(
            fn($t) => $t['id'] === $id
                ? [...$t, 'done' => !$t['done']]
                : $t,
            $todos
        )
    );

    $items = array_map(
        fn($todo) => TodoItem(['todo' => $todo, 'onToggle' => $toggle]),
        $todos
    );

    $doneCount = count(array_filter($todos, fn($t) => $t['done']));

    return H::div(
        className: 'todo-app',
        children: [
            H::h1(children: 'fc() Todo List'),
            H::p(
                style: 'text-align:center;color:#666;font-size:14px;',
                children: 'Defined with fc() - direct invocation'
            ),
            H::ul(className: 'todo-list', children: $items),
            H::div(
                className: 'todo-stats',
                children: "{$doneCount} / " . count($todos) . ' completed'
            ),
        ]
    );
}, 'fc-todo');

// ============================================================================
// fc() with StorageType - Snapshot storage example
// ============================================================================
//
// The third parameter of fc() allows specifying a StorageType:
// - StorageType::Session (default) - State persists in PHP session
// - StorageType::Memory - State resets on each request
// - StorageType::Snapshot - State is embedded in HTML (stateless server)
//
// Snapshot storage is useful for:
// - Shareable URLs that preserve component state
// - Stateless server deployments
// - Components that don't need server-side session persistence

/**
 * Counter with Snapshot storage - state is embedded in HTML.
 *
 * The state is serialized and included in the HTML output,
 * allowing the server to remain stateless.
 *
 * @var callable(array<string, mixed>): Element
 */
$FcSnapshotCounter = fc(function (array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return H::div(
        className: 'counter',
        children: [
            H::h1(children: 'Snapshot Counter'),
            H::p(
                style: 'text-align:center;color:#666;font-size:14px;',
                children: 'State is embedded in HTML (stateless server)'
            ),
            H::div(
                className: 'counter-display',
                children: "Count: {$count}"
            ),
            H::div(
                className: 'counter-buttons',
                children: [
                    H::button(
                        className: 'btn btn-decrement',
                        onClick: fn() => $setCount($count - 1),
                        children: '-'
                    ),
                    H::button(
                        className: 'btn btn-increment',
                        onClick: fn() => $setCount($count + 1),
                        children: '+'
                    ),
                    H::button(
                        className: 'btn btn-reset',
                        onClick: fn() => $setCount(0),
                        children: 'Reset'
                    ),
                ]
            ),
        ]
    );
}, 'snapshot-counter', StorageType::Snapshot);
