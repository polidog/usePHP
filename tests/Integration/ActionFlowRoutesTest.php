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
        $page = $this->http($base . '/persistent-route');
        $fields = self::firstFormFields($page['body']);
        $fields['_usephp_action'] = json_encode([
            'type' => 'restore',
            'payload' => (object) [],
            'componentId' => $fields['_usephp_component'],
            'storageType' => 'snapshot',
        ], JSON_THROW_ON_ERROR);
        unset($fields['_usephp_action_sig']);

        $response = $this->http($base . '/persistent-route', $fields, ['Origin' => $base] + self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('count=0', $response['body']);
    }
}
