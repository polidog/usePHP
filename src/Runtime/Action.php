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

    /** json_encode() and json_decode() default to this depth. */
    private const MAX_JSON_DEPTH = 512;

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

    /**
     * @throws \InvalidArgumentException If the payload holds a value that JSON cannot carry unchanged.
     */
    public function toJson(): string
    {
        self::assertJsonRoundTrippable($this->payload);

        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * Refuse a state value that would come back from JSON as something else.
     * json_encode() accepts objects, but they return as arrays, so a component
     * would only fail after the first interaction. Only null, scalars and
     * arrays of those are allowed; JsonSerializable is refused for the same
     * reason. NAN and INF have no JSON form.
     *
     * @param string $path Where $value sits, for the error message.
     * @throws \InvalidArgumentException
     */
    public static function assertJsonRoundTrippable(mixed $value, string $path = '', int $depth = 0): void
    {
        if (is_array($value)) {
            if ($depth > self::MAX_JSON_DEPTH) {
                throw new \InvalidArgumentException(sprintf(
                    'State value at "%s" is nested more than %d levels deep or references itself.',
                    $path,
                    self::MAX_JSON_DEPTH,
                ));
            }
            foreach ($value as $key => $item) {
                self::assertJsonRoundTrippable($item, $path === '' ? (string) $key : $path . '.' . $key, $depth + 1);
            }

            return;
        }

        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_float($value) && is_finite($value)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'State value at "%s" is %s, which does not survive the JSON round trip between renders. '
            . 'Only null, scalars and arrays are allowed. Convert it first, for example '
            . '$date->format(DATE_ATOM) for a date.',
            $path,
            is_float($value) ? var_export($value, true) : get_debug_type($value),
        ));
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
