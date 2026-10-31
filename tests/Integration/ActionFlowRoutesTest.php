<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What happens after an action was accepted: partial response or PRG
 * redirect, the redirect target, and what the re-render can see.
 */
final class ActionFlowRoutesTest extends ActionFlowServerTestCase
{
    private const SECRET = ['USEPHP_TEST_SECRET' => 'integration-secret'];

    private const PARTIAL = ['X-UsePHP-Partial' => '1'];

    /**
     * Follow a 303 the way the browser does.
     *
     * @param array{headers: list<string>} $response
     */
    private static function location(array $response): ?string
    {
        foreach ($response['headers'] as $line) {
            if (preg_match('/^Location:\s*(.+)$/i', $line, $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // #63: class + Snapshot on a non-Isolated route keeps the PRG redirect,
    // which is what stores the snapshot.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function snapshotRouteProvider(): iterable
    {
        yield 'sessionSnapshot()' => ['/session-route'];
        yield 'persistentSnapshot()' => ['/persistent-route'];
        yield 'sharedSnapshot()' => ['/shared-route'];
    }

    #[DataProvider('snapshotRouteProvider')]
    public function testClassSnapshotKeepsItsStateAcrossReloadsWithJavaScript(string $path): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $path);
        $cookie = $page['cookie'];

        foreach (['count=1', 'count=2'] as $expected) {
            $response = $this->http(
                $base . $path,
                self::firstFormFields($page['body']),
                ['Origin' => $base] + self::PARTIAL,
                $cookie,
            );
            self::assertSame(303, $response['status'], $response['body']);

            $target = self::location($response);
            self::assertNotNull($target);
            $page = $this->http($base . $target, cookie: $cookie);
            self::assertStringContainsString($expected, $page['body']);

            // Reloading the page the redirect landed on shows the same state.
            self::assertStringContainsString($expected, $this->http($base . $target, cookie: $cookie)['body']);
        }
    }

    public function testClassSnapshotOnAPlainRouteStillAnswersWithAPartial(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/snapshot-class');

        $response = $this->http(
            $base . '/snapshot-class',
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
        );

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('count=1', $response['body']);
    }

    public function testClassSessionStorageIsNotAffectedByTheRouteBehavior(): void
    {
        $base = $this->startServer(self::SECRET);
        $path = '/session-counter-on-persistent-route';
        $page = $this->http($base . $path);

        $response = $this->http(
            $base . $path,
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $page['cookie'],
        );

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('count=1', $response['body']);
    }

    public function testRestoreOnASnapshotRouteStillAnswersWithAPartial(): void
    {
        // usephp.js forgets the saved snapshot when `restore` is redirected.
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/persisted-on-persistent-route');
        $fields = self::firstFormFields($page['body']);
        $fields['_usephp_action'] = json_encode([
            'type' => 'restore',
            'payload' => (object) [],
            'componentId' => $fields['_usephp_component'],
            'storageType' => 'snapshot',
        ], JSON_THROW_ON_ERROR);
        self::assertSame(1, preg_match('/data-usephp-restore-sig="([^"]+)"/', $page['body'], $signature));
        $fields['_usephp_action_sig'] = html_entity_decode($signature[1], ENT_QUOTES, 'UTF-8');

        $response = $this->http($base . '/persisted-on-persistent-route', $fields, ['Origin' => $base] + self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=0', $response['body']);
    }

    // ------------------------------------------------------------------
    // #64: the redirect keeps the query, except for the snapshot it replaces.
    // ------------------------------------------------------------------

    public function testRedirectKeepsTheQueryOnAPlainRoute(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-fc?tab=2');

        $response = $this->http(
            $base . '/session-fc?tab=2',
            self::firstFormFields($page['body']),
            ['Origin' => $base],
            $page['cookie'],
        );

        self::assertSame(303, $response['status'], $response['body']);
        self::assertSame('/session-fc?tab=2', self::location($response));
    }

    public function testRedirectKeepsTheQueryNextToANewSnapshot(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/persistent-route?page=3');
        $url = '/persistent-route?page=3';

        foreach (['count=1', 'count=2'] as $expected) {
            $response = $this->http($base . $url, self::firstFormFields($page['body']), ['Origin' => $base]);
            self::assertSame(303, $response['status'], $response['body']);

            $url = self::location($response);
            self::assertIsString($url);
            self::assertStringStartsWith('/persistent-route?page=3&_snapshot=', $url);
            self::assertSame(1, substr_count($url, '_snapshot='));

            $page = $this->http($base . $url);
            self::assertStringContainsString($expected, $page['body']);
        }
    }

    public function testProtocolRelativeRequestRedirectsToAPathAndKeepsTheQuery(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-fc');

        $response = $this->http(
            $base . '//evil.example/session-fc?tab=2',
            self::firstFormFields($page['body']),
            ['Origin' => $base],
            $page['cookie'],
        );

        self::assertSame(303, $response['status'], $response['body']);
        self::assertSame('/session-fc?tab=2', self::location($response));
    }

    // ------------------------------------------------------------------
    // #65: a partial re-render sees what the page's GET saw.
    // ------------------------------------------------------------------

    public function testPartialRerenderKeepsTheRouteParams(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/tasks/1');
        self::assertStringContainsString('task=1 likes=0', $page['body']);

        $response = $this->http(
            $base . '/tasks/1',
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $page['cookie'],
        );

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('task=1 likes=1', $response['body']);
    }

    public function testPartialRerenderOfADeferredClassKeepsTheDeferQuery(): void
    {
        $base = $this->startServer();
        $fragment = $this->http($base . '/_defer/post-likes?post_id=5');
        self::assertStringContainsString('post=5 likes=0', $fragment['body']);
        $fields = self::firstFormFields($fragment['body']);
        $cookie = $fragment['cookie'];

        // The form sits on some page, so the POST goes to that page's URL.
        foreach (['post=5 likes=1', 'post=5 likes=2'] as $expected) {
            $response = $this->http($base . '/session-fc?tab=2', $fields, ['Origin' => $base] + self::PARTIAL, $cookie);

            self::assertSame(200, $response['status'], $response['body']);
            self::assertStringContainsString($expected, $response['body']);
            $fields = self::firstFormFields($response['body']);
        }
    }

    public function testDeferQueryIsOnlyEmittedByTheDeferEndpoint(): void
    {
        $base = $this->startServer();

        self::assertArrayNotHasKey(
            '_usephp_defer_query',
            self::firstFormFields($this->http($base . '/session-class?post_id=5')['body']),
        );
        self::assertArrayNotHasKey(
            '_usephp_defer_query',
            self::firstFormFields($this->http($base . '/_defer/post-likes')['body']),
        );
    }

    public function testDeferQueryDoesNotBypassTheActionChecks(): void
    {
        $base = $this->startServer();
        $fragment = $this->http($base . '/_defer/post-likes?post_id=5');
        $fields = self::firstFormFields($fragment['body']);
        unset($fields['_usephp_action_sig']);

        $response = $this->http(
            $base . '/session-fc',
            $fields,
            ['Origin' => $base] + self::PARTIAL,
            $fragment['cookie'],
        );

        self::assertSame(400, $response['status'], $response['body']);
    }

    // ------------------------------------------------------------------
    // #78: fc() + Session updates in place.
    // ------------------------------------------------------------------

    public function testSessionFcAnswersWithAPartial(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-fc');
        $cookie = $page['cookie'];
        $fields = self::firstFormFields($page['body']);

        foreach (['count=1', 'count=2'] as $expected) {
            $response = $this->http($base . '/session-fc', $fields, ['Origin' => $base] + self::PARTIAL, $cookie);

            self::assertSame(200, $response['status'], $response['body']);
            self::assertNull(self::location($response));
            self::assertStringContainsString($expected, $response['body']);
            // The wrapper stays on the page; the partial is only its contents.
            self::assertStringNotContainsString('data-usephp=', $response['body']);
            $fields = self::firstFormFields($response['body']);
        }

        self::assertStringContainsString('count=2', $this->http($base . '/session-fc', cookie: $cookie)['body']);
    }

    public function testSessionFcWithoutJavaScriptStillRedirects(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-fc');

        $response = $this->http(
            $base . '/session-fc',
            self::firstFormFields($page['body']),
            ['Origin' => $base],
            $page['cookie'],
        );

        self::assertSame(303, $response['status'], $response['body']);
    }

    public function testSessionFcPartialContainsOnlyTheTargetInstance(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/two-session-fc');

        $response = $this->http(
            $base . '/two-session-fc',
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $page['cookie'],
        );

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=1', $response['body']);
        self::assertStringNotContainsString('count=0', $response['body']);
        self::assertStringNotContainsString('page-chrome', $response['body']);
    }

    public function testSessionFcPartialKeepsTheRouteParams(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/tasks-fc/7');

        $response = $this->http(
            $base . '/tasks-fc/7',
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $page['cookie'],
        );

        self::assertStringContainsString('task=7 likes=1', $response['body']);
    }

    public function testSessionFcOnAPersistentRouteAnswersWithAPartial(): void
    {
        // Session state does not travel through the redirect, so the route's
        // snapshot behavior has nothing to do for it.
        $base = $this->startServer(self::SECRET);
        $path = '/session-fc-on-persistent-route';
        $page = $this->http($base . $path);

        $response = $this->http(
            $base . $path,
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $page['cookie'],
        );

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=1', $response['body']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function snapshotFcRouteProvider(): iterable
    {
        yield 'sessionSnapshot()' => ['/snapshot-fc-on-session-route'];
        yield 'persistentSnapshot()' => ['/snapshot-fc-on-persistent-route'];
        yield 'sharedSnapshot()' => ['/snapshot-fc-on-shared-route'];
    }

    #[DataProvider('snapshotFcRouteProvider')]
    public function testSnapshotFcOnASnapshotRouteKeepsTheRedirect(string $path): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $path);
        $cookie = $page['cookie'];

        $response = $this->http(
            $base . $path,
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $cookie,
        );

        self::assertSame(303, $response['status'], $response['body']);
        $target = self::location($response);
        self::assertIsString($target);
        self::assertStringContainsString('count=1', $this->http($base . $target, cookie: $cookie)['body']);
    }

    public function testSnapshotFcOnAPlainRouteStillAnswersWithAPartial(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/snapshot-fc');

        $response = $this->http(
            $base . '/snapshot-fc',
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
        );

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=1', $response['body']);
    }

    public function testSessionFcOnAHandlerThatCannotBeReplayedFallsBackToTheRedirect(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-fc-printed');
        $cookie = $page['cookie'];

        $response = $this->http(
            $base . '/session-fc-printed',
            self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $cookie,
        );

        // What the handler printed while being replayed must not block the
        // redirect or end up in the response.
        self::assertSame(303, $response['status'], $response['body']);
        self::assertSame('', $response['body']);
        self::assertSame('/session-fc-printed', self::location($response));
        self::assertStringContainsString('count=1', $this->http($base . '/session-fc-printed', cookie: $cookie)['body']);
    }

    // ------------------------------------------------------------------
    // #96: fc() + Memory has no state to go back to after a redirect, so the
    // partial response is the only place an action can take effect.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function memoryEnvironmentProvider(): iterable
    {
        yield 'no session_start, no secret' => [[]];
        yield 'no session_start, secret' => [self::SECRET];
        yield 'session_start, no secret' => [['USEPHP_TEST_SESSION_START' => '1']];
        yield 'session_start, secret' => [self::SECRET + ['USEPHP_TEST_SESSION_START' => '1']];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('memoryEnvironmentProvider')]
    public function testMemoryFcUpdatesAcrossActions(array $env): void
    {
        $base = $this->startServer($env);
        $page = $this->http($base . '/memory-fc');
        $cookie = $page['cookie'];
        $fields = self::firstFormFields($page['body']);

        foreach (['count=1', 'count=2', 'count=3'] as $expected) {
            $response = $this->http($base . '/memory-fc', $fields, ['Origin' => $base] + self::PARTIAL, $cookie);

            self::assertSame(200, $response['status'], $response['body']);
            self::assertNull(self::location($response));
            self::assertStringContainsString($expected, $response['body']);
            $fields = self::firstFormFields($response['body']);
        }

        // Memory state ends with the request: a reload starts over.
        self::assertStringContainsString('count=0', $this->http($base . '/memory-fc', cookie: $cookie)['body']);
    }

    /**
     * The other useState of a Memory component is not carried between actions:
     * each POST starts from an empty store and applies only the signed value.
     * This holds for class components and fc() alike. Use Session or Snapshot
     * storage for components with more than one piece of state.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function memoryTwoStatesProvider(): iterable
    {
        yield 'class' => ['/memory-two-states-class'];
        yield 'fc' => ['/memory-two-states-fc'];
    }

    #[DataProvider('memoryTwoStatesProvider')]
    public function testMemoryComponentLosesItsOtherStateOnEachAction(string $path): void
    {
        $base = $this->startServer();
        $page = $this->http($base . $path);
        $cookie = $page['cookie'];
        $headers = ['Origin' => $base] + self::PARTIAL;

        $first = $this->http($base . $path, self::firstFormFields($page['body']), $headers, $cookie);
        self::assertStringContainsString('a=1', $first['body']);
        self::assertStringContainsString('b=0', $first['body']);

        self::assertSame(2, preg_match_all('/<form\b.*?<\/form>/s', $first['body'], $forms));
        $second = $this->http($base . $path, self::firstFormFields($forms[0][1]), $headers, $cookie);
        self::assertStringContainsString('b=1', $second['body']);
        self::assertStringContainsString('a=0', $second['body']);
    }
}
