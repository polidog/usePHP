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
     *         (in source order).
     */
    public function process(string $source): array
    {
        $tokens = \token_get_all($source);
        $regions = [];
        $output = '';
        $expectExpression = true;

        $i = 0;
        $count = \count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                [$id, $text] = $token;

                if ($id === \T_IS_NOT_EQUAL && $expectExpression && $text === '<>') {
                    $output .= $this->capture($source, $tokens, $i, $regions);
                    $i = $this->advanceTokensBeyond($tokens, $i, $regions[\count($regions) - 1]['end']);
                    $expectExpression = false;
                    continue;
                }

                $output .= $text;
                $expectExpression = $this->updateExpressionContextFromToken($id, $expectExpression);
                $i++;
                continue;
            }

            if ($token === '<' && $expectExpression && $this->isPsxTagStart($tokens[$i + 1] ?? null)) {
                $output .= $this->capture($source, $tokens, $i, $regions);
                $i = $this->advanceTokensBeyond($tokens, $i, $regions[\count($regions) - 1]['end']);
                $expectExpression = false;
                continue;
            }

            $output .= $token;
            $expectExpression = $this->updateExpressionContextFromChar($token, $expectExpression);
            $i++;
        }

        return [$output, $regions];
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @param list<array{source: string, start: int, end: int}> $regions
     */
    private function capture(string $source, array $tokens, int $i, array &$regions): string
    {
        $offset = $this->tokenOffset($tokens, $i);
        // Run PsxParser purely for its end-of-region detection. The string it
        // returns is discarded — we only need the end position to slice out
        // the original PSX text.
        $parser = new PsxParser($source, $offset);
        $result = $parser->parseElement();

        $regionText = \substr($source, $offset, $result['end'] - $offset);
        $idx = \count($regions);
        $regions[] = ['source' => $regionText, 'start' => $offset, 'end' => $result['end']];

        // Padding to preserve line count is added later by the Compiler, after
        // the lowered code's own newlines have been counted.
        return $this->placeholder($idx);
    }

    public function placeholder(int $index): string
    {
        return self::PLACEHOLDER_NAMESPACE . "__psx_region_{$index}__()";
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function tokenOffset(array $tokens, int $index): int
    {
        $offset = 0;
        for ($j = 0; $j < $index; $j++) {
            $t = $tokens[$j];
            $offset += \strlen(\is_array($t) ? $t[1] : $t);
        }
        return $offset;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function advanceTokensBeyond(array $tokens, int $startIndex, int $byteEnd): int
    {
        $offset = $this->tokenOffset($tokens, $startIndex);
        $i = $startIndex;
        $count = \count($tokens);
        while ($i < $count && $offset < $byteEnd) {
            $t = $tokens[$i];
            $offset += \strlen(\is_array($t) ? $t[1] : $t);
            $i++;
        }
        return $i;
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
