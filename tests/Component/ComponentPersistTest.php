<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\SnapshotPersist;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'persisted-counter', storage: 'snapshot', persist: 'sessionStorage')]
final class PersistedSnapshotCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count] = $this->useState(0);

        return H::div(children: "Count: {$count}");
    }
}

#[Component(name: 'plain-snapshot-counter', storage: 'snapshot')]
final class PlainSnapshotCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count] = $this->useState(0);

        return H::div(children: "Count: {$count}");
    }
}

/**
 * `#[Component(persist: ...)]` — the class-component counterpart of
 * `fc(..., persist: ...)`.
 */
final class ComponentPersistTest extends TestCase
{
    protected function setUp(): void
    {
        ComponentState::clearInstances();
        StorageFactory::reset();
        RenderContext::beginRender();
    }

    protected function tearDown(): void
    {
        ComponentState::clearInstances();
        StorageFactory::reset();
        RenderContext::clearApp();
    }

    public function testAttributeAcceptsEnumOrString(): void
    {
        self::assertSame(SnapshotPersist::SessionStorage, new Component(storage: 'snapshot', persist: 'sessionStorage')->persist);
        self::assertSame(SnapshotPersist::LocalStorage, new Component(storage: 'snapshot', persist: SnapshotPersist::LocalStorage)->persist);
        self::assertNull(new Component(storage: 'snapshot')->persist);
    }

    public function testAttributeRejectsPersistWithoutSnapshotStorage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Component(storage: 'session', persist: 'sessionStorage');
    }

    public function testRegistryExposesPersist(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(PersistedSnapshotCounter::class);
        $registry->register(PlainSnapshotCounter::class);

        self::assertSame(SnapshotPersist::SessionStorage, $registry->getPersist('persisted-counter'));
        self::assertNull($registry->getPersist('plain-snapshot-counter'));
        self::assertNull($registry->getPersist('unknown'));
    }

    public function testRenderEmitsPersistAttributeOnlyWhenOptedIn(): void
    {
        $app = new UsePHP();
        $app->setSnapshotSecret('test-secret-key-for-component-persist-tests');
        $app->register(PersistedSnapshotCounter::class);
        $app->register(PlainSnapshotCounter::class);

        $persisted = $app->render('persisted-counter');
        self::assertStringContainsString('data-usephp="persisted-counter#0"', $persisted);
        self::assertStringContainsString('data-usephp-snapshot=', $persisted);
        self::assertStringContainsString('data-usephp-persist="sessionStorage"', $persisted);

        $plain = $app->render('plain-snapshot-counter');
        self::assertStringContainsString('data-usephp-snapshot=', $plain);
        self::assertStringNotContainsString('data-usephp-persist', $plain);

        // createElement() path (H::component-style embedding) carries it too.
        $element = $app->createElement('persisted-counter', 'k');
        self::assertSame('sessionStorage', $element->props['data-usephp-persist'] ?? null);
    }
}
