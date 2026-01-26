# usePHP

A framework that delivers server-driven UI with **minimal JavaScript**, using a React Hooks-like API.

## Features

- **React Hooks-like API** - Simple state management with `useState`
- **Minimal JS (~40 lines)** - Smooth UX with partial updates, graceful fallback without JS
- **Pure PHP** - No transpilation needed, PHP code runs directly on the server
- **Session-based State** - State is maintained on the server side
- **Component-oriented** - Reusable component classes
- **Progressive Enhancement** - Works even with JavaScript disabled

## Installation

```bash
composer require polidog/use-php

# Copy JS file to public directory (required for partial updates)
./vendor/bin/usephp publish
```

## Quick Start

### 1. Create a Component

```php
<?php
// components/Counter.php

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
                H::span(children: "Count: {$count}"),
                H::button(
                    onClick: fn() => $setCount($count + 1),
                    children: '+'
                ),
                H::button(
                    onClick: fn() => $setCount($count - 1),
                    children: '-'
                ),
            ]
        );
    }
}
```

### 2. Create an Entry Point

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use App\Components\Counter;
use Polidog\UsePhp\UsePHP;

// Serve usephp.js (for partial updates)
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// Register component
UsePHP::register(Counter::class);

// Set JS path
UsePHP::setJsPath('/usephp.js');

// Run
UsePHP::run('counter');
```

### 3. Start the Server

```bash
php -S localhost:8000 public/index.php
```

Open `http://localhost:8000` in your browser.

## Architecture

### With JavaScript (Partial Updates)
```
[Browser]                         [PHP Server]
    |                                  |
    |  GET /                           |
    | -------------------------------->|
    |                                  | Counter::render() executes
    |  <html>Count: 0</html>           | useState → saves to session
    | <--------------------------------|
    |                                  |
    |  POST + X-UsePHP-Partial header  |
    | -------------------------------->|
    |                                  | State update
    |  <partial>Count: 1</partial>     | Re-render component only
    | <--------------------------------|
    |  (innerHTML partial update)      |
```

### Without JavaScript (Fallback)
```
[Browser]                         [PHP Server]
    |                                  |
    |  <form> POST (button click)      |
    | -------------------------------->|
    |                                  | State update
    |  303 Redirect                    |
    | <--------------------------------|
    |                                  |
    |  GET /                           |
    | -------------------------------->|
    |  <html>Count: 1</html>           | Full page re-render
    | <--------------------------------|
```

## API

### Component Definition

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;

#[Component(name: 'my-component')]
class MyComponent extends BaseComponent
{
    public function render(): Element
    {
        // ...
    }
}
```

### useState

```php
[$state, $setState] = $this->useState($initialValue);

// Examples
[$count, $setCount] = $this->useState(0);
[$todos, $setTodos] = $this->useState([]);
[$user, $setUser] = $this->useState(['name' => 'John']);
```

### HTML Elements

```php
use Polidog\UsePhp\Html\H;

// Basic usage
H::div(
    className: 'container',
    id: 'main',
    children: [
        H::h1(children: 'Title'),
        H::button(
            onClick: fn() => $setCount($count + 1),
            children: 'Click'
        ),
    ]
);

// All HTML elements are supported
H::article(className: 'post', children: [...]);
H::table(children: [H::tr(children: [H::td(children: 'Cell')])]);
H::video(src: 'movie.mp4', controls: true);
```

### Multiple Components + Routing

```php
<?php
// public/index.php

use App\Components\{Counter, TodoList};
use Polidog\UsePhp\UsePHP;

// Serve usephp.js
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// Register components
UsePHP::register(Counter::class);
UsePHP::register(TodoList::class);

// Set JS path
UsePHP::setJsPath('/usephp.js');

// Routing
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$componentName = match ($path) {
    '/', '/counter' => 'counter',
    '/todo' => 'todo',
    default => 'counter',
};

// Run
UsePHP::run($componentName);
```

### Custom Layout

```php
UsePHP::layout('app', function ($content, $title, $jsPath) {
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <title>{$title}</title>
        <style>/* your styles */</style>
    </head>
    <body>
        {$content}
        <script src="{$jsPath}"></script>
    </body>
    </html>
    HTML;
});

UsePHP::useLayout('app');
```

## Generated HTML

```php
H::button(onClick: fn() => $setCount($count + 1), children: '+')
```

Transforms to:

```html
<form method="post" data-usephp-form style="display:inline;">
  <input type="hidden" name="_usephp_component" value="counter" />
  <input type="hidden" name="_usephp_action" value='{"type":"setState","payload":{"index":0,"value":1}}' />
  <button type="submit">+</button>
</form>
```

- `data-usephp-form` - Form intercepted by JS
- Works as a regular form submission without JS

## CLI

```bash
./vendor/bin/usephp publish  # Copy usephp.js to public/
./vendor/bin/usephp help     # Show help
```

## Requirements

- PHP 8.2+
- Sessions enabled

## Development

```bash
# Run tests
./vendor/bin/phpunit

# Start example server
php -S localhost:8000 examples/index.php
```

## License

MIT
