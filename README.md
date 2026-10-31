# usePHP

A framework for server-driven UI with a React Hooks-like API. You write PHP; one script, `usephp.js`, adds partial updates.

## Features

- **React Hooks-like API** - Simple state management with `useState`
- **Function Components (Recommended)** - Lightweight components using simple PHP callables
- **Built-in Router** - Simple, swappable router with snapshot state preservation across pages
- **No JavaScript to write** - `usephp.js` (about 1,500 lines, no dependencies or build step) handles partial updates; buttons and forms fall back to plain form posts without it
- **Pure PHP by default** - No transpilation needed for `H::xxx()` style components
- **PSX (optional)** - TSX-like template syntax compiled by `usephp compile` for HTML-heavy UIs (see [PSX section](#psx-optional-tsx-like-syntax))
- **Configurable State Storage** - Choose between session (persistent) or memory (per-request) storage
- **Progressive Enhancement** - `onClick` on buttons and `onSubmit` work with JavaScript disabled
- **Framework Integration** - Works with Laravel, Symfony, and other frameworks

## Installation

```bash
composer require polidog/use-php

# Copy JS file to public directory (full progressive enhancement layer)
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

// A component file returns its component. 'counter' is the key for state management.
return fc(function (array $props): Element {
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
}, 'counter');
```

### 2. Create an Entry Point with Router

```php
<?php
// public/index.php

ob_start(); // $page prints the doctype; buffering keeps the session cookie sendable

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\UsePHP;

// Serve usephp.js (for partial updates)
if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/usephp.js');
    exit;
}

$Counter = require __DIR__ . '/../components/Counter.php';

$app = new UsePHP();

// Signs actions and snapshots. Load a real secret from configuration.
$app->setSnapshotSecret('your-secret-key-here');

// The document around a page. A route that returns an Element lets usePHP
// pick the component out of it for a partial update.
$page = function (string $title, Element $content): Element {
    echo '<!DOCTYPE html>';

    return H::html(lang: 'en', children: [
        H::head(children: [
            H::meta(charset: 'utf-8'),
            H::title(children: $title),
        ]),
        H::body(children: [
            H::nav(children: [
                H::a(href: '/', children: 'Counter'),
                ' ',
                H::a(href: '/about', children: 'About'),
            ]),
            $content,
            H::script(src: '/usephp.js', defer: true),
        ]),
    ]);
};

// Configure routes
$router = $app->getRouter();
$router->get('/', fn(): Element => $page('Counter', $Counter(['initial' => 0])));
$router->get('/about', fn(): Element => $page('About', H::p(children: 'A usePHP app.')));

// Run the application
$app->run();
```

You don't need to call `session_start()` for this; see [Requirements](#requirements).

If your layout is a string, for example a template, prefer
`UsePHP::renderClientScript()` over a hand-written `<script src="/usephp.js">`.
It loads the published asset with `defer` and includes a tiny inline fallback
that hydrates deferred components if the asset cannot be read; partial form
updates still require the full `usephp.js` file. A route handler that returns
such a string costs the Session storage components on the page their partial
update: an action reloads the page instead. To keep it, run the action before
the layout, as `examples/index.php` does with `handleAction()`.

### 3. Start the Server

```bash
php -S localhost:8000 public/index.php
```

Open `http://localhost:8000` in your browser.

## Router

usePHP includes a built-in router that can be swapped or disabled for framework integration.

### Basic Usage

```php
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\UsePHP;

$app = new UsePHP();

// A component class has to be registered before a route can name it.
$app->register(HomeComponent::class);
$app->register(AdminDashboard::class);

$router = $app->getRouter();

// A registered component class
$router->get('/', HomeComponent::class);

// A callable: it gets the route parameters and the request, and returns an Element or a string
$router->get('/users/{id:\d+}', fn(array $params) => $UserPage(['key' => $params['id'], 'id' => $params['id']]));
$router->post('/users', function (array $params, RequestContext $request): string {
    return createUser($request->post);
});

// Route groups
$router->group('/admin', function ($group) {
    $group->get('/dashboard', AdminDashboard::class);
});

// Run the application
$app->run();
```

Naming a component class that was not registered throws a `LogicException` when the route is requested. Any other string has to be a callable, such as a function name; the name of an invokable class is not accepted as a handler.

### Route Patterns

| Pattern | Matches |
|---|---|
| `/about`, `/file.txt` | Literally: `.` is a dot, not a regex wildcard |
| `/users/{id}` | One path segment |
| `/users/{id:\d+}` | A segment matching the regex |
| `/posts/{page?}` | `/posts` and `/posts/2` (`page` is left out of the parameters when absent) |

- `HEAD` falls back to the matching `GET` route, unless a route was registered for `HEAD` itself. The middleware and handler run as usual, and PHP drops the body.
- Parameters are percent-decoded once, after matching. A value can contain `/` or `..` that the pattern never saw (`/users/a%2Fb` gives `a/b`), so validate it before using it as a file path.
- A value that decodes to invalid UTF-8 or contains a NUL byte makes the route not match: the answer is a 404.

### useRouter Hook

`useRouter()` gives a component the current request URL and the route parameters. usePHP has no named routes, so links are written as paths.

```php
use function Polidog\UsePhp\Runtime\useRouter;

$Breadcrumb = fc(function (array $props): Element {
    $router = useRouter();

    return H::p(children: "{$router['currentUrl']}: user {$router['params']['id']}");
}, 'breadcrumb');
```

The `useRouter()` hook returns:
- `currentUrl` - Current request URL
- `params` - Route parameters from current match

### Route Parameters and State

An instance's state belongs to its ID, and the ID comes from the component and its key, not from the URL. A `fc()` component with no props key is `FC@file.php:12#task` (`file.php:12` is where the closure starts) and a class without a key is `App\TaskDetail#0`. So by default `/tasks/1` and `/tasks/2` use the same Session state: like the first task, and the second one has the same count.

This is by design: it is what lets a cart follow the visitor across pages. When the state belongs to one record, put the route parameter in the key.

For a function component, pass it as the `key` prop:

<!-- readme-test: route-key-fc -->
```php
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

$TaskDetail = fc(function (array $props): Element {
    [$likes, $setLikes] = useState(0);

    return H::div(children: [
        H::h1(children: "Task {$props['id']}: {$likes} likes"),
        H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
    ]);
}, 'task');

// Instance ids: FC@...#task/1, FC@...#task/2
$router->get('/tasks/{id:\d+}', fn(array $params): Element => $TaskDetail([
    'key' => $params['id'],
    'id' => $params['id'],
]));
```

A class named directly as the route handler always renders under the same ID, because `$router->get('/tasks/{id:\d+}', TaskDetail::class)` has nowhere to put a key. Use a closure that calls `createElement()` instead. The component reads the parameter with `useRouter()`, which also works during its partial update:

<!-- readme-test: route-key-class -->
```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\useRouter;

#[Component]
final class TaskDetail extends BaseComponent
{
    public function render(): Element
    {
        $id = useRouter()['params']['id'];
        [$likes, $setLikes] = $this->useState(0);

        return H::div(children: [
            H::h1(children: "Task {$id}: {$likes} likes"),
            H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
        ]);
    }
}

$app->register(TaskDetail::class);

$router->get('/tasks/{id:\d+}', fn(array $params): Element => $app->createElement(
    TaskDetail::class,
    'task-' . $params['id'],
));
```

`createElement()` and `render()` take the class or the name given in `#[Component(name: ...)]`, and both give the same instance ID and state. Anything that doesn't resolve to a registered component throws a `LogicException`.

`H::component($fn, ['key' => ..., ...])` inside a `children` array also keys the state, but it renders no wrapper element, so its actions reload the page instead of updating in place.

Keep keys short and restrict the parameter with a regex: an action on an instance ID over 255 bytes or with control characters is rejected with a 400. Session state is kept per ID for as long as the session lives, so every record a visitor opens adds to their session.

### Middleware

`middleware()` adds guards to a route. They run in order before anything else happens for the route: on `GET` and `HEAD`, and on action POSTs to that page.

<!-- readme-test: middleware -->
```php
use Polidog\UsePhp\Router\RequestContext;

session_start();

$requireLogin = function (array $params, RequestContext $request) {
    return isset($_SESSION['user']);
};

$router->get('/account', fn() => 'Your account')->middleware($requireLogin);
```

A middleware is a callable or the name of an invokable class with a no-argument constructor. It receives `(array $params, RequestContext $request)`, and `$request->method` is `POST` for an action. What it returns decides what happens next:

| Return value | Result |
|---|---|
| `null`, nothing, or `true` | Continue with the next middleware, then the handler |
| `false` | End the request with `403 Forbidden` |
| a string or `Element` | End the request with that as the body, keeping any status set with `http_response_code()` |
| anything else | `LogicException`, so a broken guard never leaves the route open |

It can also end the request itself with `exit` or `$app->redirect('/login')`, or throw: the exception propagates out of `run()` and the handler does not run. A middleware that can't be resolved is a `LogicException` as well.

Guards belong to the URL they are attached to, so a few paths don't go through them:

- **Deferred endpoints.** `GET /_defer/{name}` has no route. A deferred component on a protected page has to check access itself. An action from a deferred form does run the guards of the page it is POSTed to.
- **A custom front controller** that invokes handlers on its own has to call `$app->runRouteMiddleware($match, $request)`: it returns the response body if a middleware ended the request, or `null` to continue. `handleAction()` applies the guards of the page it was POSTed to by itself.

An action can't get around the guards by being POSTed to another URL: its signature names the page it was rendered for, and any other path answers with a 400 (see [Signed actions](#signed-actions)).

### Snapshot Behavior

Control how the state of `StorageType::Snapshot` components is preserved across page navigations. Other storage types are not affected.

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

- **Isolated** keeps the state only in the page. An action is a partial update, or without JavaScript a page answered directly to the POST.
- **Persistent, Session and Shared** have somewhere to write, so every action ends in a redirect, with or without JavaScript, class components included. The redirect keeps the query string and removes only `_snapshot`.
- **Persistent** carries one instance in `?_snapshot=`. An action on a second instance replaces the first, which goes back to its initial state and logs a warning. That includes a parent and its child: the child's state doesn't survive. For a page with several instances, use `sessionSnapshot()`.
- **Session and Shared** store each instance separately in `$_SESSION`, at most 64 per slot (the Session slot, or one Shared group). Past that, the instance written longest ago goes back to its initial state, with a log line. An instance with the same class and ID shares its state across Session routes.
- Deferred components on Session and Shared routes don't read the slot: the endpoint has no route.

#### Nested Snapshot Components

- In a parent's partial update, `usephp.js` sends the snapshots its descendants hold (`_usephp_snapshots[]`), so they keep their state. Every entry goes through the same HMAC check, and one that doesn't verify rejects the request with a 400.
- In a child's partial update, the page is replayed with its ancestors in their initial state. Props the child gets from an ancestor's state are therefore out of date in that fragment.
- A deferred region inside a parent is placed again by the parent's partial update and goes back to the state it had when it was fetched, unless it is `reloadable`.
- A partial needs to find the component in the replayed page, so the handler has to return it in an Element or render it through `renderElement()`. Otherwise an action on an Isolated route falls back to a redirect, which loses the state; usePHP logs a `Snapshot state lost` warning.

#### StorageType vs SnapshotBehavior

These two concepts control state at different levels:

| | StorageType (Component) | SnapshotBehavior (Router) |
|---|---|---|
| **Scope** | Individual component | Route/page transitions |
| **Configuration** | `#[Component(storage: '...')]` | `$router->get(...)->sessionSnapshot()` |
| **Purpose** | How a component's state is stored | Where the state of Snapshot-storage components goes between requests |

**Example:** A `TodoList` component with `storage: 'session'` stores its own state in the session, whatever the route declares. A `Cart` component with `storage: 'snapshot'` keeps its state in the page, and `persistentSnapshot()` on its route moves that state into the URL.

### Framework Integration

When using usePHP within Laravel, Symfony, or other frameworks, disable the router and let the framework route to a controller that handles actions before it renders:

```php
$app = new UsePHP();
$app->register(Counter::class);
$app->disableRouter();  // Use NullRouter

// Laravel example
Route::match(['get', 'post'], '/counter', function () use ($app) {
    return $app->handleAction() ?? $app->render(Counter::class);
});

// Symfony example
#[Route('/counter')]
public function counter(): Response
{
    return new Response($this->app->handleAction() ?? $this->app->render(Counter::class));
}
```

`handleAction()` returns `null` unless the request is an action POST. Without a router there is no route to replay, so use class components here: an `fc()` component can't be found again for a partial update, and its actions redirect. A redirect ends the PHP process with `exit`. See [CSRF protection](#csrf-protection) if the framework already checks CSRF.

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
    |  (morphed into the live DOM)     |
```

Sibling components are not re-rendered. On a route where the action has to redirect (see [Snapshot Behavior](#snapshot-behavior)), `usephp.js` follows the redirect with a page navigation.

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

That is for state with a place to live: Session storage, and Snapshot storage on Persistent, Session and Shared routes. Snapshot storage on an Isolated route keeps state nowhere but the page, so the server answers the POST itself:

```
[Browser]                         [PHP Server]
    |                                  |
    |  <form> POST (button click)      |
    | -------------------------------->|
    |                                  | State update, full page render
    |  200 <html>Count: 1</html>       | Cache-Control: no-store
    | <--------------------------------|
```

Reloading that page makes the browser ask whether to resend the form. Memory storage can't change without JavaScript at all: a redirect starts from its initial value.

### Partial Updates

A partial response is morphed into the live DOM instead of replacing it, so unsent input, focus and the text selection survive. Who wins a form field that survives the morph, most recent intent first:

1. A value the user edited during the request stays the user's.
2. If the server rendered a different value than last time, the server's value wins.
3. A field in the form that was just sent takes the rendered value, as after a full page POST.
4. Any other unsent value stays the user's.

Hidden fields always follow the server.

- Give rows of a list that can be reordered or deleted an `id`. Without one, rows are matched by position.
- A component, its ancestors and its descendants update in turn; siblings don't wait for each other. A fixed-value action on a component whose relative has a request in flight is dropped: its value was computed when it was rendered, so replaying it could hit the wrong row. A `useFormAction()` action is sent again with the latest values once the request finishes. `aria-busy` on the wrapper is only for display.
- A failed request is not replayed; see [Failed partial submits are not replayed](#failed-partial-submits-are-not-replayed).

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
$html = $app->renderElement($element);

// Method B: H::component()
// Creates an Element that resolves when it is placed in a children array.
// It renders no wrapper, so its actions reload the page instead of updating in place.
$counterFn = function (array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);
    return H::button(onClick: fn() => $setCount($count + 1), children: "Count: $count");
};
H::div(children: [
    H::component($counterFn, ['initial' => 5, 'key' => 'my-counter']),
]);

// Method C: Direct call (only for pure components without useState)
$Greeting = fn(array $props): Element => H::div(children: "Hello, {$props['name']}!");
$Greeting(['name' => 'World']); // OK - no state needed
```

Call a Snapshot-storage component from a route handler or between `RenderContext::setApp()` calls: it needs the app to sign its snapshot, and throws a `LogicException` without it.

**Instance keys:**

An instance ID comes from the component and its key.

- With a key in `fc()` only, the ID ends in that key: `FC@file.php:12#row`.
- With a `key` prop too, the two are combined, so a component with a fixed key can still render several instances: `$Row(['key' => 'k1'])` ends in `#row/k1`. The `key` prop can be a string or an int.
- With neither, the ID ends in the instance's position among components of that name.
- Two instances with the same ID on a page share one slot of state. This is not detected.

The state also depends on where a closure is defined: moving `fc()` to another line starts from fresh state. See [Route Parameters and State](#route-parameters-and-state) for keys that follow the URL.

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

// Snapshot storage that also survives a page reload (see below)
use Polidog\UsePhp\Storage\SnapshotPersist;
$SnapshotCounter = fc(fn() => ..., 'key', StorageType::Snapshot, persist: SnapshotPersist::SessionStorage);
```

| Storage Type | Description | Use Case |
|--------------|-------------|----------|
| `Session` | State stored in PHP session | Default. Forms, shopping carts |
| `Memory` | State reset per request | Temporary UI state, modals |
| `Snapshot` | State embedded in HTML | Stateless server, shareable URLs |

`Session` and `Memory` components update in place when the route handler returns an Element; other components on the page are not re-rendered.

**Memory storage** only changes through a partial update, so it needs JavaScript, and a reload goes back to the initial value. Each action starts from an empty store, so a component with several `useState` calls loses the others on every action. Use Session or Snapshot storage for that.

**Snapshot storage and page reloads.** Like React's `useState`, Snapshot
state lives in the page: the only copy is the signed `data-usephp-snapshot`
attribute that round-trips with each action, so a reload on a plain
(Isolated) route starts over from the initial value. To keep it across
reloads while the server stays stateless, opt in to client-side
persistence with `persist:`. `usephp.js` then mirrors the latest snapshot
into `sessionStorage` (per tab) or `localStorage` (shared) after every
update, and on the next load POSTs it back as a `restore` action so the
component re-renders with the saved state — still HMAC-verified, like any
other action. Use this on Isolated routes; `persistentSnapshot()` /
`sessionSnapshot()` routes already carry the snapshot in the URL or the
PHP session. `window.usePHP.forgetSnapshots()` clears the saved entries.

#### Class-based Components

You can also write a component as a class, and render it by name after registering it with `$app->register(MyComponent::class)`:

```php
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Runtime\Element;

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

// Snapshot storage kept across reloads in the browser's sessionStorage
#[Component(storage: 'snapshot', persist: 'sessionStorage')]
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

State travels as JSON between renders, so a value can only be null, a scalar or an array of those. A float stays a float. Anything else (an object, a date, `NAN`) throws when the action or snapshot holding it renders; convert it first, for example `$date->format(DATE_ATOM)`. `$setState(null)` stores `null`: the initial value does not come back.

### useEffect

```php
use function Polidog\UsePhp\Runtime\useEffect;

useEffect(function () use ($id) {
    logView($id);
}, [$id]);
```

The effect runs on the first render and when a dependency changes. `null` deps run it on every render, `[]` only on the first.

If the effect returns a `Closure`, that is its cleanup. A cleanup lives in memory for the current request: it runs only when the same effect runs again within that request. It does not run on the next request, and there is no unmount, so don't rely on it to release anything that outlives the request. Any other return value is ignored, including callable strings and arrays; return a method as `$handle->close(...)`.

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

// Any attribute works as a named argument. Spread the names PHP can't spell.
H::div(title: 'x', role: 'alert');
H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x');

// A textarea's value becomes its content
H::textarea(name: 'body', value: $body);
```

**Children** can be an Element, a string, a number, a `Stringable`, `null`, a bool, or an array of those. Nested arrays are flattened. `null` and bools render nothing. Anything else throws.

**Attributes:** `aria-*`, `contenteditable`, `draggable` and `spellcheck` write a bool as `"true"` or `"false"`; other attributes write `true` as a bare attribute and leave out `false`. Invalid UTF-8 in text or attributes is replaced with U+FFFD.

**`<style>` and `<script>`** content is written without escaping, because the HTML parser doesn't decode it. **Never put user input there.** Content containing the element's own end tag throws, and so does an Element as a child. Inside `<svg>` and `<math>` they are ordinary markup, so their content is escaped.

### Events

Only these four event props exist. `onFocus`, `onBlur`, `onLoad` and `onError` throw an `InvalidArgumentException`: each would be a server round trip.

| Prop | Fires on | Without JavaScript |
|---|---|---|
| `onClick` | A click | Works on `<button>` and on `<input type="submit">` / `type="image"`; other elements need `usephp.js` |
| `onSubmit` | Submitting a `<form>` | Works |
| `onChange` | The DOM `change` event: for text, on blur or Enter, not on each keystroke | Needs `usephp.js` |
| `onInput` | Input, after 300 ms without typing | Needs `usephp.js` |

A prop takes an `Action` or a closure with no parameters. The closure runs during rendering to build the action, not when the event fires; to receive what the user typed, use `useFormAction()`.

### Form Input

`useFormAction(['field' => $setter])` copies submitted fields into `useState` hooks. Use it on a form's `onSubmit` (works without JavaScript), or on `onChange` / `onInput` of a named control for its own field.

<!-- readme-test: form-input -->
```php
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useFormAction;
use function Polidog\UsePhp\Runtime\useState;

$Profile = fc(function (): Element {
    [$name, $setName] = useState('');

    return H::form(
        onSubmit: useFormAction(['name' => $setName]),
        children: [
            H::input(name: 'name', value: $name),
            H::button(children: 'Save'),
            H::p(children: "Saved: {$name}"),
        ],
    );
}, 'profile');

$router->get('/profile', fn(): Element => $Profile());
```

**Trust boundary:** which field goes to which hook is signed and can't be changed by the client. The values are not signed: treat them as untrusted input and validate them before using them. A value that is not a string, is longer than `UsePHP::MAX_FORM_FIELD_BYTES` (65535 bytes) or is not valid UTF-8 rejects the whole request with a 400. A field that was not sent, like an unchecked checkbox, becomes `''`.

Only `onClick` on buttons and submit inputs and `onSubmit` work without JavaScript. Other elements need `usephp.js`. An `fc()` component with Session storage updates in place while the user types, as long as its route handler returns an Element; if the handler returns a string, each action reloads the page and the input loses focus.

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
$App = fc(function(array $props) use ($Button, $Card): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        $Card(['title' => 'Counter', 'content' => "Count: $count"]),
        $Button(['onClick' => fn() => $setCount($count + 1), 'children' => 'Increment']),
    ]);
}, 'app');
```

## PSX (optional, TSX-like syntax)

PSX is an opt-in alternative way to author components: you write HTML directly and `usephp compile` lowers it to `createElement()`, the function behind the `H::xxx()` calls shown above. The runtime is unchanged — PSX is purely a syntax layer.

### When to use it

- Components with deeply nested HTML where `H::div(children: [...])` becomes hard to read
- Teams used to JSX/TSX who want familiar template syntax

If your components are simple, sticking with `H::xxx()` is fine — PSX adds a build step, while plain PHP doesn't.

### Syntax at a glance

```php
<?php
// components/Counter.psx
namespace App\Components;

use Polidog\UsePhp\Storage\StorageType;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        <div className="counter">
            <span>Count: {$count}</span>
            <button onClick={fn() => $setCount($count + 1)}>+</button>
            <button onClick={fn() => $setCount($count - 1)}>-</button>
        </div>
    );
}, 'counter', StorageType::Session);
```

Lowers to (cached file inside `var/cache/psx/`):

```php
return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        \Polidog\UsePhp\Html\createElement('div', ['className' => 'counter', 'children' => [
            \Polidog\UsePhp\Html\createElement('span', ['children' => ['Count: ', $count]]),
            \Polidog\UsePhp\Html\createElement('button', ['onClick' => fn() => $setCount($count + 1), 'children' => ['+']]),
            \Polidog\UsePhp\Html\createElement('button', ['onClick' => fn() => $setCount($count - 1), 'children' => ['-']]),
        ]])
    );
}, 'counter', StorageType::Session);
```

A `.psx` file doesn't need `use Polidog\UsePhp\Html\H` for its tags: the compiled code calls `createElement()` by its full name, so markup like `{0}` or `width="100"` isn't held to the parameter types of `H::xxx()`.

Differences from plain PHP worth knowing:

- `&&` and `||` with a tag on the right work as in JSX: `{$isAdmin && <AdminBar />}` renders the tag. One difference from JSX: `{0 && <b>x</b>}` renders nothing, not `0`.
- `{/* comment */}` is a comment and produces no child.
- HTML entities in text and attribute values are decoded at compile time, then escaped again on output.
- Inside `<style>` and `<script>` everything is text, so `{$x}` is not expanded.

### Compile workflow

```bash
# One-shot compile of all .psx under components/
./vendor/bin/usephp compile components/

# Watch mode for the dev loop
./vendor/bin/usephp compile components/ --watch

# CI: fail if anything is out of date
./vendor/bin/usephp compile components/ --check

# Remove the cache directory
./vendor/bin/usephp compile components/ --clean

# Use a custom cache directory
./vendor/bin/usephp compile components/ --cache=build/psx
```

A path that does not exist is an error (exit code 1).

`compile` writes its output to `var/cache/psx/` (configurable with `--cache=PATH`). Each `.psx` source file produces a sha1-named `.php` companion in that directory, plus a single `manifest.php` (FQCN → compiled-path map). The `.psx` source files in your project tree are the only PSX-related files you commit — the cache directory is intended to be ignored:

```gitignore
/var/cache/psx/
```

### Loading PSX components at runtime

```php
use Polidog\UsePhp\Psx\CompileCommand;

$app = new UsePHP();
$app->loadComponentManifest(__DIR__ . '/../var/cache/psx/' . CompileCommand::MANIFEST_FILENAME);

// Use a PSX component as a route handler
$router = $app->getRouter();
$router->get('/', function () use ($app) {
    return $app->renderPsxComponent('App\\Components\\Counter', ['initial' => 0]);
});
```

### Composing PSX components

`<Counter />` (PascalCase) inside another `.psx` resolves through PHP's `use` statements to a fully qualified class name, just like a regular class import:

```php
<?php
namespace App\Pages;

use App\Components\Counter;
use App\Components\Forms\Input as FormInput;

return fn() => (
    <div>
        <Counter initial={5} />
        <FormInput type="email" />
    </div>
);
```

If a component is registered at runtime instead of being defined in a `.psx` file, declare it with a comment so the compiler doesn't fail validation:

```php
// @psx-runtime App\Legacy\WidgetCounter
```

For the full spec — Fragment syntax, attribute dispatch, manifest format, edge cases — see [docs/PSX.md](docs/PSX.md).

### Editor support

Syntax highlighting for `.psx` is bundled in [`editors/`](editors/README.md):

- **Neovim / Vim** — [`editors/nvim/`](editors/nvim/README.md) (lazy.nvim / packer / vim-plug / manual install)
- **VS Code** — [`editors/vscode/`](editors/vscode/README.md) (`.vsix` build or local symlink)

Each editor's README walks through install + verify steps. An LSP /
tree-sitter grammar / PHPStan extension are intentionally out of scope
here and will land in dedicated repositories.

## Deferred rendering (CDN-friendly partial hydration)

Some components depend on per-user state (logged-in name, cart count, A/B
bucket) and would otherwise force the entire page out of the CDN cache. The
fix is to split such a component into two pieces:

1. A **base** component that does the actual rendering.
2. A **deferred wrapper** that carries the defer config — `fc(..., defer: new Defer(...))`
   for closure components, or `#[Defer(...)]` for class components.

The page references the wrapper, which renders only a fallback in the cacheable
HTML. The real component is fetched after page load via a separate GET to a
**dedicated endpoint** `/_defer/{name}`.

```psx
{/* UserHeader.psx — the actual content, reused inline elsewhere if needed */}
return fn(array $props) => <header>Hello {$_SESSION['user']['name'] ?? 'guest'}</header>;
```

```psx
{/* UserHeaderDeferred.psx — the wrapper */}
use Polidog\UsePhp\Component\Defer;
use function Polidog\UsePhp\Runtime\fc;

return fc(
    fn(array $props) => <UserHeader />,
    defer: new Defer(name: 'user-header', cacheControl: 'private, no-store'),
);
```

```psx
{/* Page.psx — fallback travels as a normal prop */}
<UserHeaderDeferred fallback={<HeaderSkeleton />} />
```

`usephp compile` discovers `Defer` configs and writes a sidecar
`deferred-manifest.php` next to the regular manifest — `loadComponentManifest()`
picks it up and auto-calls `registerDeferred()` for each entry, so the manual
wiring goes away. Class components use the same flow:

```php
#[Component(name: 'UserHeaderDeferred')]
#[Defer(name: 'user-header', cacheControl: 'private, no-store')]
final class UserHeaderDeferred extends BaseComponent
{
    public function render(): Element { /* the real content */ }
}

$app->register(UserHeaderDeferred::class); // auto-registers the defer endpoint too
```

What happens at runtime:

1. SSR invokes the wrapper, which (on the page-render path) emits
   `<div data-usephp-defer-url="/_defer/user-header">…fallback…</div>`.
2. The main HTML is independent of the user — safe to cache at the CDN edge.
3. `usephp.js` finds all `[data-usephp-defer-url]` elements after `DOMContentLoaded` and `GET`s each URL. The framework flips an internal flag, re-invokes the wrapper in endpoint mode (so it returns the real content instead of the placeholder), and responds with the configured `Cache-Control`.
4. The placeholder is replaced in place with the real component.
5. Because each deferred endpoint has its own URL, each can carry its own cache policy — `public, s-maxage=60` for a shared announcement bar, `private, no-store` for a session-coupled `UserHeader`, etc.

### Passing data from the parent

Any prop besides `fallback` is forwarded as a query parameter on the deferred
fetch:

```psx
<PostCommentsDeferred fallback={<Skeleton />} post_id={$postId} sort="new" />
```

This becomes `GET /_defer/post-comments?post_id=123&sort=new`. The wrapper's
inner closure receives them as `$props['post_id']`. Values must be scalar
(`int`/`string`/`float`/`bool`) — arrays, Elements, and Closures cannot cross
the URL boundary. All values arrive as **strings** on the server.

### Client-side cache & forced reset

`usephp.js` puts a two-tier cache in front of every deferred fetch:

- **L1 — in-memory** `Map<URL, DocumentFragment>`, per page lifetime. Always
  on; a full reload clears it. Identical to the previous behaviour.
- **L2 — `localStorage`**, surviving reloads and shared across tabs.
  **Strictly opt-in, and the component decides** — `usephp.js` never looks
  at the HTTP `Cache-Control` header for this. A fragment is persisted
  **only** when the component sets `Defer::$localCache = true`; without it
  the fragment stays memory-only, so a session-coupled component (the
  default) can't leak one user's content to the next on a shared terminal.
  **By default there is no time expiry** — a persisted entry lives until a
  `DEFER_CACHE_VERSION` bump or `clearDeferCache()` removes it. Set
  `Defer::$localCacheTtl` (seconds) to additionally bound it by age (see
  below). The endpoint's `cacheControl` is a **separate** concern
  (server/CDN caching) and is intentionally decoupled from this client
  decision.

Read order is L1 → L2 → network; L2 is consulted only for components that
opted in. An L2 hit is promoted into L1. Both tiers are keyed by URL and
bounded at 64 entries (L1 LRU, L2 oldest-first by insertion), so a list
page that defers per-row fragments can't grow them without limit.

Make a deferred component client-cacheable by opting in — on the
`#[Defer]` attribute or the `Defer` value object:

```php
// Class component
#[Defer(name: 'announcement-bar', localCache: true)]

// Closure component in a .psx
fc($render, defer: new Defer(name: 'announcement-bar', localCache: true));
```

This renders `<div data-usephp-defer-url="…" data-usephp-defer-cache>`;
`usephp.js` keys off that bare attribute's presence (and nothing else) to
decide persistence. `localCache` and `cacheControl` are independent: you
can ship `cacheControl: 'private, no-store'` for the endpoint while still
allowing the client cache, or vice versa. Because there's no expiry by
default, invalidate via `DEFER_CACHE_VERSION` (deploy) or
`clearDeferCache()` (runtime).

Fragments that contain a usePHP form (the hidden `_usephp_csrf` /
`_usephp_snapshot` fields, or a `data-usephp-snapshot` wrapper) are
**never written to `localStorage`**, even with `localCache: true` — they
stay in the per-page L1 tier and `usephp.js` logs a warning. Persisting
them would expose the session CSRF token to the next user of a shared
device and resurrect a stale token after the session rotates.

#### Optional time bound — `Defer::$localCacheTtl`

If a fragment should simply go stale after N seconds, set
`localCacheTtl` (seconds) alongside `localCache: true`:

```php
#[Defer(name: 'feed', localCache: true, localCacheTtl: 60)]

fc($render, defer: new Defer(name: 'feed', localCache: true, localCacheTtl: 60));
```

This adds a **separate** `data-usephp-defer-cache-ttl="60"` attribute next
to the bare opt-in. Once the persisted entry is older than that, the next
read **discards it and re-fetches from the network** — the fallback shows
briefly, then the fresh fragment. It is a **hard discard, not
stale-while-revalidate** (no stale paint, no background swap). The bound
applies to the L2 `localStorage` tier only; L1 is per-page anyway.

Any `localCacheTtl <= 0` (the `0` default included) means no time bound —
byte-identical markup and behaviour to a plain `localCache: true`. A
negative is normalised to `0` (read as "no bound", not an error), so only
a genuinely *positive* value ever takes effect. A separate attribute
(rather than a value on the bare opt-in) keeps that default, and every
non-opted-in placeholder, byte-identical to the pre-feature markup. A
*positive* TTL without `localCache: true` still throws (it would bound an
entry that's never written).

**Forced reset is handled in JS:**

```js
// Runtime purge (e.g. after login/logout, or a "refresh" button):
window.usePHP.clearDeferCache();                                  // both tiers, everything
window.usePHP.clearDeferCache('post-comments');                   // every variant of one defer name
window.usePHP.clearDeferCache('/_defer/post-comments?post_id=1'); // one exact placeholder URL

// Deploy-time invalidation: bump the constant in usephp.js. A mismatch
// against the value persisted in localStorage wipes the whole namespace
// before the first cache read, so old fragments can't survive a release.
const DEFER_CACHE_VERSION = '1'; // → '2' on the next deploy
window.usePHP.DEFER_CACHE_VERSION;                                // read the current build's value
```

Name matching is prefix-agnostic, so a custom `setDeferPrefix('/api/_d')`
still resolves. If `localStorage` is unavailable (Safari private mode, quota
exceeded, disabled) the L2 tier silently no-ops and L1 keeps serving the
page.

### Explicit reload

By default a deferred fragment is fetched **once**: `usephp.js` replaces the
placeholder with the response and the wrapper is gone, so there is nothing
to re-target. Opt into `Defer::$reloadable` to keep a re-fetchable wrapper
in the DOM:

```php
// Class component
#[Defer(name: 'todo-list', reloadable: true)]

// Closure component in a .psx
fc($render, defer: new Defer(name: 'todo-list', reloadable: true));
```

This renders the placeholder with `data-usephp-defer-name="todo-list"`.
`usephp.js` then swaps content *inside* that wrapper instead of replacing it
away, so the region can be re-fetched later. Every reload **busts both
cache tiers for that URL first**, so it always reflects current server
state. Three ways to trigger it, all over one core API:

```js
// 1. Imperative — call from anywhere (no form required):
window.usePHP.reloadDefer();                       // every reloadable region
window.usePHP.reloadDefer('todo-list');            // by defer name
window.usePHP.reloadDefer('/_defer/todo-list?p=2'); // by exact URL
// returns the number of regions reloaded (0 ⇒ nothing matched)
```

```psx
// 2. After a partial form submit — the canonical "form mutates data,
//    reload the list" wiring. Fires only once the mutation response is
//    applied, so the re-fetch sees the new state:
<form data-usephp-form data-usephp-reload-defer="todo-list"> … </form>

// 3. On click of any element outside a usephp form — a standalone
//    Refresh button/link, a toolbar control, etc.:
<button data-usephp-reload-defer="todo-list">Refresh</button>
```

The attribute value is a space/comma-separated list of defer names (or
exact URLs); an empty value reloads every reloadable region. Reload is a
distinct concern from `clearDeferCache()` — the latter only invalidates,
it never re-fetches. Components that don't set `reloadable` are unchanged:
replaced away on resolve, byte-identical markup.

### Requirements & limitations

- **One component per mode.** Use a base component for inline rendering and a wrapper carrying `Defer` for the deferred endpoint. Don't try to switch modes on the same component at the call site.
- **Defer names are URL-safe and unique.** Pattern: `[A-Za-z0-9_-]+`. Two wrappers attempting to register the same name fail at compile time.
- **Class-based defer targets are supported.** Annotate the class with `#[Defer(name: '...')]`; `register()` auto-wires the endpoint. The class itself decides what `render()` produces in endpoint mode.
- **Params must be scalar.** They travel through the URL query string, so `int`/`string`/`float`/`bool` only (bools are coerced to `'1'/'0'`). Arrays, Elements, Closures, and resources are rejected at render time.
- **Authorization is the component's responsibility.** The name and params are visible in the URL, and the endpoint has no route, so [route middleware](#middleware) does not run for it. For endpoints that surface sensitive data, check session/permissions inside the component. (HMAC signing is no longer needed; the name is a public entry-point identifier.)
- **Nested defer works.** A deferred component's output may itself contain `<...Deferred />` placeholders. Each inner one stays a placeholder and is served by its own endpoint with its own `Cache-Control`, so its endpoint has to be registered too; `usephp.js` recursively hydrates them.
- **Two-tier defer cache (L1 in-memory + opt-in L2 `localStorage`).** Component-decided persistence (`Defer::$localCache`, not the HTTP `Cache-Control`; no time expiry by default, optionally bounded by `Defer::$localCacheTtl` seconds) and a JS forced-reset API — see [Client-side cache & forced reset](#client-side-cache--forced-reset) above. Components that don't opt in behave exactly like the previous in-memory-only cache.
- **Opt-in explicit reload (`Defer::$reloadable`).** Keeps a re-targetable wrapper so a deferred region can be re-fetched via `window.usePHP.reloadDefer()`, a form's `data-usephp-reload-defer`, or a click on any element — see [Explicit reload](#explicit-reload) above. Each reload busts both cache tiers for that URL first. Components that don't opt in are replaced away on resolve, byte-identical markup.
- **No-JS users see the fallback only.** If JavaScript runs but the published `usephp.js` asset cannot be read, `UsePHP::renderClientScript()` includes a tiny inline fallback that still fetches deferred fragments once. The full asset is still required for partial form updates, defer caching, and explicit reload APIs.
- **Framework integration:** call `$app->handleDeferred()` from your controller; it returns the rendered HTML for `GET /_defer/...` requests, or `null` otherwise. Mirrors `handleAction()`. The prefix is configurable via `setDeferPrefix('/api/_d')`.

## Generated HTML

```php
H::button(onClick: fn() => $setCount($count + 1), children: '+')
```

Transforms to:

```html
<button type="submit" form="usephp-f-65c6c10e7fbb6f81">+</button>
<!-- at the end of the component wrapper: -->
<form method="post" data-usephp-form id="usephp-f-65c6c10e7fbb6f81" hidden>
  <input type="hidden" name="_usephp_component" value="FC@Counter.php:11#counter" />
  <input type="hidden" name="_usephp_action" value='{"type":"setState","payload":{"index":0,"value":1},"componentId":"FC@Counter.php:11#counter","storageType":"session"}' />
  <input type="hidden" name="_usephp_action_sig" value="…hmac-sha256…" />
  <input type="hidden" name="_usephp_csrf" value="…" />
</form>
```

- The button sits where you put it and points at a separate hidden form through its `form` attribute. The form goes at the end of the component wrapper, so a button inside your own `<form>`, `<p>` or `<tbody>` doesn't break the markup.
- `data-usephp-form` - Form intercepted by JS
- `_usephp_action_sig` - HMAC over the action and what it is bound to; the server only executes actions it rendered (see [Signed actions](#signed-actions))
- `_usephp_csrf` - Present when a session is active (see [CSRF protection](#csrf-protection))
- Works as a regular form submission without JS

## CLI

```bash
./vendor/bin/usephp publish               # Copy usephp.js to public/
./vendor/bin/usephp compile components/   # Compile .psx files (see PSX section)
./vendor/bin/usephp help                  # Show help
```

## Security

usePHP ships with several built-in defenses. The notes below cover the parts an application author still has to wire up correctly.

### What usePHP does not protect (trust boundaries)

The guarantees in this section are about transport: signing, CSRF tokens, URL filtering. They do not move the trust boundary. Everything that round-trips through the browser is client input, and the points below are the application author's responsibility.

- **`useState` values are client input.** A setter computes its value on the server, but that value travels through the browser, and a user can replay any action the server ever rendered for them. Never derive authorization, prices, payment or wizard-step completion, user identity, or anything else the server must decide from hook state. Re-read those from `$_SESSION` or your database at render time.

  ```php
  // Bad: "paid" lives in hook state — the client can replay the action that set it.
  [$paid, $setPaid] = useState(false);

  // Good: the server decides at render time; hook state only drives presentation.
  $paid = $_SESSION['paid'] ?? false;
  ```

- **Snapshot contents are plaintext in the HTML.** The `data-usephp-snapshot` attribute and the `_usephp_snapshot` hidden field carry the state as readable JSON. The HMAC prevents tampering, not reading. Do not put secrets or personal data in the state of Snapshot-storage components.

- **Snapshots are replayable and have no expiry.** `SnapshotBehavior::Persistent` carries the state in the URL on purpose: anyone with the link reproduces that state. `Session` and `Shared` snapshots are bound to the PHP session id, but neither kind expires — a snapshot stays valid for as long as the signing secret does. Treat a snapshot as a bookmark, not as proof that something happened.

- **`_usephp_snapshots[]` is client input.** It is how a page's other snapshots come along with an action. Every entry has to pass the snapshot HMAC, but which entries get sent is up to the client, so the replay caveats above apply to them too.

- **`useFormAction()` values are client input,** and so are route parameters. See [Form Input](#form-input) and [Route Patterns](#route-patterns).

- **`<style>` and `<script>` content is not escaped.** See [HTML Elements](#html-elements).

- **`/_defer/{name}` is an unauthenticated GET endpoint.** [Route middleware](#middleware) does not cover it, and query parameters become component props verbatim. Beyond checking permissions inside the component (see [Deferred rendering](#deferred-rendering-cdn-friendly-partial-hydration) above), treat props as untrusted input: validate and cast them before use, and never forward them into attribute names, tag names, or event props such as `onClick`.

- **Session cookie flags and `session_regenerate_id()` are the application's job.** See [Session cookie hardening](#session-cookie-hardening) below.

### Snapshot HMAC secret (required for Snapshot storage)

`SnapshotBehavior::Persistent|Session|Shared` and components declared with `#[Component(storage: 'snapshot')]` round-trip state through the client, so the framework HMAC-signs every snapshot. You must configure a high-entropy secret before rendering a snapshot-using component or you'll get a `LogicException` at render time.

```php
$app = new UsePHP();
$app->setSnapshotSecret(getenv('USEPHP_SNAPSHOT_SECRET'));
```

Generate the key once and load it from configuration:

```php
// One-time generation — store the result, do NOT regenerate each request.
echo bin2hex(random_bytes(32));
```

The key must be **stable across requests and across worker processes** (PHP-FPM, multi-server). If different workers have different keys, snapshots produced by one worker will fail verification on the next. Rotating the key invalidates every outstanding snapshot.

Never commit the secret to git. The placeholders used in `examples/` (`'your-secret-key-here'`, `'phase-1-demo-secret'`) are deliberately weak — replace them before deploying anything.

**What the signature does and does not bind.** `SnapshotBehavior::Session` and `Shared` snapshots are additionally bound to the PHP session id: they are stored in `$_SESSION` and only verify inside the session that produced them, so a signed snapshot obtained in one browser cannot be planted into another user's session (a `?_snapshot=` parameter is ignored on Session routes). The trade-off is that `session_regenerate_id()` — which you should call after login anyway — invalidates the snapshots stored under the old id. `SnapshotBehavior::Persistent` snapshots and `#[Component(storage: 'snapshot')]` form round trips are intentionally *not* user-bound: their state lives in the URL / form, and anyone holding that URL can replay it. Snapshots carry no issue time and no expiry; a signature stays valid until the secret (or the session id, for bound snapshots) changes.

### CSRF protection

`$app->run()` and `$app->handleAction()` enforce two layers on every POST:

1. **Origin / Referer same-origin check** (always). A request with neither header, or with an origin that doesn't match the current `Host`, is rejected with `403 Forbidden`.
2. **Session-bound synchronizer token** (when a session is active). Every action form embeds a per-session token as `<input type="hidden" name="_usephp_csrf" value="...">`, and the POST is checked against it with `hash_equals`. A request that has a session cookie but whose session can't be started fails this check.

The token is exposed via `$app->getCsrfToken()` if you render forms yourself.

If the surrounding framework (Laravel `VerifyCsrfToken`, Symfony's CSRF component, etc.) already enforces CSRF on POST handlers, opt out of usePHP's check so the two layers don't double-validate:

```php
$app = new UsePHP();
$app->disableCsrfProtection();
```

### Signed actions

Every action form carries `_usephp_action_sig`, an HMAC-SHA256 over where the
action may be POSTed, the session, the component instance id and the exact
`_usephp_action` JSON. A POST refuses any action whose signature is missing or
does not verify, so the browser can only replay actions the server itself
rendered: it cannot change the hook `index`, the `value`, the `storageType`, or
point the action at a different component instance.

The signature is bound to the session, the instance and the page's path, so a
form rendered in one session does not verify in another, and re-sending a form
to another page is answered with a 400. That keeps an action from getting around
the [middleware](#middleware) of the page it came from.

- **A normal form** can only be POSTed to the path of the page that rendered it.
  The path is compared the way the router does: a trailing slash and the query
  string don't matter, and percent-encoding does.
- **A form from a deferred component** is rendered at `/_defer/{name}`, which
  can't know the page holding it, so it is bound to the defer name and accepted
  on any page. This gives nothing away, since anyone can fetch a fresh form from
  the endpoint. The guards of the page it is POSTed to still run, while the
  endpoint itself has none.
- **HTML you fetch from your own route and insert into another page** has forms
  bound to that route, and `usephp.js` POSTs them to the page holding them, so
  they get a 400. Make such a component a deferred one. For a form you write by
  hand, name the page it will POST to with
  `$app->signAction($instanceId, $actionJson, $pagePath)`.
- **The `restore` action** (snapshot persistence) is built by `usephp.js`, so its
  JSON is not what is signed. It carries no payload, and the snapshot it sends
  must pass its own HMAC and name the same instance. It also needs the
  `data-usephp-restore-sig` signature that a page renders for a persisted
  component, bound like any other, so an old snapshot can't bring a guarded
  component up on an open page.

**When upgrading:** signatures now cover the page, so every action form rendered
by an earlier version answers with a 400, including pages held in a CDN or
browser cache. A page without `data-usephp-restore-sig` has its `restore`
rejected with a 400 too, and `usephp.js` then drops the snapshot it saved for
that page. Purge cached pages when you deploy.

The signing key is the snapshot secret when `setSnapshotSecret()` was called,
otherwise a random per-session key stored in `$_SESSION`, in which case a page
with an action form starts a session. If that isn't possible because output was
already sent, the action can't be verified: set a secret, or render before
printing anything. `signAction()` is for forms you render yourself, and is
internal rather than a stable API.

Signing fixes the transport, not the trust boundary: a user can still replay
any action that was rendered for them, so hook state must not be used for
authorization decisions (see the trust-boundary notes above). Malformed action
payloads (wrong types, out-of-range hook index, bad instance id) are answered
with `400` instead of surfacing as a PHP `TypeError`.

#### Behind a TLS-terminating proxy

When usePHP runs behind a reverse proxy that terminates TLS (nginx, an ALB, Cloudflare, etc.), `$_SERVER['HTTPS']` is unset on the PHP-FPM side even though the browser sees `https://`. The expected origin would then be computed as `http://...` and every legitimate POST would fail the same-origin check with a 403.

You have two options:

1. **Pass the scheme into `$_SERVER`** before usePHP runs, e.g. in nginx:
   ```nginx
   fastcgi_param HTTPS on;
   fastcgi_param HTTP_HOST $http_host;
   ```
2. **Opt into proxy-header trust** — usePHP will honor `X-Forwarded-Proto`, `X-Forwarded-Host`, and `X-Forwarded-Port`:
   ```php
   $app->trustProxyHeaders();
   ```
   Only enable this when every request reaches PHP through a proxy that you control and that strips or overwrites these headers from the client side. Otherwise an attacker can spoof them and bypass the origin check.

### Session cookie hardening

usePHP uses `$_SESSION` for the `Session` and `Shared` snapshot behaviors and for its CSRF token. The library does not set cookie flags for you — configure them in `php.ini` or `session_start()` options:

```ini
session.cookie_httponly = 1
session.cookie_secure   = 1     ; set when serving over HTTPS
session.cookie_samesite = Lax   ; or Strict
```

Call `session_regenerate_id(true)` after authentication to defeat session fixation.

### Same-origin redirects

`$app->redirect($url)` and `SimpleRouter::createRedirectUrl()` reject absolute URLs (`https://...`), protocol-relative URLs (`//host/path`), and scheme-prefixed paths (`javascript:...`). Pass only same-origin paths starting with `/`. If you need to redirect off-site, write the `Location` header yourself after running the target through your own allow-list.

### Deferred fetch guard (client)

`usephp.js` (and the inline fallback emitted by `renderClientScript()`) only
hydrates a `data-usephp-defer-url` placeholder when the URL resolves to the
**page's own origin** and lives **under the defer prefix**, and only accepts
a response whose `Content-Type` is `text/html`. Anything else is skipped
with a console warning. This keeps a placeholder that slipped through an
HTML sanitiser (Markdown, a sanitiser that keeps `data-*` attributes) from
pulling an arbitrary URL — or a same-origin API that echoes user input —
into the DOM via `innerHTML`.

The default prefix `/_defer` is baked into the client. If you call
`setDeferPrefix()`, pass the same value to `renderClientScript()` so the
client accepts your placeholders:

```php
$app->setDeferPrefix('/api/_d');
echo UsePHP::renderClientScript('/usephp.js', '/api/_d');
// emits: window.usePHP.deferPrefix = "/api/_d"
```

If you load `usephp.js` with your own `<script>` tag, set
`window.usePHP = { deferPrefix: '/api/_d' }` before it runs.

### Failed partial submits are not replayed

When a partial (`X-UsePHP-Partial`) POST returns a non-2xx status or the
request throws, `usephp.js` does **not** fall back to a full-page
`form.submit()`: the server may already have processed the action (e.g.
the state change committed and only rendering failed, or the response
timed out), so a replay would run a non-idempotent action twice. Instead
the form dispatches a cancelable, bubbling `usephp:submit-error`
`CustomEvent` (`detail.response` for HTTP failures, `detail.error` for
exceptions). If no listener calls `preventDefault()`, a console warning is
logged and the component wrapper gets a `data-usephp-error` attribute you
can style; the attribute is cleared on the next submit.

```js
document.addEventListener('usephp:submit-error', (e) => {
    e.preventDefault();
    if (e.detail.response?.status === 403) location.reload(); // stale CSRF token
    else showToast('Something went wrong, please try again.');
});
```

### URL-attribute XSS guard

`href`, `src`, `action`, `formaction`, `data`, `poster`, `background`, and `xlink:href` are URL-context attributes. The renderer drops any value whose scheme resolves to `javascript:`, `vbscript:`, or `data:`, judged the way browsers parse URLs: after removing tabs and newlines from the whole value and stripping leading whitespace and control characters. Regular relative or absolute HTTP(S) URLs pass through unchanged.

`srcdoc` is not a URL: it holds a document that runs in the page's origin. Any non-empty value throws a `RuntimeException`. Use `src` with a separate URL.

### Tag and attribute name validation

Prop *keys* and `Element::$type` are written into the markup verbatim (only values are escaped), so the renderer validates them before interpolation. Tag names must match `/^[A-Za-z][A-Za-z0-9\-]*$/` (HTML/SVG elements and custom elements such as `my-widget`); attribute names — after the JSX→HTML mapping — must match `/^[A-Za-z_:][A-Za-z0-9:_.\-]*$/`, which admits `data-*`, `aria-*`, `stroke-width`, `xlink:href`, `xml:lang`, `viewBox` and friends but not quotes, whitespace, `=`, `<` or `>`. Inline event handler attributes (`onclick`, `onerror`, … — anything matching `/^on[a-z]/i` that reaches the renderer) are refused as well; use the `onClick`, `onChange`, `onInput` and `onSubmit` props with an `Action`, which compile to the `wire:*` form-action path. A name that fails either check throws a `RuntimeException` rather than rendering, since it is always a bug or an injection attempt. The `onFocus`, `onBlur`, `onLoad` and `onError` props throw an `InvalidArgumentException` earlier, in `createElement()` (see [Events](#events)).

### PSX compilation cache

`usephp compile` writes generated PHP files into `var/cache/psx/` and `$app->loadComponentManifest()` `require`s them. The cache directory must be **writable only by the build/deployment role, not by HTTP request handlers**. Never call `loadComponentManifest()` with a request-derived path.

### Reporting issues

If you find a security issue, please email the maintainer rather than opening a public issue.

## Requirements

- PHP 8.5+
- The PHP `session` extension

You don't have to call `session_start()`: usePHP starts the session when Session storage or a Session or Shared route needs it, and resumes it from the visitor's cookie for action forms. Sending a cookie has to happen before output, so if visitors can hold a session cookie, render forms before printing anything, or call `session_start()` yourself first. Otherwise a form rendered after output has no CSRF token and its POST gets a 403.

Without a snapshot secret, a page with an action form creates a session and a cookie to hold the signing key. With one, pages using only Snapshot storage stay cookie-free.

## Known Limitations

- **Large lists are expensive to render ([#87](https://github.com/polidog/usePHP/issues/87)).** A setter's value is computed at render time and stored in the form, so a list with its whole array in one `useState` writes that array once per button: HTML size grows with the square of the row count, and about 1,000 rows exceed a 128 MB `memory_limit`. Keep long lists on the server and act on an id.
- **With two tabs, the last write wins ([#80](https://github.com/polidog/usePHP/issues/80)).** For the same reason, each tab sends the value it computed when it rendered. Open the same counter in two tabs, press once in each, and it ends at 1. Changing a list in one tab can undo a change made in another.

## Development

```bash
# Run tests
./vendor/bin/phpunit

# Start example server
php -S localhost:8000 examples/index.php
```

## License

MIT
