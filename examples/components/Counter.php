<?php

declare(strict_types=1);

namespace App\Components;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Html\button;
use function Polidog\UsePhp\Html\div;
use function Polidog\UsePhp\Html\h1;
use function Polidog\UsePhp\Html\span;

#[Component(name: 'counter')]
class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return div(
            className: 'counter',
            children: [
                h1(children: 'usePHP Counter'),
                div(
                    className: 'counter-display',
                    children: [
                        span(children: "Count: {$count}"),
                    ]
                ),
                div(
                    className: 'counter-buttons',
                    children: [
                        button(
                            className: 'btn btn-decrement',
                            onClick: fn() => $setCount($count - 1),
                            children: '-'
                        ),
                        button(
                            className: 'btn btn-increment',
                            onClick: fn() => $setCount($count + 1),
                            children: '+'
                        ),
                        button(
                            className: 'btn btn-reset',
                            onClick: fn() => $setCount(0),
                            children: 'Reset'
                        ),
                    ]
                ),
            ]
        );
    }
}
