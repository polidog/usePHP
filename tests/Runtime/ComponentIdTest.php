<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\ComponentId;

class ComponentIdTest extends TestCase
{
    public function testCreateWithKey(): void
    {
        $id = ComponentId::create('Counter', 'main');

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('main', $id->key);
        $this->assertNull($id->parent);
    }

    public function testCreateWithIndex(): void
    {
        $id = ComponentId::createWithIndex('Counter', 0);

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('0', $id->key);
    }

    public function testToStringSimple(): void
    {
        $id = ComponentId::create('Counter', 'main');

        $this->assertEquals('Counter@main', $id->toString());
        $this->assertEquals('Counter@main', (string) $id);
    }

    public function testToStringNested(): void
    {
        $parent = ComponentId::create('Page', 'home');
        $child = ComponentId::create('Counter', 'item-1', $parent);

        $this->assertEquals('Page@home/Counter@item-1', $child->toString());
    }

    public function testToLegacyString(): void
    {
        $id = ComponentId::create('Counter', 'main');

        $this->assertEquals('Counter#main', $id->toLegacyString());
    }

    public function testParseSimple(): void
    {
        $id = ComponentId::parse('Counter@main');

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('main', $id->key);
        $this->assertNull($id->parent);
    }

    public function testParseNested(): void
    {
        $id = ComponentId::parse('Page@home/Counter@item-1');

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('item-1', $id->key);
        $this->assertNotNull($id->parent);
        $this->assertEquals('Page', $id->parent->componentName);
        $this->assertEquals('home', $id->parent->key);
    }

    public function testFromLegacy(): void
    {
        $id = ComponentId::fromLegacy('Counter#0');

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('0', $id->key);
    }

    public function testFromLegacyWithStringKey(): void
    {
        $id = ComponentId::fromLegacy('Counter#my-key');

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('my-key', $id->key);
    }

    public function testParseLegacyFormat(): void
    {
        // parse() should detect legacy format and handle it
        $id = ComponentId::parse('Counter#0');

        $this->assertEquals('Counter', $id->componentName);
        $this->assertEquals('0', $id->key);
    }

    public function testIsLegacyFormat(): void
    {
        $legacyId = ComponentId::create('Counter', '0');
        $newId = ComponentId::create('Counter', 'main');

        $this->assertTrue($legacyId->isLegacyFormat());
        $this->assertFalse($newId->isLegacyFormat());
    }

    public function testGetPath(): void
    {
        $grandparent = ComponentId::create('App', 'root');
        $parent = ComponentId::create('Page', 'home', $grandparent);
        $child = ComponentId::create('Counter', 'item-1', $parent);

        $this->assertEquals(['App', 'Page', 'Counter'], $child->getPath());
        $this->assertEquals(['App', 'Page'], $parent->getPath());
        $this->assertEquals(['App'], $grandparent->getPath());
    }

    public function testGetDepth(): void
    {
        $grandparent = ComponentId::create('App', 'root');
        $parent = ComponentId::create('Page', 'home', $grandparent);
        $child = ComponentId::create('Counter', 'item-1', $parent);

        $this->assertEquals(0, $grandparent->getDepth());
        $this->assertEquals(1, $parent->getDepth());
        $this->assertEquals(2, $child->getDepth());
    }

    public function testEquals(): void
    {
        $id1 = ComponentId::create('Counter', 'main');
        $id2 = ComponentId::create('Counter', 'main');
        $id3 = ComponentId::create('Counter', 'other');

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testInvalidParseThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComponentId::parse('InvalidFormat');
    }

    public function testInvalidFromLegacyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComponentId::fromLegacy('InvalidFormat');
    }
}
