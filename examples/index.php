<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/components/Counter.php';
require_once __DIR__ . '/components/TodoList.php';

use App\Components\Counter;
use App\Components\TodoList;
use Polidog\UsePhp\UsePHP;

// Register components
UsePHP::register(Counter::class);
UsePHP::register(TodoList::class);

// Custom layout with styles (No JavaScript!)
UsePHP::layout('app', function (string $content, string $title): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - usePHP</title>
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

        /* Todo styles */
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

        /* Navigation */
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

        /* No JS badge */
        .no-js-badge {
            text-align: center;
            margin-top: 20px;
            padding: 8px;
            background: #e8f5e9;
            border-radius: 4px;
            color: #2e7d32;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <nav>
        <a href="/counter">Counter</a>
        <a href="/todo">Todo</a>
    </nav>
    {$content}
    <div class="no-js-badge">
        ✨ No JavaScript - Pure PHP
    </div>
</body>
</html>
HTML;
});

UsePHP::useLayout('app');

// Run the application
UsePHP::run();
