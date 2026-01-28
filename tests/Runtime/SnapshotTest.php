<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\ComponentId;
use Polidog\UsePhp\Runtime\Snapshot;

class SnapshotTest extends TestCase
{
    public function testCreateSnapshot(): void
    {
        $snapshot = new Snapshot(
            componentName: 'Counter',
            key: 'main',
            state: [0 => 5, 1 => 'hello'],
            effectDeps: [0 => []],
        );

        $this->assertEquals('Counter', $snapshot->componentName);
        $this->assertEquals('main', $snapshot->key);
        $this->assertEquals([0 => 5, 1 => 'hello'], $snapshot->state);
        $this->assertEquals([0 => []], $snapshot->effectDeps);
        $this->assertNull($snapshot->checksum);
    }

    public function testFromComponentId(): void
    {
        $componentId = ComponentId::create('Counter', 'main');
        $snapshot = Snapshot::fromComponentId($componentId, [0 => 5]);

        $this->assertEquals('Counter', $snapshot->componentName);
        $this->assertEquals('main', $snapshot->key);
        $this->assertEquals([0 => 5], $snapshot->state);
    }

    public function testGetComponentId(): void
    {
        $snapshot = new Snapshot('Counter', 'main');
        $componentId = $snapshot->getComponentId();

        $this->assertEquals('Counter', $componentId->componentName);
        $this->assertEquals('main', $componentId->key);
    }

    public function testGetInstanceId(): void
    {
        $snapshot = new Snapshot('Counter', 'main');

        $this->assertEquals('Counter#main', $snapshot->getInstanceId());
    }

    public function testToJsonAndFromJson(): void
    {
        $original = new Snapshot(
            componentName: 'Counter',
            key: 'main',
            state: [0 => 5],
            effectDeps: [0 => []],
        );

        $json = $original->toJson();
        $restored = Snapshot::fromJson($json);

        $this->assertEquals($original->componentName, $restored->componentName);
        $this->assertEquals($original->key, $restored->key);
        $this->assertEquals($original->state, $restored->state);
        $this->assertEquals($original->effectDeps, $restored->effectDeps);
    }

    public function testToArray(): void
    {
        $snapshot = new Snapshot(
            componentName: 'Counter',
            key: 'main',
            state: [0 => 5],
            effectDeps: [0 => []],
            checksum: 'abc123',
        );

        $array = $snapshot->toArray();

        $this->assertEquals([
            'memo' => [
                'name' => 'Counter',
                'key' => 'main',
            ],
            'state' => [0 => 5],
            'effectDeps' => [0 => []],
            'checksum' => 'abc123',
        ], $array);
    }

    public function testFromArray(): void
    {
        $array = [
            'memo' => [
                'name' => 'Counter',
                'key' => 'main',
            ],
            'state' => [0 => 5],
            'effectDeps' => [0 => []],
            'checksum' => 'abc123',
        ];

        $snapshot = Snapshot::fromArray($array);

        $this->assertEquals('Counter', $snapshot->componentName);
        $this->assertEquals('main', $snapshot->key);
        $this->assertEquals([0 => 5], $snapshot->state);
        $this->assertEquals('abc123', $snapshot->checksum);
    }

    public function testFromArrayWithMinimalData(): void
    {
        $array = [
            'memo' => [
                'name' => 'Counter',
                'key' => 'main',
            ],
        ];

        $snapshot = Snapshot::fromArray($array);

        $this->assertEquals('Counter', $snapshot->componentName);
        $this->assertEquals('main', $snapshot->key);
        $this->assertEquals([], $snapshot->state);
        $this->assertEquals([], $snapshot->effectDeps);
        $this->assertNull($snapshot->checksum);
    }

    public function testFromArrayWithInvalidDataThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::fromArray(['invalid' => 'data']);
    }

    public function testWithState(): void
    {
        $original = new Snapshot('Counter', 'main', [0 => 5]);
        $updated = $original->withState([0 => 10]);

        $this->assertEquals([0 => 5], $original->state);
        $this->assertEquals([0 => 10], $updated->state);
        $this->assertNull($updated->checksum); // Checksum should be cleared
    }

    public function testWithEffectDeps(): void
    {
        $original = new Snapshot('Counter', 'main', [], [0 => []]);
        $updated = $original->withEffectDeps([0 => [1, 2]]);

        $this->assertEquals([0 => []], $original->effectDeps);
        $this->assertEquals([0 => [1, 2]], $updated->effectDeps);
    }

    public function testWithChecksum(): void
    {
        $snapshot = new Snapshot('Counter', 'main');
        $withChecksum = $snapshot->withChecksum('abc123');

        $this->assertNull($snapshot->checksum);
        $this->assertEquals('abc123', $withChecksum->checksum);
    }

    public function testHasState(): void
    {
        $emptySnapshot = new Snapshot('Counter', 'main');
        $snapshotWithState = new Snapshot('Counter', 'main', [0 => 5]);

        $this->assertFalse($emptySnapshot->hasState());
        $this->assertTrue($snapshotWithState->hasState());
    }
}
