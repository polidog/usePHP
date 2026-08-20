<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\RenderContext;

use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

/**
 * Actions POSTed by fc() function components.
 *
 * fc() components are not registry entries, so handleAction() cannot look
 * them up by instance id the way it does for class components. For
 * Snapshot storage on a plain (Isolated) route that used to mean the
 * partial request fell through to the PRG redirect — which carries no
 * snapshot — and the state change was silently lost. The fix replays the
 * page's GET route and returns the instance's subtree as the partial.
 */
final class FunctionComponentActionTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-function-component-action-tests';

    /** @var array<string, mixed> */
    private array $savedPost = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();
        $this->savedPost = $_POST;
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_SERVER = $this->savedServer;
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
    }

    /**
     * @return callable(array<string, mixed>): Element
     */
    private static function snapshotCounter(string $key): callable
    {
        return fc(static function (array $props): Element {
            [$count, $setCount] = useState($props['initial'] ?? 0);

            return H::div(className: 'counter', children: [
                H::span(className: 'display', children: "Count: {$count}"),
                H::button(onClick: fn() => $setCount($count + 1), children: '+'),
            ]);
        }, $key, StorageType::Snapshot);
    }

    /**
     * Pull the `_usephp_*` hidden fields of the first form out of rendered
     * HTML, exactly as the browser would submit them.
     *
     * @return array{component: string, action: string, snapshot: string}
     */
    private static function extractFormFields(string $html, string $instanceId): array
    {
        $instanceEscaped = \preg_quote(\htmlspecialchars($instanceId, \ENT_QUOTES, 'UTF-8'), '/');
        $matched = \preg_match(
            '/name="_usephp_component" value="' . $instanceEscaped . '" \/>\s*'
            . '<input type="hidden" name="_usephp_action" value="([^"]*)" \/>\s*'
            . '(?:<input type="hidden" name="_usephp_csrf" value="[^"]*" \/>)?'
            . '<input type="hidden" name="_usephp_snapshot" value="([^"]*)" \/>/',
            $html,
            $m,
        );
        self::assertSame(1, $matched, "form for {$instanceId} not found in HTML");

        return [
            'component' => $instanceId,
            'action' => \html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8'),
            'snapshot' => \html_entity_decode($m[2], \ENT_QUOTES, 'UTF-8'),
        ];
    }

    /**
     * Simulate the browser's partial (X-UsePHP-Partial) submission of a
     * usePHP form to the page URL.
     *
     * @param array{component: string, action: string, snapshot: string} $fields
     */
    private static function submitPartial(UsePHP $app, string $path, array $fields): ?string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
        $_POST = [
            '_usephp_component' => $fields['component'],
            '_usephp_action' => $fields['action'],
            '_usephp_snapshot' => $fields['snapshot'],
            '_usephp_csrf' => $app->getCsrfToken(),
        ];

        // A real request starts from an empty state cache; the GET that
        // produced the form ran in a different process.
        ComponentState::clearInstances();

        return $app->handleAction();
    }

    private static function renderRoute(UsePHP $app, string $path): string
    {
        $match = $app->getRouter()->match(new RequestContext(method: 'GET', path: $path));
        self::assertNotNull($match);

        RenderContext::setApp($app);
        try {
            $element = ($match->handler)($match->params, new RequestContext(method: 'GET', path: $path));
        } finally {
            RenderContext::clearApp();
        }
        self::assertInstanceOf(Element::class, $element);

        return $app->renderElement($element);
    }

    public function testSnapshotFcOnIsolatedRouteReturnsUpdatedPartial(): void
    {
        $counter = self::snapshotCounter('snapshot-counter');

        $app = new UsePHP();
        $app->setSnapshotSecret(self::SECRET);
        $app->getRouter()->get('/snapshot', static function () use ($counter): Element {
            RenderContext::beginRender();
            return $counter(['initial' => 0]);
        });

        $page = self::renderRoute($app, '/snapshot');
        self::assertStringContainsString('Count: 0', $page);

        \preg_match('/data-usephp="([^"]+)"/', $page, $m);
        $instanceId = \html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');
        self::assertStringEndsWith('#snapshot-counter', $instanceId);

        // Click "+" once.
        $html = self::submitPartial($app, '/snapshot', self::extractFormFields($page, $instanceId));

        self::assertNotNull($html, 'partial submit must not fall through to the PRG redirect');
        self::assertStringContainsString('Count: 1', $html);
        self::assertStringNotContainsString('Count: 0', $html);
        // The partial is the wrapper's *contents*: no nested data-usephp wrapper.
        self::assertStringNotContainsString('data-usephp="', $html);
        // usephp.js copies this field back onto the wrapper for the next round trip.
        self::assertStringContainsString('data-usephp-snapshot-update', $html);

        // The refreshed snapshot carries the new state and a valid signature,
        // so a second click continues from 1, not from the stale 0.
        $html2 = self::submitPartial($app, '/snapshot', self::extractFormFields($html, $instanceId));
        self::assertNotNull($html2);
        self::assertStringContainsString('Count: 2', $html2);
    }

    public function testSnapshotFcPartialOnlyReturnsTheTargetedInstance(): void
    {
        $counterA = self::snapshotCounter('counter-a');
        $counterB = self::snapshotCounter('counter-b');

        $app = new UsePHP();
        $app->setSnapshotSecret(self::SECRET);
        $app->getRouter()->get('/multi', static function () use ($counterA, $counterB): Element {
            RenderContext::beginRender();
            return H::div(children: [
                H::h2(children: 'A'),
                $counterA(['initial' => 10]),
                H::h2(children: 'B'),
                $counterB(['initial' => 20]),
            ]);
        });

        $page = self::renderRoute($app, '/multi');
        \preg_match_all('/data-usephp="([^"]+)"/', $page, $m);
        self::assertCount(2, $m[1]);
        $instanceB = \html_entity_decode($m[1][1], \ENT_QUOTES, 'UTF-8');
        self::assertStringEndsWith('#counter-b', $instanceB);

        $html = self::submitPartial($app, '/multi', self::extractFormFields($page, $instanceB));

        self::assertNotNull($html);
        self::assertStringContainsString('Count: 21', $html);
        self::assertStringNotContainsString('Count: 10', $html);
        self::assertStringNotContainsString('<h2>', $html);
    }
}
