# usePHP

A framework that delivers server-driven UI with **minimal JavaScript**, using a React Hooks-like API.

## Features

- **React Hooks-like API** - Simple state management with `useState`
- **Minimal JS (~40 lines)** - Smooth UX with partial updates, graceful fallback without JS
- **Pure PHP** - No transpilation needed, PHP code runs directly on the server
- **Configurable State Storage** - Choose between session (persistent) or memory (per-request) storage per component
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

// Handle POST actions (for partial updates)
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// Render component
$content = UsePHP::render('counter');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Counter - usePHP</title>
</head>
<body>
    <?= $content ?>
    <script src="/usephp.js"></script>
</body>
</html>
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

### State Storage

By default, component state is stored in PHP sessions and persists across page navigations. You can configure this behavior per component using the `storage` parameter:

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Storage\StorageType;

// Session storage (default) - state persists across page navigations
#[Component(name: 'counter')]
class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);
        // $count persists when user navigates away and comes back
        // ...
    }
}

// Memory storage - state resets on each page load
#[Component(name: 'search-form', storage: StorageType::Memory)]
class SearchForm extends BaseComponent
{
    public function render(): Element
    {
        [$query, $setQuery] = $this->useState('');
        // $query resets to '' on page reload/navigation
        // ...
    }
}

// You can also use string values
#[Component(name: 'wizard', storage: 'memory')]
class Wizard extends BaseComponent { /* ... */ }
```

**Storage Types:**

| Type | Behavior | Use Case |
|------|----------|----------|
| `session` (default) | State persists across page navigations | Counters, shopping carts, user preferences |
| `memory` | State resets on each page load | Search forms, temporary UI state, wizards that should reset |

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

// Conditional rendering
H::div(children: [
    $isLoggedIn ? H::span(children: 'Welcome') : null,
    $count > 0 ? H::ul(children: $items) : H::p(children: 'No items'),
]);

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

// Handle POST actions
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// Routing
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$componentName = match ($path) {
    '/', '/counter' => 'counter',
    '/todo' => 'todo',
    default => 'counter',
};

// Render
$content = UsePHP::render($componentName);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= ucfirst($componentName) ?> - usePHP</title>
</head>
<body>
    <?= $content ?>
    <script src="/usephp.js"></script>
</body>
</html>
```

## Generated HTML

```php
H::button(onClick: fn() => $setCount($count + 1), children: '+')
```

Transforms to:

```html
<form method="post" data-usephp-form style="display:inline;">
  <input type="hidden" name="_usephp_component" value="counter#0" />
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
