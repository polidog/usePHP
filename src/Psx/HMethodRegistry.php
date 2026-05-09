<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

use Polidog\UsePhp\Html\H;

/**
 * Reflection-based registry of named parameters defined on H::xxx() methods.
 *
 * The PSX compiler consults this to decide whether a tag's attributes can be
 * passed as named arguments (H::div(className: ...)) or whether to fall back
 * to H::__callStatic('div', [...]) — which is required when any attribute
 * isn't part of the fixed method signature (e.g., data-id, aria-label).
 */
final class HMethodRegistry
{
    /** @var array<string, list<string>|null> */
    private static array $cache = [];

    /**
     * Returns the named parameters of H::$tagName(), or null if the tag
     * isn't a defined method (and therefore must go through __callStatic).
     *
     * @return list<string>|null
     */
    public static function getParams(string $tagName): ?array
    {
        if (\array_key_exists($tagName, self::$cache)) {
            return self::$cache[$tagName];
        }

        try {
            $method = new \ReflectionMethod(H::class, $tagName);
        } catch (\ReflectionException) {
            return self::$cache[$tagName] = null;
        }

        // __callStatic and component() aren't HTML tag emitters.
        if ($method->isStatic() === false || $method->getName() === '__callStatic') {
            return self::$cache[$tagName] = null;
        }

        $names = [];
        foreach ($method->getParameters() as $param) {
            $names[] = $param->getName();
        }

        return self::$cache[$tagName] = $names;
    }

    /**
     * True if the given attribute name can be passed as a named argument to H::$tagName().
     */
    public static function isKnownAttribute(string $tagName, string $attrName): bool
    {
        $params = self::getParams($tagName);
        if ($params === null) {
            return false;
        }
        return \in_array($attrName, $params, true);
    }
}
