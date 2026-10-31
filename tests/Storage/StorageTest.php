<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Storage\MemoryStorage;
use Polidog\UsePhp\Storage\SessionStorage;
use Polidog\UsePhp\Storage\SnapshotStorage;
use Polidog\UsePhp\Storage\StateStorageInterface;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;

class StorageTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        StorageFactory::reset();
    }

    /**
     * @return iterable<string, array{StateStorageInterface}>
     */
    public static function allStorages(): iterable
    {
        yield 'memory' => [new MemoryStorage()];
        yield 'session' => [new SessionStorage()];
        yield 'snapshot' => [new SnapshotStorage()];
    }

    #[DataProvider('allStorages')]
    public function testNullValueCountsAsSet(StateStorageInterface $storage): void
    {
        $storage->set('key', null);

        $this->assertTrue($storage->has('key'));
        $this->assertNull($storage->get('key', 'default'));

        $storage->delete('key');

        $this->assertFalse($storage->has('key'));
        $this->assertSame('default', $storage->get('key', 'default'));
    }

    public function testNullStateSurvivesSnapshotRoundTrip(): void
    {
        $storage = new SnapshotStorage();
        $storage->set('usephp:C#0:state:0', null);
        $exported = $storage->exportState('C#0');
        $this->assertSame([0 => null], $exported['state']);

        $restored = new SnapshotStorage();
        $restored->initializeFromSnapshot(new Snapshot('C', '0', $exported['state']), 'C#0');

        $this->assertTrue($restored->has('usephp:C#0:state:0'));
    }

    public function testMemoryStorageBasicOperations(): void
    {
        $storage = new MemoryStorage();

        // Initially not set
        $this->assertFalse($storage->has('key'));
        $this->assertNull($storage->get('key'));
        $this->assertEquals('default', $storage->get('key', 'default'));

        // Set and get
        $storage->set('key', 'value');
        $this->assertTrue($storage->has('key'));
        $this->assertEquals('value', $storage->get('key'));

        // Delete
        $storage->delete('key');
        $this->assertFalse($storage->has('key'));
    }

    public function testSessionStorageBasicOperations(): void
    {
        $storage = new SessionStorage();

        // Initially not set
        $this->assertFalse($storage->has('key'));
        $this->assertNull($storage->get('key'));
        $this->assertEquals('default', $storage->get('key', 'default'));

        // Set and get
        $storage->set('key', 'value');
        $this->assertTrue($storage->has('key'));
        $this->assertEquals('value', $storage->get('key'));

        // Delete
        $storage->delete('key');
        $this->assertFalse($storage->has('key'));
    }

    public function testMemoryStorageClearByPrefix(): void
    {
        $storage = new MemoryStorage();

        $storage->set('usephp:comp1:state:0', 'value1');
        $storage->set('usephp:comp1:state:1', 'value2');
        $storage->set('usephp:comp2:state:0', 'value3');
        $storage->set('other:key', 'value4');

        $storage->clearByPrefix('usephp:comp1:');

        $this->assertFalse($storage->has('usephp:comp1:state:0'));
        $this->assertFalse($storage->has('usephp:comp1:state:1'));
        $this->assertTrue($storage->has('usephp:comp2:state:0'));
        $this->assertTrue($storage->has('other:key'));
    }

    public function testSessionStorageClearByPrefix(): void
    {
        $storage = new SessionStorage();

        $storage->set('usephp:comp1:state:0', 'value1');
        $storage->set('usephp:comp1:state:1', 'value2');
        $storage->set('usephp:comp2:state:0', 'value3');
        $storage->set('other:key', 'value4');

        $storage->clearByPrefix('usephp:comp1:');

        $this->assertFalse($storage->has('usephp:comp1:state:0'));
        $this->assertFalse($storage->has('usephp:comp1:state:1'));
        $this->assertTrue($storage->has('usephp:comp2:state:0'));
        $this->assertTrue($storage->has('other:key'));
    }

    public function testStorageFactoryCreatesCorrectType(): void
    {
        $sessionStorage = StorageFactory::create(StorageType::Session);
        $memoryStorage = StorageFactory::create(StorageType::Memory);

        $this->assertInstanceOf(SessionStorage::class, $sessionStorage);
        $this->assertInstanceOf(MemoryStorage::class, $memoryStorage);
    }

    public function testStorageFactoryReturnsSameInstance(): void
    {
        $first = StorageFactory::create(StorageType::Memory);
        $second = StorageFactory::create(StorageType::Memory);

        $this->assertSame($first, $second);
    }

    public function testMemoryStorageIsIsolatedPerInstance(): void
    {
        StorageFactory::reset();

        $storage1 = new MemoryStorage();
        $storage2 = new MemoryStorage();

        $storage1->set('key', 'value1');
        $storage2->set('key', 'value2');

        // Each instance has its own data
        $this->assertEquals('value1', $storage1->get('key'));
        $this->assertEquals('value2', $storage2->get('key'));
    }

    public function testStorageTypeEnum(): void
    {
        $this->assertEquals('session', StorageType::Session->value);
        $this->assertEquals('memory', StorageType::Memory->value);

        $this->assertEquals(StorageType::Session, StorageType::from('session'));
        $this->assertEquals(StorageType::Memory, StorageType::from('memory'));
    }
}
