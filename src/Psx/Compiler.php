<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

use PhpParser\Error;
use PhpParser\ParserFactory;

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
 * 4. TagOperandLowering rewrites `&&` / `||` that have a tag on the right, while
 *    the source still parses.
 * 5. The lowered PHP for each region is substituted back into the
 *    pre-processed source, replacing the placeholder.
 * 6. The result of a whole-file compile is parsed, so that invalid output is a
 *    compile error rather than a failure when the file is first loaded.
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
     * @param PsxPreProcessorInterface    $preProcessor  Replaces PSX regions in source
     *        with placeholder calls so the result parses as valid PHP.
     * @param PsxParserFactoryInterface   $parserFactory Builds the per-region parser.
     *        A factory is used because each region needs a fresh parser bound to
     *        its own (source, start, namespaceContext) tuple.
     */
    public function __construct(
        private readonly PsxPreProcessorInterface $preProcessor = new PsxPreProcessor(),
        private readonly PsxParserFactoryInterface $parserFactory = new PsxParserFactory(),
    ) {}

    /**
     * Compiles a whole file. The result is checked to be valid PHP.
     *
     * @param NamespaceContext|null $context Optional pre-existing context; derived
     *        from the source when omitted.
     */
    public function compile(string $source, ?NamespaceContext $context = null): string
    {
        $output = $this->lower($source, $context, isExpression: false);
        $this->assertValidPhp($output, $source);
        return $output;
    }

    /**
     * Compiles the body of a `{...}` brace expression. It is not validated on its
     * own: the file it belongs to is, once this result is part of it.
     *
     * @param NamespaceContext|null $context The outer file's context, so that
     *        namespace + use resolution is preserved.
     */
    public function compileExpression(string $expression, ?NamespaceContext $context = null): string
    {
        $prefix = '<?php ';
        return \substr($this->lower($prefix . $expression, $context, isExpression: true), \strlen($prefix));
    }

    private function lower(string $source, ?NamespaceContext $context, bool $isExpression): string
    {
        $this->lastReferences = [];

        [$processed, $regions] = $this->preProcessor->process($source);

        // Reuse the outer context when invoked recursively (from inside a `{...}`
        // brace expression), otherwise derive it from the pre-processed source.
        $namespaceContext = $context ?? NamespaceContext::fromSource($processed);

        $placeholders = [];
        foreach (\array_keys($regions) as $idx) {
            $placeholders[] = $this->preProcessor->placeholder($idx);
        }

        $output = $regions === []
            ? $processed
            : new TagOperandLowering()->lower($processed, $placeholders, $isExpression);

        foreach ($regions as $idx => $region) {
            $parser = $this->parserFactory->create($region['source'], 0, $namespaceContext);
            $result = $parser->parseElement();
            $lowered = $this->padToOriginalLineCount($region['source'], $result['php']);
            $output = \str_replace(
                $placeholders[$idx],
                $lowered,
                $output,
            );
        }

        $this->lastReferences = $namespaceContext->getResolvedReferences();
        return $output;
    }

    /**
     * Line numbers in the output match the .psx source, so a parse error can
     * point at the line the author wrote.
     */
    private function assertValidPhp(string $output, string $source): void
    {
        try {
            new ParserFactory()->createForNewestSupportedVersion()->parse($output);
        } catch (Error $e) {
            $line = $e->getStartLine();
            $sourceLine = \explode("\n", $source)[$line - 1] ?? '';
            throw new \RuntimeException(
                "PSX compile error at line $line: generated PHP is invalid: {$e->getRawMessage()}\n    $sourceLine",
                previous: $e,
            );
        }
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
