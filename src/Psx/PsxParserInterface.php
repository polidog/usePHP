<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Parses one PSX expression from a source string starting at `<` and emits
 * equivalent PHP source code (calls to H::xxx() or renderPsxComponent()).
 *
 * The single concrete implementation is {@see PsxParser}. Construction takes
 * the source / start offset / optional NamespaceContext, so this contract only
 * covers the post-construction parse step.
 */
interface PsxParserInterface
{
    /**
     * Parse a single PSX element starting at the configured position and
     * return the lowered PHP source plus the source byte-offset where the
     * element ends (exclusive).
     *
     * @return array{php: string, end: int}
     */
    public function parseElement(): array;
}
