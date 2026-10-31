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

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;

use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;
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
        StorageFactory::reset();
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
    // #39: Session/Shared snapshots are bound to the PHP session id. A
    // signed snapshot obtained in one session must not verify in another,
    // and a `?_snapshot=` on a Session route must never reach $_SESSION.
    // ------------------------------------------------------------------

    public function testSessionRouteIgnoresSnapshotCarriedByTheQueryString(): void
    {
        // Issue #39 reproduction: the attacker holds a snapshot signed with
        // the app-wide secret (obtained from their own browser) and sends the
        // logged-in victim a /cart?_snapshot=... link.
        $serializer = new SnapshotSerializer('s3cret');
        $attackerSnapshot = $serializer->serialize(
            new Snapshot('Cart', '0', [0 => ['item' => 'attacker-chosen', 'qty' => 99]]),
        );

        $_SESSION = ['user' => 'victim'];

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/cart', static fn() => H::div(children: 'cart'))->sessionSnapshot();

        $request = new RequestContext(method: 'GET', path: '/cart', query: ['_snapshot' => $attackerSnapshot]);
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);

        $app->restoreSnapshot($request, $match);

        self::assertSame(['user' => 'victim'], $_SESSION);
        self::assertArrayNotHasKey('_usephp_snapshot', $_SESSION);
        self::assertNull(ComponentState::current(), 'nothing may be restored from the query on a Session route');
    }

    public function testSessionRouteIgnoresEvenASessionBoundSnapshotFromTheQueryString(): void
    {
        // Even a snapshot signed for *this* session id is not accepted from
        // the URL: the framework never issues Session snapshots that way, so
        // the import path simply does not exist any more.
        $serializer = new SnapshotSerializer('s3cret')->withContext(session_id());
        $ownSnapshot = $serializer->serialize(new Snapshot('Cart', '0', [0 => 1]));

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/cart', static fn() => H::div(children: 'cart'))->sessionSnapshot();

        $request = new RequestContext(method: 'GET', path: '/cart', query: ['_snapshot' => $ownSnapshot]);
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);

        $app->restoreSnapshot($request, $match);

        self::assertArrayNotHasKey('_usephp_snapshot', $_SESSION);
        self::assertNull(ComponentState::current());
    }

    public function testPersistentRouteStillRestoresUnboundSnapshotFromTheUrl(): void
    {
        // Persistent is URL-carried state by design and stays on the
        // unbound serializer — this pins that #39 did not change it.
        $serializer = new SnapshotSerializer('s3cret');
        $shared = $serializer->serialize(new Snapshot('Cart', '0', [0 => 'from-url']));

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/cart', static fn() => H::div(children: 'cart'))->persistentSnapshot();

        $request = new RequestContext(method: 'GET', path: '/cart', query: ['_snapshot' => $shared]);
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);

        $app->restoreSnapshot($request, $match);

        $state = ComponentState::current();
        self::assertNotNull($state);
        self::assertSame('from-url', $state->getState(0, null));
        self::assertArrayNotHasKey('_usephp_snapshot', $_SESSION);
    }

    public function testSharedSnapshotSignedForAnotherSessionIsRejected(): void
    {
        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/wizard/1', static fn() => H::div(children: 'step'))->sharedSnapshot('wizard');

        // Shared restores from $_SESSION; the request carries no snapshot.
        $request = new RequestContext(method: 'GET', path: '/wizard/1');
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);

        $serializer = new SnapshotSerializer('s3cret');
        $snapshot = new Snapshot('Wizard', '0', [0 => 42]);

        // 1. Bytes signed in a different session (or by the pre-#39 unbound
        //    serializer) that somehow landed in this session: rejected.
        $_SESSION['_usephp_shared_wizard'] = $serializer->withContext('some-other-session-id')->serialize($snapshot);
        $app->restoreSnapshot($request, $match);
        self::assertNull(ComponentState::current(), 'snapshot bound to another session id must not restore');

        $_SESSION['_usephp_shared_wizard'] = $serializer->serialize($snapshot);
        $app->restoreSnapshot($request, $match);
        self::assertNull(ComponentState::current(), 'unbound snapshot must not restore from $_SESSION');

        // 2. Bytes signed for this session id (what doHandleAction() writes
        //    on the PRG path): restored.
        $_SESSION['_usephp_shared_wizard'] = $serializer->withContext(session_id())->serialize($snapshot);
        $app->restoreSnapshot($request, $match);
        $state = ComponentState::current();
        self::assertNotNull($state);
        self::assertSame(42, $state->getState(0, null));

        // 3. The session id changes (login → session_regenerate_id()); the
        //    same bytes are now signed for a stale id and stop verifying.
        ComponentState::clearInstances();
        StorageFactory::reset();
        $previousId = session_id();
        self::assertTrue(session_regenerate_id());
        self::assertNotSame($previousId, session_id());

        $app->restoreSnapshot($request, $match);
        self::assertNull(ComponentState::current(), 'session_regenerate_id() must invalidate bound snapshots');
    }

    public function testSnapshotStorageFormRoundTripIsNotSessionBound(): void
    {
        // `#[Component(storage: 'snapshot')]` / fc(..., StorageType::Snapshot)
        // round-trips the snapshot through the form's _usephp_snapshot field
        // with the unbound serializer. With a session active (as it is in
        // every request that uses CSRF tokens) the posted snapshot must still
        // verify — i.e. the session binding applies only to Session/Shared.
        $counter = fc(static function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);

            return H::div(className: 'counter', children: [
                H::span(className: 'display', children: "Count: {$count}"),
                H::button(onClick: static fn() => $setCount($count + 1), children: '+'),
            ]);
        }, 'snapshot-counter', StorageType::Snapshot);

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/snapshot', static function () use ($counter): Element {
            RenderContext::beginRender();
            return $counter(['initial' => 0]);
        });

        $page = $this->renderRoute($app, '/snapshot');
        self::assertStringContainsString('Count: 0', $page);

        self::assertSame(1, \preg_match('/data-usephp="([^"]+)"/', $page, $m));
        $instanceId = \html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');

        $instanceEscaped = \preg_quote(\htmlspecialchars($instanceId, \ENT_QUOTES, 'UTF-8'), '/');
        $matched = \preg_match(
            '/name="_usephp_component" value="' . $instanceEscaped . '" \/>\s*'
            . '<input type="hidden" name="_usephp_action" value="([^"]*)" \/>\s*'
            . '<input type="hidden" name="_usephp_action_sig" value="([^"]*)" \/>'
            . '(?:<input type="hidden" name="_usephp_csrf" value="[^"]*" \/>)?'
            . '<input type="hidden" name="_usephp_snapshot" value="([^"]*)" \/>/',
            $page,
            $fields,
        );
        self::assertSame(1, $matched, 'usePHP form fields not found');

        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/snapshot';
            $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
            $_POST = [
                '_usephp_component' => $instanceId,
                '_usephp_action' => \html_entity_decode($fields[1], \ENT_QUOTES, 'UTF-8'),
                '_usephp_action_sig' => \html_entity_decode($fields[2], \ENT_QUOTES, 'UTF-8'),
                '_usephp_snapshot' => \html_entity_decode($fields[3], \ENT_QUOTES, 'UTF-8'),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];

            ComponentState::clearInstances();
            StorageFactory::reset();

            $html = $app->handleAction();
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }

        self::assertNotNull($html);
        self::assertNotSame('Invalid snapshot', $html);
        self::assertStringContainsString('Count: 1', $html);
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
        self::assertMatchesRegularExpression('/<button type="submit" form="usephp-f-[0-9a-f]+">Go<\/button>/', $html);
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

    // ------------------------------------------------------------------
    // #37 / #41 / #42: actions are server-signed; malformed input is a 400.
    // ------------------------------------------------------------------

    /**
     * Fake a same-origin browser POST of an action and return the body.
     *
     * @param array<string, string> $overrides Extra/overriding POST fields.
     */
    private function postAction(UsePHP $app, string $instanceId, string $actionJson, array $overrides = []): ?string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/counter';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
        $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
        $_POST = $overrides + [
            '_usephp_component' => $instanceId,
            '_usephp_action' => $actionJson,
            '_usephp_csrf' => $app->getCsrfToken(),
        ];
        ComponentState::clearInstances();
        http_response_code(200);

        return $app->handleAction();
    }

    private function signedCounterApp(): UsePHP
    {
        $app = new UsePHP();
        $app->setSnapshotSecret('test-secret');
        $app->disableRouter();
        $app->register(SignedActionCounter::class);

        return $app;
    }

    private static function setStateJson(int $index, mixed $value, ?string $storageType = null): string
    {
        $data = ['type' => 'setState', 'payload' => ['index' => $index, 'value' => $value], 'componentId' => 'SignedActionCounter#0'];
        if ($storageType !== null) {
            $data['storageType'] = $storageType;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function testRenderedActionFormCarriesSignatureThatVerifies(): void
    {
        $app = $this->signedCounterApp();
        $html = $app->render('SignedActionCounter');

        self::assertSame(1, preg_match('/name="_usephp_action" value="([^"]*)" \/>\s*<input type="hidden" name="_usephp_action_sig" value="([0-9a-f]{64})"/', $html, $m));
        $actionJson = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        self::assertTrue($app->verifyActionSignature('SignedActionCounter#0', $actionJson, $m[2]));
    }

    public function testSignedActionIsExecuted(): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $app = $this->signedCounterApp();
            $json = self::setStateJson(0, 41);
            $html = $this->postAction($app, 'SignedActionCounter#0', $json, [
                '_usephp_action_sig' => (string) $app->signAction('SignedActionCounter#0', $json),
            ]);

            self::assertSame(200, http_response_code());
            self::assertStringContainsString('count=41', (string) $html);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    public function testUnsignedActionIsRejected(): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $app = $this->signedCounterApp();
            $html = $this->postAction($app, 'SignedActionCounter#0', self::setStateJson(0, true));

            self::assertSame('Invalid action signature', $html);
            self::assertSame(400, http_response_code());
            self::assertArrayNotHasKey('usephp:SignedActionCounter#0:state:0', $_SESSION);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
    }

    public function testTamperedSignedActionIsRejected(): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $app = $this->signedCounterApp();
            $signature = (string) $app->signAction('SignedActionCounter#0', self::setStateJson(0, 1));

            // Change the value the server signed.
            $html = $this->postAction($app, 'SignedActionCounter#0', self::setStateJson(0, 999), ['_usephp_action_sig' => $signature]);
            self::assertSame('Invalid action signature', $html);
            self::assertSame(400, http_response_code());

            // Change the storage type the server signed (attempt to mint a signed snapshot).
            $html = $this->postAction($app, 'SignedActionCounter#0', self::setStateJson(0, 1, 'snapshot'), ['_usephp_action_sig' => $signature]);
            self::assertSame('Invalid action signature', $html);
            self::assertSame(400, http_response_code());

            // Point the signed action at a different instance.
            $html = $this->postAction($app, 'SignedActionCounter#1', self::setStateJson(0, 1), ['_usephp_action_sig' => $signature]);
            self::assertSame(400, http_response_code());
            self::assertNotNull($html);
            self::assertStringStartsWith('Invalid action', $html);
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedActionProvider(): iterable
    {
        yield 'index as string' => ['{"type":"setState","payload":{"index":"0","value":1}}'];
        yield 'negative index' => ['{"type":"setState","payload":{"index":-1,"value":1}}'];
        yield 'huge index' => ['{"type":"setState","payload":{"index":99999,"value":1}}'];
        yield 'payload scalar' => ['{"type":"setState","payload":"x"}'];
        yield 'json scalar' => ['"scalar"'];
        yield 'json list' => ['[1,2]'];
        yield 'missing type' => ['{"payload":{}}'];
        yield 'type array' => ['{"type":["x"]}'];
        yield 'unknown storageType' => ['{"type":"setState","payload":{"index":0,"value":1},"storageType":"redis"}'];
        yield 'componentId not string' => ['{"type":"setState","payload":{"index":0,"value":1},"componentId":5}'];
        yield 'invalid json' => ['{not json'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedActionProvider')]
    public function testMalformedActionIsBadRequestNotTypeError(string $actionJson): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $app = $this->signedCounterApp();
            // Sign it so the shape check — not the signature check — is what rejects it.
            $html = $this->postAction($app, 'SignedActionCounter#0', $actionJson, [
                '_usephp_action_sig' => (string) $app->signAction('SignedActionCounter#0', $actionJson),
            ]);

            self::assertSame('Invalid action data', $html);
            self::assertSame(400, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidInstanceIdProvider(): iterable
    {
        yield 'no hash' => ['SignedActionCounter'];
        yield 'control char' => ["SignedActionCounter#0\n"];
        yield 'too long' => ['SignedActionCounter#' . str_repeat('a', 300)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInstanceIdProvider')]
    public function testInvalidInstanceIdIsBadRequest(string $instanceId): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $app = $this->signedCounterApp();
            $json = self::setStateJson(0, 1);
            $html = $this->postAction($app, $instanceId, $json, [
                '_usephp_action_sig' => (string) $app->signAction($instanceId, $json),
            ]);

            self::assertSame('Invalid action request', $html);
            self::assertSame(400, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
    }

    public function testRestoreRequiresASnapshotForTheSameInstance(): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $app = $this->signedCounterApp();
            $restore = '{"type":"restore","payload":{},"componentId":"SignedActionCounter#0","storageType":"snapshot"}';

            // No snapshot at all → nothing to restore from.
            $html = $this->postAction($app, 'SignedActionCounter#0', $restore);
            self::assertSame('Invalid action request', $html);
            self::assertSame(400, http_response_code());

            // A valid snapshot that belongs to another instance.
            $other = $app->getSnapshotSerializer()->serialize(new Snapshot('SignedActionCounter', '1', [0 => 7]));
            $html = $this->postAction($app, 'SignedActionCounter#0', $restore, ['_usephp_snapshot' => $other]);
            self::assertSame('Invalid snapshot', $html);
            self::assertSame(400, http_response_code());

            // A restore that smuggles a mutation in its payload.
            $mutating = '{"type":"restore","payload":{"index":0,"value":1},"componentId":"SignedActionCounter#0","storageType":"snapshot"}';
            $own = $app->getSnapshotSerializer()->serialize(new Snapshot('SignedActionCounter', '0', [0 => 7]));
            $html = $this->postAction($app, 'SignedActionCounter#0', $mutating, ['_usephp_snapshot' => $own]);
            self::assertSame('Invalid action request', $html);
            self::assertSame(400, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
    }

    public function testSignActionFallsBackToAPerSessionKeyWithoutSecret(): void
    {
        $app = new UsePHP();
        $signature = $app->signAction('Counter#0', '{"type":"setState"}');

        self::assertNotNull($signature);
        self::assertArrayHasKey('_usephp_action_key', $_SESSION);
        self::assertTrue($app->verifyActionSignature('Counter#0', '{"type":"setState"}', $signature));
        self::assertFalse($app->verifyActionSignature('Counter#1', '{"type":"setState"}', $signature));

        // A different session key must not verify the old signature.
        $_SESSION['_usephp_action_key'] = bin2hex(random_bytes(32));
        self::assertFalse($app->verifyActionSignature('Counter#0', '{"type":"setState"}', $signature));
    }

    // ------------------------------------------------------------------
    // #38: the PRG redirect after a POST action must never echo a
    // client-controlled authority back in the Location header.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function redirectPathProvider(): iterable
    {
        yield 'plain path' => ['/counter', '/counter'];
        yield 'strips query' => ['/counter?x=1&next=//evil.example', '/counter'];
        yield 'protocol-relative authority is dropped' => ['//evil.example/counter', '/counter'];
        yield 'protocol-relative authority without path' => ['//evil.example', '/'];
        yield 'backslash authority' => ['/\\evil.example/counter', '/evil.example/counter'];
        yield 'triple slash' => ['///evil.example/counter', '/evil.example/counter'];
        yield 'mixed slashes' => ['/\\/evil.example', '/evil.example'];
        yield 'absolute url keeps only the path' => ['https://evil.example/counter', '/counter'];
        yield 'empty' => ['', '/'];
        yield 'control characters' => ["/counter\r\nX-Injected: 1", '/'];
        yield 'tab' => ["/coun\tter", '/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirectPathProvider')]
    public function testNormalizeRedirectPathYieldsSameOriginPath(string $requestUri, string $expected): void
    {
        $path = UsePHP::normalizeRedirectPath($requestUri);

        self::assertSame($expected, $path);
        self::assertStringStartsWith('/', $path);
        self::assertStringStartsNotWith('//', $path);
        self::assertStringNotContainsString('\\', $path);
        self::assertStringNotContainsString('?', $path);
    }

    /**
     * Render a GET route the way UsePHP::run() would, as its own request.
     */
    private function renderRoute(UsePHP $app, string $path, bool $restoreSnapshot = false): string
    {
        ComponentState::clearInstances();
        StorageFactory::reset();

        $request = new RequestContext(method: 'GET', path: $path);
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);

        if ($restoreSnapshot) {
            $app->restoreSnapshot($request, $match);
        }

        RenderContext::setApp($app);
        try {
            $element = ($match->handler)($match->params, $request);
        } finally {
            RenderContext::clearApp();
        }
        self::assertInstanceOf(Element::class, $element);

        return $app->renderElement($element);
    }

    // ------------------------------------------------------------------
    // #52 / #53: Session and Shared restore from $_SESSION on every GET,
    // with or without a `?_snapshot=` query parameter.
    // ------------------------------------------------------------------

    public function testSharedRouteRestoresWithoutAQueryParameter(): void
    {
        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()->get('/wizard/2', static fn() => H::div(children: 'step'))->sharedSnapshot('wizard');

        $_SESSION['_usephp_shared_wizard'] = new SnapshotSerializer('s3cret')
            ->withContext(session_id())
            ->serialize(new Snapshot('Wizard', '0', [0 => 7]));

        $request = new RequestContext(method: 'GET', path: '/wizard/2');
        $match = $app->getRouter()->match($request);
        self::assertNotNull($match);
        $app->restoreSnapshot($request, $match);

        $state = ComponentState::current();
        self::assertNotNull($state, 'Shared state must restore from $_SESSION without ?_snapshot=');
        self::assertSame(7, $state->getState(0, null));
    }

    public function testSessionRouteRestoresStateWrittenByThePrgRedirect(): void
    {
        $counter = fc(static function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);

            return H::div(className: 'counter', children: [
                H::span(className: 'display', children: "Count: {$count}"),
                H::button(onClick: static fn() => $setCount($count + 1), children: '+'),
            ]);
        }, 'session-counter', StorageType::Snapshot);

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->withHeaderEmitter(static function (string $header): void {});
        $app->withExitHandler(static function (): void {});
        $app->getRouter()->get('/session-counter', static function () use ($counter): Element {
            RenderContext::beginRender();
            return $counter(['initial' => 0]);
        })->sessionSnapshot();

        $page = $this->renderRoute($app, '/session-counter', restoreSnapshot: true);
        self::assertStringContainsString('Count: 0', $page);

        self::assertSame(1, \preg_match('/data-usephp="([^"]+)"/', $page, $m));
        $instanceId = \html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');
        $instanceEscaped = \preg_quote(\htmlspecialchars($instanceId, \ENT_QUOTES, 'UTF-8'), '/');
        self::assertSame(1, \preg_match(
            '/name="_usephp_component" value="' . $instanceEscaped . '" \/>\s*'
            . '<input type="hidden" name="_usephp_action" value="([^"]*)" \/>\s*'
            . '<input type="hidden" name="_usephp_action_sig" value="([^"]*)" \/>'
            . '(?:<input type="hidden" name="_usephp_csrf" value="[^"]*" \/>)?'
            . '<input type="hidden" name="_usephp_snapshot" value="([^"]*)" \/>/',
            $page,
            $fields,
        ), 'usePHP form fields not found');

        // Full-page submit (no X-UsePHP-Partial): doHandleAction() takes the
        // PRG path and writes the post-action snapshot into $_SESSION.
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/session-counter';
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
            unset($_SERVER['HTTP_X_USEPHP_PARTIAL']);
            $_POST = [
                '_usephp_component' => $instanceId,
                '_usephp_action' => \html_entity_decode($fields[1], \ENT_QUOTES, 'UTF-8'),
                '_usephp_action_sig' => \html_entity_decode($fields[2], \ENT_QUOTES, 'UTF-8'),
                '_usephp_snapshot' => \html_entity_decode($fields[3], \ENT_QUOTES, 'UTF-8'),
                '_usephp_csrf' => $app->getCsrfToken(),
            ];
            ComponentState::clearInstances();
            StorageFactory::reset();
            http_response_code(200);

            self::assertSame('', $app->handleAction());
            self::assertSame(303, http_response_code());
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            http_response_code(200);
        }
        self::assertArrayHasKey('_usephp_snapshot', $_SESSION);

        // The redirected GET restores the state from $_SESSION (#52).
        $after = $this->renderRoute($app, '/session-counter', restoreSnapshot: true);
        self::assertStringContainsString('Count: 1', $after);
        self::assertStringNotContainsString('Count: 0', $after);

        // Bytes signed for another session id must not restore.
        $_SESSION['_usephp_snapshot'] = new SnapshotSerializer('s3cret')
            ->withContext('other-session')
            ->serialize(new Snapshot('X', '0', [0 => 99]));
        $again = $this->renderRoute($app, '/session-counter', restoreSnapshot: true);
        self::assertStringContainsString('Count: 0', $again);
    }

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
            $actionJson = json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 1],
                'componentId' => 'Counter#0',
            ], JSON_THROW_ON_ERROR);
            $_POST = [
                '_usephp_component' => 'Counter#0',
                '_usephp_action' => $actionJson,
                '_usephp_action_sig' => $app->signAction('Counter#0', $actionJson),
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

/**
 * Session-storage class component used by the signed-action tests.
 */
#[\Polidog\UsePhp\Component\Component(name: 'SignedActionCounter')]
final class SignedActionCounter extends \Polidog\UsePhp\Component\BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(children: [
            "count={$count}",
            H::button(onClick: fn() => $setCount($count + 1), children: '+'),
        ]);
    }
}
