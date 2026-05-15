<?php

declare(strict_types=1);

namespace Polidog\UsePhp;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Router\NullRouter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\RouteMatch;
use Polidog\UsePhp\Router\RouterInterface;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Router\SnapshotBehavior;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Main application class for usePHP.
 * Minimal JS for partial updates, falls back to full page reload.
 */
final class UsePHP
{
    /**
     * Regex pattern (without delimiters) that a deferred component name must
     * match. Names appear in URLs as a path segment, so we restrict to a
     * conservative set of URL-safe characters. Shared by the producer
     * (`registerDeferred`, `Renderer::renderDeferred`) and the consumer
     * (`doHandleDeferred`) so the two ends cannot drift apart.
     */
    public const DEFER_NAME_PATTERN = '[A-Za-z0-9_-]+';

    public static function isValidDeferName(string $name): bool
    {
        return \preg_match('/^' . self::DEFER_NAME_PATTERN . '$/', $name) === 1;
    }

    private ComponentRegistry $registry;
    private ?SnapshotSerializer $snapshotSerializer = null;
    private ?RouterInterface $router = null;
    private ?RouteMatch $currentMatch = null;

    /** @var array<string, string> FQCN => path to .psx.php file */
    private array $psxManifest = [];

    /** @var array<string, callable> FQCN => loaded callable */
    private array $psxLoaded = [];

    /** @var array<string, array{component: string, cacheControl: ?string}> name => deferred registration */
    private array $deferredRegistry = [];

    private string $deferPrefix = Renderer::DEFAULT_DEFER_PREFIX;

    /**
     * Optional header sink used by tests to capture headers without depending
     * on xdebug. In production this stays null and we go through `\header()`.
     *
     * @var \Closure(string): void|null
     */
    private ?\Closure $headerEmitter = null;

    public function __construct()
    {
        $this->registry = new ComponentRegistry();
    }

    /**
     * Register a component class.
     *
     * @param class-string<ComponentInterface> $className
     */
    public function register(string $className): self
    {
        $this->registry->register($className);
        return $this;
    }

    /**
     * Load a PSX component manifest. The manifest is a PHP file that returns
     * an array mapping FQCN to compiled .psx.php file paths.
     */
    public function loadComponentManifest(string $path): self
    {
        if (!\file_exists($path)) {
            throw new \RuntimeException("PSX manifest not found: $path");
        }
        $manifest = require $path;
        if (!\is_array($manifest)) {
            throw new \RuntimeException("PSX manifest must return an array: $path");
        }
        foreach ($manifest as $fqcn => $filePath) {
            $this->psxManifest[(string) $fqcn] = (string) $filePath;
        }
        return $this;
    }

    /**
     * Register a callable component under an FQCN. Used as a bridge for
     * variable-based fc() components defined outside .psx files.
     */
    public function registerComponent(string $fqcn, callable $component): self
    {
        $this->psxLoaded[$fqcn] = $component;
        return $this;
    }

    /**
     * Register a deferred component under a URL-safe name.
     *
     * The name becomes the path segment under the defer prefix (default
     * `/_defer`), so `<X defer="user-header" />` resolves to
     * `GET /_defer/user-header`. Each registration can carry its own
     * Cache-Control header so per-component CDN caching becomes possible
     * (e.g. `private, no-store` for session-coupled fragments, `public,
     * s-maxage=60` for shared ones).
     *
     * @param string $name URL-safe registration name (`[A-Za-z0-9_-]+`).
     * @param string $component FQCN of a PSX component or one registered
     *        via registerComponent().
     * @param string|null $cacheControl Optional Cache-Control header. When
     *        omitted, defaults to `private, max-age=0` so per-user fragments
     *        do not leak through shared caches by accident.
     */
    public function registerDeferred(
        string $name,
        string $component,
        ?string $cacheControl = null,
    ): self {
        if (!self::isValidDeferName($name)) {
            throw new \InvalidArgumentException(
                'Deferred component name must match `' . self::DEFER_NAME_PATTERN . "`, got: '$name'",
            );
        }
        if (isset($this->deferredRegistry[$name])) {
            $existing = $this->deferredRegistry[$name]['component'];
            throw new \InvalidArgumentException(
                "Deferred name '$name' is already registered (component: $existing). "
                . 'Deferred names are part of the public URL surface and must be unique — '
                . 'reusing a name with a different component or cacheControl is almost '
                . 'always a mistake. Pick a distinct name.',
            );
        }
        $this->deferredRegistry[$name] = [
            'component' => $component,
            'cacheControl' => $cacheControl,
        ];
        return $this;
    }

