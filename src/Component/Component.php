<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Attribute;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Attribute to mark a class as a usePHP component.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Component
{
    public StorageType $storageType;

    public function __construct(
        public ?string $name = null,
        StorageType|string $storage = StorageType::Session,
        /**
         * If true, requires an explicit key when rendering.
         * This ensures stable component identification.
         */
        public bool $requireKey = false,
    ) {
        $this->storageType = $storage instanceof StorageType
            ? $storage
            : StorageType::from($storage);
    }
}
