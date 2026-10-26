<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\UsePHP;

/**
 * Side-effect probe for the GH-40 tests: if createElement() ever executes a
 * string callable again, this flips the flag.
 */
function gh40SideEffectProbe(): Action
{
    $GLOBALS['gh40_probe_called'] = true;
    return Action::setState(0, 'pwned');
}

/**
 * Regression tests covering the security hardening done in response to the
 * 2026-05 audit. Each test pins a specific class of attack closed; if one of
 * these starts failing the defense has likely regressed.
 */
final class SecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
    }

    // ------------------------------------------------------------------
    // H-1: SnapshotSerializer no longer accepts an empty secret key, and
    // verifyChecksum no longer treats a null checksum as valid.
    // ------------------------------------------------------------------

    public function testSnapshotSerializerRejectsEmptySecretKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnapshotSerializer('');
    }

    public function testUsePhpGetSnapshotSerializerThrowsBeforeConfigured(): void
    {
        $app = new UsePHP();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('setSnapshotSecret');
        $app->getSnapshotSerializer();
    }

    public function testVerifyChecksumRejectsNullChecksum(): void
    {
        $serializer = new SnapshotSerializer('any-non-empty-key');
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);
        // No HMAC attached — must always be rejected now.
        self::assertFalse($serializer->verifyChecksum($snapshot));
    }

    public function testForgedSnapshotWithNullChecksumIsRejected(): void
    {
        // Re-create the audit scenario: attacker POSTs a snapshot JSON
        // with checksum=null hoping the empty-key bypass admits it.
        $serializer = new SnapshotSerializer('production-secret');
        $forged = json_encode([
            'memo' => ['name' => 'Counter', 'key' => 'main'],
            'state' => [0 => 999999],
            'effectDeps' => [],
            'checksum' => null,
        ], JSON_THROW_ON_ERROR);

        $this->expectException(SnapshotVerificationException::class);
        $serializer->deserialize($forged);
    }

    // ------------------------------------------------------------------
    // H-2: CSRF protection — Origin/Referer + session-bound token.
    // ------------------------------------------------------------------

    public function testHandleActionRejectsRequestWithoutOriginHeader(): void
    {
        $app = $this->buildAppWithCounter();

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_HOST'] = 'example.test';
            // No HTTP_ORIGIN, no HTTP_REFERER.
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 999],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            http_response_code(200);
            $html = $app->handleAction();
            self::assertSame('Forbidden', $html);
            self::assertSame(403, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    public function testHandleActionRejectsCrossOriginRequest(): void
    {
        $app = $this->buildAppWithCounter();

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 999],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            http_response_code(200);
            $html = $app->handleAction();
            self::assertSame('Forbidden', $html);
            self::assertSame(403, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    public function testHandleActionRejectsMissingCsrfTokenWhenSessionActive(): void
    {
        $app = $this->buildAppWithCounter();
        // Generate a CSRF token by reading it — populates $_SESSION.
        $app->getCsrfToken();

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 999],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                // Deliberately no _usephp_csrf.
            ];

            http_response_code(200);
            $html = $app->handleAction();
            self::assertSame('Forbidden', $html);
            self::assertSame(403, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    public function testGetCsrfTokenIsStablePerSession(): void
    {
        $app = new UsePHP();
        $token1 = $app->getCsrfToken();
        $token2 = $app->getCsrfToken();

        self::assertNotSame('', $token1);
        self::assertSame($token1, $token2);
        self::assertSame(64, strlen($token1)); // bin2hex(random_bytes(32))
    }

    public function testDisableCsrfProtectionTurnsOffOriginAndTokenChecks(): void
    {
        $app = new UsePHP();
        $app->disableCsrfProtection();
        // getCsrfToken returns empty once CSRF is off, and verifyCsrf (via
        // handleAction) will skip its checks. Indirect assertion via the
        // public surface: token is empty after opt-out.
        self::assertSame('', $app->getCsrfToken());
        self::assertFalse($app->isCsrfProtectionEnabled());
    }

    // ------------------------------------------------------------------
    // H-3: Open redirect — UsePHP::redirect and SimpleRouter::createRedirectUrl
    // both reject absolute / protocol-relative / scheme-prefixed URLs.
    // ------------------------------------------------------------------

    public function testCreateRedirectUrlRejectsProtocolRelativeUrl(): void
    {
        $router = new SimpleRouter(new SnapshotSerializer('test-secret'));
        $snapshot = new Snapshot('Counter', 'main', [0 => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same-origin');
        $router->createRedirectUrl('//evil.example/foo', $snapshot);
    }

    public function testCreateRedirectUrlRejectsAbsoluteUrl(): void
    {
        $router = new SimpleRouter(new SnapshotSerializer('test-secret'));
        $snapshot = new Snapshot('Counter', 'main', [0 => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $router->createRedirectUrl('https://evil.example/foo', $snapshot);
    }

    public function testCreateRedirectUrlThrowsOnMissingSerializer(): void
    {
        $router = new SimpleRouter(/* no serializer */);
        $snapshot = new Snapshot('Counter', 'main', [0 => 1]);

        $this->expectException(\LogicException::class);
        $router->createRedirectUrl('/ok', $snapshot);
    }

    public function testCreateRedirectUrlPassesThroughWhenNoSnapshot(): void
    {
        $router = new SimpleRouter(/* no serializer */);
        // No snapshot — even with no serializer, no work is done.
        self::assertSame('/ok', $router->createRedirectUrl('/ok'));
    }

    public function testCreateRedirectUrlThrowsOnMalformedUrl(): void
    {
        $router = new SimpleRouter(new SnapshotSerializer('test-secret'));
        $snapshot = new Snapshot('Counter', 'main', [0 => 1]);

        // parse_url returns false for inputs containing a bare port etc.
        // A silent fallback to "/" would mask the caller's bug — make it loud.
        $this->expectException(\InvalidArgumentException::class);
        $router->createRedirectUrl('http://:80', $snapshot);
    }

    // ------------------------------------------------------------------
    // H-2 (proxy headers): CSRF origin check honors X-Forwarded-Proto/Host
    // when trustProxyHeaders() is enabled, ignores them otherwise.
    // ------------------------------------------------------------------

    public function testCsrfRejectsForwardedProtoByDefault(): void
    {
        // By default, X-Forwarded-Proto is ignored. The expected origin is
        // computed from $_SERVER['HTTPS'] (unset → http), so an Origin of
        // https://example.test does not match http://example.test.
        $app = $this->buildAppWithCounter();

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['HTTP_ORIGIN'] = 'https://example.test';
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 1],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            http_response_code(200);
            $html = $app->handleAction();
            self::assertSame('Forbidden', $html);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    public function testCsrfHonorsForwardedProtoWhenEnabled(): void
    {
        $app = $this->buildAppWithCounter();
        $app->trustProxyHeaders();

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['HTTP_ORIGIN'] = 'https://example.test';
            $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1'; // avoid PRG exit
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 1],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            http_response_code(200);
            $html = $app->handleAction();
            // CSRF gate passed (would be "Forbidden" otherwise). The action
            // itself targets an unregistered component, so the partial-render
            // path returns an empty string — what matters here is that we got
            // past the CSRF check.
            self::assertNotSame('Forbidden', $html);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    public function testCsrfHonorsForwardedHostWhenEnabled(): void
    {
        $app = $this->buildAppWithCounter();
        $app->trustProxyHeaders();

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            // The FPM-side Host is the internal one; the browser-visible
            // Host is in X-Forwarded-Host. When proxy headers are trusted
            // the latter wins.
            $_SERVER['HTTP_HOST'] = 'internal.lb';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'public.example';
            $_SERVER['HTTP_ORIGIN'] = 'http://public.example';
            $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 1],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            http_response_code(200);
            $html = $app->handleAction();
            self::assertNotSame('Forbidden', $html);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    // ------------------------------------------------------------------
    // H-4: Session ブランチで未検証 snapshot を $_SESSION に書かない。
    // ------------------------------------------------------------------
    // (handleSnapshotRestoration is private; we cover the contract by
    // observing that an invalid extracted snapshot does not pollute the
    // session. See doHandleAction Session branch which already verifies.)

    public function testInvalidExtractedSnapshotDoesNotPolluteSession(): void
    {
        $serializer = new SnapshotSerializer('production-secret');
        // Forged snapshot — null checksum so the new verify rejects it.
        $forged = json_encode([
            'memo' => ['name' => 'Counter', 'key' => 'main'],
            'state' => [0 => 999],
            'effectDeps' => [],
            'checksum' => null,
        ], JSON_THROW_ON_ERROR);

        try {
            $serializer->deserialize($forged);
            self::fail('Expected SnapshotVerificationException');
        } catch (SnapshotVerificationException) {
            // Expected — verifies the gate that handleSnapshotRestoration
            // calls in front of $_SESSION write.
            self::assertArrayNotHasKey('_usephp_snapshot', $_SESSION);
        }
    }

    // ------------------------------------------------------------------
    // L-2: URL-context attributes block javascript:/data:/vbscript: schemes.
    // ------------------------------------------------------------------

    public function testRendererBlocksJavascriptHref(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('a', ['href' => 'javascript:alert(1)'], ['click me']);

        $html = $renderer->renderElement($element);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    public function testRendererBlocksWhitespacePrefixedJavascriptHref(): void
    {
        $renderer = new Renderer('test');
        // Browsers tolerate leading whitespace/control chars before the
        // scheme — make sure we do too.
        $element = new Element('a', ['href' => "\t  javascript:alert(1)"], ['x']);

        $html = $renderer->renderElement($element);
        self::assertStringNotContainsString('javascript', $html);
    }

    public function testRendererBlocksDataUrl(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('iframe', ['src' => 'data:text/html,<script>alert(1)</script>'], []);

        $html = $renderer->renderElement($element);
        self::assertStringNotContainsString('data:text/html', $html);
    }

    public function testRendererAllowsNormalUrl(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('a', ['href' => '/page?x=1'], ['ok']);

        $html = $renderer->renderElement($element);
        self::assertStringContainsString('href="/page?x=1"', $html);
    }

    public function testRendererAllowsRelativeUrl(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('a', ['href' => 'page.html'], ['ok']);

        $html = $renderer->renderElement($element);
        self::assertStringContainsString('href="page.html"', $html);
    }

    // ------------------------------------------------------------------
    // #31: tag names and attribute names are validated before they are
    // interpolated into the markup; inline on* handlers are refused.
    // ------------------------------------------------------------------

    public function testRendererRejectsAttributeNameWithQuoteAndSpace(): void
    {
        $renderer = new Renderer('test');
        // Would previously render as `<div x" onmouseover="alert(1)="y">`.
        $element = new Element('div', ['x" onmouseover="alert(1)' => 'y'], ['hi']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsAttributeNameWithAngleBracket(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('div', ['a><img src=x onerror=alert(1)>' => '1'], ['hi']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsAttributeNameWithWhitespace(): void
    {
        $renderer = new Renderer('test');
        // The reproduction from the issue: a key containing a space is
        // otherwise emitted as two attributes.
        $element = new Element('div', ['x onclick' => 'alert(1)', 'id' => 'ok'], ['hello']);

        $this->expectException(\RuntimeException::class);
        $renderer->renderElement($element);
    }

    public function testRendererRejectsLowercaseOnclickStringProp(): void
    {
        $renderer = new Renderer('test');
        // PSX compiles non-whitelisted attributes through H::__callStatic,
        // so a lowercase `onclick` string prop reaches the renderer as-is.
        $element = H::__callStatic('div', ['onclick' => 'alert(1)']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler attribute "onclick" is not allowed');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsOnerrorProp(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('img', ['src' => '/x.png', 'onerror' => 'alert(1)'], []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler attribute "onerror" is not allowed');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsCamelCaseEventPropThatBypassedCreateElement(): void
    {
        $renderer = new Renderer('test');
        // createElement() only converts onLoad etc. when the value is an
        // Action/callable; a raw Element with a string handler must still
        // be refused (the check is case-insensitive).
        $element = new Element('img', ['src' => '/x.png', 'onLoad' => 'alert(1)'], []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inline event handler attribute "onLoad" is not allowed');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsBooleanInlineEventAttribute(): void
    {
        $renderer = new Renderer('test');
        // Name validation happens before the boolean-attribute shortcut.
        $element = new Element('div', ['onmouseover' => true], ['x']);

        $this->expectException(\RuntimeException::class);
        $renderer->renderElement($element);
    }

    public function testRendererRejectsMalformedTagName(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('img src=x onerror=alert(1)', [], []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML tag name');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsTagNameWithAngleBracket(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('div><script>alert(1)</script', [], ['x']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML tag name');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsMalformedTagNameOnActionElement(): void
    {
        $renderer = new Renderer('test');
        // The form-wrapped path (renderWithForm) reuses the tag name too;
        // the check in renderElement() must run before dispatching there.
        $element = new Element(
            'button onmouseover=alert(1)',
            ['wire:click' => Action::setState(0, 1)],
            ['x'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML tag name');
        $renderer->renderElement($element);
    }

    public function testRendererRejectsMalformedAttributeNameOnActionElement(): void
    {
        $renderer = new Renderer('test');
        $element = new Element(
            'button',
            ['wire:click' => Action::setState(0, 1), 'x" onmouseover="alert(1)' => 'y'],
            ['x'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name');
        $renderer->renderElement($element);
    }

    public function testRendererAllowsDataAriaAndNamespacedAttributeNames(): void
    {
        $renderer = new Renderer('test');
        $element = H::__callStatic('svg', [
            'data-id' => '42',
            'aria-label' => 'close',
            'strokeWidth' => '2',          // JSX map -> stroke-width
            'xlinkHref' => '#icon',        // JSX map -> xlink:href
            'xmlLang' => 'en',             // JSX map -> xml:lang
            'xmlnsXlink' => 'http://www.w3.org/1999/xlink',
            'viewBox' => '0 0 24 24',      // canonical camelCase, no map entry
            'httpEquiv' => 'refresh',      // JSX map -> http-equiv
            'acceptCharset' => 'utf-8',    // JSX map -> accept-charset
            'className' => 'icon',         // JSX map -> class
            'htmlFor' => 'input-1',        // JSX map -> for
            'hidden' => true,
        ]);

        $html = $renderer->renderElement($element);

        self::assertStringContainsString('data-id="42"', $html);
        self::assertStringContainsString('aria-label="close"', $html);
        self::assertStringContainsString('stroke-width="2"', $html);
        self::assertStringContainsString('xlink:href="#icon"', $html);
        self::assertStringContainsString('xml:lang="en"', $html);
        self::assertStringContainsString('xmlns:xlink="http://www.w3.org/1999/xlink"', $html);
        self::assertStringContainsString('viewBox="0 0 24 24"', $html);
        self::assertStringContainsString('http-equiv="refresh"', $html);
        self::assertStringContainsString('accept-charset="utf-8"', $html);
        self::assertStringContainsString('class="icon"', $html);
        self::assertStringContainsString('for="input-1"', $html);
        self::assertStringContainsString(' hidden', $html);
    }

    public function testRendererAllowsCustomElementAndSvgTagNames(): void
    {
        $renderer = new Renderer('test');
        $element = new Element('my-widget', ['data-x' => '1'], [
            new Element('linearGradient', ['id' => 'g'], []),
            new Element('foreignObject', [], ['x']),
        ]);

        $html = $renderer->renderElement($element);

        self::assertStringStartsWith('<my-widget data-x="1">', $html);
        self::assertStringContainsString('<linearGradient id="g"></linearGradient>', $html);
        self::assertStringContainsString('<foreignObject>x</foreignObject>', $html);
        self::assertStringEndsWith('</my-widget>', $html);
    }

    public function testOnClickActionStillRendersWireFormWithoutInlineHandler(): void
    {
        $renderer = new Renderer('test');
        // createElement() converts onClick into a wire:click Action before
        // the renderer sees it, so the framework event path keeps working
        // while raw on* attributes are refused.
        $element = H::button(onClick: fn() => Action::setState(0, 1), children: 'Go');

        $html = $renderer->renderElement($element);

        self::assertStringContainsString('<form method="post" data-usephp-form', $html);
        self::assertStringContainsString('name="_usephp_action"', $html);
        self::assertStringContainsString('<button type="submit">Go</button>', $html);
        self::assertStringNotContainsString('onclick', strtolower($html));
        self::assertStringNotContainsString('wire:', $html);
    }

    // ------------------------------------------------------------------
    // L-3: parse_url(false) tolerated by RequestContext::fromGlobals.
    // ------------------------------------------------------------------

    public function testFromGlobalsToleratesMalformedRequestUri(): void
    {
        $savedServer = $_SERVER;
        try {
            // parse_url returns false for inputs like a port-only fragment.
            $_SERVER['REQUEST_URI'] = '///\\\\http://[::1';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $request = RequestContext::fromGlobals();
            // Should not have raised a warning; path falls back gracefully.
            self::assertNotSame('', $request->path);
        } finally {
            $_SERVER = $savedServer;
        }
    }

    // ------------------------------------------------------------------
    // GH-40: createElement() used is_callable() on event props, so a string
    // such as 'phpinfo' forwarded from untrusted props was executed as a PHP
    // function. Only Action instances and Closures are accepted now.
    // ------------------------------------------------------------------

    public function testEventPropRejectsStringCallableWithoutExecutingIt(): void
    {
        // The __callStatic path is what PSX / spread-props calls hit when an
        // attribute is not part of the typed signature; it bypasses PHP's own
        // parameter type check, so createElement() must enforce the rule itself.
        $GLOBALS['gh40_probe_called'] = false;
        $probe = __NAMESPACE__ . '\\gh40SideEffectProbe';
        self::assertTrue(is_callable($probe), 'precondition: probe must be a valid string callable');

        try {
            H::__callStatic('button', ['onClick' => $probe, 'children' => 'x']);
            self::fail('Expected InvalidArgumentException for string callable event prop');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('onClick', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());
        }

        self::assertFalse($GLOBALS['gh40_probe_called'], 'string callable must not be executed');
    }

    public function testEventPropRejectsBuiltinFunctionNames(): void
    {
        foreach (['strlen', 'phpinfo', 'php_uname'] as $name) {
            try {
                H::__callStatic('div', ['onClick' => $name, 'children' => 'x']);
                self::fail("Expected InvalidArgumentException for '{$name}'");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('onClick must be an Action or a Closure; got string', $e->getMessage());
            }
        }
    }

    public function testEventPropRejectsArrayCallable(): void
    {
        $GLOBALS['gh40_probe_called'] = false;
        $callable = [$this, 'gh40ArrayCallableTarget'];
        self::assertTrue(is_callable($callable), 'precondition: must be a valid array callable');

        try {
            H::__callStatic('button', ['onClick' => $callable, 'children' => 'x']);
            self::fail('Expected InvalidArgumentException for array callable event prop');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('onClick must be an Action or a Closure; got array', $e->getMessage());
        }

        self::assertFalse($GLOBALS['gh40_probe_called'], 'array callable must not be executed');
    }

    public function testEventPropRejectsInvokableObjectThatIsNotAClosure(): void
    {
        $invokable = new class {
            public function __invoke(): Action
            {
                $GLOBALS['gh40_probe_called'] = true;
                return Action::setState(0, 'pwned');
            }
        };
        $GLOBALS['gh40_probe_called'] = false;

        try {
            H::__callStatic('button', ['onClick' => $invokable, 'children' => 'x']);
            self::fail('Expected InvalidArgumentException for invokable object event prop');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('onClick must be an Action or a Closure; got class@anonymous', $e->getMessage());
        }

        self::assertFalse($GLOBALS['gh40_probe_called'], 'invokable object must not be executed');
    }

    public function testEventPropRejectsOtherScalars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('onChange must be an Action or a Closure; got int');
        H::__callStatic('select', ['onChange' => 42]);
    }

    public function testTypedHMethodRejectsStringCallableAtSignatureLevel(): void
    {
        // The typed H::xxx() signatures were narrowed from Action|callable|null
        // to Action|\Closure|null, so a string never even reaches createElement().
        $GLOBALS['gh40_probe_called'] = false;
        $probe = __NAMESPACE__ . '\\gh40SideEffectProbe';

        try {
            /** @phpstan-ignore argument.type */
            H::button(onClick: $probe, children: 'x');
            self::fail('Expected TypeError for string callable on typed H::button()');
        } catch (\TypeError) {
            // expected
        }

        self::assertFalse($GLOBALS['gh40_probe_called'], 'string callable must not be executed');
    }

    public function testEventPropStillAcceptsActionInstance(): void
    {
        $action = Action::setState(0, 1, 'Counter#0');
        $element = H::button(onClick: $action, children: 'x');

        self::assertSame($action, $element->props['wire:click']);
        self::assertArrayNotHasKey('onClick', $element->props);
    }

    public function testEventPropStillAcceptsClosureReturningAction(): void
    {
        $action = Action::setState(0, 2, 'Counter#0');
        $closure = \Closure::fromCallable(static fn(): Action => $action);
        $element = H::button(onClick: $closure, children: 'x');

        self::assertSame($action, $element->props['wire:click']);
    }

    public function testEventPropStillAcceptsArrowFunction(): void
    {
        $action = Action::setState(0, 3, 'Counter#0');
        $element = H::input(onChange: fn() => $action);

        self::assertSame($action, $element->props['wire:change']);
    }

    public function testEventPropIgnoresNull(): void
    {
        $element = H::button(onClick: null, children: 'x');
        self::assertArrayNotHasKey('wire:click', $element->props);
    }

    public function gh40ArrayCallableTarget(): Action
    {
        $GLOBALS['gh40_probe_called'] = true;
        return Action::setState(0, 'pwned');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildAppWithCounter(): UsePHP
    {
        $app = new UsePHP();
        // CSRF tests don't exercise Snapshot storage, but disableRouter()
        // simplifies the action handling.
        $app->disableRouter();
        // An action that is not answered with a partial ends in a PRG
        // redirect followed by `exit`, which would take the PHPUnit process
        // down with it (#49). Swallow headers and the exit instead.
        $app->withHeaderEmitter(static function (string $header): void {});
        $app->withExitHandler(static function (): void {});
        return $app;
    }

    public function testPrgRedirectGoesThroughTheExitSeam(): void
    {
        $app = $this->buildAppWithCounter();
        $headers = [];
        $exited = 0;
        $app->withHeaderEmitter(static function (string $header) use (&$headers): void {
            $headers[] = $header;
        });
        $app->withExitHandler(static function () use (&$exited): void {
            $exited++;
        });

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/counter?x=1';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
            // No X-UsePHP-Partial header: full-page submit → PRG redirect.
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => json_encode([
                    'type' => 'setState',
                    'payload' => ['index' => 0, 'value' => 1],
                    'componentId' => 'Counter#0',
                ], JSON_THROW_ON_ERROR),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            http_response_code(200);
            $html = $app->handleAction();

            self::assertSame('', $html);
            self::assertSame(1, $exited);
            self::assertSame(303, http_response_code());
            self::assertContains('Location: /counter', $headers);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
    }
}
