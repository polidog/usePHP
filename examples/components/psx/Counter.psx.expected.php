<?php

declare(strict_types=1);

namespace App\Components\Psx;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Storage\StorageType;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        \Polidog\UsePhp\Html\createElement('div', ['className' => 'counter', 'children' => [
\Polidog\UsePhp\Html\createElement('h1', ['children' => ['usePHP Counter (PSX)']]), 
\Polidog\UsePhp\Html\createElement('div', ['className' => 'counter-display', 'children' => [
\Polidog\UsePhp\Html\createElement('span', ['children' => ['Count: ', $count]])
]]), 
\Polidog\UsePhp\Html\createElement('div', ['className' => 'counter-buttons', 'children' => [
\Polidog\UsePhp\Html\createElement('button', ['className' => 'btn btn-decrement', 'onClick' => fn() => $setCount($count - 1), 'children' => ['-']]), 
\Polidog\UsePhp\Html\createElement('button', ['className' => 'btn btn-increment', 'onClick' => fn() => $setCount($count + 1), 'children' => ['+']]), 
\Polidog\UsePhp\Html\createElement('button', ['className' => 'btn btn-reset', 'onClick' => fn() => $setCount(0), 'children' => ['Reset']])
]])
]])
    );
}, 'psx-counter', StorageType::Snapshot);
