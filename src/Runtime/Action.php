<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Storage\StorageType;

/**
 * Represents an action to be executed (e.g., state update).
 */
final readonly class Action
{
    public const SET_FIELDS = 'setFields';

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
     * Copy submitted form fields into hooks. The field-to-hook map is part of
     * the signed action; only the values come from the client.
     *
     * @param array<string, int> $fields Field name => hook index
     */
    public static function setFields(array $fields, ?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self(self::SET_FIELDS, ['fields' => $fields], $componentId, $storageType);
    }

    /**
     * Re-render a component from the snapshot sent with the request without
     * changing any state. usephp.js sends this on page load for components
     * that opted into client-side snapshot persistence.
     */
    public static function restore(?string $componentId = null, ?StorageType $storageType = null): self
    {
        return new self('restore', [], $componentId, $storageType);
    }

    /**
     * Rebuild an Action from decoded JSON.
     *
     * The input comes straight from the `_usephp_action` POST field, so every
     * shape assumption is checked here and reported as InvalidArgumentException
     * rather than surfacing as a TypeError from the constructor.
     *
     * @param array<mixed> $data
     * @throws \InvalidArgumentException If $data is not a well-formed action.
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new \InvalidArgumentException('Action "type" must be a non-empty string');
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Action "payload" must be an object');
        }

        $componentId = $data['componentId'] ?? null;
        if ($componentId !== null && !is_string($componentId)) {
            throw new \InvalidArgumentException('Action "componentId" must be a string or null');
        }

        $storageType = null;
        if (isset($data['storageType'])) {
            $storageType = is_string($data['storageType']) ? StorageType::tryFrom($data['storageType']) : null;
            if ($storageType === null) {
                throw new \InvalidArgumentException('Action "storageType" is not a known storage type');
            }
        }

        /** @var array<string, mixed> $payload */
        return new self($type, $payload, $componentId, $storageType);
    }
}
