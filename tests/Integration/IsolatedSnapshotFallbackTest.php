<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #69: an Isolated route has nowhere to keep state but the response, so the
 * paths that used to fall into a redirect keep the state another way.
 */
final class IsolatedSnapshotFallbackTest extends SnapshotInstancesServerTestCase
{
    /**
     * The refreshed page or fragment replaces what the next press acts on.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function noJavaScriptProvider(): iterable
    {
        yield 'fc()' => ['/snapshot-fc', 'count'];
        yield 'class component' => ['/snapshot-class', 'count'];
        yield 'handler returning a string' => ['/string-handler', 'str'];
        yield 'handler printing its page' => ['/printing-handler', 'printed'];
    }

    #[DataProvider('noJavaScriptProvider')]
    public function testAPlainPostIsAnsweredWithThePageAndKeepsCounting(string $path, string $label): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $path);
        $cookie = $page['cookie'];
        $html = $page['body'];

        foreach ([1, 2, 3] as $expected) {
            $response = $this->press($base, $path, $html, $label, $cookie);

            self::assertSame(200, $response['status'], $response['body']);
            self::assertNull(self::locationOf($response));
            self::assertStringContainsString("{$label}={$expected}", $response['body']);
            self::assertContains('Cache-Control: no-store', $response['headers']);
            $html = $response['body'];
        }
    }

    public function testAPlainPostKeepsTheHandlersPageAroundTheComponent(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/string-handler')['body'];

        $response = $this->press($base, '/string-handler', $html, 'str', null);

        self::assertStringStartsWith('<main>', $response['body']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function pairProvider(): iterable
    {
        yield 'Element handler' => ['/pair-isolated'];
        yield 'string handler' => ['/string-handler-pair'];
    }

    #[DataProvider('pairProvider')]
    public function testInstancesKeepEachOthersStateWithoutJavaScript(string $path): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . $path)['body'];

        $html = $this->press($base, $path, $html, 'a', null)['body'];
        self::assertStringContainsString('a=1', $html);
        self::assertStringContainsString('b=0', $html);

        $html = $this->press($base, $path, $html, 'b', null)['body'];
        self::assertStringContainsString('a=1', $html);
        self::assertStringContainsString('b=1', $html);

        $html = $this->press($base, $path, $html, 'a', null)['body'];
        self::assertStringContainsString('a=2', $html);
        self::assertStringContainsString('b=1', $html);
    }

    public function testNestedInstancesKeepEachOthersStateWithoutJavaScript(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];

        $html = $this->press($base, '/nested', $html, 'child', null)['body'];
        $html = $this->press($base, '/nested', $html, 'grandchild', null)['body'];
        $html = $this->press($base, '/nested', $html, 'parent', null)['body'];

        self::assertStringContainsString('parent=1', $html);
        self::assertStringContainsString('child=1', $html);
        self::assertStringContainsString('grandchild=1', $html);
    }

    public function testASessionInstanceOnAPageWithCarriedSnapshotsDoesNotLoseThem(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/mixed-isolated');
        $cookie = $page['cookie'];

        $html = $this->press($base, '/mixed-isolated', $page['body'], 'snap', $cookie)['body'];
        self::assertStringContainsString('snap=1', $html);

        $response = $this->press($base, '/mixed-isolated', $html, 'sess', $cookie);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('snap=1', $response['body']);
        self::assertStringContainsString('sess=1', $response['body']);
    }

    public function testAFirstPlainPostOfASessionInstanceStillRedirects(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/mixed-isolated');

        self::assertSame(303, $this->press($base, '/mixed-isolated', $page['body'], 'sess', $page['cookie'])['status']);
    }

    public function testACarriedSnapshotThatDoesNotVerifyIsRejectedOnThePlainPathToo(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/pair-isolated')['body'];
        $html = $this->press($base, '/pair-isolated', $html, 'a', null)['body'];

        $fields = self::formFor($html, 'b');
        self::assertIsArray($fields['_usephp_snapshots'] ?? null);
        $tampered = json_decode((string) $fields['_usephp_snapshots'][0], true, 512, JSON_THROW_ON_ERROR);
        $tampered['state'] = [99];

        $response = $this->press($base, '/pair-isolated', $html, 'b', null, extra: [
            '_usephp_snapshots' => [json_encode($tampered, JSON_THROW_ON_ERROR)],
        ]);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertStringNotContainsString('a=99', $response['body']);
    }

    public function testThePageIsNotChargedForTheFieldsUntilItIsNeeded(): void
    {
        $base = $this->startServer(self::SECRET);

        self::assertStringNotContainsString('_usephp_snapshots', $this->http($base . '/pair-isolated')['body']);

        // One instance has nothing to carry.
        $html = $this->http($base . '/snapshot-fc')['body'];
        $response = $this->press($base, '/snapshot-fc', $html, 'count', null);
        self::assertStringNotContainsString('_usephp_snapshots', $response['body']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function redirectingRouteProvider(): iterable
    {
        yield 'sessionSnapshot()' => ['/snapshot-fc-on-session-route'];
        yield 'persistentSnapshot()' => ['/snapshot-fc-on-persistent-route'];
        yield 'sharedSnapshot()' => ['/snapshot-fc-on-shared-route'];
    }

    #[DataProvider('redirectingRouteProvider')]
    public function testARouteWithSomewhereToWriteKeepsThePlainPostRedirect(string $path): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . $path);

        self::assertSame(303, $this->press($base, $path, $page['body'], 'count', $page['cookie'])['status']);
    }

    // ------------------------------------------------------------------
    // With JavaScript: handlers answering with a string
    // ------------------------------------------------------------------

    public function testAStringHandlerAnswersAPartial(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/string-handler')['body'];

        foreach ([1, 2] as $expected) {
            $response = $this->press($base, '/string-handler', $html, 'str', null, self::PARTIAL);

            self::assertSame(200, $response['status'], $response['body']);
            self::assertNull(self::locationOf($response));
            self::assertStringContainsString("str={$expected}", $response['body']);
            self::assertStringNotContainsString('<main>', $response['body']);
            $html = $response['body'];
        }
    }

    public function testAPrintingHandlerAnswersAPartial(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/printing-handler')['body'];

        $response = $this->press($base, '/printing-handler', $html, 'printed', null, self::PARTIAL);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('printed=1', $response['body']);
        self::assertStringNotContainsString('<main>', $response['body']);
    }

    public function testACarriedSiblingIsRejectedOnAStringHandlerToo(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/string-handler-pair')['body'];
        [, $sibling] = self::wrapperSnapshots($html);

        $response = $this->press($base, '/string-handler-pair', $html, 'a', null, self::PARTIAL, [
            '_usephp_snapshots' => [$sibling],
        ]);

        self::assertSame(400, $response['status'], $response['body']);
    }

    public function testWhenTheWrapperCannotBeFoundItWarnsBeforeRedirecting(): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $html = $this->http($base . '/hideable')['body'];

        $response = $this->press($base, '/hideable?hide=1', $html, 'hideable', null, self::PARTIAL);

        self::assertSame(303, $response['status'], $response['body']);
        self::assertStringContainsString('Snapshot state lost', (string) file_get_contents($log));
    }

    public function testNoWarningWhenTheStateIsKept(): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $html = $this->http($base . '/string-handler')['body'];

        $this->press($base, '/string-handler', $html, 'str', null, self::PARTIAL);
        $this->press($base, '/string-handler', $html, 'str', null);

        self::assertStringNotContainsString('Snapshot state lost', (string) file_get_contents($log));
    }

    // ------------------------------------------------------------------
    // With JavaScript: components inside a defer endpoint
    // ------------------------------------------------------------------

    public function testADeferredComponentAnswersAPartialFromThePageItsFormPostsTo(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/defer-page');
        self::assertStringNotContainsString('ds=0', $page['body']);
        $html = $this->http($base . '/_defer/ds?tag=7')['body'];
        self::assertStringContainsString('ds=0 tag=7', $html);

        foreach ([1, 2] as $expected) {
            $response = $this->press($base, '/defer-page', $html, 'ds', null, self::PARTIAL);

            self::assertSame(200, $response['status'], $response['body']);
            self::assertNull(self::locationOf($response));
            // The placeholder's params reach the re-render as the endpoint's did.
            self::assertStringContainsString("ds={$expected} tag=7", $response['body']);
            self::assertStringNotContainsString('page-chrome', $response['body']);
            $html = $response['body'];
        }
    }

    public function testADeferredComponentThatIsNowhereOnThePageStillWarns(): void
    {
        $log = $this->errorLogFile();
        $base = $this->startServer(self::SECRET + ['USEPHP_TEST_ERROR_LOG' => $log]);
        $html = $this->http($base . '/_defer/ds?tag=7')['body'];

        $response = $this->press($base, '/snapshot-fc', $html, 'ds', null, self::PARTIAL);

        self::assertSame(303, $response['status'], $response['body']);
        self::assertStringContainsString('Snapshot state lost', (string) file_get_contents($log));
    }
}