    /**
     * Set the URL prefix under which deferred component endpoints are served.
     * Default is `/_defer`. Pass without trailing slash.
     */
    public function setDeferPrefix(string $prefix): self
    {
        $prefix = '/' . \trim($prefix, '/');
        if ($prefix === '/') {
            throw new \InvalidArgumentException('Defer prefix must not be empty or root.');
        }
        $this->deferPrefix = $prefix;
        return $this;
    }

    public function getDeferPrefix(): string
    {
        return $this->deferPrefix;
    }

    /**
     * Test-only seam: redirect outgoing headers from `\header()` to the given
     * closure so tests can assert on `Cache-Control` etc. without xdebug.
     * Pass `null` to restore the default `\header()` behavior.
     *
     * @internal Not part of the public API; subject to change without notice.
     *
     * @param \Closure(string): void|null $emitter
     */
    public function withHeaderEmitter(?\Closure $emitter): self
    {
        $this->headerEmitter = $emitter;
        return $this;
    }

    private function emitHeader(string $header): void
    {
        if ($this->headerEmitter !== null) {
            ($this->headerEmitter)($header);
            return;
        }
        \header($header);
    }

    /**
     * Register a global exception handler that prints stack traces with
     * `.psx.php` file paths rewritten to their original `.psx` source paths.
     * Combined with line-preserving compilation, errors look like they came
     * from the .psx source.
     *
     * NOTE: this REPLACES the active exception handler (set_exception_handler
     * semantics). If you have an existing reporter such as Sentry/Bugsnag/
     * Whoops installed, calling this method silently disables it. Either:
     *  - call this BEFORE installing your reporter so the reporter wins, or
     *  - capture the return value and chain manually inside your reporter, or
     *  - use `StackTraceRewriter::formatException($e)` directly inside your
     *    own handler.
     *
     * Returns the previous exception handler (if any).
     */
    public function installPsxErrorHandler(): ?callable
    {
        return \set_exception_handler(static function (\Throwable $e): void {
            $message = \Polidog\UsePhp\Psx\StackTraceRewriter::formatException($e) . "\n";
            // STDERR is only defined in CLI/phpdbg; opening php://stderr works
            // in any SAPI (and falls back to error_log on the rare case the
            // stream can't be opened — e.g., bizarre stream-wrapper config).
            $stderr = \fopen('php://stderr', 'wb');
            if ($stderr !== false) {
                \fwrite($stderr, $message);
                \fclose($stderr);
                return;
            }
            \error_log($message);
        });
    }

    /**
     * Invoke a PSX component by FQCN. Compiled PSX tags <Counter />
     * lower to a call to this method.
     *
     * @param array<string, mixed> $props
     */
    public function renderPsxComponent(string $fqcn, array $props = []): Element
    {
        if (!isset($this->psxLoaded[$fqcn])) {
            if (!isset($this->psxManifest[$fqcn])) {
                throw new \RuntimeException("PSX component not registered: $fqcn");
            }
            $compiledPath = $this->psxManifest[$fqcn];
            if (!\is_file($compiledPath) || !\is_readable($compiledPath)) {
                throw new \RuntimeException(
                    "Compiled PSX file not found for $fqcn: $compiledPath. "
                    . 'Run `vendor/bin/usephp compile` to regenerate.'
                );
            }
            try {
                $callable = require $compiledPath;
            } catch (\ParseError $e) {
                throw new \RuntimeException(
                    "Compiled PSX file is invalid PHP: $compiledPath. "
                    . 'Run `vendor/bin/usephp compile` to regenerate. ('
                    . $e->getMessage() . ')',
                    0,
                    $e,
                );
            }
            if (!\is_callable($callable)) {
                throw new \RuntimeException("PSX file did not return a callable: $compiledPath");
            }
            $this->psxLoaded[$fqcn] = $callable;
        }

        return ($this->psxLoaded[$fqcn])($props);
    }

