<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Action;

class ActionTest extends TestCase
{
    public function testSetStateWithoutComponentId(): void
    {
        $action = Action::setState(0, 'value');

        $this->assertEquals('setState', $action->type);
        $this->assertEquals(['index' => 0, 'value' => 'value'], $action->payload);
        $this->assertNull($action->componentId);
    }

    public function testSetStateWithComponentId(): void
    {
        $action = Action::setState(1, 42, 'Counter#0');

        $this->assertEquals('setState', $action->type);
        $this->assertEquals(['index' => 1, 'value' => 42], $action->payload);
        $this->assertEquals('Counter#0', $action->componentId);
    }

    public function testToArrayIncludesComponentId(): void
    {
        $action = Action::setState(0, 'test', 'MyComponent#1');
        $array = $action->toArray();

        $this->assertArrayHasKey('componentId', $array);
        $this->assertEquals('MyComponent#1', $array['componentId']);
    }

    public function testToJsonIncludesComponentId(): void
    {
        $action = Action::setState(0, 'test', 'Counter#2');
        $json = $action->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('Counter#2', $decoded['componentId']);
    }

    public function testFromArrayWithComponentId(): void
    {
        $data = [
            'type' => 'setState',
            'payload' => ['index' => 0, 'value' => 'hello'],
            'componentId' => 'Counter#5',
        ];

        $action = Action::fromArray($data);

        $this->assertEquals('setState', $action->type);
        $this->assertEquals('Counter#5', $action->componentId);
    }

    public function testFromArrayWithoutComponentId(): void
    {
        $data = [
            'type' => 'setState',
            'payload' => ['index' => 0, 'value' => 'hello'],
        ];

        $action = Action::fromArray($data);

        $this->assertNull($action->componentId);
    }

    public function testWithComponentId(): void
    {
        $action = Action::setState(0, 'value');
        $this->assertNull($action->componentId);

        $newAction = $action->withComponentId('Counter#3');
        $this->assertEquals('Counter#3', $newAction->componentId);

        // Original action should be unchanged (immutable)
        $this->assertNull($action->componentId);
    }
}
