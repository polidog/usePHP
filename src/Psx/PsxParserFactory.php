<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

final class PsxParserFactory implements PsxParserFactoryInterface
{
    public function create(
        string $source,
        int $start,
        ?NamespaceContext $namespaceContext = null,
    ): PsxParserInterface {
        return new PsxParser($source, $start, $namespaceContext);
    }
}
