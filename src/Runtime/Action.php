<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Represents an action to be executed (e.g., state update).
 */
final readonly class Action
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $componentId = null,
    ) {}

    /**
     * Create a new Action with updated payload.
     *
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return new self($this->type, $payload, $this->componentId);
    }

    /**
     * Create a new Action with a specific componentId.
     */
    public function withComponentId(string $componentId): self
    {
        return new self($this->type, $this->payload, $componentId);
    }

    /**
     * @return array{type: string, payload: array<string, mixed>, componentId: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'componentId' => $this->componentId,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function setState(int $stateIndex, mixed $value, ?string $componentId = null): self
    {
        return new self('setState', [
            'index' => $stateIndex,
            'value' => $value,
        ], $componentId);
    }

    /**
     * @param array{type: string, payload?: array<string, mixed>, componentId?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['payload'] ?? [], $data['componentId'] ?? null);
    }
}
