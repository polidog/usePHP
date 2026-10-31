<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;

/**
 * #68: a partial re-render of a parent keeps the state of its snapshot
 * descendants, from snapshots the client sends and the server verifies.
 */
final class NestedSnapshotPartialTest extends SnapshotInstancesServerTestCase
{
    /**
     * What usephp.js does: send the snapshots of every wrapper inside the
     * acting component, then swap in the response.
     *
     * @return array{status: int, headers: list<string>, body: string, cookie: ?string}
     */
    private function clickWithDescendants(string $base, string $path, string $html, string $label, string $wrapperHtml): array
    {
        // The first wrapper in $wrapperHtml is the acting component itself.
        $descendants = array_slice(self::wrapperSnapshots($wrapperHtml), 1);

        return $this->press($base, $path, $html, $label, null, self::PARTIAL, ['_usephp_snapshots' => $descendants]);
    }

    /**
     * The refreshed snapshot a partial response hands back to usephp.js.
     */
    private static function updatedSnapshot(string $partial): string
    {
        self::assertSame(1, preg_match('/name="_usephp_snapshot" value="([^"]*)" data-usephp-snapshot-update/', $partial, $m), $partial);

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * A page where the child was already pressed once, as the server would
     * have it after that partial: the child's wrapper holds child=1.
     *
     * @return array{0: string, 1: string} Base URL and the page HTML with the child's state applied
     */
    private function pageWithChildPressed(string $path = '/nested'): array
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . $path)['body'];

        [, $childSnapshot, $grandchildSnapshot] = self::wrapperSnapshots($html);
        $response = $this->press($base, $path, $html, 'child', null, self::PARTIAL, [
            '_usephp_snapshots' => [$grandchildSnapshot],
        ]);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('child=1', $response['body']);

        $updated = self::updatedSnapshot($response['body']);
        self::assertNotSame($childSnapshot, $updated);

        // Apply it the way applyPartial() does.
        $escapedOld = htmlspecialchars($childSnapshot, ENT_QUOTES, 'UTF-8');
        $escapedNew = htmlspecialchars($updated, ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString($escapedOld, $html);

        return [$base, str_replace($escapedOld, $escapedNew, $html)];
    }

