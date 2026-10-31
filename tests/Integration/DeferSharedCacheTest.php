<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #109: a defer response that belongs to one visitor must never allow shared
 * caching, whatever policy the endpoint registered and whoever started the
 * session. A CDN would hand its cookie, CSRF token or session-bound signatures
 * to the next visitor.
 */
final class DeferSharedCacheTest extends ActionFlowServerTestCase
{
    private const APP = __DIR__ . '/Fixtures/defer-cache-app.php';

    private const SECRET = ['USEPHP_TEST_SECRET' => 'integration-secret'];

    private const APP_SESSION = ['USEPHP_TEST_SESSION_START' => '1'];

    /** No session file has to exist: without strict mode PHP adopts the id. */
    private const SESSION_COOKIE = 'PHPSESSID=aaaabbbbccccddddeeeeffff00001111';

    private ?string $log = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->log !== null) {
            unlink($this->log);
            $this->log = null;
        }
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string, 2: ?string, 3: string}>
     */
    public static function visitorResponseProvider(): iterable
    {
        $noLimiter = ['USEPHP_TEST_CACHE_LIMITER' => 'none'];
        $publicLimiter = ['USEPHP_TEST_CACHE_LIMITER' => 'public'];
        $closed = self::APP_SESSION + ['USEPHP_TEST_SESSION_CLOSED' => '1'];
        $cookie = self::SESSION_COOKIE;
        $session = 'uses the session';

        // The framework starts the session during the render.
        yield 'session fc' => [[], 'session-fc', null, $session];
        yield 'session class' => [[], 'session-class', null, $session];
        yield 'session fc, secret' => [self::SECRET, 'session-fc', null, $session];
        yield 'button, no secret' => [[], 'button-memory', null, $session];
        yield 'session fc, no cache limiter' => [$noLimiter, 'session-fc', null, $session];
        yield 'button, no cache limiter' => [$noLimiter, 'button-memory', null, $session];
        yield 'session fc, public cache limiter' => [$publicLimiter, 'session-fc', null, $session];
        // session.cache_limiter = public replaces even a registered private policy.
        yield 'private endpoint, public cache limiter' => [$publicLimiter, 'private-session', null, $session];

        // A returning visitor: no Set-Cookie, but the form is signed for their session.
        yield 'button, cookie' => [[], 'button-memory', $cookie, $session];
        yield 'snapshot button, cookie, secret' => [self::SECRET, 'button-snapshot', $cookie, $session];
        yield 'button, cookie, no cache limiter' => [$noLimiter, 'button-memory', $cookie, $session];

        // The app started the session before usePHP ran.
        yield 'app session, new visitor' => [self::APP_SESSION, 'static', null, $session];
        yield 'app session, cookie' => [self::APP_SESSION, 'static', $cookie, $session];
        yield 'app session, button' => [self::APP_SESSION + self::SECRET, 'button-snapshot', $cookie, $session];
        yield 'app session, max-age only' => [self::APP_SESSION, 'static-max-age', null, $session];

        // The session was read and closed again before the headers go out.
        yield 'app closed session, new visitor' => [$closed, 'static', null, $session];
        yield 'app closed session, cookie' => [$closed, 'static', $cookie, $session];
        yield 'component closed session' => [$noLimiter, 'reads-then-closes', $cookie, $session];

        // A cookie that has nothing to do with the session.
        yield 'app cookie' => [[], 'app-cookie', null, 'sets a cookie'];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('visitorResponseProvider')]
    public function testAVisitorsResponseIsNotLeftToSharedCaches(array $env, string $name, ?string $cookie, string $reason): void
    {
        $log = $this->errorLog();
        $base = $this->startServer($env + ['USEPHP_TEST_ERROR_LOG' => $log], self::APP);

        $response = $this->http($base . '/_defer/' . $name, cookie: $cookie);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(['Cache-Control: private, no-store'], self::cacheControlOf($response['headers']));
        $logged = (string) file_get_contents($log);
        self::assertStringContainsString("[usePHP] defer endpoint '{$name}'", $logged);
        self::assertStringContainsString($reason, $logged);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string, 2: ?string, 3: string}>
     */
    public static function sharedResponseProvider(): iterable
    {
        $cookie = self::SESSION_COOKIE;

        yield 'static' => [[], 'static', null, 'public, s-maxage=60'];
        yield 'max-age only' => [[], 'static-max-age', null, 'max-age=60'];
        yield 'secret' => [self::SECRET, 'static', null, 'public, s-maxage=60'];
        yield 'no cache limiter' => [['USEPHP_TEST_CACHE_LIMITER' => 'none'], 'static', null, 'public, s-maxage=60'];
        // Nothing starts the session, so its data cannot reach the response.
        yield 'a session cookie alone' => [[], 'static', $cookie, 'public, s-maxage=60'];
        yield 'a session cookie alone, secret' => [self::SECRET, 'static', $cookie, 'public, s-maxage=60'];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('sharedResponseProvider')]
    public function testAResponseThatUsesNoSessionKeepsItsPolicy(array $env, string $name, ?string $cookie, string $policy): void
    {
        $log = $this->errorLog();
        $base = $this->startServer($env + ['USEPHP_TEST_ERROR_LOG' => $log], self::APP);

        $response = $this->http($base . '/_defer/' . $name, cookie: $cookie);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(['Cache-Control: ' . $policy], self::cacheControlOf($response['headers']));
        self::assertSame([], preg_grep('/^Set-Cookie:/i', $response['headers']));
        self::assertSame([], preg_grep('/^(Pragma|Expires):/i', $response['headers']));
        self::assertSame('', (string) file_get_contents($log));
    }

    public function testASignedFormWithoutASessionIsTheSameForEveryVisitor(): void
    {
        $base = $this->startServer(self::SECRET, self::APP);

        $first = $this->http($base . '/_defer/button-snapshot');
        $second = $this->http($base . '/_defer/button-snapshot');

        self::assertSame(['Cache-Control: public, s-maxage=60'], self::cacheControlOf($first['headers']));
        self::assertSame([], preg_grep('/^Set-Cookie:/i', $first['headers']));
        self::assertStringContainsString('_usephp_action_sig', $first['body']);
        self::assertStringNotContainsString('_usephp_csrf', $first['body']);
        self::assertSame(self::firstFormFields($first['body']), self::firstFormFields($second['body']));
    }

    public function testAnEndpointThatAskedForPrivacyIsNotWarnedAbout(): void
    {
        $log = $this->errorLog();
        $base = $this->startServer(['USEPHP_TEST_ERROR_LOG' => $log, 'USEPHP_TEST_CACHE_LIMITER' => 'none'], self::APP);

        $response = $this->http($base . '/_defer/private-session');

        self::assertSame(['Cache-Control: private, no-store'], self::cacheControlOf($response['headers']));
        self::assertNotSame([], preg_grep('/^Set-Cookie:/i', $response['headers']));
        self::assertSame('', (string) file_get_contents($log));
    }

    public function testHeadersThatWereAlreadySentAreReported(): void
    {
        $log = $this->errorLog();
        $base = $this->startServer(self::APP_SESSION + ['USEPHP_TEST_EARLY_OUTPUT' => '1', 'USEPHP_TEST_ERROR_LOG' => $log], self::APP);

        $this->http($base . '/_defer/static');

        self::assertStringContainsString('headers were already sent', (string) file_get_contents($log));
    }

    private function errorLog(): string
    {
        $log = tempnam(sys_get_temp_dir(), 'usephp-defer-cache-');
        self::assertIsString($log);

        return $this->log = $log;
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private static function cacheControlOf(array $headers): array
    {
        return array_values(preg_grep('/^Cache-Control:/i', $headers) ?: []);
    }
}
