<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Attribute;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Attribute to mark a class as a usePHP component.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Component
{
    public StorageType $storageType;

    public function __construct(
        public string $name,
        StorageType|string $storage = StorageType::Session,
    ) {
        $this->storageType = $storage instanceof StorageType
            ? $storage
            : StorageType::from($storage);
    }
}