    /**
     * Render a component and return HTML.
     *
     * @param string $componentName The registered component name
     * @param string|null $key Optional explicit key for the component instance
     */
    public function render(string $componentName, ?string $key = null): string
    {
        return $this->doRenderComponent($componentName, $key);
    }

    /**
     * Create a component Element (without rendering to HTML).
     *
     * Use this when you want to compose multiple components using H class,
     * then render the entire tree with renderElement().
     *
     * @param string $componentName The registered component name
     * @param string|null $key Optional explicit key for the component instance
     */
    public function createElement(string $componentName, ?string $key = null): Element
    {
        return $this->doCreateElement($componentName, $key);
    }

    /**
     * Render an Element tree to HTML.
     *
     * Use this to render Element trees created with createElement() and H class.
     */
    public function renderElement(Element $element): string
    {
        return $this->doRenderElement($element);
    }

    /**
     * Configure the snapshot serializer with a secret key.
     *
     * @param string $secretKey The secret key for snapshot verification
     */
    public function setSnapshotSecret(string $secretKey): self
    {
        $this->snapshotSerializer = new SnapshotSerializer($secretKey);
        return $this;
    }

    /**
     * Get the snapshot serializer.
     */
    public function getSnapshotSerializer(): SnapshotSerializer
    {
        if ($this->snapshotSerializer === null) {
            $this->snapshotSerializer = new SnapshotSerializer();
        }
        return $this->snapshotSerializer;
    }

    /**
     * Set a custom router.
     */
    public function setRouter(RouterInterface $router): self
    {
        $this->router = $router;
        return $this;
    }

    /**
     * Get the current router, creating a SimpleRouter if none set.
     */
    public function getRouter(): RouterInterface
    {
        if ($this->router === null) {
            $this->router = new SimpleRouter($this->getSnapshotSerializer());
        }
        return $this->router;
    }

    /**
     * Disable routing (use NullRouter).
     * Use this when integrating with frameworks like Laravel or Symfony.
     */
    public function disableRouter(): self
    {
        $this->router = new NullRouter();
        return $this;
    }

    /**
     * Get the current route match.
     */
    public function getCurrentMatch(): ?RouteMatch
    {
        return $this->currentMatch;
    }

