<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A replay runs the page's handler again from a POST, and a no-JS answer
 * returns the whole page. Neither may get around what guards a normal GET:
 * route middleware (#56) and the rule that a deferred component only renders
 * inline on its own endpoint (#59).
 */
final class SnapshotReplayGuardsTest extends SnapshotInstancesServerTestCase
{
    private const AUTH = 'auth=yes';

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function guardedActionProvider(): iterable
    {
        yield 'plain POST, Element handler' => ['/guarded', []];
        yield 'plain POST, string handler' => ['/guarded-string', []];
        yield 'partial, Element handler' => ['/guarded', self::PARTIAL];
        yield 'partial, string handler' => ['/guarded-string', self::PARTIAL];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('guardedActionProvider')]
    public function testAnActionWithoutPermissionIsForbiddenAndNeverRunsTheHandler(string $path, array $headers): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $html = $this->http($base . $path, cookie: self::AUTH)['body'];
        file_put_contents($log, '');

        $response = $this->press($base, $path, $html, 'guarded', null, $headers);

        self::assertSame(403, $response['status'], $response['body']);
        self::assertStringNotContainsString('guarded=', $response['body']);
        self::assertStringNotContainsString('GUARDED-HANDLER-RAN', (string) file_get_contents($log));
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('guardedActionProvider')]
    public function testAnActionWithPermissionWorks(string $path, array $headers): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . $path, cookie: self::AUTH)['body'];

        $response = $this->press($base, $path, $html, 'guarded', self::AUTH, $headers);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('guarded=1', $response['body']);
    }

    public function testADeferredTargetIsNotReachedThroughAGuardedPage(): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $html = $this->http($base . '/_defer/ds?tag=7')['body'];

        $response = $this->press($base, '/guarded-defer', $html, 'ds', null, self::PARTIAL);

        self::assertSame(403, $response['status'], $response['body']);
        self::assertStringNotContainsString('ds=1', $response['body']);
        self::assertStringNotContainsString('GUARDED-HANDLER-RAN', (string) file_get_contents($log));
    }

    public function testAReplayKeepsANestedDeferAsAPlaceholder(): void
    {
        $base = $this->startServer(self::SECRET);
        $fragment = $this->http($base . '/_defer/outer-defer');
        self::assertContains('Cache-Control: public, s-maxage=60', $fragment['headers']);
        self::assertStringNotContainsString('inner-secret', $fragment['body']);

        $response = $this->press($base, '/defer-nested', $fragment['body'], 'outer', null, self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('outer=1', $response['body']);
        self::assertStringNotContainsString('inner-secret', $response['body']);
        self::assertStringContainsString('data-usephp-defer-url="/_defer/inner-defer"', $response['body']);
        // The replay must not put this answer under the outer endpoint's policy.
        self::assertSame([], preg_grep('/^Cache-Control:.*public/i', $response['headers']));
    }

    public function testAnActionInsideANestedDeferFindsItThroughTheOuterPlaceholder(): void
    {
        $base = $this->startServer(self::SECRET);
        $fragment = $this->http($base . '/_defer/inner-defer')['body'];

        $response = $this->press($base, '/defer-nested', $fragment, 'inner-secret', null, self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('inner-secret=1', $response['body']);
        self::assertStringNotContainsString('outer=', $response['body']);
        self::assertSame([], preg_grep('/^Cache-Control:.*public/i', $response['headers']));
    }

    public function testThePlainAnswerOfAPageWithADeferKeepsThePlaceholder(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/defer-page');
        self::assertStringContainsString('data-usephp-defer-url="/_defer/ds?tag=7"', $page['body']);

        // A plain POST of a fragment's form: the answer is the page, and what
        // was deferred stays deferred.
        $fragment = $this->http($base . '/_defer/ds?tag=7')['body'];
        $response = $this->press($base, '/defer-page', $fragment, 'ds', null);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('page-chrome', $response['body']);
        self::assertStringContainsString('data-usephp-defer-url="/_defer/ds?tag=7"', $response['body']);
        self::assertStringNotContainsString('ds=', $response['body']);
    }

    // ------------------------------------------------------------------
    // #74: the handler resolution moved along with the shared handler call.
    // ------------------------------------------------------------------

    public function testAPlainPostFindsAComponentByItsRegisteredName(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/renamed-class')['body'];

        $response = $this->press($base, '/renamed-class', $html, 'count', null);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=1', $response['body']);
    }

    // ------------------------------------------------------------------
    // The plain answer with _usephp_defer_query and a late CSRF token.
    // ------------------------------------------------------------------

    public function testAPlainAnswerIgnoresADeferQuery(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/query-page')['body'];

        $response = $this->press($base, '/query-page', $html, 'q', null, extra: ['_usephp_defer_query' => 'x=client']);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('q=1', $response['body']);
        self::assertStringContainsString('query=none', $response['body']);
        self::assertStringNotContainsString('_usephp_defer_query', $response['body']);
    }

    public function testAPartialOfADeferredComponentKeepsItsDeferQuery(): void
    {
        $base = $this->startServer(self::SECRET);
        $fragment = $this->http($base . '/_defer/ds?tag=7')['body'];
        self::assertSame('tag=7', self::formFor($fragment, 'ds')['_usephp_defer_query']);

        $response = $this->press($base, '/defer-page', $fragment, 'ds', null, self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame('tag=7', self::formFor($response['body'], 'ds')['_usephp_defer_query']);
    }

    public function testASessionStartedByThePlainAnswerGetsItsTokenAndKeepsWorking(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/late-session');
        self::assertNull($page['cookie']);
        self::assertArrayNotHasKey('_usephp_csrf', self::formFor($page['body'], 'late'));

        $first = $this->press($base, '/late-session', $page['body'], 'late', null);
        self::assertSame(200, $first['status'], $first['body']);
        self::assertStringContainsString('late=1', $first['body']);
        $cookie = $first['cookie'];
        self::assertNotNull($cookie, 'the render started the session');

        // Both forms were rendered after it started, so the cookie and token
        // the browser now holds are accepted for either of them.
        self::assertArrayHasKey('_usephp_csrf', self::formFor($first['body'], 'late'));
        self::assertArrayHasKey('_usephp_csrf', self::formFor($first['body'], 'late-sess'));

        $second = $this->press($base, '/late-session', $first['body'], 'late', $cookie);
        self::assertSame(200, $second['status'], $second['body']);
        self::assertStringContainsString('late=2', $second['body']);

        $third = $this->press($base, '/late-session', $second['body'], 'late-sess', $cookie);
        self::assertSame(200, $third['status'], $third['body']);
        self::assertStringContainsString('late=2', $third['body']);
        self::assertStringContainsString('late-sess=1', $third['body']);
    }

    // ------------------------------------------------------------------
    // #77: the carried fields reach the forms, wherever those now sit.
    // ------------------------------------------------------------------

    public function testEveryActionFormOfThePlainAnswerCarriesTheSnapshots(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];

        $answer = $this->press($base, '/nested', $html, 'child', null)['body'];

        preg_match_all('/<form\b[^>]*\bhidden\b[^>]*>.*?<\/form>/s', $answer, $forms);
        self::assertCount(3, $forms[0]);
        foreach ($forms[0] as $form) {
            self::assertSame(3, substr_count($form, 'name="_usephp_snapshots[]"'), $form);
        }
        foreach (['parent', 'child', 'grandchild'] as $label) {
            self::assertCount(3, self::formFor($answer, $label)['_usephp_snapshots']);
        }
    }

    public function testTextThatLooksLikeRendererOutputIsNotCarried(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/lookalike-page')['body'];

        $answer = $this->press($base, '/lookalike-page', $html, 'a', null);
        self::assertSame(200, $answer['status'], $answer['body']);
        self::assertStringContainsString("<form data-usephp-form>';</script>", $answer['body']);
        self::assertStringNotContainsString('value="forged"', $answer['body']);
        self::assertStringNotContainsString('value="also forged"', $answer['body']);

        $next = $this->press($base, '/lookalike-page', $answer['body'], 'b', null);
        self::assertSame(200, $next['status'], $next['body']);
        self::assertStringContainsString('a=1', $next['body']);
        self::assertStringContainsString('b=1', $next['body']);
    }
}
