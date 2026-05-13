<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Builds a {@see PsxParserInterface} for a single PSX region. The Compiler
 * delegates parser construction to a factory rather than instantiating
 * PsxParser directly so the parser implementation can be swapped (e.g. in
 * tests with a spy/fake to verify Compiler orchestration without depending
 * on parser internals).
 *
 * The single concrete implementation is {@see PsxParserFactory}.
 */
interface PsxParserFactoryInterface
{
    public function create(
        string $source,
        int $start,
        ?NamespaceContext $namespaceContext = null,
    ): PsxParserInterface;
}