    /**
     * Run the router and render the matched component.
     *
     * This is the main entry point for standalone usePHP applications.
     *
     * @param RequestContext|null $request Optional request context (defaults to fromGlobals)
     */
    public function run(?RequestContext $request = null): void
    {
        RenderContext::setApp($this);

        try {
            $request ??= RequestContext::fromGlobals();

            // Handle POST actions first
            if ($request->isPost() && isset($_POST['_usephp_action'])) {
                $html = $this->doHandleAction();
                echo $html;
                return;
            }

            // Handle deferred component requests (GET /_defer/{name})
            if ($this->matchesDeferRoute($request)) {
                $html = $this->doHandleDeferred($request);
                echo $html;
                return;
            }

            $router = $this->getRouter();
            $match = $router->match($request);

            if ($match === null) {
                http_response_code(404);
                echo '404 Not Found';
                return;
            }

            $this->currentMatch = $match;

            // Handle snapshot restoration for persistent/session behaviors
            $this->handleSnapshotRestoration($request, $match);

            // Render the component
            $handler = $match->handler;
            $html = '';

            if (is_string($handler) && class_exists($handler)) {
                // Component class
                $html = $this->render($handler);
            } elseif (is_callable($handler)) {
                // Callable handler
                $result = $handler($match->params, $request);
                if ($result instanceof Element) {
                    $html = $this->renderElement($result);
                } else {
                    $html = (string) $result;
                }
            }

            echo $html;
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Handle snapshot restoration based on route behavior.
     */
    private function handleSnapshotRestoration(RequestContext $request, RouteMatch $match): void
    {
        $router = $this->router ?? new NullRouter();
        $snapshotData = $router->extractSnapshot($request);

        if ($snapshotData === null) {
            return;
        }

        switch ($match->snapshotBehavior) {
            case SnapshotBehavior::Persistent:
                // Restore snapshot from URL
                try {
                    $snapshot = $this->getSnapshotSerializer()->deserialize($snapshotData);
                    ComponentState::fromSnapshot($snapshot);
                } catch (SnapshotVerificationException $e) {
                    // Invalid snapshot, ignore
                }
                break;

            case SnapshotBehavior::Session:
                // Store snapshot in session for later use
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['_usephp_snapshot'] = $snapshotData;
                }
                break;

            case SnapshotBehavior::Shared:
                // Restore from session if in same group
                if ($match->sharedGroup !== null && session_status() === PHP_SESSION_ACTIVE) {
                    $sessionKey = '_usephp_shared_' . $match->sharedGroup;
                    if (isset($_SESSION[$sessionKey])) {
                        try {
                            $snapshot = $this->getSnapshotSerializer()->deserialize($_SESSION[$sessionKey]);
                            ComponentState::fromSnapshot($snapshot);
                        } catch (SnapshotVerificationException $e) {
                            // Invalid snapshot, ignore
                        }
                    }
                }
                break;

            case SnapshotBehavior::Isolated:
            default:
                // No restoration for isolated pages
                break;
        }
    }

    /**
     * Redirect to a URL with optional snapshot preservation.
     *
     * @param string $url The URL to redirect to
     * @param Snapshot|null $snapshot Optional snapshot to pass
     */
    public function redirect(string $url, ?Snapshot $snapshot = null): never
    {
        $router = $this->getRouter();

        if ($snapshot !== null && $this->currentMatch?->snapshotBehavior === SnapshotBehavior::Persistent) {
            $url = $router->createRedirectUrl($url, $snapshot);
        }

        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * Handle a POST action and return the partial HTML.
     * Returns null if not a valid action request.
     */
    public function handleAction(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['_usephp_action'])) {
            return null;
        }

        RenderContext::setApp($this);

        try {
            return $this->doHandleAction();
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Handle a deferred component fetch and return the rendered HTML.
     * Returns null if the request is not a defer route (caller should
     * continue with normal routing).
     *
     * Use this from inside a framework integration so the defer endpoint
     * works without going through UsePHP::run().
     */
    public function handleDeferred(?RequestContext $request = null): ?string
    {
        $request ??= RequestContext::fromGlobals();
        if (!$this->matchesDeferRoute($request)) {
            return null;
        }

        RenderContext::setApp($this);

        try {
            return $this->doHandleDeferred($request);
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Does this request target a deferred component endpoint?
     */
    private function matchesDeferRoute(RequestContext $request): bool
    {
        if ($request->method !== 'GET') {
            return false;
        }
        return $request->path === $this->deferPrefix
            || \str_starts_with($request->path, $this->deferPrefix . '/');
    }

    /**
     * Render a deferred component request.
     */
    private function doHandleDeferred(RequestContext $request): string
    {
        $prefixWithSlash = $this->deferPrefix . '/';
        if (!\str_starts_with($request->path, $prefixWithSlash)) {
            return $this->respondNotFound('Not Found');
        }
        $name = \rawurldecode(\substr($request->path, \strlen($prefixWithSlash)));
        if (!self::isValidDeferName($name)) {
            return $this->respondNotFound('Not Found');
        }

        $registration = $this->deferredRegistry[$name] ?? null;
        if ($registration === null) {
            return $this->respondNotFound(
                'Deferred component not registered: ' . \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8'),
            );
        }

        // Query parameters become component props. If a client passes an
        // array form (e.g. `?post_id[]=1`), we reject it as 400 rather than
        // silently dropping it — otherwise the component renders with the
        // key missing and the failure mode is invisible. This also mirrors
        // the parent-side check in Renderer::renderDeferred, where non-scalar
        // params throw at render time.
        /** @var array<string, mixed> $props */
        $props = [];
        foreach ($request->query as $key => $value) {
            if (!\is_scalar($value)) {
                \http_response_code(400);
                $this->emitHeader('Cache-Control: no-store');
                return 'Deferred component param "' . \htmlspecialchars((string) $key, \ENT_QUOTES, 'UTF-8')
                    . '" must be a scalar; arrays and other types are not supported.';
            }
            $props[$key] = $value;
        }

        $this->emitHeader('Cache-Control: ' . ($registration['cacheControl'] ?? 'private, max-age=0'));

        // Reset render context state — this is a fresh sub-render, not a
        // continuation of any page-level render pass.
        RenderContext::beginRender();

        try {
            $element = $this->renderPsxComponent($registration['component'], $props);
        } catch (\Throwable $e) {
            // Surface details to the operator via error_log, but return a
            // generic message to the client — exception messages frequently
            // expose FQCNs, file paths, or internal state we don't want
            // visible on a public endpoint.
            \error_log(\sprintf(
                "[usePHP] defer render failed for name '%s' (component %s): %s\n%s",
                $name,
                $registration['component'],
                $e->getMessage(),
                $e->getTraceAsString(),
            ));
            \http_response_code(500);
            $this->emitHeader('Cache-Control: no-store');
            return 'Failed to render deferred component.';
        }

        return $this->renderElement($element);
    }

    /**
     * Emit a 404 with a `no-store` Cache-Control so a CDN's default policy
     * cannot pin the negative result against a later valid registration.
     */
    private function respondNotFound(string $body): string
    {
        \http_response_code(404);
        $this->emitHeader('Cache-Control: no-store');
        return $body;
    }

    /**
     * Handle form action submission.
     */
    private function doHandleAction(): string
    {
        $instanceId = $_POST['_usephp_component'] ?? null;
        $actionJson = $_POST['_usephp_action'] ?? null;
        $snapshotJson = $_POST['_usephp_snapshot'] ?? null;
        $isPartial = isset($_SERVER['HTTP_X_USEPHP_PARTIAL']);

        if ($instanceId === null || $actionJson === null) {
            http_response_code(400);
            return 'Invalid action request';
        }

        // Parse action first to get storageType
        try {
            $actionData = json_decode($actionJson, true, 512, JSON_THROW_ON_ERROR);
            $action = Action::fromArray($actionData);
        } catch (\JsonException $e) {
            http_response_code(400);
            return 'Invalid action data';
        }

        // Extract component name from instanceId (e.g., "Counter#0" => "Counter")
        $componentName = explode('#', $instanceId)[0];

        // Check if this is a registered class-based component
        $isRegisteredComponent = $this->registry->has($componentName);

        // Determine storage type: use action's storageType if available (for function components),
        // otherwise use registry for class components, default to Session
        $storageType = $action->storageType
            ?? ($isRegisteredComponent ? $this->registry->getStorageType($componentName) : StorageType::Session);

        // Handle snapshot storage - restore state from snapshot
        try {
            if ($storageType === StorageType::Snapshot && $snapshotJson !== null) {
                $snapshot = $this->getSnapshotSerializer()->deserialize($snapshotJson);
                $state = ComponentState::fromSnapshot($snapshot);
            } else {
                // Use instanceId for state to match the correct component instance
                $state = ComponentState::getInstance($instanceId, $storageType);
            }
        } catch (SnapshotVerificationException $e) {
            http_response_code(400);
            return 'Invalid snapshot';
        }

        // Execute the action
        if ($action->type === 'setState') {
            $index = $action->payload['index'] ?? 0;
            $value = $action->payload['value'] ?? null;
            $state->setState($index, $value);
        }

        // Partial update (AJAX) - return only component HTML
        if ($isPartial && $isRegisteredComponent) {
            return $this->doRenderComponentPartialWithInstanceId($instanceId, $componentName);
        }

        // Full page - PRG pattern with snapshot behavior handling
        $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

        // Handle snapshot preservation based on route behavior
        if ($this->currentMatch !== null && $storageType === StorageType::Snapshot) {
            $snapshot = $state->createSnapshot();

            switch ($this->currentMatch->snapshotBehavior) {
                case SnapshotBehavior::Persistent:
                    // Pass snapshot in URL
                    $router = $this->router ?? new NullRouter();
                    $redirectUrl = $router->createRedirectUrl((string) $redirectUrl, $snapshot);
                    break;

                case SnapshotBehavior::Session:
                    // Store snapshot in session
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $serialized = $this->getSnapshotSerializer()->serialize($snapshot);
                        $_SESSION['_usephp_snapshot'] = $serialized;
                    }
                    break;

                case SnapshotBehavior::Shared:
                    // Store in shared group session
                    if ($this->currentMatch->sharedGroup !== null && session_status() === PHP_SESSION_ACTIVE) {
                        $sessionKey = '_usephp_shared_' . $this->currentMatch->sharedGroup;
                        $serialized = $this->getSnapshotSerializer()->serialize($snapshot);
                        $_SESSION[$sessionKey] = $serialized;
                    }
                    break;

                case SnapshotBehavior::Isolated:
                default:
                    // No preservation for isolated pages
                    break;
            }
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    /**
     * Create a component Element with wrapper.
     */
    private function doCreateElement(string $componentName, ?string $key = null): Element
    {
        RenderContext::setApp($this);

        try {
            $component = $this->registry->create($componentName);

            if ($component === null) {
                return new Element('div', [], []);
            }

            $instanceId = RenderContext::nextInstanceId($componentName, $key);
            $storageType = $this->registry->getStorageType($componentName);
            $state = ComponentState::getInstance($instanceId, $storageType);
            ComponentState::reset();

            if ($component instanceof BaseComponent) {
                $component->setComponentState($state);
            }

            // Get the element from component
            $innerElement = $component->render();

            // Build wrapper props
            $props = ['data-usephp' => $instanceId];

            // Add snapshot if using snapshot storage
            if ($storageType === StorageType::Snapshot) {
                $snapshot = $state->createSnapshot();
                $snapshotJson = $this->getSnapshotSerializer()->serialize($snapshot);
                $props['data-usephp-snapshot'] = $snapshotJson;
            }

            return new Element('div', $props, [$innerElement]);
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Render an Element tree to HTML.
     */
    private function doRenderElement(Element $element): string
    {
        RenderContext::setApp($this);

        try {
            $renderer = new Renderer(
                '_root_',
                $this->getSnapshotSerializer(),
                deferPrefix: $this->deferPrefix,
            );
            return $renderer->renderElement($element);
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Render a component with wrapper.
     */
    private function doRenderComponent(string $componentName, ?string $key = null): string
    {
        RenderContext::setApp($this);

        try {
            $component = $this->registry->create($componentName);

            if ($component === null) {
                return '';
            }

            // Start a new render pass
            RenderContext::beginRender();

            $instanceId = RenderContext::nextInstanceId($componentName, $key);
            $storageType = $this->registry->getStorageType($componentName);
            $state = ComponentState::getInstance($instanceId, $storageType);
            ComponentState::reset();

            if ($component instanceof BaseComponent) {
                $component->setComponentState($state);
            }

            $renderer = new Renderer(
                $instanceId,
                $this->getSnapshotSerializer(),
                $storageType,
                $this->deferPrefix,
            );

            return $renderer->render(fn() => $component->render());
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Render a component partial with a specific instance ID (for form action handling).
     */
    private function doRenderComponentPartialWithInstanceId(string $instanceId, string $componentName): string
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return '';
        }

        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer(
            $instanceId,
            $this->getSnapshotSerializer(),
            $storageType,
            $this->deferPrefix,
        );

        return $renderer->renderPartial(fn() => $component->render());
    }

    /**
     * Get the component registry.
     */
    public function getRegistry(): ComponentRegistry
    {
        return $this->registry;
    }
}
