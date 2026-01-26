<?php

declare(strict_types=1);

namespace App\Components;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Html\button;
use function Polidog\UsePhp\Html\div;
use function Polidog\UsePhp\Html\h1;
use function Polidog\UsePhp\Html\input;
use function Polidog\UsePhp\Html\li;
use function Polidog\UsePhp\Html\span;
use function Polidog\UsePhp\Html\ul;

#[Component(name: 'todo')]
class TodoList extends BaseComponent
{
    public function render(): Element
    {
        [$todos, $setTodos] = $this->useState([
            ['id' => 1, 'text' => 'Learn usePHP', 'done' => false],
            ['id' => 2, 'text' => 'Build something cool', 'done' => false],
        ]);

        $items = array_map(
            fn($todo) => li(
                className: $todo['done'] ? 'todo-item done' : 'todo-item',
                children: [
                    span(children: $todo['text']),
                    button(
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

        return div(
            className: 'todo-app',
            children: [
                h1(children: 'usePHP Todo'),
                ul(className: 'todo-list', children: $items),
                div(
                    className: 'todo-stats',
                    children: [
                        span(children: sprintf(
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
