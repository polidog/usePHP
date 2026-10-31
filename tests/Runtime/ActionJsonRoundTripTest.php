<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Storage\StorageType;

class ActionJsonRoundTripTest extends TestCase
{
    protected function tearDown(): void
    {
        ComponentState::clearInstances();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function roundTrippableValueProvider(): iterable
    {
        yield 'whole float' => [2.0];
        yield 'negative whole float' => [-3.0];
        yield 'fraction' => [1.5];
        yield 'large float' => [1.0E+25];
        yield 'int' => [2];
        yield 'string' => ['a'];
        yield 'bool' => [false];
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'list' => [[1, 2.0, 'x']];
        yield 'map with int keys' => [[1 => 'a', 5 => 'b']];
        yield 'nested map' => [['a' => 2.0, 'b' => ['c' => [1.0, null]]]];
    }

    #[DataProvider('roundTrippableValueProvider')]
    public function testValueKeepsItsTypeThroughTheJsonRoundTrip(mixed $value): void
    {
        $json = Action::setState(0, $value, 'test#0')->toJson();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame($value, $decoded['payload']['value']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function nonRoundTrippableValueProvider(): iterable
    {
        yield 'date' => [new \DateTimeImmutable('2026-01-01'), 'DateTimeImmutable'];
        yield 'stdClass' => [new \stdClass(), 'stdClass'];
        yield 'closure' => [fn() => 1, 'Closure'];
        yield 'JsonSerializable' => [
            new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return ['a' => 1];
                }
            },
            'JsonSerializable',
        ];
        yield 'enum' => [StorageType::Session, StorageType::class];
        yield 'object nested in an array' => [['a' => [new \stdClass()]], 'value.a.0'];
        yield 'NaN' => [NAN, 'NAN'];
        yield 'infinity' => [['x' => INF], 'INF'];
    }

    #[DataProvider('nonRoundTrippableValueProvider')]
    public function testValueThatWouldChangeThrowsWhenEncoded(mixed $value, string $inMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($inMessage);

        Action::setState(0, $value, 'test#0')->toJson();
    }

    public function testRenderingAnActionWithAnObjectThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DateTimeImmutable');

        new Renderer('test')->renderElement(new Element(
            'button',
            ['wire:click' => Action::setState(0, ['dt' => new \DateTimeImmutable('2026-01-01')], 'test#0')],
            ['x'],
        ));
    }

    public function testSelfReferencingArrayThrowsInsteadOfRecursingForever(): void
    {
        $value = [];
        $value['self'] = &$value;

        $this->expectException(\InvalidArgumentException::class);

        Action::setState(0, $value, 'test#0')->toJson();
    }

    public function testSnapshotKeepsWholeFloatsAndStillVerifies(): void
    {
        $serializer = new SnapshotSerializer('test-secret-key-with-enough-entropy');

        $json = $serializer->serialize(new Snapshot('Comp', '0', [0 => 2.0, 1 => ['a' => 3.0]]));
        $restored = $serializer->deserialize($json);

        self::assertSame([0 => 2.0, 1 => ['a' => 3.0]], $restored->state);
    }

    public function testRenderingSnapshotStateWithAnObjectThrows(): void
    {
        $id = 'SnapshotObject#0';
        $state = ComponentState::getInstance($id, StorageType::Snapshot);
        $state->setState(0, new \DateTimeImmutable('2026-01-01'));

        $renderer = new Renderer(
            $id,
            new SnapshotSerializer('test-secret-key-with-enough-entropy'),
            StorageType::Snapshot,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DateTimeImmutable');

        $renderer->render(fn(): Element => new Element('div', [], ['x']));
    }
}