    public function testEachActionFormLivesInTheComponentItActsOn(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];

        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $forms = $document->querySelectorAll('form[data-usephp-form]');
        self::assertCount(3, $forms);
        foreach ($forms as $form) {
            $acting = $form->querySelector('input[name="_usephp_component"]')?->getAttribute('value');
            // A morph of an inner component would otherwise remove it.
            self::assertSame($acting, $form->closest('[data-usephp]')?->getAttribute('data-usephp'));
        }
    }

    public function testPressingTheParentKeepsTheChildsState(): void
    {
        [$base, $html] = $this->pageWithChildPressed();

        $response = $this->clickWithDescendants($base, '/nested', $html, 'parent', $html);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('parent=1', $response['body']);
        self::assertStringContainsString('child=1', $response['body']);
        self::assertStringContainsString('grandchild=0', $response['body']);
    }

    public function testADeeperDescendantKeepsItsStateToo(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];

        $pressed = $this->press($base, '/nested', $html, 'grandchild', null, self::PARTIAL);
        self::assertStringContainsString('grandchild=1', $pressed['body']);
        $updated = self::updatedSnapshot($pressed['body']);

        $response = $this->press($base, '/nested', $html, 'parent', null, self::PARTIAL, [
            '_usephp_snapshots' => [self::wrapperSnapshots($html)[1], $updated],
        ]);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('parent=1', $response['body']);
        self::assertStringContainsString('grandchild=1', $response['body']);
    }

    public function testASessionParentKeepsItsSnapshotChildsState(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/nested-session-parent');
        $cookie = $page['cookie'];
        $snapshots = self::wrapperSnapshots($page['body']);

        $pressed = $this->press($base, '/nested-session-parent', $page['body'], 'child', $cookie, self::PARTIAL);
        $updated = self::updatedSnapshot($pressed['body']);

        $response = $this->press($base, '/nested-session-parent', $page['body'], 'session-parent', $cookie, self::PARTIAL, [
            '_usephp_snapshots' => [$updated, $snapshots[1]],
        ]);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('session-parent=1', $response['body']);
        self::assertStringContainsString('child=1', $response['body']);
    }

    public function testADescendantTheActionHidesIsNotAnError(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested-hideable')['body'];

        $response = $this->clickWithDescendants($base, '/nested-hideable', $html, 'parent', $html);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('parent=1', $response['body']);
        self::assertStringContainsString('no child', $response['body']);
    }

    public function testASessionRouteStillRedirectsAndKeepsTheChild(): void
    {
        $base = $this->startServer(self::SECRET);
        $page = $this->http($base . '/nested-on-session-route');
        $cookie = $page['cookie'];

        $html = $this->pressAndFollow($base, '/nested-on-session-route', $page['body'], 'child', $cookie);
        self::assertStringContainsString('child=1', $html);

        $html = $this->pressAndFollow($base, '/nested-on-session-route', $html, 'parent', $cookie);
        self::assertStringContainsString('parent=1', $html);
        self::assertStringContainsString('child=1', $html);
    }

    // ------------------------------------------------------------------
    // The field is client input: it must not become a way to inject state.
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: \Closure(string): mixed}>
     */
    public static function rejectedFieldProvider(): iterable
    {
        yield 'edited state' => [static function (string $signed): mixed {
            $data = json_decode($signed, true, 512, JSON_THROW_ON_ERROR);
            $data['state'] = [99];

            return [json_encode($data, JSON_THROW_ON_ERROR)];
        }];
        yield 'edited memo' => [static function (string $signed): mixed {
            $data = json_decode($signed, true, 512, JSON_THROW_ON_ERROR);
            $data['memo']['key'] = 'grandchild';

            return [json_encode($data, JSON_THROW_ON_ERROR)];
        }];
        yield 'no checksum' => [static function (string $signed): mixed {
            $data = json_decode($signed, true, 512, JSON_THROW_ON_ERROR);
            $data['state'] = [99];
            unset($data['checksum']);

            return [json_encode($data, JSON_THROW_ON_ERROR)];
        }];
        yield 'signed with another secret' => [static function (string $signed): mixed {
            $snapshot = new SnapshotSerializer(self::SECRET_VALUE)->deserialize($signed)->withState([99]);

            return [new SnapshotSerializer('another-secret')->serialize($snapshot)];
        }];
        yield 'signed for a session' => [static function (string $signed): mixed {
            $snapshot = new SnapshotSerializer(self::SECRET_VALUE)->deserialize($signed)->withState([99]);

            return [new SnapshotSerializer(self::SECRET_VALUE)->withContext('some-session')->serialize($snapshot)];
        }];
        yield 'one bad among good ones' => [static fn(string $signed): mixed => [$signed, 'not json']];
        yield 'not json' => [static fn(string $signed): mixed => ['{']];
        yield 'json that is not an object' => [static fn(string $signed): mixed => ['1']];
        yield 'not a string' => [static fn(string $signed): mixed => [['a' => 'b']]];
        yield 'not a list' => [static fn(string $signed): mixed => 'scalar'];
    }

    /**
     * @param \Closure(string): mixed $field
     */
    #[DataProvider('rejectedFieldProvider')]
    public function testAFieldThatDoesNotVerifyRejectsTheRequest(\Closure $field): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];
        $signedChild = self::wrapperSnapshots($html)[1];

        $response = $this->press($base, '/nested', $html, 'parent', null, self::PARTIAL, [
            '_usephp_snapshots' => $field($signedChild),
        ]);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertStringNotContainsString('child=99', $response['body']);
        self::assertStringNotContainsString('parent=', $response['body']);
    }

    public function testASignedSnapshotOfAnInstanceOutsideTheActingOneIsRejected(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested-with-sibling')['body'];
        $ids = self::instanceIds($html);
        $sibling = array_search('sibling', array_map(
            static fn(string $id): string => explode('#', $id, 2)[1],
            $ids,
        ), true);
        self::assertIsInt($sibling);
        $signedSibling = self::wrapperSnapshots($html)[$sibling];

        $response = $this->press($base, '/nested-with-sibling', $html, 'parent', null, self::PARTIAL, [
            '_usephp_snapshots' => [$signedSibling],
        ]);

        self::assertSame(400, $response['status'], $response['body']);
    }

    public function testASignedSnapshotOfAnAncestorIsRejected(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];
        [$signedParent] = self::wrapperSnapshots($html);

        $response = $this->press($base, '/nested', $html, 'child', null, self::PARTIAL, [
            '_usephp_snapshots' => [$signedParent],
        ]);

        self::assertSame(400, $response['status'], $response['body']);
    }

    public function testASnapshotNamingTheActingInstanceCannotOverrideIt(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];
        [$parentId] = self::instanceIds($html);
        [$name, $key] = explode('#', $parentId, 2);

        $response = $this->press($base, '/nested', $html, 'parent', null, self::PARTIAL, [
            '_usephp_snapshots' => [new SnapshotSerializer(self::SECRET_VALUE)->serialize(new Snapshot($name, $key, [99]))],
        ]);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('parent=1', $response['body']);
        self::assertStringNotContainsString('parent=99', $response['body']);
    }

    public function testASnapshotOfAnInstanceThatIsNotOnThePageIsInert(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];

        $response = $this->press($base, '/nested', $html, 'parent', null, self::PARTIAL, [
            '_usephp_snapshots' => [new SnapshotSerializer(self::SECRET_VALUE)->serialize(new Snapshot('Nowhere', '0', [99]))],
        ]);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('parent=1', $response['body']);
        self::assertStringNotContainsString('99', preg_replace('/(value|data-usephp-snapshot)="[^"]*"/', '', $response['body']) ?? '');
    }

    public function testOnlySoManyCarriedSnapshotsAreRead(): void
    {
        $base = $this->startServer(self::SECRET);
        $html = $this->http($base . '/nested')['body'];
        $signedChild = self::wrapperSnapshots($html)[1];

        $filler = new SnapshotSerializer(self::SECRET_VALUE)->serialize(new Snapshot('Nowhere', '0', []));
        $response = $this->press($base, '/nested', $html, 'parent', null, self::PARTIAL, [
            '_usephp_snapshots' => [...array_fill(0, 256, $filler), 'past the bound, so this never gets parsed', $signedChild],
        ]);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('parent=1', $response['body']);
    }

    public function testAnActionWithoutASecretCannotCarrySnapshots(): void
    {
        $base = $this->startServer();
        $page = $this->http($base . '/session-fc');

        $response = $this->post(
            $base . '/session-fc',
            ['_usephp_snapshots' => ['{}']] + self::firstFormFields($page['body']),
            ['Origin' => $base] + self::PARTIAL,
            $page['cookie'],
        );

        self::assertSame(400, $response['status'], $response['body']);
    }
}
