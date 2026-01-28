<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Storage\SnapshotStorage;

class SnapshotStorageTest extends TestCase
{
    private SnapshotStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new SnapshotStorage();
    }

    public function testBasicGetSet(): void
    {
        $this->storage->set('key1', 'value1');

        $this->assertTrue($this->storage->has('key1'));
        $this->assertEquals('value1', $this->storage->get('key1'));
    }

    public function testGetDefault(): void
    {
        $this->assertEquals('default', $this->storage->get('nonexistent', 'default'));
    }

    public function testDelete(): void
    {
        $this->storage->set('key1', 'value1');
        $this->storage->delete('key1');

        $this->assertFalse($this->storage->has('key1'));
    }

    public function testClearByPrefix(): void
    {
        $this->storage->set('usephp:Counter#0:state:0', 5);
        $this->storage->set('usephp:Counter#0:state:1', 'hello');
        $this->storage->set('usephp:Counter#1:state:0', 10);

        $this->storage->clearByPrefix('usephp:Counter#0:');

        $this->assertFalse($this->storage->has('usephp:Counter#0:state:0'));
        $this->assertFalse($this->storage->has('usephp:Counter#0:state:1'));
        $this->assertTrue($this->storage->has('usephp:Counter#1:state:0'));
    }

    public function testInitializeFromSnapshot(): void
    {
        $snapshot = new Snapshot(
            componentName: 'Counter',
            key: 'main',
            state: [0 => 5, 1 => 'hello'],
            effectDeps: [0 => []],
        );

        $this->storage->initializeFromSnapshot($snapshot, 'Counter#main');

        $this->assertEquals(5, $this->storage->get('usephp:Counter#main:state:0'));
        $this->assertEquals('hello', $this->storage->get('usephp:Counter#main:state:1'));
        $this->assertEquals([], $this->storage->get('usephp:Counter#main:effect_deps:0'));
    }

    public function testExportState(): void
    {
        $this->storage->set('usephp:Counter#main:state:0', 5);
        $this->storage->set('usephp:Counter#main:state:1', 'hello');
        $this->storage->set('usephp:Counter#main:effect_deps:0', []);
        $this->storage->set('usephp:Counter#main:action:someAction', 'ignored');

        $exported = $this->storage->exportState('Counter#main');

        $this->assertEquals([0 => 5, 1 => 'hello'], $exported['state']);
        $this->assertEquals([0 => []], $exported['effectDeps']);
    }

    public function testExportStateWithUnsortedKeys(): void
    {
        // Add keys out of order
        $this->storage->set('usephp:Counter#main:state:2', 'third');
        $this->storage->set('usephp:Counter#main:state:0', 'first');
        $this->storage->set('usephp:Counter#main:state:1', 'second');

        $exported = $this->storage->exportState('Counter#main');

        // Should be sorted by index
        $keys = array_keys($exported['state']);
        $this->assertEquals([0, 1, 2], $keys);
    }

    public function testGetSourceSnapshot(): void
    {
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);
        $this->storage->initializeFromSnapshot($snapshot, 'Counter#main');

        $this->assertTrue($this->storage->hasSourceSnapshot());
        $this->assertSame($snapshot, $this->storage->getSourceSnapshot());
    }

    public function testNoSourceSnapshot(): void
    {
        $this->assertFalse($this->storage->hasSourceSnapshot());
        $this->assertNull($this->storage->getSourceSnapshot());
    }

    public function testClear(): void
    {
        $snapshot = new Snapshot('Counter', 'main', [0 => 5]);
        $this->storage->initializeFromSnapshot($snapshot, 'Counter#main');
        $this->storage->set('extra', 'data');

        $this->storage->clear();

        $this->assertFalse($this->storage->has('usephp:Counter#main:state:0'));
        $this->assertFalse($this->storage->has('extra'));
        $this->assertFalse($this->storage->hasSourceSnapshot());
    }

    public function testRoundTripWithSnapshot(): void
    {
        // Initialize from snapshot
        $original = new Snapshot(
            componentName: 'Counter',
            key: 'main',
            state: [0 => 5],
            effectDeps: [0 => []],
        );
        $this->storage->initializeFromSnapshot($original, 'Counter#main');

        // Modify state
        $this->storage->set('usephp:Counter#main:state:0', 10);

        // Export
        $exported = $this->storage->exportState('Counter#main');

        $this->assertEquals([0 => 10], $exported['state']);
        $this->assertEquals([0 => []], $exported['effectDeps']);
    }
}
