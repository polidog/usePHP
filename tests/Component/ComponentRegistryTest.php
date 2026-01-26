<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

#[Component(name: 'test-component')]
class TestComponent extends BaseComponent
{
    public function render(): Element
    {
        return H::div(children: 'Test');
    }
}

#[Component(name: 'another')]
class AnotherComponent extends BaseComponent
{
    public function render(): Element
    {
        return H::div(children: 'Another');
    }
}

class ComponentRegistryTest extends TestCase
{
    public function testRegisterComponent(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(TestComponent::class);

        $this->assertTrue($registry->has('test-component'));
        $this->assertSame(TestComponent::class, $registry->get('test-component'));
    }

    public function testCreateComponent(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(TestComponent::class);

        $component = $registry->create('test-component');

        $this->assertInstanceOf(TestComponent::class, $component);
    }

    public function testGetAllComponents(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(TestComponent::class);
        $registry->register(AnotherComponent::class);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('test-component', $all);
        $this->assertArrayHasKey('another', $all);
    }

    public function testCreateNonExistentComponent(): void
    {
        $registry = new ComponentRegistry();

        $this->assertNull($registry->create('non-existent'));
    }
}
