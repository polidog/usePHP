<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #56: an action is signed for the page it was rendered on, so a form received
 * on a guarded page cannot be submitted to another URL to get around the guard.
 */
final class ActionPageBindingTest extends SnapshotInstancesServerTestCase
{
    private const AUTH = 'auth=yes';

    /**
     * A session that was shown /admin while it had access: its session cookie,
     * and the page it was shown.
     *
     * @return array{0: string, 1: string, 2: string} Base URL, cookie and page HTML
     */
    private function adminPage(array $env = []): array
    {
        $base = $this->startServer(self::SECRET + $env);
        $page = $this->http($base . '/admin', cookie: self::AUTH);
        self::assertSame(200, $page['status'], $page['body']);
        self::assertNotNull($page['cookie']);

        return [$base, $page['cookie'], $page['body']];
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function deliveryProvider(): iterable
    {
        yield 'session state, plain POST' => ['bound', []];
        yield 'session state, partial' => ['bound', self::PARTIAL];
        yield 'snapshot state, plain POST' => ['snap', []];
        yield 'snapshot state, partial' => ['snap', self::PARTIAL];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('deliveryProvider')]
    public function testAFormFromTheGuardedPageIsRejectedOnAnOpenOne(string $label, array $headers): void
    {
        [$base, $cookie, $html] = $this->adminPage();

        $response = $this->press($base, '/public', $html, $label, $cookie, $headers);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertStringContainsString('bound=0', $this->http($base . '/public', cookie: $cookie)['body']);
    }

    public function testANoAccessPostToTheGuardedPageIsForbiddenAndRunsNothing(): void
    {
        $log = $this->errorLogFile();
        [$base, $cookie, $html] = $this->adminPage(['USEPHP_TEST_ERROR_LOG' => $log]);
        file_put_contents($log, '');

        foreach (['bound', 'snap'] as $label) {
            foreach ([[], self::PARTIAL] as $headers) {
                $response = $this->press($base, '/admin', $html, $label, $cookie, $headers);
                self::assertSame(403, $response['status'], $response['body']);
            }
        }

        self::assertStringNotContainsString('ADMIN-HANDLER-RAN', (string) file_get_contents($log));
    }

    public function testAPostWithAccessToTheGuardedPageWorks(): void
    {
        [$base, $cookie, $html] = $this->adminPage();
        $withAccess = $cookie . '; ' . self::AUTH;

        $redirect = $this->press($base, '/admin', $html, 'bound', $withAccess);
        self::assertSame(303, $redirect['status'], $redirect['body']);
        self::assertSame('/admin', self::locationOf($redirect));

        $html = $this->http($base . '/admin', cookie: $withAccess)['body'];
        self::assertStringContainsString('bound=1', $html);

        $partial = $this->press($base, '/admin', $html, 'bound', $withAccess, self::PARTIAL);
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('bound=2', $partial['body']);

        $direct = $this->press($base, '/admin', $html, 'snap', $withAccess);
        self::assertSame(200, $direct['status'], $direct['body']);
        self::assertStringContainsString('snap=1', $direct['body']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function otherRouteProvider(): iterable
    {
        yield 'between open routes' => ['/public', '/other'];
        yield 'between values of one route parameter' => ['/items/1', '/items/2'];
        yield 'an encoded path the router treats as another string' => ['/public', '/%70ublic'];
        yield 'a longer path' => ['/public', '/public/x'];
    }

    #[DataProvider('otherRouteProvider')]
    public function testAFormIsNotReusableOnAnotherPath(string $from, string $to): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $from);
        $cookie = $page['cookie'];

        $response = $this->press($base, $to, $page['body'], 'bound', $cookie);

        self::assertSame(400, $response['status'], $response['body']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function samePagePathProvider(): iterable
    {
        yield 'a trailing slash' => ['/public', '/public/'];
        yield 'a trailing slash, the other way' => ['/public/', '/public'];
        yield 'a query' => ['/public', '/public?page=2'];
        yield 'another query' => ['/public?a=1', '/public?b=2'];
    }

    /**
     * The router sends these to the same route, so the guard is the same one.
     */
    #[DataProvider('samePagePathProvider')]
    public function testAFormIsAcceptedOnWhatTheRouterTreatsAsThePage(string $from, string $to): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $from);

        $response = $this->press($base, $to, $page['body'], 'bound', $page['cookie'], self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('bound=1', $response['body']);
    }

    public function testATrailingSlashStillMeetsTheGuard(): void
    {
        [$base, $cookie, $html] = $this->adminPage();

        self::assertSame(403, $this->press($base, '/admin/', $html, 'bound', $cookie)['status']);
    }

    public function testANormalFlowOnAnOpenPage(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/public');
        $cookie = $page['cookie'];

        $partial = $this->press($base, '/public', $page['body'], 'bound', $cookie, self::PARTIAL);
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('bound=1', $partial['body']);

        // The forms in a partial are good for the page too.
        $again = $this->press($base, '/public', $partial['body'], 'bound', $cookie, self::PARTIAL);
        self::assertStringContainsString('bound=2', $again['body']);

        $plain = $this->press($base, '/public', $page['body'], 'bound', $cookie);
        self::assertSame(303, $plain['status'], $plain['body']);

        $direct = $this->press($base, '/public', $page['body'], 'snap', $cookie);
        self::assertSame(200, $direct['status'], $direct['body']);
        self::assertStringContainsString('snap=1', $direct['body']);
        $next = $this->press($base, '/public', $direct['body'], 'snap', $cookie);
        self::assertStringContainsString('snap=2', $next['body']);
    }

    public function testThePrgRedirectKeepsItsQuery(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/public?page=2');

        $response = $this->press($base, '/public?page=2', $page['body'], 'bound', $page['cookie']);

        self::assertSame(303, $response['status'], $response['body']);
        self::assertSame('/public?page=2', self::locationOf($response));
        self::assertStringContainsString('bound=1', $this->http($base . '/public?page=2', cookie: $page['cookie'])['body']);
    }

    // ------------------------------------------------------------------
    // Deferred fragments are rendered at /_defer/{name} but post to the page.
    // ------------------------------------------------------------------

    public function testAFormFromADeferEndpointIsAcceptedOnThePageThatHoldsIt(): void
    {
        $base = $this->startServer(self::SECRET);
        $fragment = $this->http($base . '/_defer/ds?tag=7')['body'];

        $first = $this->press($base, '/defer-page', $fragment, 'ds', null, self::PARTIAL);
        self::assertSame(200, $first['status'], $first['body']);
        self::assertStringContainsString('ds=1', $first['body']);

        // What the partial renders is bound to the page it answered.
        $second = $this->press($base, '/defer-page', $first['body'], 'ds', null, self::PARTIAL);
        self::assertStringContainsString('ds=2', $second['body']);
        self::assertSame(400, $this->press($base, '/public', $first['body'], 'ds', null, self::PARTIAL)['status']);
    }

    public function testAGuardedPageStillGuardsAFormFromADeferEndpoint(): void
    {
        $base = $this->startServer(self::SECRET);
        $fragment = $this->http($base . '/_defer/ds?tag=7')['body'];

        self::assertSame(403, $this->press($base, '/guarded-defer', $fragment, 'ds', null, self::PARTIAL)['status']);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function clientChosenFieldProvider(): iterable
    {
        yield 'defer query' => [['_usephp_defer_query' => 'tag=7']];
        yield 'a defer name' => [['_usephp_defer_name' => 'ds', '_usephp_defer' => 'ds']];
        yield 'a page path' => [['_usephp_page' => '/admin']];
    }

    /**
     * @param array<string, string> $extra
     */
    #[DataProvider('clientChosenFieldProvider')]
    public function testNoClientFieldChoosesTheBinding(array $extra): void
    {
        [$base, $cookie, $html] = $this->adminPage();

        $response = $this->press($base, '/public', $html, 'bound', $cookie, extra: $extra);

        self::assertSame(400, $response['status'], $response['body']);
    }

    // ------------------------------------------------------------------
    // restore carries no action, so it needs a binding of its own: otherwise
    // its re-render signs fresh forms for whatever page it was POSTed to.
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function restoreFields(string $html): array
    {
        self::assertSame(1, preg_match('/data-usephp="([^"]+)"/', $html, $id));
        $fields = [
            '_usephp_component' => $id[1],
            '_usephp_action' => json_encode(['type' => 'restore', 'payload' => [], 'componentId' => $id[1], 'storageType' => 'snapshot'], JSON_THROW_ON_ERROR),
            '_usephp_snapshot' => self::wrapperSnapshots($html)[0],
        ];
        if (preg_match('/data-usephp-restore-sig="([^"]+)"/', $html, $sig) === 1) {
            $fields['_usephp_action_sig'] = html_entity_decode($sig[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/name="_usephp_csrf" value="([^"]+)"/', $html, $csrf) === 1) {
            $fields['_usephp_csrf'] = html_entity_decode($csrf[1], ENT_QUOTES, 'UTF-8');
        }

        return $fields;
    }

    public function testRestoreOnThePageWorks(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/admin-class', cookie: self::AUTH);
        $cookie = ($page['cookie'] !== null ? $page['cookie'] . '; ' : '') . self::AUTH;

        $response = $this->post($base . '/admin-class', self::restoreFields($page['body']), ['Origin' => $base] + self::PARTIAL, $cookie);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=0', $response['body']);
    }

    public function testRestoreOfAGuardedComponentIsRejectedOnAnOpenPage(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/admin-class', cookie: self::AUTH);

        $response = $this->post($base . '/public', self::restoreFields($page['body']), ['Origin' => $base] + self::PARTIAL, $page['cookie']);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertStringNotContainsString('count=', $response['body']);
    }

    public function testRestoreWithoutItsSignatureIsRejected(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/admin-class', cookie: self::AUTH);
        $cookie = ($page['cookie'] !== null ? $page['cookie'] . '; ' : '') . self::AUTH;

        $fields = self::restoreFields($page['body']);
        unset($fields['_usephp_action_sig']);

        self::assertSame(400, $this->post($base . '/admin-class', $fields, ['Origin' => $base] + self::PARTIAL, $cookie)['status']);
    }
}
