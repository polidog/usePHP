<?php

declare(strict_types=1);

namespace App\Components;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

#[Component(name: 'counter')]
class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(
            className: 'counter',
            children: [
                H::h1(children: 'usePHP Counter'),
                H::div(
                    className: 'counter-display',
                    children: [
                        H::span(children: "Count: {$count}"),
                    ]
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
}
