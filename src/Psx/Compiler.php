<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * PSX (TSX-like syntax) compiler.
 *
 * Transforms .psx source into equivalent PHP that calls H::xxx() / H::__callStatic()
 * / RenderContext::getApp()->renderPsxComponent(...) and emits H::Fragment(...) for
 * `<>...</>` syntax.
 *
 * Strategy:
 * 1. Tokenize PHP code with token_get_all().
 * 2. Build a NamespaceContext from `namespace` / `use` declarations so component
 *    tags can be resolved to FQCNs.
 * 3. Walk tokens tracking expression-start context.
 * 4. When `<` (or T_IS_NOT_EQUAL `<>` for Fragment) appears at an expression-start
 *    position with a valid lookahead, hand off to PsxParser for one PSX expression.
 * 5. Reassemble the output: emit lowered PHP for PSX regions, copy original PHP
 *    elsewhere.
 *
 * Not yet implemented (see docs/PSX.md §8):
 * - Source maps (errors point to .psx.php, not the original .psx)
 * - PHPStan extension / IDE plugins
 * - Watch mode
 */
final class Compiler
{
    /** @var list<string> FQCNs of component tags seen during the most recent compile() call */
    private array $lastReferences = [];

    /**
     * @param NamespaceContext|null $context Optional pre-existing context (used when
     *        recursively compiling brace expressions inside an outer .psx file so
     *        namespace + use resolution is preserved).
     */
    public function compile(string $source, ?NamespaceContext $context = null): string
    {
        $tokens = \token_get_all($source);
        $namespaceContext = $context ?? NamespaceContext::parse($tokens);
        $this->lastReferences = [];
        $output = '';
        $expectExpression = true;

        $i = 0;
        $count = \count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                [$id, $text] = $token;

                // PHP tokenizes `<>` as T_IS_NOT_EQUAL. In expression context this
                // is a Fragment opener — switch to PSX parsing.
                if ($id === \T_IS_NOT_EQUAL && $expectExpression && $text === '<>') {
                    $offset = $this->tokenOffset($tokens, $i);
                    $parser = new PsxParser($source, $offset, $namespaceContext);
                    $result = $parser->parseElement();
                    $output .= $result['php'];
                    $i = $this->advanceTokensBeyond($tokens, $i, $result['end']);
                    $expectExpression = false;
                    continue;
                }

                $output .= $text;
                $expectExpression = $this->updateExpressionContext($id, $expectExpression);
                $i++;
                continue;
            }

            // Single-character token (string).
            if ($token === '<' && $expectExpression) {
                // Look ahead to decide if this is a PSX tag start.
                $next = $tokens[$i + 1] ?? null;
                if ($this->isPsxTagStart($next)) {
                    $offset = $this->tokenOffset($tokens, $i);
                    $parser = new PsxParser($source, $offset, $namespaceContext);
                    $result = $parser->parseElement();
                    $output .= $result['php'];

                    // Skip tokens consumed by the PSX parser.
                    $i = $this->advanceTokensBeyond($tokens, $i, $result['end']);
                    $expectExpression = false;
                    continue;
                }
            }

            $output .= $token;
            $expectExpression = $this->updateExpressionContextChar($token, $expectExpression);
            $i++;
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
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function tokenOffset(array $tokens, int $index): int
    {
        // Compute byte offset of token at $index by summing prior token lengths.
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
        // Fragment <> or closing </ would also start with these single chars.
        return $next === '/' || $next === '>';
    }

    private function updateExpressionContext(int $tokenId, bool $current): bool
    {
        // Tokens after which an expression is expected.
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

        // Whitespace/comments don't change context.
        if (\in_array($tokenId, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            return $current;
        }

        // Most other tokens (identifiers, literals, etc.) end an expression-start position.
        return false;
    }

    private function updateExpressionContextChar(string $char, bool $current): bool
    {
        return match ($char) {
            '=', ',', '(', '[', '{', ';', '?', ':', '!', '&', '|', '^', '+', '-', '*', '/', '%', '.' => true,
            ')', ']', '}' => false,
            default => $current,
        };
    }
}
