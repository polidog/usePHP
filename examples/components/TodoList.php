<?php

declare(strict_types=1);

namespace App\Components;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

#[Component]
class TodoList extends BaseComponent
{
    public function render(): Element
    {
        [$todos, $setTodos] = $this->useState([
            ['id' => 1, 'text' => 'Learn usePHP', 'done' => false],
            ['id' => 2, 'text' => 'Build something cool', 'done' => false],
        ]);

        $items = array_map(
            fn($todo) => H::li(
                className: $todo['done'] ? 'todo-item done' : 'todo-item',
                children: [
                    H::span(children: $todo['text']),
                    H::button(
                        className: 'btn-toggle',
                        onClick: fn() => $setTodos(
                            array_map(
                                fn($t) => $t['id'] === $todo['id']
                                    ? [...$t, 'done' => !$t['done']]
                                    : $t,
                                $todos
                            )
                        ),
                        children: $todo['done'] ? '✓' : '○'
                    ),
                ]
            ),
            $todos
        );

        return H::div(
            className: 'todo-app',
            children: [
                H::h1(children: 'usePHP Todo'),
                H::ul(className: 'todo-list', children: $items),
                H::div(
                    className: 'todo-stats',
                    children: [
                        H::span(children: sprintf(
                            '%d / %d completed',
                            count(array_filter($todos, fn($t) => $t['done'])),
                            count($todos)
                        )),
                    ]
                ),
            ]
        );
    }
}
