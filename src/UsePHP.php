<?php

declare(strict_types=1);

namespace Polidog\UsePhp;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Component\Defer;
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
     * True while {@see doHandleDeferred()} is rendering an endpoint response.
     * Read by {@see \Polidog\UsePhp\Runtime\FunctionComponent} so the wrapper
     * skips its placeholder branch and renders the inner component inline.
     * Class-based defer targets don't need this flag — they're routed through
     * the dedicated class-component path inside {@see renderDeferredTarget()}
     * only on the endpoint side, so there is no placeholder branch to skip.
     */
    private bool $renderingDeferredEndpoint = false;

    /**
     * Whether the built-in CSRF check runs in {@see doHandleAction()}.
     *
     * Defaults to true so a usePHP-only deployment (without an upstream
     * framework) is protected out of the box. Disable when the host
     * framework already enforces CSRF (e.g. Laravel's VerifyCsrfToken
     * middleware), so the two layers don't double-validate and reject
     * legitimate submissions.
     */
    private bool $csrfProtectionEnabled = true;

    /**
     * When true, the CSRF origin check honors `X-Forwarded-Proto` /
     * `X-Forwarded-Host` (and the older `X-Forwarded-Port`) so the
     * expected origin matches what the browser saw — necessary behind
     * TLS-terminating proxies (nginx, ALB, Cloudflare) that leave the
     * PHP-FPM side speaking plain HTTP.
     *
     * Defaults to false because these headers are trivially spoofable
     * when traffic can reach the app without first traversing the
     * proxy. Operators must guarantee that the proxy strips or
     * overwrites them before enabling this.
     */
    private bool $trustProxyHeaders = false;

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
     * If the class carries a `#[Defer]` attribute, the deferred endpoint is
     * auto-registered too — callers don't need a separate
     * `registerDeferred()` call. A PSX bridge is also installed so
     * `<MyDeferredClass fallback={...} />` resolves to a placeholder on the
     * page-render path; the endpoint-side render is routed through the
     * class-component path in {@see renderDeferredTarget()}.
     *
     * @param class-string<ComponentInterface> $className
     */
    public function register(string $className): self
    {
        $this->registry->register($className);

        $name = $className::getComponentName();
        $defer = $this->registry->getDefer($name);
        if ($defer !== null) {
            $this->registerComponent($className, $this->makeClassDeferPlaceholder($defer));
            $this->autoRegisterDeferred($defer->name, $className, $defer->cacheControl);
        }

        return $this;
    }

    /**
     * Build a PSX-side bridge for a class component with `#[Defer]`.
     *
     * The bridge handles the *page* side: when PSX compiles
     * `<MyDeferredClass fallback={<X />} />` to
     * `renderPsxComponent($fqcn, $props)`, this closure runs and emits the
     * `H::defer(...)` placeholder via {@see Defer::buildPlaceholder()} — the
     * shared materialiser that the closure flow ({@see \Polidog\UsePhp\Runtime\FunctionComponent})
     * also uses, so the two paths cannot drift on validation rules. The
     * endpoint side bypasses this bridge — {@see renderDeferredTarget()}
     * routes class components to {@see doCreateElement()} directly so state
     * and snapshot wrapping line up with how the class would render on the
     * page.
     *
     * @return \Closure(array<string, mixed>): Element
     */
    private function makeClassDeferPlaceholder(Defer $defer): \Closure
    {
        return static fn(array $props): Element => $defer->buildPlaceholder($props);
    }

    /**
     * Load a PSX component manifest. The manifest is a PHP file that returns
     * an array mapping FQCN to compiled .psx.php file paths.
     *
     * If a sibling `deferred-manifest.php` exists in the same directory, its
     * `name => ['component' => FQCN, 'cacheControl' => ...]` entries are
     * auto-registered as deferred endpoints — produced by
     * `usephp compile` for any .psx that returned `fc(..., defer: ...)`.
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

        $deferredPath = \dirname($path) . \DIRECTORY_SEPARATOR
            . \Polidog\UsePhp\Psx\CompileCommand::DEFERRED_MANIFEST_FILENAME;
        if (\file_exists($deferredPath)) {
            $deferred = require $deferredPath;
            if (!\is_array($deferred)) {
                throw new \RuntimeException("PSX deferred manifest must return an array: $deferredPath");
            }
            foreach ($deferred as $name => $entry) {
                if (!\is_array($entry) || !isset($entry['component']) || !\is_string($entry['component'])) {
                    throw new \RuntimeException(
                        "PSX deferred manifest entry for '$name' is malformed in $deferredPath",
                    );
                }
                $cacheControl = $entry['cacheControl'] ?? null;
                if ($cacheControl !== null && !\is_string($cacheControl)) {
                    throw new \RuntimeException(
                        "PSX deferred manifest cacheControl for '$name' must be string|null in $deferredPath",
                    );
                }
                $this->autoRegisterDeferred((string) $name, $entry['component'], $cacheControl);
            }
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
     * Internal counterpart to {@see registerDeferred()} used by the auto-wire
     * paths (`#[Defer]` on classes, the deferred manifest produced by
     * `usephp compile`). Re-registering the *same* component with the *same*
     * cacheControl is a no-op so dev-mode rebuilds (e.g., re-loading the
     * manifest on every request) stay silent — but a conflicting redefine
     * surfaces as an error the same way as a user-driven double-register.
     */
    private function autoRegisterDeferred(
        string $name,
        string $component,
        ?string $cacheControl,
    ): void {
        if (isset($this->deferredRegistry[$name])) {
            $existing = $this->deferredRegistry[$name];
            if ($existing['component'] === $component && $existing['cacheControl'] === $cacheControl) {
                return;
            }
        }
        $this->registerDeferred($name, $component, $cacheControl);
    }

    /**
     * Whether a defer endpoint render is currently in flight. The wrapping
     * `FunctionComponent` (and the class-component path) consults this to
     * decide between emitting a placeholder and actually rendering inline.
     *
     * @internal Not part of the public stable API.
     */
    public function isRenderingDeferredEndpoint(): bool
    {
        return $this->renderingDeferredEndpoint;
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
     * Required before using Snapshot storage. The key must be high-entropy
     * and stable across requests (and across worker processes in production)
     * so HMACs computed by one request are verifiable by the next. Generate
     * one with `bin2hex(random_bytes(32))` and load it from configuration.
     *
     * If a router has already been constructed, its serializer reference is
     * updated so calls made before `setSnapshotSecret()` still see the new
     * key.
     *
     * @param string $secretKey The secret key for snapshot HMAC.
     * @throws \InvalidArgumentException If $secretKey is empty.
     */
    public function setSnapshotSecret(string $secretKey): self
    {
        $this->snapshotSerializer = new SnapshotSerializer($secretKey);
        if ($this->router instanceof SimpleRouter) {
            $this->router->setSerializer($this->snapshotSerializer);
        }
        return $this;
    }

    /**
     * Get the snapshot serializer. Throws if no secret has been configured —
     * snapshots round-trip through the client and must be HMAC-signed, so
     * silently constructing a serializer without a key would let an attacker
     * forge state.
     *
     * @throws \LogicException If `setSnapshotSecret()` was not called.
     */
    public function getSnapshotSerializer(): SnapshotSerializer
    {
        if ($this->snapshotSerializer === null) {
            throw new \LogicException(
                'Snapshot serializer secret is not configured. '
                . 'Call UsePHP::setSnapshotSecret($key) with a high-entropy key '
                . '(e.g. bin2hex(random_bytes(32))) before using Snapshot storage. '
                . 'Snapshots round-trip through the client and must be HMAC-signed '
                . 'to prevent tampering.'
            );
        }
        return $this->snapshotSerializer;
    }

    /**
     * Return the snapshot serializer if configured, or null otherwise.
     *
     * Used for code paths that thread the serializer into helpers that may
     * legitimately operate without one (e.g. the Renderer, when the active
     * component is not using Snapshot storage). Hot paths that actually need
     * to serialize/verify state must call {@see getSnapshotSerializer()}
     * which fails loudly.
     */
    private function tryGetSnapshotSerializer(): ?SnapshotSerializer
    {
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
     *
     * The router is constructed with whatever serializer is configured at
     * this point — possibly `null` if `setSnapshotSecret()` hasn't been
     * called yet. Calling `setSnapshotSecret()` later updates the router's
     * serializer too, so the order doesn't matter for non-snapshot routes.
     */
    public function getRouter(): RouterInterface
    {
        if ($this->router === null) {
            $this->router = new SimpleRouter($this->tryGetSnapshotSerializer());
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
     * Disable the built-in CSRF check. Use when an upstream framework
     * (Laravel, Symfony, ...) already enforces CSRF on POST handlers —
     * leaving usePHP's check enabled would either double-validate (rejecting
     * legitimate submissions) or hide the framework's failure mode.
     *
     * Disabling here does NOT remove the hidden `_usephp_csrf` field from
     * rendered forms; that field is harmless when the server doesn't read it.
     */
    public function disableCsrfProtection(): self
    {
        $this->csrfProtectionEnabled = false;
        return $this;
    }

    /**
     * Whether built-in CSRF protection is active.
     */
    public function isCsrfProtectionEnabled(): bool
    {
        return $this->csrfProtectionEnabled;
    }

    /**
     * Honor `X-Forwarded-Proto` / `X-Forwarded-Host` / `X-Forwarded-Port`
     * when computing the expected origin for the CSRF check.
     *
     * ENABLE THIS ONLY when every request reaches PHP through a proxy
     * that you control and that strips or overwrites these headers from
     * the client side. Otherwise an attacker can spoof the headers and
     * defeat the same-origin check.
     */
    public function trustProxyHeaders(bool $trust = true): self
    {
        $this->trustProxyHeaders = $trust;
        return $this;
    }

    /**
     * Return (and lazily generate) the per-session CSRF token used to
     * cross-check the hidden form field in {@see doHandleAction()}.
     *
     * When no session is active the token is empty — `verifyCsrf()` then
     * falls back to Origin/Referer alone, which is still sufficient against
     * cross-site form submissions from a modern browser. Sessions add a
     * second, state-bound layer.
     */
    public function getCsrfToken(): string
    {
        if (!$this->csrfProtectionEnabled) {
            return '';
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $existing = $_SESSION['_usephp_csrf'] ?? null;
        if (!is_string($existing) || $existing === '') {
            $existing = bin2hex(random_bytes(32));
            $_SESSION['_usephp_csrf'] = $existing;
        }
        return $existing;
    }

    /**
     * Verify the current POST request against CSRF rules. Returns null when
     * the request is acceptable, or a short reason string when it should be
     * rejected. Combines two layers:
     *
     *  1. Origin/Referer same-origin check — primary defense, no state
     *     required, works for both Session and Snapshot storage.
     *  2. Session-bound synchronizer token — additional defense, only
     *     enforced when a session is active (so the snapshot-only mode
     *     doesn't need a session just to submit a form).
     *
     * The check is intentionally strict on the source-origin side: a
     * request with neither Origin nor Referer is rejected, because modern
     * browsers always send at least one on POST. CLI / test code that
     * bypasses this should disable CSRF explicitly.
     */
    private function verifyCsrf(): ?string
    {
        if (!$this->csrfProtectionEnabled) {
            return null;
        }

        $expectedOrigin = $this->computeExpectedOrigin();
        if ($expectedOrigin === null) {
            return 'CSRF check failed: cannot determine expected origin (missing Host header)';
        }

        $sourceOrigin = $this->extractSourceOrigin();
        if ($sourceOrigin === null) {
            return 'CSRF check failed: request has no Origin or Referer header';
        }
        if (!hash_equals($expectedOrigin, $sourceOrigin)) {
            return 'CSRF check failed: source origin does not match this host';
        }

        // Session-bound token layer — only enforced when a session is active.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionToken = $_SESSION['_usephp_csrf'] ?? null;
            $postToken = $_POST['_usephp_csrf'] ?? null;
            if (!is_string($sessionToken) || $sessionToken === ''
                || !is_string($postToken) || !hash_equals($sessionToken, $postToken)) {
                return 'CSRF check failed: token missing or mismatched';
            }
        }

        return null;
    }

    private function computeExpectedOrigin(): ?string
    {
        $host = $this->resolveTrustedHeader('HTTP_X_FORWARDED_HOST', 'HTTP_HOST');
        if ($host === null) {
            return null;
        }

        $scheme = $this->resolveExpectedScheme();
        $origin = $scheme . '://' . $host;

        // Some proxies pass the public port via X-Forwarded-Port instead of
        // appending it to X-Forwarded-Host. Honor it only when the host
        // doesn't already carry a port and the port is non-default for the
        // chosen scheme.
        if ($this->trustProxyHeaders && !str_contains($host, ':')) {
            /** @var mixed $portRaw */
            $portRaw = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? null;
            if (is_string($portRaw) && $portRaw !== ''
                && !(($scheme === 'https' && $portRaw === '443')
                  || ($scheme === 'http'  && $portRaw === '80'))) {
                $origin .= ':' . $portRaw;
            }
        }

        return $origin;
    }

    /**
     * Compute the expected URL scheme. Falls back to plain HTTP unless we
     * see a TLS marker — either `$_SERVER['HTTPS']` set directly (mod_php,
     * fastcgi_param HTTPS on) or, when proxy headers are trusted,
     * `X-Forwarded-Proto: https`.
     */
    private function resolveExpectedScheme(): string
    {
        if ($this->trustProxyHeaders) {
            /** @var mixed $proto */
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
            if (is_string($proto) && $proto !== '') {
                return strtolower(trim($proto)) === 'https' ? 'https' : 'http';
            }
        }
        /** @var mixed $httpsRaw */
        $httpsRaw = $_SERVER['HTTPS'] ?? '';
        if (is_string($httpsRaw) && $httpsRaw !== '' && strtolower($httpsRaw) !== 'off') {
            return 'https';
        }
        return 'http';
    }

    /**
     * Read `$forwardedKey` when proxy headers are trusted, otherwise
     * `$directKey`. Returns null when neither yields a non-empty string.
     * Only the first comma-separated value of an X-Forwarded-* header is
     * used — that's the originating client's value as set by the closest
     * trusted proxy.
     */
    private function resolveTrustedHeader(string $forwardedKey, string $directKey): ?string
    {
        if ($this->trustProxyHeaders) {
            /** @var mixed $forwarded */
            $forwarded = $_SERVER[$forwardedKey] ?? null;
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        /** @var mixed $direct */
        $direct = $_SERVER[$directKey] ?? null;
        if (is_string($direct) && strlen($direct) !== 0) {
            return $direct;
        }
        return null;
    }

    private function extractSourceOrigin(): ?string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '' && $origin !== 'null') {
            return $origin;
        }
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        if (!is_string($referer) || $referer === '') {
            return null;
        }
        $parsed = parse_url($referer);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $result = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $result .= ':' . $parsed['port'];
        }
        return $result;
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
                    $this->logSnapshotRejection('Persistent', $e);
                }
                break;

            case SnapshotBehavior::Session:
                // Verify the snapshot's HMAC *before* storing it in the
                // session. The data here was extracted from the request
                // (query string / etc.), so it is attacker-controlled — if
                // we wrote it verbatim, a later restoration would either
                // trust it without a re-check or pollute the session with
                // arbitrary attacker text. Re-serialize from the verified
                // Snapshot object so the bytes in $_SESSION are guaranteed
                // to have come out of our own serializer.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    try {
                        $snapshot = $this->getSnapshotSerializer()->deserialize($snapshotData);
                        $_SESSION['_usephp_snapshot'] = $this->getSnapshotSerializer()->serialize($snapshot);
                    } catch (SnapshotVerificationException $e) {
                        $this->logSnapshotRejection('Session', $e);
                    }
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
                            $this->logSnapshotRejection('Shared', $e);
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
     * Surface a snapshot HMAC rejection to error_log so operators can tell
     * the difference between a key-rotation event, an attacker probe, and
     * a bug in their own code. The exception message itself is generic so
     * we include the snapshot behavior tag to make the entry searchable.
     */
    private function logSnapshotRejection(string $behavior, SnapshotVerificationException $e): void
    {
        \error_log(sprintf(
            '[usePHP] Snapshot rejected (behavior=%s): %s',
            $behavior,
            $e->getMessage(),
        ));
    }

    /**
     * Redirect to a same-origin URL with optional snapshot preservation.
     *
     * Only same-origin paths are allowed. Absolute URLs (`https://...`) and
     * protocol-relative URLs (`//example.com/...`) are rejected to prevent
     * this method from being chained into an open-redirect primitive — a
     * caller that needs to redirect off-site should write the `Location`
     * header itself, after running the value through its own allow-list.
     *
     * @param string $url Same-origin path (must start with `/`).
     * @param Snapshot|null $snapshot Optional snapshot to encode into the URL
     *        when the current route uses {@see SnapshotBehavior::Persistent}.
     * @throws \InvalidArgumentException If $url is not same-origin.
     */
    public function redirect(string $url, ?Snapshot $snapshot = null): never
    {
        self::assertSameOriginPath($url);

        $router = $this->getRouter();

        if ($snapshot !== null && $this->currentMatch?->snapshotBehavior === SnapshotBehavior::Persistent) {
            $url = $router->createRedirectUrl($url, $snapshot);
        }

        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * Reject URLs that would let `redirect()` jump off-site. Mirrors the
     * rules used by {@see SimpleRouter::createRedirectUrl()} so the two
     * code paths can't drift.
     */
    private static function assertSameOriginPath(string $url): void
    {
        if (str_starts_with($url, '//')) {
            throw new \InvalidArgumentException(
                'Redirect URL must be same-origin (a path beginning with "/" and not "//"). '
                . 'Got: ' . $url
            );
        }
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            throw new \InvalidArgumentException('Redirect URL is malformed: ' . $url);
        }
        if (isset($parsed['scheme']) || isset($parsed['host'])) {
            throw new \InvalidArgumentException(
                'Redirect URL must be same-origin (no scheme or host). Got: ' . $url
            );
        }
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
        //
        // Reserved framework keys (`key`, `fallback`) are stripped: `key`
        // is the component-instance identity that controls which slot of
        // Session/Snapshot state the component reads — letting it come
        // from the URL would let an attacker target another user's state
        // key. `fallback` is page-side only.
        /** @var array<string, mixed> $props */
        $props = [];
        foreach ($request->query as $key => $value) {
            $keyStr = (string) $key;
            if ($keyStr === 'key' || $keyStr === 'fallback') {
                continue;
            }
            if (!\is_scalar($value)) {
                \http_response_code(400);
                $this->emitHeader('Cache-Control: no-store');
                return 'Deferred component param "' . \htmlspecialchars($keyStr, \ENT_QUOTES, 'UTF-8')
                    . '" must be a scalar; arrays and other types are not supported.';
            }
            $props[$keyStr] = $value;
        }

        $this->emitHeader('Cache-Control: ' . ($registration['cacheControl'] ?? 'private, max-age=0'));

        // Reset render context state — this is a fresh sub-render, not a
        // continuation of any page-level render pass.
        RenderContext::beginRender();

        // Flip the endpoint-render flag so deferred wrappers (FunctionComponent
        // wrappers from `fc(..., defer: ...)`) skip their placeholder branch
        // and actually render the inner component here.
        $this->renderingDeferredEndpoint = true;

        try {
            $element = $this->renderDeferredTarget($registration['component'], $props);
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
        } finally {
            $this->renderingDeferredEndpoint = false;
        }

        return $this->renderElement($element);
    }

    /**
     * Render either a PSX/callable target or a class-based `ComponentInterface`
     * target by its registered identifier. Two render paths exist in the
     * framework — PSX callables go through {@see renderPsxComponent()},
     * class components through {@see doCreateElement()} so state, snapshots,
     * and the data-usephp wrapper line up with how the same class would
     * render on the page side.
     *
     * @param array<string, mixed> $props
     */
    private function renderDeferredTarget(string $component, array $props): Element
    {
        if (
            \class_exists($component)
            && \is_subclass_of($component, ComponentInterface::class)
        ) {
            // Class component path. The registry stores under the component
            // name (which may be customised via #[Component(name: ...)]),
            // not the FQCN — go through getComponentName() so a custom name
            // still resolves. $props are intentionally not threaded through
            // here: class components don't accept render-time props, they
            // pick up per-request state through useState/$_SESSION/etc.
            return $this->doCreateElement($component::getComponentName());
        }

        return $this->renderPsxComponent($component, $props);
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
        $csrfReason = $this->verifyCsrf();
        if ($csrfReason !== null) {
            http_response_code(403);
            $this->emitHeader('Cache-Control: no-store');
            // Generic message to the client — the specific reason goes to
            // error_log so an operator can debug without leaking the
            // distinction (token vs origin) to a probing attacker.
            \error_log('[usePHP] ' . $csrfReason);
            return 'Forbidden';
        }

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
                $this->tryGetSnapshotSerializer(),
                deferPrefix: $this->deferPrefix,
                csrfToken: $this->getCsrfToken(),
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
                $this->tryGetSnapshotSerializer(),
                $storageType,
                $this->deferPrefix,
                $this->getCsrfToken(),
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
            $this->tryGetSnapshotSerializer(),
            $storageType,
            $this->deferPrefix,
            $this->getCsrfToken(),
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
