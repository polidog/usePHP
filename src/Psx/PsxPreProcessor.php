<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Walks PHP tokens to find PSX regions in a source string and replaces each
 * with a placeholder function call so the result is valid PHP that nikic's
 * parser can fully parse.
 *
 * Placeholders take the form `\Polidog\UsePhp\Psx\Internal\__psx_region_N__()`
 * — a top-level function call. The Compiler later substitutes each occurrence
 * with the lowered PSX expression.
 *
 * Strategy: iterate. Each pass re-tokenizes the current source and locates
 * the *first* PSX region only, then splices the placeholder in and loops.
 * JSX text routinely contains characters that PHP's tokenizer mis-handles —
 * an unbalanced `'` or `"` is swallowed as a string literal running to the
 * next matching quote or EOF, and `#` / `//` start a line comment that
 * consumes the rest of the line. Either case can corrupt the token stream
 * for the region itself and anything after it. Tokens *before* the first
 * such region remain accurate though, so their offsets are trustworthy.
 * Replacing each region with a placeholder removes the corrupting input
 * for the next pass.
 *
 * Line-count preservation is NOT done here: this stage emits the placeholder
 * with no padding. The Compiler tops up newlines once it knows how many lines
 * the lowered code actually consumes, so line numbers in the final output
 * match the original .psx source.
 */
final class PsxPreProcessor implements PsxPreProcessorInterface
{
    public const PLACEHOLDER_NAMESPACE = '\\Polidog\\UsePhp\\Psx\\Internal\\';

    /**
     * @return array{0: string, 1: list<array{source: string, start: int, end: int}>}
     *         The pre-processed source plus the list of replaced PSX regions
     *         (in source order). `start`/`end` are offsets in the *original*
     *         source.
     */
    public function process(string $source): array
    {
        $regions = [];
        // Modified offsets shift as we splice placeholders in; this tracks
        // (originalOffset - currentOffset) so we can report region positions
        // against the original source.
        $offsetShift = 0;

        while (true) {
            $offset = $this->findFirstRegionOffset($source);
            if ($offset === null) {
                break;
            }

            $end = new PsxParser($source, $offset)->parseElement()['end'];

            $regionText = \substr($source, $offset, $end - $offset);
            $idx = \count($regions);
            $regions[] = [
                'source' => $regionText,
                'start' => $offset + $offsetShift,
                'end' => $end + $offsetShift,
            ];

            $placeholder = $this->placeholder($idx);
            $source = \substr($source, 0, $offset) . $placeholder . \substr($source, $end);
            $offsetShift += ($end - $offset) - \strlen($placeholder);
        }

        return [$source, $regions];
    }

    public function placeholder(int $index): string
    {
        return self::PLACEHOLDER_NAMESPACE . "__psx_region_{$index}__()";
    }

    /**
     * Locate the byte offset of the first PSX region in `$source`, or null if
     * none remains. Only the prefix up to the region matters — tokens after
     * the JSX may be misparsed by PHP's tokenizer, but that's fine because we
     * never consult them.
     */
    private function findFirstRegionOffset(string $source): ?int
    {
        $tokens = \token_get_all($source);
        $expectExpression = true;
        $offset = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                [$id, $text] = $token;

                if ($id === \T_IS_NOT_EQUAL && $expectExpression && $text === '<>') {
                    return $offset;
                }

                $offset += \strlen($text);
                $expectExpression = $this->updateExpressionContextFromToken($id, $expectExpression);
                continue;
            }

            if ($token === '<' && $expectExpression && $this->isPsxTagStart($tokens[$i + 1] ?? null)) {
                return $offset;
            }

            $offset++;
            $expectExpression = $this->updateExpressionContextFromChar($token, $expectExpression);
        }

        return null;
    }

    /**
     * @param array{0:int,1:string,2:int}|string|null $next
     */
    private function isPsxTagStart(mixed $next): bool
    {
        if ($next === null) {
            return false;
        }
        if (\is_array($next)) {
            return $next[0] === \T_STRING;
        }
        return $next === '/' || $next === '>';
    }

    private function updateExpressionContextFromToken(int $tokenId, bool $current): bool
    {
        $expressionStartingTokens = [
            \T_RETURN,
            \T_ECHO,
            \T_PRINT,
            \T_DOUBLE_ARROW,
            \T_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR,
            \T_OPEN_TAG,
            \T_OPEN_TAG_WITH_ECHO,
            \T_FN,
        ];

        if (\in_array($tokenId, $expressionStartingTokens, true)) {
            return true;
        }

        if (\in_array($tokenId, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            return $current;
        }

        return false;
    }

    private function updateExpressionContextFromChar(string $char, bool $current): bool
    {
        return match ($char) {
            '=', ',', '(', '[', '{', ';', '?', ':', '!', '&', '|', '^', '+', '-', '*', '/', '%', '.' => true,
            ')', ']', '}' => false,
            default => $current,
        };
    }
}
