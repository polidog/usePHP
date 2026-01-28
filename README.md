# usePHP

A framework that delivers server-driven UI with **minimal JavaScript**, using a React Hooks-like API.

## Features

- **React Hooks-like API** - Simple state management with `useState`
- **Function Components (Recommended)** - Lightweight components using simple PHP callables
- **Built-in Router** - Simple, swappable router with snapshot state preservation across pages
- **Minimal JS (~40 lines)** - Smooth UX with partial updates, graceful fallback without JS
- **Pure PHP** - No transpilation needed, PHP code runs directly on the server
- **Configurable State Storage** - Choose between session (persistent) or memory (per-request) storage
- **Progressive Enhancement** - Works even with JavaScript disabled
- **Framework Integration** - Works with Laravel, Symfony, and other frameworks

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

### 2. Create an Entry Point with Router

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Counter.php';

use Polidog\UsePhp\UsePHP;

// Serve usephp.js (for partial updates)
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

// Configure snapshot security (recommended)
UsePHP::setSnapshotSecret('your-secret-key-here');

// Configure routes
$router = UsePHP::getRouter();
$router->get('/', Counter::class)->name('home');
$router->get('/about', AboutPage::class)->name('about');

// Run the application
UsePHP::run();
```

### 3. Start the Server

```bash
php -S localhost:8000 public/index.php
```

Open `http://localhost:8000` in your browser.

## Router

usePHP includes a built-in router that can be swapped or disabled for framework integration.

### Basic Usage

```php
use Polidog\UsePhp\UsePHP;

$router = UsePHP::getRouter();

// Register routes
$router->get('/', HomeComponent::class)->name('home');
$router->get('/users/{id}', UserComponent::class)->name('user.show');
$router->post('/users', CreateUserHandler::class)->name('user.create');

// Route groups
$router->group('/admin', function ($group) {
    $group->get('/dashboard', DashboardComponent::class)->name('admin.dashboard');
    $group->get('/users', AdminUsersComponent::class)->name('admin.users');
});

// Run the application
UsePHP::run();
```

### URL Generation

```php
// Generate URLs from route names
$url = $router->generate('user.show', ['id' => '42']);  // /users/42
```

### useRouter Hook

Access router functionality within components:

```php
use function Polidog\UsePhp\Runtime\useRouter;

$NavComponent = fc(function(array $props): Element {
    $router = useRouter();

    return H::nav(children: [
        H::a(href: $router['navigate']('home'), children: 'Home'),
        H::a(href: $router['navigate']('about'), children: 'About'),
        $router['isActive']('home') ? H::span(children: '(current)') : null,
    ]);
}, 'nav');
```

The `useRouter()` hook returns:
- `navigate(routeName, params)` - Generate URL for a named route
- `currentUrl` - Current request URL
- `params` - Route parameters from current match
- `isActive(routeName)` - Check if a route is currently active

### Snapshot Behavior

Control how state is preserved across page navigations:

```php
// Isolated (default) - State is page-specific
$router->get('/page', PageComponent::class)->isolatedSnapshot();

// Persistent - State is passed via URL when navigating
$router->get('/cart', CartComponent::class)->persistentSnapshot();

// Session - State is stored in session
$router->get('/wizard', WizardComponent::class)->sessionSnapshot();

// Shared - State is shared between specific routes
$router->get('/step1', Step1Component::class)->sharedSnapshot('checkout');
$router->get('/step2', Step2Component::class)->sharedSnapshot('checkout');
```

#### StorageType vs SnapshotBehavior

These two concepts control state at different levels:

| | StorageType (Component) | SnapshotBehavior (Router) |
|---|---|---|
| **Scope** | Individual component | Route/page transitions |
| **Configuration** | `#[Component(storage: '...')]` | `$router->get(...)->sessionSnapshot()` |
| **Purpose** | How a component's state is stored | How snapshots are handled across routes |

**Example:** A `TodoList` component with `storage: 'session'` stores its own state in the session. Meanwhile, `SnapshotBehavior::Persistent` on a route controls whether the entire page snapshot is passed via URL when navigating to another route.

### Framework Integration

When using usePHP within Laravel, Symfony, or other frameworks:

```php
// Laravel example
Route::get('/counter', function () {
    UsePHP::disableRouter();  // Use NullRouter
    return UsePHP::render(Counter::class);
});

// Symfony example
#[Route('/counter')]
public function counter(): Response
{
    UsePHP::disableRouter();
    return new Response(UsePHP::render(Counter::class));
}
```

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

// Function component with snapshot storage (stateless server)
use Polidog\UsePhp\Storage\StorageType;

$SnapshotCounter = fc(function(array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::div(children: "Count: {$count}");
}, 'snapshot-counter', StorageType::Snapshot);
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

**fc() Storage Types:**

The `fc()` function accepts an optional third parameter to specify the storage type:

```php
use Polidog\UsePhp\Storage\StorageType;

// Session storage (default) - State persists in PHP session
$Counter = fc(fn() => ..., 'key');
$Counter = fc(fn() => ..., 'key', StorageType::Session);

// Memory storage - State resets on each request
$TempForm = fc(fn() => ..., 'key', StorageType::Memory);

// Snapshot storage - State is embedded in HTML (stateless server)
$SnapshotCounter = fc(fn() => ..., 'key', StorageType::Snapshot);
```

| Storage Type | Description | Use Case |
|--------------|-------------|----------|
| `Session` | State stored in PHP session | Default. Forms, shopping carts |
| `Memory` | State reset per request | Temporary UI state, modals |
| `Snapshot` | State embedded in HTML | Stateless server, shareable URLs |

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

#### Component Storage Types

The `#[Component]` attribute accepts a `storage` parameter to control how component state is persisted:

```php
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Storage\StorageType;

// Session storage (default) - State persists across page navigations
#[Component(storage: 'session')]
class TodoList extends BaseComponent { ... }

// Memory storage - State is reset on each page load
#[Component(storage: 'memory')]
class TemporaryForm extends BaseComponent { ... }

// Snapshot storage - State is embedded in HTML, stateless on server
#[Component(storage: 'snapshot')]
class Counter extends BaseComponent { ... }
```

| Storage Type | Description | Use Case |
|--------------|-------------|----------|
| `session` | State stored in PHP session | Default. Forms, shopping carts, user preferences |
| `memory` | State reset per request | Temporary UI state, modals |
| `snapshot` | State embedded in HTML | Stateless server, shareable URLs |

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

- PHP 8.5+
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
