<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Storage\StorageType;

class ComponentAttributeTest extends TestCase
{
    public function testDefaultStorageTypeIsSession(): void
    {
        $component = new Component(name: 'test');

        $this->assertEquals(StorageType::Session, $component->storageType);
    }

    public function testStorageTypeCanBeSetWithEnum(): void
    {
        $component = new Component(name: 'test', storage: StorageType::Memory);

        $this->assertEquals(StorageType::Memory, $component->storageType);
    }

    public function testStorageTypeCanBeSetWithString(): void
    {
        $component = new Component(name: 'test', storage: 'memory');

        $this->assertEquals(StorageType::Memory, $component->storageType);
    }

    public function testStorageTypeSessionWithString(): void
    {
        $component = new Component(name: 'test', storage: 'session');

        $this->assertEquals(StorageType::Session, $component->storageType);
    }
}
