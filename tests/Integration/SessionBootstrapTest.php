<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #55: actions must work whether or not the app calls session_start(), and
 * making them work must not relax the CSRF or signature checks.
 */
final class SessionBootstrapTest extends ActionFlowServerTestCase
{
    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function workingCombinationProvider(): iterable
    {
        $secret = ['USEPHP_TEST_SECRET' => 'integration-secret'];
        $sessionStart = ['USEPHP_TEST_SESSION_START' => '1'];

        foreach (['/session-class', '/session-fc', '/memory-class', '/memory-fc'] as $path) {
            yield "no session_start, no secret: {$path}" => [[], $path];
            yield "no session_start, secret: {$path}" => [$secret, $path];
            yield "session_start, no secret: {$path}" => [$sessionStart, $path];
            yield "session_start, secret: {$path}" => [$secret + $sessionStart, $path];
        }
        foreach (['/snapshot-class', '/snapshot-fc'] as $path) {
            yield "no session_start, secret: {$path}" => [$secret, $path];
            yield "session_start, secret: {$path}" => [$secret + $sessionStart, $path];
        }
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('workingCombinationProvider')]
    public function testActionIsAcceptedInEveryCombination(array $env, string $path): void
    {
        $base = $this->startServer($env);

        $page = $this->http($base . $path);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('count=0', $page['body']);

        $response = $this->http(
            $base . $path,
            self::firstFormFields($page['body']),
            ['Origin' => $base],
            $page['cookie'],
        );

        self::assertContains(
            $response['status'],
            [200, 303],
            'action rejected: ' . $response['status'] . ' ' . $response['body'],
        );
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function sessionStateProvider(): iterable
    {
        foreach (['/session-class', '/session-fc'] as $path) {
            yield "no secret: {$path}" => [[], $path];
            yield "secret: {$path}" => [['USEPHP_TEST_SECRET' => 'integration-secret'], $path];
        }
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('sessionStateProvider')]
    public function testSessionStateSurvivesTwoFullPagePosts(array $env, string $path): void
    {
        $base = $this->startServer($env);

        $page = $this->http($base . $path);
        $cookie = $page['cookie'];
        self::assertNotNull($cookie);

        foreach (['count=1', 'count=2'] as $expected) {
            $response = $this->http($base . $path, self::firstFormFields($page['body']), ['Origin' => $base], $cookie);
            self::assertSame(303, $response['status'], $response['body']);

            $page = $this->http($base . $path, cookie: $cookie);
            self::assertStringContainsString($expected, $page['body']);
        }
    }

    public function testFormsCarryTheCsrfTokenOnTheFirstRender(): void
    {
        $base = $this->startServer();

        foreach (['/session-class', '/session-fc', '/memory-class', '/memory-fc'] as $path) {
            $fields = self::firstFormFields($this->http($base . $path)['body']);

            self::assertNotSame('', $fields['_usephp_csrf'] ?? '', "no CSRF token in the form of {$path}");
            self::assertNotSame('', $fields['_usephp_action_sig'] ?? '', "no signature in the form of {$path}");
        }
    }

    public function testSnapshotPageStaysSessionlessWithoutACookie(): void
    {
        $base = $this->startServer(['USEPHP_TEST_SECRET' => 'integration-secret']);

        $page = $this->http($base . '/snapshot-class');

        self::assertNull($page['cookie'], 'a Snapshot page must not create a session');
        self::assertArrayNotHasKey('_usephp_csrf', self::firstFormFields($page['body']));

        $response = $this->http(
            $base . '/snapshot-class',
            self::firstFormFields($page['body']),
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
        );
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=1', $response['body']);
        self::assertNull($response['cookie'], 'a Snapshot action must not create a session');
    }

    public function testVisitorWithASessionCookieGetsSessionBoundSnapshotForms(): void
    {
        $base = $this->startServer(['USEPHP_TEST_SECRET' => 'integration-secret']);
        $cookie = $this->http($base . '/session-class')['cookie'];
        self::assertNotNull($cookie);

        // The same browser now opens a page that does not itself use the
        // session. POST will resume the session, so the form has to match.
        $page = $this->http($base . '/snapshot-class', cookie: $cookie);
        $fields = self::firstFormFields($page['body']);
        self::assertNotSame('', $fields['_usephp_csrf'] ?? '');

        $response = $this->http(
            $base . '/snapshot-class',
            $fields,
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
            $cookie,
        );
        self::assertSame(200, $response['status'], $response['body']);
    }

    // ------------------------------------------------------------------
    // Nothing above may relax the checks.
    // ------------------------------------------------------------------

    public function testPostWithoutCookieDoesNotAcceptAFormSignedForASession(): void
    {
        foreach ([[], ['USEPHP_TEST_SECRET' => 'integration-secret']] as $env) {
            $base = $this->startServer($env);
            $fields = self::firstFormFields($this->http($base . '/session-class')['body']);

            $response = $this->http($base . '/session-class', $fields, ['Origin' => $base]);

            self::assertSame(400, $response['status'], $response['body']);
            self::assertSame('Invalid action signature', $response['body']);
            self::assertNull($response['cookie'], 'a rejected POST must not create a session');
        }
    }

    public function testPostWithoutCookieOrSecretIsRejectedAndCreatesNoSession(): void
    {
        $base = $this->startServer();

        $response = $this->http($base . '/memory-fc', [
            '_usephp_component' => 'memory-fc#0',
            '_usephp_action' => '{"type":"setState","payload":{"index":0,"value":1}}',
            '_usephp_action_sig' => str_repeat('0', 64),
        ], ['Origin' => $base]);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertNull($response['cookie']);
    }

    public function testPostWithCookieAndNoCsrfTokenIsRejected(): void
    {
        foreach (['/session-class', '/session-fc', '/memory-fc'] as $path) {
            $base = $this->startServer();
            $page = $this->http($base . $path);
            $fields = self::firstFormFields($page['body']);
            unset($fields['_usephp_csrf']);

            $response = $this->http($base . $path, $fields, ['Origin' => $base], $page['cookie']);

            self::assertSame(403, $response['status'], $response['body']);
        }
    }

    public function testPostWithAWrongCsrfTokenIsRejected(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-class');
        $fields = self::firstFormFields($page['body']);
        $fields['_usephp_csrf'] = str_repeat('a', 64);

        $response = $this->http($base . '/session-class', $fields, ['Origin' => $base], $page['cookie']);

        self::assertSame(403, $response['status'], $response['body']);
    }

    public function testCrossOriginPostIsRejectedWithAndWithoutCookie(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-class');
        $fields = self::firstFormFields($page['body']);

        foreach ([$page['cookie'], null] as $cookie) {
            $response = $this->http($base . '/session-class', $fields, ['Origin' => 'http://evil.example'], $cookie);
            self::assertSame(403, $response['status'], $response['body']);
        }
    }

    public function testPostWithoutOriginIsRejectedWithoutCookie(): void
    {
        $base = $this->startServer(['USEPHP_TEST_SECRET' => 'integration-secret']);
        $fields = self::firstFormFields($this->http($base . '/snapshot-class')['body']);

        $response = $this->http($base . '/snapshot-class', $fields);

        self::assertSame(403, $response['status'], $response['body']);
    }

    public function testFormRenderedInOneSessionCannotBeReplayedInAnother(): void
    {
        $base = $this->startServer(['USEPHP_TEST_SECRET' => 'integration-secret']);
        $attacker = $this->http($base . '/session-class');
        $victim = $this->http($base . '/session-class');
        self::assertNotNull($victim['cookie']);
        self::assertNotSame($attacker['cookie'], $victim['cookie']);

        $victimFields = self::firstFormFields($victim['body']);
        $replayed = self::firstFormFields($attacker['body']);
        // Even with the victim's own CSRF token, the signature is the attacker's.
        $replayed['_usephp_csrf'] = $victimFields['_usephp_csrf'];

        $response = $this->http($base . '/session-class', $replayed, ['Origin' => $base], $victim['cookie']);

        self::assertSame(400, $response['status'], $response['body']);
    }

    public function testUnknownSessionCookieGainsNothing(): void
    {
        $base = $this->startServer(['USEPHP_TEST_SECRET' => 'integration-secret']);
        $fields = self::firstFormFields($this->http($base . '/snapshot-class')['body']);

        $response = $this->http(
            $base . '/snapshot-class',
            $fields,
            ['Origin' => $base],
            'PHPSESSID=' . str_repeat('a', 32),
        );

        self::assertSame(403, $response['status'], $response['body']);
    }

    public function testMalformedSessionCookieFailsClosed(): void
    {
        $base = $this->startServer(['USEPHP_TEST_SECRET' => 'integration-secret']);
        $fields = self::firstFormFields($this->http($base . '/snapshot-class')['body']);

        $response = $this->http(
            $base . '/snapshot-class',
            $fields,
            ['Origin' => $base],
            'PHPSESSID=' . str_repeat('!', 300),
        );

        self::assertContains($response['status'], [400, 403], $response['body']);
    }

    public function testCookieWithoutAStartableSessionFailsClosed(): void
    {
        // Output already went out, so the session cannot start. The token
        // layer must not silently disappear for a visitor who has a session.
        $base = $this->startServer([
            'USEPHP_TEST_SECRET' => 'integration-secret',
            'USEPHP_TEST_EARLY_OUTPUT' => '1',
        ]);
        $fields = self::firstFormFields($this->http($base . '/snapshot-class')['body']);

        $response = $this->http(
            $base . '/snapshot-class',
            $fields,
            ['Origin' => $base],
            'PHPSESSID=' . str_repeat('a', 32),
        );

        self::assertStringContainsString('Forbidden', $response['body']);
        self::assertStringNotContainsString('count=1', $response['body']);
    }
}
