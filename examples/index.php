<?php

declare(strict_types=1);

/**
 * usePHP Example Application
 *
 * Run: php -S localhost:8000 examples/index.php
 */

// ============================================
// Static file handling
// ============================================
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/../public/usephp.js');
    exit;
}

// ============================================
// Bootstrap
// ============================================
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/components/Counter.php';
require_once __DIR__ . '/components/TodoList.php';
require_once __DIR__ . '/components/FunctionComponents.php';

use App\Components\Counter;
use App\Components\TodoList;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

use function App\Components\FunctionCounter;
use function App\Components\FunctionTodoList;

// ============================================
// Component registration
// ============================================
UsePHP::register(Counter::class);
UsePHP::register(TodoList::class);

// ============================================
// Snapshot security (optional but recommended)
// ============================================
// Set a secret key to prevent tampering with snapshot state
UsePHP::setSnapshotSecret('your-secret-key-here');

// ============================================
// Handle POST action (for partial updates)
// ============================================
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// ============================================
// Simple routing
// ============================================
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// ============================================
// Render based on route
// ============================================
$title = 'usePHP';
$content = '';

switch ($path) {
    case '/':
    case '/counter':
        $title = 'Counter';
        $content = UsePHP::render(Counter::class);
        break;

    case '/multi':
        // Multiple counters with explicit keys using H class
        // Keys ensure stable state even when order changes
        $title = 'Multiple Counters';
        $content = UsePHP::renderElement(
            H::Fragment(children: [
                H::h2(style: 'text-align:center;color:#666;', children: 'Counter A'),
                UsePHP::createElement(Counter::class, 'counter-a'),
                H::h2(style: 'text-align:center;color:#666;margin-top:30px;', children: 'Counter B'),
                UsePHP::createElement(Counter::class, 'counter-b'),
            ])
        );
        break;

    case '/todo':
        $title = 'Todo';
        $content = UsePHP::render(TodoList::class);
        break;

    case '/fc-counter':
        // Function component counter using H::component()
        $title = 'Function Counter';
        RenderContext::beginRender();
        $content = UsePHP::renderElement(
            H::div(children: [
                H::component('App\Components\FunctionCounter', ['initial' => 0, 'key' => 'fc-counter']),
            ])
        );
        break;

    case '/fc-todo':
        // Function component todo list using H::component()
        $title = 'Function Todo';
        RenderContext::beginRender();
        $content = UsePHP::renderElement(
            H::div(children: [
                H::component('App\Components\FunctionTodoList', ['key' => 'fc-todo']),
            ])
        );
        break;

    case '/fc-wrapped-counter':
        // Using fc() wrapper - direct invocation style
        // $FcCounter is defined with fc() in FunctionComponents.php
        global $FcCounter;
        $title = 'fc() Counter';
        RenderContext::beginRender();
        $content = UsePHP::renderElement($FcCounter(['initial' => 0]));
        break;

    case '/fc-wrapped-todo':
        // Using fc() wrapper - direct invocation style
        // $FcTodoList is defined with fc() in FunctionComponents.php
        global $FcTodoList;
        $title = 'fc() Todo';
        RenderContext::beginRender();
        $content = UsePHP::renderElement($FcTodoList([]));
        break;

    default:
        $title = 'Counter';
        $content = UsePHP::render(Counter::class);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> - usePHP</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .counter, .todo-app {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            margin: 0 0 20px;
            color: #333;
            text-align: center;
        }
        .counter-display {
            font-size: 48px;
            font-weight: bold;
            color: #2196F3;
            margin: 20px 0;
            text-align: center;
        }
        .counter-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn, button {
            font-size: 16px;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover, button:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }
        .btn-increment { background: #4CAF50; color: white; }
        .btn-decrement { background: #f44336; color: white; }
        .btn-reset { background: #9E9E9E; color: white; }
        .todo-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .todo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        .todo-item.done span {
            text-decoration: line-through;
            color: #999;
        }
        .btn-toggle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e0e0e0;
            font-size: 18px;
        }
        .todo-item.done .btn-toggle {
            background: #4CAF50;
            color: white;
        }
        .todo-stats {
            margin-top: 20px;
            text-align: center;
            color: #666;
        }
        nav {
            margin-bottom: 20px;
            text-align: center;
        }
        nav a {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 5px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        nav a:hover {
            background: #1976D2;
        }
        [aria-busy="true"] {
            opacity: 0.6;
            pointer-events: none;
        }
        .badge {
            text-align: center;
            margin-top: 20px;
            padding: 8px;
            background: #e3f2fd;
            border-radius: 4px;
            color: #1565c0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <nav>
        <a href="/counter">Counter</a>
        <a href="/multi">Multi</a>
        <a href="/todo">Todo</a>
        <a href="/fc-counter">H::component</a>
        <a href="/fc-wrapped-counter">fc()</a>
    </nav>
    <?= $content ?>
    <div class="badge">Partial updates with ~40 lines of JS</div>
    <script src="/usephp.js"></script>
</body>
</html>
