# usePHP

A framework that delivers server-driven UI with **minimal JavaScript**, using a React Hooks-like API.

## Features

- **React Hooks-like API** - Simple state management with `useState`
- **Function Components (Recommended)** - Lightweight components using simple PHP callables
- **Minimal JS (~40 lines)** - Smooth UX with partial updates, graceful fallback without JS
- **Pure PHP** - No transpilation needed, PHP code runs directly on the server
- **Configurable State Storage** - Choose between session (persistent) or memory (per-request) storage
- **Progressive Enhancement** - Works even with JavaScript disabled

## Installation

```bash
composer require polidog/use-php

# Copy JS file to public directory (required for partial updates)
./vendor/bin/usephp publish
```

## Quick Start

### 1. Create a Function Component

```php
<?php
// components/Counter.php

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

// Define a counter component with fc() wrapper
$Counter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);

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
}, 'counter'); // 'counter' is the key for state management
```

### 2. Create an Entry Point

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;

// Serve usephp.js (for partial updates)
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// Handle POST actions (for partial updates)
$actionResult = UsePHP::handleAction();
if ($actionResult !== null) {
    echo $actionResult;
    exit;
}

// Render component
global $Counter;
RenderContext::beginRender();
$content = UsePHP::renderElement($Counter(['initial' => 0]));
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
    |                                  | Component renders
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

#### Function Components (Recommended)

Function components are simple PHP callables that return Elements. They are the recommended way to build components in usePHP.

```php
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\useState;
use function Polidog\UsePhp\Runtime\fc;

// Simple function component (pure, no state)
$Greeting = fn(array $props): Element => H::div(
    children: "Hello, {$props['name']}!"
);

// Function component with useState
$Counter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::div(children: [
        H::span(children: "Count: {$count}"),
        H::button(
            onClick: fn() => $setCount($count + 1),
            children: '+'
        ),
    ]);
}, 'counter');
```

**Using function components:**

```php
// Method A: fc() wrapper (Recommended)
// Wrap with fc() for direct invocation with state support
$Counter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::div(children: "Count: $count");
}, 'my-counter');

$element = $Counter(['initial' => 5]); // Direct call
$html = UsePHP::renderElement($element);

// Method B: H::component()
// Creates an Element that resolves during render
H::div(children: [
    H::component($counterFn, ['initial' => 5, 'key' => 'my-counter']),
]);

// Method C: Direct call (only for pure components without useState)
$Greeting = fn(array $props): Element => H::div(children: "Hello, {$props['name']}!");
$Greeting(['name' => 'World']); // OK - no state needed
```

#### Class-based Components

For more complex components that need lifecycle methods or dependency injection, you can use class-based components:

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;

#[Component]
class MyComponent extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);
        // ...
    }
}
```

### useState

```php
use function Polidog\UsePhp\Runtime\useState;

// In function components
[$state, $setState] = useState($initialValue);

// Examples
[$count, $setCount] = useState(0);
[$todos, $setTodos] = useState([]);
[$user, $setUser] = useState(['name' => 'John']);

// In class-based components
[$state, $setState] = $this->useState($initialValue);
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

### Composing Components

```php
// Define reusable components
$Button = fc(function(array $props): Element {
    return H::button(
        className: 'btn',
        onClick: $props['onClick'] ?? null,
        children: $props['children'] ?? ''
    );
}, 'button');

$Card = fc(function(array $props): Element {
    return H::div(
        className: 'card',
        children: [
            H::h2(children: $props['title']),
            H::p(children: $props['content']),
        ]
    );
}, 'card');

// Compose them together
$App = fc(function(array $props): Element {
    [$count, $setCount] = useState(0);

    global $Button, $Card;

    return H::div(children: [
        $Card(['title' => 'Counter', 'content' => "Count: $count"]),
        $Button(['onClick' => fn() => $setCount($count + 1), 'children' => 'Increment']),
    ]);
}, 'app');
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
