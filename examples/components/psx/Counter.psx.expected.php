<?php

declare(strict_types=1);

namespace App\Components\Psx;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Storage\StorageType;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return H::div(className: 'counter', children: [
        H::h1(children: 'usePHP Counter (PSX)'),
        H::div(className: 'counter-display', children: [
            H::span(children: ['Count: ', $count]),
        ]),
        H::div(className: 'counter-buttons', children: [
            H::button(className: 'btn btn-decrement', onClick: fn() => $setCount($count - 1), children: '-'),
            H::button(className: 'btn btn-increment', onClick: fn() => $setCount($count + 1), children: '+'),
            H::button(className: 'btn btn-reset', onClick: fn() => $setCount(0), children: 'Reset'),
        ]),
    ]);
}, 'psx-counter', StorageType::Snapshot);
