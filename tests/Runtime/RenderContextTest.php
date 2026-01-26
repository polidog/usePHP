<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;

use function Polidog\UsePhp\Runtime\useState;

class RenderContextTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testNextInstanceIdAutoIncrement(): void
    {
        RenderContext::beginRender();

        $id1 = RenderContext::nextInstanceId('Counter');
        $id2 = RenderContext::nextInstanceId('Counter');
        $id3 = RenderContext::nextInstanceId('Counter');

        $this->assertEquals('Counter#0', $id1);
        $this->assertEquals('Counter#1', $id2);
        $this->assertEquals('Counter#2', $id3);
    }

    public function testNextInstanceIdWithExplicitKey(): void
    {
        RenderContext::beginRender();

        $id1 = RenderContext::nextInstanceId('Counter', 'my-counter');
        $id2 = RenderContext::nextInstanceId('Counter', 'other-counter');

        $this->assertEquals('Counter#my-counter', $id1);
        $this->assertEquals('Counter#other-counter', $id2);
    }

    public function testBeginRenderResetsCounters(): void
    {
        RenderContext::beginRender();
        RenderContext::nextInstanceId('Counter');
        RenderContext::nextInstanceId('Counter');

        $this->assertEquals(2, RenderContext::getInstanceCount('Counter'));

        // New render pass
        RenderContext::beginRender();

        $this->assertEquals(0, RenderContext::getInstanceCount('Counter'));
    }

    public function testMultipleComponentInstancesHaveSeparateState(): void
    {
        RenderContext::beginRender();

        // First counter instance
        $instanceId1 = RenderContext::nextInstanceId('Counter');
        $state1 = ComponentState::getInstance($instanceId1);
        $state1->setState(0, 10);

        // Second counter instance
        $instanceId2 = RenderContext::nextInstanceId('Counter');
        $state2 = ComponentState::getInstance($instanceId2);
        $state2->setState(0, 20);

        // Verify they have different state
        $this->assertEquals(10, $state1->getState(0, 0));
        $this->assertEquals(20, $state2->getState(0, 0));
    }

    public function testDifferentComponentTypesHaveSeparateCounters(): void
    {
        RenderContext::beginRender();

        $id1 = RenderContext::nextInstanceId('Counter');
        $id2 = RenderContext::nextInstanceId('TodoList');
        $id3 = RenderContext::nextInstanceId('Counter');

        $this->assertEquals('Counter#0', $id1);
        $this->assertEquals('TodoList#0', $id2);
        $this->assertEquals('Counter#1', $id3);
    }

    public function testRendererWithMultipleInstances(): void
    {
        RenderContext::beginRender();

        // Simulate rendering two Counter components on the same page
        $instanceId1 = RenderContext::nextInstanceId('Counter');
        $instanceId2 = RenderContext::nextInstanceId('Counter');

        // Set different initial states
        $state1 = ComponentState::getInstance($instanceId1);
        $state1->setState(0, 5);

        $state2 = ComponentState::getInstance($instanceId2);
        $state2->setState(0, 100);

        // Render first counter
        $renderer1 = new Renderer($instanceId1);
        $html1 = $renderer1->render(function () {
            [$count, $setCount] = useState(0);
            return H::div(children: "Count: $count");
        });

        // Reset hooks for next component
        ComponentState::reset();

        // Render second counter
        $renderer2 = new Renderer($instanceId2);
        $html2 = $renderer2->render(function () {
            [$count, $setCount] = useState(0);
            return H::div(children: "Count: $count");
        });

        $this->assertStringContainsString('Count: 5', $html1);
        $this->assertStringContainsString('Count: 100', $html2);
    }
}
