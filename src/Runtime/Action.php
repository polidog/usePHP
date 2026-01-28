<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Storage\StorageType;

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
        public ?StorageType $storageType = null,
    ) {}

    /**
     * Create a new Action with updated payload.
     *
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return new self($this->type, $payload, $this->componentId, $this->storageType);
    }

    /**
     * Create a new Action with a specific componentId.
     */
    public function withComponentId(string $componentId): self
    {
        return new self($this->type, $this->payload, $componentId, $this->storageType);
    }

    /**
     * Create a new Action with a specific storageType.
     */
    public function withStorageType(StorageType $storageType): self
    {
        return new self($this->type, $this->payload, $this->componentId, $storageType);
    }

    /**
     * @return array{type: string, payload: array<string, mixed>, componentId: string|null, storageType: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'componentId' => $this->componentId,
            'storageType' => $this->storageType?->value,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function setState(int $stateIndex, mixed $value, ?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self('setState', [
            'index' => $stateIndex,
            'value' => $value,
        ], $componentId, $storageType);
    }

    /**
     * @param array{type: string, payload?: array<string, mixed>, componentId?: string|null, storageType?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        $storageType = isset($data['storageType']) ? StorageType::tryFrom($data['storageType']) : null;
        return new self($data['type'], $data['payload'] ?? [], $data['componentId'] ?? null, $storageType);
    }
}
