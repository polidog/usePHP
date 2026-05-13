<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * PSX (TSX-like syntax) compiler.
 *
 * Pipeline:
 * 1. PsxPreProcessor scans tokens to locate PSX regions and replaces each
 *    with a unique placeholder function call, producing valid PHP source
 *    (parseable by nikic/php-parser) that has the same line layout as
 *    the original .psx.
 * 2. NamespaceContext::fromSource parses the pre-processed source through
 *    nikic to extract the namespace + use map.
 * 3. PsxParser is run on each captured PSX region using that NamespaceContext
 *    so component tag names (PascalCase) resolve to FQCNs via PHP's `use`
 *    statements.
 * 4. The lowered PHP for each region is substituted back into the
 *    pre-processed source, replacing the placeholder.
 *
 * Side-stream: per-tag line preservation lives inside PsxParser (each child
 * is prefixed with newlines matching the source between siblings) so the
 * compiled output's lines remain aligned with the original .psx source.
 */
final class Compiler implements CompilerInterface
{
    /** @var list<string> FQCNs of component tags seen during the most recent compile() call */
    private array $lastReferences = [];

    /**
     * @param PsxPreProcessorInterface $preProcessor Replaces PSX regions in source
     *        with placeholder calls so the result parses as valid PHP. Injected so
     *        tests (and any future variant pre-processor) can swap the implementation.
     */
    public function __construct(
        private readonly PsxPreProcessorInterface $preProcessor = new PsxPreProcessor(),
    ) {}

    /**
     * @param NamespaceContext|null $context Optional pre-existing context (used when
     *        recursively compiling brace expressions inside an outer .psx file so
     *        namespace + use resolution is preserved).
     */
    public function compile(string $source, ?NamespaceContext $context = null): string
    {
        $this->lastReferences = [];

        [$processed, $regions] = $this->preProcessor->process($source);

        // Reuse the outer context when invoked recursively (from inside a `{...}`
        // brace expression), otherwise derive it from the pre-processed source.
        $namespaceContext = $context ?? NamespaceContext::fromSource($processed);

        $output = $processed;
        foreach ($regions as $idx => $region) {
            $parser = new PsxParser($region['source'], 0, $namespaceContext);
            $result = $parser->parseElement();
            $lowered = $this->padToOriginalLineCount($region['source'], $result['php']);
            $output = \str_replace(
                $this->preProcessor->placeholder($idx),
                $lowered,
                $output,
            );
        }

        $this->lastReferences = $namespaceContext->getResolvedReferences();
        return $output;
    }

    /**
     * @return list<string>
     */
    public function getLastReferences(): array
    {
        return $this->lastReferences;
    }

    /**
     * Append trailing newlines to a lowered PSX expression so the compiled
     * output has the same line count as the original PSX region. Without this,
     * a multi-line PSX block would compress lines and shift code below.
     *
     * Invariant: PsxParser only inserts newlines that exist in the source, so
     * the lowered code's newline count is always ≤ the source span's newline
     * count. If we ever observe more, that's a compiler bug — assert in dev,
     * tolerate in prod (returning the lowered string unchanged means lines
     * after the block shift, but rendering still works).
     */
    private function padToOriginalLineCount(string $regionSource, string $lowered): string
    {
        $originalNewlines = \substr_count($regionSource, "\n");
        $loweredNewlines = \substr_count($lowered, "\n");
        \assert(
            $loweredNewlines <= $originalNewlines,
            "PSX compiler emitted more newlines ($loweredNewlines) than the source span had ($originalNewlines).",
        );
        if ($loweredNewlines >= $originalNewlines) {
            return $lowered;
        }
        return $lowered . \str_repeat("\n", $originalNewlines - $loweredNewlines);
    }
}
