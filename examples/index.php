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
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

// ============================================
// Application setup
// ============================================
$app = new UsePHP();

// ============================================
// Component registration
// ============================================
$app->register(Counter::class);
$app->register(TodoList::class);

// ============================================
// Snapshot security (optional but recommended)
// ============================================
$app->setSnapshotSecret('your-secret-key-here');

// ============================================
// Router configuration
// ============================================
$router = $app->getRouter();

// Home / Counter page
$router->get('/', Counter::class);
$router->get('/counter', Counter::class);

// Multiple counters page
$router->get('/multi', function (array $params, RequestContext $request) use ($app): Element {
    return H::Fragment(children: [
        H::h2(style: 'text-align:center;color:#666;', children: 'Counter A'),
        $app->createElement(Counter::class, 'counter-a'),
        H::h2(style: 'text-align:center;color:#666;margin-top:30px;', children: 'Counter B'),
        $app->createElement(Counter::class, 'counter-b'),
    ]);
});

// Todo page
$router->get('/todo', TodoList::class);

// Function component pages
$router->get('/fc-counter', function (): Element {
    RenderContext::beginRender();
    return H::div(children: [
        H::component('App\Components\FunctionCounter', ['initial' => 0, 'key' => 'fc-counter']),
    ]);
});

$router->get('/fc-todo', function (): Element {
    RenderContext::beginRender();
    return H::div(children: [
        H::component('App\Components\FunctionTodoList', ['key' => 'fc-todo']),
    ]);
});

// fc() wrapper examples
$router->get('/fc-wrapped-counter', function (): Element {
    global $FcCounter;
    RenderContext::beginRender();
    return $FcCounter(['initial' => 0]);
});

$router->get('/fc-wrapped-todo', function (): Element {
    global $FcTodoList;
    RenderContext::beginRender();
    return $FcTodoList([]);
});

// Persistent snapshot example - state is passed via URL
$router->get('/cart', function (): Element {
    global $FcCounter;
    RenderContext::beginRender();
    return H::div(children: [
        H::h2(children: 'Cart (Persistent Snapshot)'),
        H::p(children: 'State is preserved in URL when navigating'),
        $FcCounter(['initial' => 0]),
    ]);
})->persistentSnapshot();

// ============================================
// Layout wrapper
// ============================================
$layoutWrapper = function (string $title, string $content): void {
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
            margin: 0 5px 5px 0;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        nav a:hover {
            background: #1976D2;
        }
        nav a.active {
            background: #1565C0;
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
        <a href="/">Counter</a>
        <a href="/multi">Multi</a>
        <a href="/todo">Todo</a>
        <a href="/fc-counter">H::component</a>
        <a href="/fc-wrapped-counter">fc()</a>
        <a href="/cart">Cart</a>
    </nav>
    <?= $content ?>
    <div class="badge">Partial updates with ~40 lines of JS</div>
    <script src="/usephp.js"></script>
</body>
</html>
<?php
};

// ============================================
// Run application with router
// ============================================
$request = RequestContext::fromGlobals();
$match = $router->match($request);

// Handle POST actions first
if ($request->isPost() && isset($_POST['_usephp_action'])) {
    $html = $app->handleAction();
    if ($html !== null) {
        echo $html;
        exit;
    }
}

if ($match === null) {
    http_response_code(404);
    $layoutWrapper('404 Not Found', '<h1>404 Not Found</h1>');
    exit;
}

// Get title from path
$titles = [
    '/' => 'Counter',
    '/counter' => 'Counter',
    '/multi' => 'Multiple Counters',
    '/todo' => 'Todo',
    '/fc-counter' => 'Function Counter',
    '/fc-todo' => 'Function Todo',
    '/fc-wrapped-counter' => 'fc() Counter',
    '/fc-wrapped-todo' => 'fc() Todo',
    '/cart' => 'Cart',
];
$title = $titles[$request->path] ?? 'usePHP';

// Render the component
$handler = $match->handler;

if (is_string($handler) && class_exists($handler)) {
    // Class-based component
    $content = $app->render($handler);
} elseif (is_callable($handler)) {
    // Callable handler
    $result = $handler($match->params, $request);
    if ($result instanceof Element) {
        $content = $app->renderElement($result);
    } else {
        $content = (string) $result;
    }
} else {
    $content = '';
}

$layoutWrapper($title, $content);
