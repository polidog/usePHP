<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\NullRouter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'MalformedSnapshotCounter', storage: StorageType::Snapshot)]
final class MalformedSnapshotCounter extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'counter');
    }
}

/**
 * #86: a broken `_snapshot` / `_usephp_snapshot` is client input. It must never
 * surface as an uncaught exception (a 500, with a stack trace in the log).
 */
final class MalformedSnapshotRequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer;

    /** @var array<string, mixed> */
    private array $savedPost;

    private string $savedErrorLog;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::beginRender();
        $this->savedServer = $_SERVER;
        $this->savedPost = $_POST;
        // The rejections are logged by design; keep them out of the test output.
        $this->savedErrorLog = (string) ini_set('error_log', '/dev/null');
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_POST = $this->savedPost;
        $_SESSION = [];
        ini_set('error_log', $this->savedErrorLog);
        ComponentState::clearInstances();
        StorageFactory::reset();
        http_response_code(200);
    }

    public function testArrayShapedSnapshotQueryIsIgnoredBySimpleRouter(): void
    {
        $router = new SimpleRouter();

        self::assertNull($router->extractSnapshot(new RequestContext('GET', '/', query: ['_snapshot' => ['1']])));
    }

    public function testArrayShapedSnapshotQueryIsIgnoredByNullRouter(): void
    {
        $router = new NullRouter();

        self::assertNull($router->extractSnapshot(new RequestContext('GET', '/', query: ['_snapshot' => ['1']])));
        self::assertNull($router->extractSnapshot(new RequestContext('GET', '/', query: ['snapshot' => ['1']])));
        self::assertSame('ok', $router->extractSnapshot(
            new RequestContext('GET', '/', query: ['_snapshot' => ['1'], 'snapshot' => 'ok']),
        ));
    }

    public function testArrayShapedSnapshotQueryDoesNotBreakAnIsolatedRoute(): void
    {
        $app = new UsePHP();
        $app->getRouter()->get('/page', static fn(): Element => H::p(children: 'page'));

        self::assertSame('<p>page</p>', $this->renderRequest($app, '/page', ['_snapshot' => ['1']]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function brokenQuerySnapshotProvider(): iterable
    {
        yield 'array' => [['1']];
        yield 'not json' => ['garbage'];
        yield 'empty object' => ['{}'];
        yield 'json scalar' => ['1'];
        yield 'json null' => ['null'];
        yield 'empty string' => [''];
    }

    #[DataProvider('brokenQuerySnapshotProvider')]
    public function testBrokenSnapshotOnPersistentRouteRendersThePageWithoutState(mixed $snapshot): void
    {
        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()
            ->get('/persistent', static fn(): Element => H::p(children: 'page'))
            ->persistentSnapshot();

        self::assertSame('<p>page</p>', $this->renderRequest($app, '/persistent', ['_snapshot' => $snapshot]));
        self::assertSame(200, http_response_code());
        self::assertNull(ComponentState::current());
    }

    public function testCorruptSnapshotInTheSessionIsIgnored(): void
    {
        $_SESSION['_usephp_snapshot'] = 'garbage';

        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->getRouter()
            ->get('/session', static fn(): Element => H::p(children: 'page'))
            ->sessionSnapshot();

        self::assertSame('<p>page</p>', $this->renderRequest($app, '/session'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenPostedSnapshotProvider(): iterable
    {
        yield 'not json' => ['garbage'];
        yield 'empty object' => ['{}'];
        yield 'json scalar' => ['1'];
        yield 'json null' => ['null'];
        yield 'memo name is an array' => ['{"memo":{"name":[],"key":"0"},"checksum":"x"}'];
        yield 'number too large for json_encode' => ['{"memo":{"name":"a","key":"0"},"state":[1e999],"checksum":"x"}'];
    }

    #[DataProvider('brokenPostedSnapshotProvider')]
    public function testBrokenPostedSnapshotIsABadRequest(string $snapshot): void
    {
        $app = new UsePHP()->setSnapshotSecret('s3cret');
        $app->register(MalformedSnapshotCounter::class);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/page';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_ORIGIN'] = 'http://example.test';
        $_POST = [
            '_usephp_component' => 'MalformedSnapshotCounter#0',
            '_usephp_action' => '{"type":"restore","payload":[]}',
            '_usephp_snapshot' => $snapshot,
            '_usephp_csrf' => $app->getCsrfToken(),
        ];

        self::assertSame('Invalid snapshot', $app->handleAction());
        self::assertSame(400, http_response_code());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function renderRequest(UsePHP $app, string $path, array $query = []): string
    {
        ob_start();
        try {
            $app->run(new RequestContext('GET', $path, query: $query));
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
