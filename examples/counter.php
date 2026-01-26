<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\UsePhp\Http\ActionHandler;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

use function Polidog\UsePhp\Html\button;
use function Polidog\UsePhp\Html\div;
use function Polidog\UsePhp\Html\h1;
use function Polidog\UsePhp\Html\span;
use function Polidog\UsePhp\Runtime\useState;

// Define the Counter component
function Counter(): Element
{
    [$count, $setCount] = useState(0);

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

// Create action handler and register component
$handler = new ActionHandler();
$handler->register('counter', Counter(...));

// Handle AJAX action requests
if ($handler->isActionRequest()) {
    $handler->handleAndRespond();
    exit;
}

// Initial page render
$renderer = new Renderer('counter');
$html = $renderer->render(Counter(...));

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>usePHP Counter Example</title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .counter {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .counter h1 {
            margin: 0 0 20px;
            color: #333;
        }
        .counter-display {
            font-size: 48px;
            font-weight: bold;
            color: #2196F3;
            margin: 20px 0;
        }
        .counter-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            font-size: 18px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn:active {
            transform: translateY(0);
        }
        .btn-increment {
            background: #4CAF50;
            color: white;
        }
        .btn-decrement {
            background: #f44336;
            color: white;
        }
        .btn-reset {
            background: #9E9E9E;
            color: white;
        }
        [data-usephp-loading="true"] {
            opacity: 0.7;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <?= $html ?>

    <script src="../public/usephp.js"></script>
</body>
</html>
