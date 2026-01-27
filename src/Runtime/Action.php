<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Represents an action to be executed (e.g., state update).
 */
readonly class Action
{
    public function __construct(
        public string $type,
        public array $payload = []
    ) {}

    /**
     * Create a new Action with updated payload (PHP 8.5 Clone With).
     *
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return clone($this, ['payload' => $payload]);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function setState(int $stateIndex, mixed $value): self
    {
        return new self('setState', [
            'index' => $stateIndex,
            'value' => $value,
        ]);
    }

    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['payload'] ?? []);
    }
}
