# usePHP

A framework that delivers server-driven UI with **minimal JavaScript**, using a React Hooks-like API.

## Features

- **React Hooks-like API** - Simple state management with `useState`
- **Function Components (Recommended)** - Lightweight components using simple PHP callables
- **Built-in Router** - Simple, swappable router with snapshot state preservation across pages
- **Minimal JS (~40 lines)** - Smooth UX with partial updates, graceful fallback without JS
- **Pure PHP by default** - No transpilation needed for `H::xxx()` style components
- **PSX (optional)** - TSX-like template syntax compiled by `usephp compile` for HTML-heavy UIs (see [PSX section](#psx-optional-tsx-like-syntax))
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

## PSX (optional, TSX-like syntax)

PSX is an opt-in alternative way to author components: you write HTML directly and `usephp compile` lowers it to the same `H::xxx()` calls shown above. The runtime is unchanged — PSX is purely a syntax layer.

### When to use it

- Components with deeply nested HTML where `H::div(children: [...])` becomes hard to read
- Teams used to JSX/TSX who want familiar template syntax

If your components are simple, sticking with `H::xxx()` is fine — PSX adds a build step, while plain PHP doesn't.

### Syntax at a glance

```php
<?php
// components/Counter.psx
namespace App\Components;

use Polidog\UsePhp\Html\H;
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
        H::div(className: 'counter', children: [
            H::span(children: ['Count: ', $count]),
            H::button(onClick: fn() => $setCount($count + 1), children: '+'),
            H::button(onClick: fn() => $setCount($count - 1), children: '-'),
        ])
    );
}, 'counter', StorageType::Session);
```

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
$router->get('/', function () use ($app) {
    \Polidog\UsePhp\Runtime\RenderContext::beginRender();
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

### Requirements & limitations

- **One component per mode.** Use a base component for inline rendering and a wrapper carrying `Defer` for the deferred endpoint. Don't try to switch modes on the same component at the call site.
- **Defer names are URL-safe and unique.** Pattern: `[A-Za-z0-9_-]+`. Two wrappers attempting to register the same name fail at compile time.
- **Class-based defer targets are supported.** Annotate the class with `#[Defer(name: '...')]`; `register()` auto-wires the endpoint. The class itself decides what `render()` produces in endpoint mode.
- **Params must be scalar.** They travel through the URL query string, so `int`/`string`/`float`/`bool` only (bools are coerced to `'1'/'0'`). Arrays, Elements, Closures, and resources are rejected at render time.
- **Authorization is the component's responsibility.** The name and params are visible in the URL — for endpoints that surface sensitive data, check session/permissions inside the component. (HMAC signing is no longer needed; the name is a public entry-point identifier.)
- **Nested defer works.** A deferred component's output may itself contain `<...Deferred />` placeholders; `usephp.js` recursively hydrates them.
- **Per-page defer cache.** `usephp.js` keeps an in-memory `Map<URL, DocumentFragment>` for the lifetime of the page. Partial updates that re-emit the same defer placeholder reuse the cached fragment instead of re-fetching. Cache is keyed by URL, so changing params produces a new entry; a full page reload clears it. The cache is bounded at 64 entries with simple LRU eviction so list-style pages that defer per-row fragments (`<CommentDeferred id={$id} />` × N) cannot grow it without limit.
- **No-JS users see the fallback only.** This is the documented trade-off: the deferred component never renders without JavaScript.
- **Framework integration:** call `UsePHP::handleDeferred()` from your controller; it returns the rendered HTML for `GET /_defer/...` requests, or `null` otherwise. Mirrors `handleAction()`. The prefix is configurable via `setDeferPrefix('/api/_d')`.

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
./vendor/bin/usephp publish               # Copy usephp.js to public/
./vendor/bin/usephp compile components/   # Compile .psx files (see PSX section)
./vendor/bin/usephp help                  # Show help
```

## Security

usePHP ships with several built-in defenses. The notes below cover the parts an application author still has to wire up correctly.

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

### CSRF protection

`UsePHP::run()` and `UsePHP::handleAction()` enforce two layers on every POST:

1. **Origin / Referer same-origin check** (always). A request with neither header, or with an origin that doesn't match the current `Host`, is rejected with `403 Forbidden`.
2. **Session-bound synchronizer token** (when a session is active). `Renderer::renderWithForm` embeds a per-session token as `<input type="hidden" name="_usephp_csrf" value="...">`; `doHandleAction` validates it with `hash_equals`.

The token is exposed via `UsePHP::getCsrfToken()` if you render forms yourself.

If the surrounding framework (Laravel `VerifyCsrfToken`, Symfony's CSRF component, etc.) already enforces CSRF on POST handlers, opt out of usePHP's check so the two layers don't double-validate:

```php
$app = new UsePHP();
$app->disableCsrfProtection();
```

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

`UsePHP::redirect($url)` and `SimpleRouter::createRedirectUrl()` reject absolute URLs (`https://...`), protocol-relative URLs (`//host/path`), and scheme-prefixed paths (`javascript:...`). Pass only same-origin paths starting with `/`. If you need to redirect off-site, write the `Location` header yourself after running the target through your own allow-list.

### URL-attribute XSS guard

`href`, `src`, `action`, `formaction`, `srcdoc`, `data`, `poster`, `background`, and `xlink:href` are URL-context attributes. The renderer drops any value whose scheme resolves to `javascript:`, `vbscript:`, or `data:` (after stripping leading whitespace and control characters, the way browsers parse URLs). Regular relative or absolute HTTP(S) URLs pass through unchanged.

### PSX compilation cache

`usephp compile` writes generated PHP files into `var/cache/psx/` and `UsePHP::loadComponentManifest()` `require`s them. The cache directory must be **writable only by the build/deployment role, not by HTTP request handlers**. Never call `loadComponentManifest()` with a request-derived path.

### Reporting issues

If you find a security issue, please email the maintainer rather than opening a public issue.

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
