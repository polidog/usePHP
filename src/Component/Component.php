<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Attribute;
use Polidog\UsePhp\Storage\SnapshotPersist;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Attribute to mark a class as a usePHP component.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Component
{
    public StorageType $storageType;

    /**
     * Snapshot storage only: keep the snapshot in Web Storage across page
     * reloads. See {@see SnapshotPersist}.
     */
    public ?SnapshotPersist $persist;

    public function __construct(
        public ?string $name = null,
        StorageType|string $storage = StorageType::Session,
        /**
         * If true, requires an explicit key when rendering.
         * This ensures stable component identification.
         */
        public bool $requireKey = false,
        SnapshotPersist|string|null $persist = null,
    ) {
        $this->storageType = $storage instanceof StorageType
            ? $storage
            : StorageType::from($storage);
        $this->persist = is_string($persist) ? SnapshotPersist::from($persist) : $persist;

        if ($this->persist !== null && $this->storageType !== StorageType::Snapshot) {
            throw new \InvalidArgumentException(
                '#[Component] persist only applies to snapshot storage, got "'
                . $this->storageType->value . '".'
            );
        }
    }
}
