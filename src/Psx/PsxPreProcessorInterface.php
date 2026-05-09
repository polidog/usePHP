<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Replaces PSX regions in PHP source with placeholder function calls so the
 * result is valid PHP that nikic's parser can fully parse.
 *
 * The single concrete implementation is {@see PsxPreProcessor}.
 */
interface PsxPreProcessorInterface
{
    /**
     * Process source and return the rewritten source plus the captured PSX regions.
     *
     * @return array{0: string, 1: list<array{source: string, start: int, end: int}>}
     *         The pre-processed source plus the list of replaced PSX regions
     *         (in source order).
     */
    public function process(string $source): array;

    /**
     * Build the placeholder function call used in the pre-processed source for
     * the PSX region at the given index.
     */
    public function placeholder(int $index): string;
}
