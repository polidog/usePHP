<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\UsePHP;

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

    private function buildAppWithCounter(): UsePHP
    {
        $app = new UsePHP();
        // CSRF tests don't exercise Snapshot storage, but disableRouter()
        // simplifies the action handling.
        $app->disableRouter();
        return $app;
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
