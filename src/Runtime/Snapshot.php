<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Value object representing a component state snapshot.
 *
 * Used for stateless state management where state is embedded in HTML
 * and sent with each request (similar to Livewire's approach).
 */
final readonly class Snapshot
{
    public function __construct(
        public string $componentName,
        public string $key,
        /** @var array<int, mixed> State values indexed by hook index */
        public array $state = [],
        /** @var array<int, array<mixed>|null> Effect dependencies indexed by hook index */
        public array $effectDeps = [],
        public ?string $checksum = null,
    ) {}

    /**
     * Create a Snapshot from a ComponentId.
     *
     * @param array<int, mixed> $state
     * @param array<int, array<mixed>|null> $effectDeps
     */
    public static function fromComponentId(
        ComponentId $componentId,
        array $state = [],
        array $effectDeps = [],
        ?string $checksum = null,
    ): self {
        return new self(
            $componentId->componentName,
            $componentId->key,
            $state,
            $effectDeps,
            $checksum,
        );
    }

    /**
     * Get the ComponentId for this snapshot.
     */
    public function getComponentId(): ComponentId
    {
        return ComponentId::create($this->componentName, $this->key);
    }

    /**
     * Get the legacy instance ID string.
     */
    public function getInstanceId(): string
    {
        return $this->componentName . '#' . $this->key;
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $json;
    }

    /**
     * Convert to array representation.
     *
     * @return array{memo: array{name: string, key: string}, state: array<int, mixed>, effectDeps: array<int, array<mixed>|null>, checksum: string|null}
     */
    public function toArray(): array
    {
        return [
            'memo' => [
                'name' => $this->componentName,
                'key' => $this->key,
            ],
            'state' => $this->state,
            'effectDeps' => $this->effectDeps,
            'checksum' => $this->checksum,
        ];
    }

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::fromArray($data);
    }

    /**
     * Create from array representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['memo']) || !is_array($data['memo'])) {
            throw new \InvalidArgumentException('Invalid snapshot data: missing memo');
        }

        $memo = $data['memo'];
        if (!isset($memo['name']) || !isset($memo['key'])) {
            throw new \InvalidArgumentException('Invalid snapshot data: missing memo.name or memo.key');
        }

        return new self(
            (string) $memo['name'],
            (string) $memo['key'],
            isset($data['state']) && is_array($data['state']) ? $data['state'] : [],
            isset($data['effectDeps']) && is_array($data['effectDeps']) ? $data['effectDeps'] : [],
            isset($data['checksum']) && is_string($data['checksum']) ? $data['checksum'] : null,
        );
    }

    /**
     * Create a new Snapshot with updated state.
     *
     * @param array<int, mixed> $state
     */
    public function withState(array $state): self
    {
        return new self(
            $this->componentName,
            $this->key,
            $state,
            $this->effectDeps,
            null, // Checksum needs to be recalculated
        );
    }

    /**
     * Create a new Snapshot with updated effect deps.
     *
     * @param array<int, array<mixed>|null> $effectDeps
     */
    public function withEffectDeps(array $effectDeps): self
    {
        return new self(
            $this->componentName,
            $this->key,
            $this->state,
            $effectDeps,
            null, // Checksum needs to be recalculated
        );
    }

    /**
     * Create a new Snapshot with a checksum.
     */
    public function withChecksum(string $checksum): self
    {
        return new self(
            $this->componentName,
            $this->key,
            $this->state,
            $this->effectDeps,
            $checksum,
        );
    }

    /**
     * Check if this snapshot has any state.
     */
    public function hasState(): bool
    {
        return !empty($this->state);
    }
}
