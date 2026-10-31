<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #67: Session and Shared routes keep one snapshot per instance id, bounded,
 * still bound to the session id.
 */
final class SessionSnapshotInstancesTest extends SnapshotInstancesServerTestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function routeProvider(): iterable
    {
        yield 'sessionSnapshot()' => ['/pair-session'];
        yield 'sharedSnapshot()' => ['/pair-shared-1'];
    }

    #[DataProvider('routeProvider')]
    public function testInstancesOnOnePageKeepTheirOwnState(string $path): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $path);
        $cookie = $page['cookie'];

        $html = $this->pressAndFollow($base, $path, $page['body'], 'a', $cookie);
        self::assertStringContainsString('a=1', $html);
        self::assertStringContainsString('b=0', $html);

        $html = $this->pressAndFollow($base, $path, $html, 'b', $cookie);
        self::assertStringContainsString('a=1', $html);
        self::assertStringContainsString('b=1', $html);

        $html = $this->pressAndFollow($base, $path, $html, 'a', $cookie);
        self::assertStringContainsString('a=2', $html);
        self::assertStringContainsString('b=1', $html);

        // A reload restores the same thing.
        $reloaded = $this->http($base . $path, cookie: $cookie)['body'];
        self::assertStringContainsString('a=2', $reloaded);
        self::assertStringContainsString('b=1', $reloaded);
    }

    public function testASharedGroupCarriesEveryInstanceAcrossItsRoutesOnly(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/pair-shared-1');
        $cookie = $page['cookie'];

        $html = $this->pressAndFollow($base, '/pair-shared-1', $page['body'], 'a', $cookie);
        $this->pressAndFollow($base, '/pair-shared-1', $html, 'b', $cookie);

        $sameGroup = $this->http($base . '/pair-shared-2', cookie: $cookie)['body'];
        self::assertStringContainsString('a=1', $sameGroup);
        self::assertStringContainsString('b=1', $sameGroup);

        $otherGroup = $this->http($base . '/pair-other-group', cookie: $cookie)['body'];
        self::assertStringContainsString('a=0', $otherGroup);
        self::assertStringContainsString('b=0', $otherGroup);
    }

    public function testAnotherVisitorDoesNotSeeTheState(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/pair-session');
        $this->pressAndFollow($base, '/pair-session', $page['body'], 'a', $page['cookie']);

        self::assertStringContainsString('a=0', $this->http($base . '/pair-session')['body']);
    }

    public function testTheOldestWrittenInstanceIsDroppedAtTheCap(): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $cookie = $this->http($base . '/keyed-session')['cookie'];

        $press = function (int $k) use ($base, $cookie): string {
            $path = '/keyed-session?k=' . $k;
            $page = $this->http($base . $path, cookie: $cookie)['body'];

            return $this->pressAndFollow($base, $path, $page, 'keyed', $cookie);
        };

        for ($k = 0; $k < 64; $k++) {
            self::assertStringContainsString('keyed=1', $press($k));
        }
        self::assertStringNotContainsString('evicted', (string) file_get_contents($log));

        // Writing instance 0 again makes instance 1 the oldest.
        self::assertStringContainsString('keyed=2', $press(0));
        $press(64);

        self::assertStringContainsString('keyed=2', $this->http($base . '/keyed-session?k=0', cookie: $cookie)['body']);
        self::assertStringContainsString('keyed=0', $this->http($base . '/keyed-session?k=1', cookie: $cookie)['body']);
        self::assertStringContainsString('keyed=1', $this->http($base . '/keyed-session?k=2', cookie: $cookie)['body']);
        self::assertStringContainsString('keyed=1', $this->http($base . '/keyed-session?k=64', cookie: $cookie)['body']);
        self::assertStringContainsString('evicted', (string) file_get_contents($log));
    }

    public function testASingleSlotValueLeftByAnOlderReleaseStillRestoresAndIsKept(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/pair-session');
        $cookie = $page['cookie'];
        [$first] = self::instanceIds($page['body']);

        $this->http($base . '/plant?' . http_build_query(['id' => $first, 'value' => 5, 'mode' => 'legacy']), cookie: $cookie);

        $html = $this->http($base . '/pair-session', cookie: $cookie)['body'];
        self::assertStringContainsString('a=5', $html);
        self::assertStringContainsString('b=0', $html);

        // The first write migrates it into the map instead of dropping it.
        $html = $this->pressAndFollow($base, '/pair-session', $html, 'b', $cookie);
        self::assertStringContainsString('a=5', $html);
        self::assertStringContainsString('b=1', $html);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableSlotProvider(): iterable
    {
        yield 'single slot, signed for another session' => ['legacy-foreign'];
        yield 'single slot, not a snapshot' => ['legacy-garbage'];
        yield 'neither a string nor a map' => ['not-a-map'];
        yield 'map entry signed for another session' => ['map-foreign'];
        yield 'map entry signed without a session binding' => ['map-unbound'];
        yield 'map entry filed under another instance id' => ['map-wrong-key'];
        yield 'map entry that is not a string' => ['map-non-string'];
    }

    #[DataProvider('unusableSlotProvider')]
    public function testAnUnusableStoredValueIsNeitherRestoredNorFatal(string $mode): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/pair-session');
        $cookie = $page['cookie'];
        [$first] = self::instanceIds($page['body']);

        $this->http($base . '/plant?' . http_build_query(['id' => $first, 'value' => 9, 'mode' => $mode]), cookie: $cookie);

        $response = $this->http($base . '/pair-session', cookie: $cookie);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('a=0', $response['body']);

        // Writing over it works, and what it held does not come back.
        $html = $this->pressAndFollow($base, '/pair-session', $response['body'], 'b', $cookie);
        self::assertStringContainsString('a=0', $html);
        self::assertStringContainsString('b=1', $html);
    }

    public function testARequestCarriedSnapshotIsStillIgnoredOnASessionRoute(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/pair-session');
        $cookie = $page['cookie'];
        $html = $this->pressAndFollow($base, '/pair-session', $page['body'], 'a', $cookie);
        [$snapshot] = self::wrapperSnapshots($html);

        $other = $this->http($base . '/pair-session?' . http_build_query(['_snapshot' => $snapshot]));
        self::assertStringContainsString('a=0', $other['body']);
    }

    /**
     * Not a goal: the URL carries one snapshot so it does not grow with the
     * page. The last instance acted on wins, and the log says so.
     */
    public function testAPersistentRouteCarriesOnlyTheLastInstanceAndWarns(): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $page = $this->http($base . '/pair-persistent');

        $first = $this->press($base, '/pair-persistent', $page['body'], 'a', $page['cookie']);
        $target = self::locationOf($first);
        self::assertNotNull($target);
        $html = $this->http($base . $target, cookie: $page['cookie'])['body'];
        self::assertStringContainsString('a=1', $html);
        self::assertStringContainsString('b=0', $html);
        self::assertStringNotContainsString('only one', (string) file_get_contents($log));

        $second = $this->press($base, $target, $html, 'b', $page['cookie']);
        $target = self::locationOf($second);
        self::assertNotNull($target);
        $html = $this->http($base . $target, cookie: $page['cookie'])['body'];
        self::assertStringContainsString('a=0', $html);
        self::assertStringContainsString('b=1', $html);
        self::assertStringContainsString('only one', (string) file_get_contents($log));
    }
}
