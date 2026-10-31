<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Gives `&&` and `||` the JSX meaning when a tag is on their right-hand side.
 *
 * In PHP these operators return a bool, so `{$x && <b/>}` would evaluate to
 * `true` and render nothing, and `{$x || <b/>}` could never render the tag.
 * They are rewritten to ternaries that return an operand instead:
 *
 *     L && R   =>   ((L) ? (R) : false)
 *     L || R   =>   ((L) ?: (R))
 *
 * The truthiness of the result does not change, only its type. Unlike JS, a
 * falsy left side of `&&` is not returned, so `{0 && <b/>}` renders nothing.
 * Operators with no tag on the right keep their PHP meaning.
 *
 * Works on the pre-processed source, where each tag is a placeholder call and
 * the code parses. Edits are spliced in by offset rather than pretty-printing
 * the AST, so comments and line numbers stay as written.
 */
final class TagOperandLowering
{
    private const OPERATOR_TOKENS = [\T_BOOLEAN_AND, \T_BOOLEAN_OR, \T_LOGICAL_AND, \T_LOGICAL_OR];

    /**
     * @param list<string> $placeholders Source text of the placeholder calls.
     * @param bool $isExpression Whether $source is a `<?php expr` fragment with
     *        no terminating semicolon rather than a whole file.
     */
    public function lower(string $source, array $placeholders, bool $isExpression): string
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($isExpression ? $source . "\n;" : $source);
        } catch (Error) {
            // Not this step's job to report: the compiler validates its output.
            return $source;
        }
        if ($ast === null) {
            return $source;
        }

        $finder = new NodeFinder();
        $isPlaceholder = static fn(Node $node): bool => $node instanceof FuncCall
            && \in_array(self::textOf($node, $source), $placeholders, true);
        $operators = $finder->find(
            $ast,
            static fn(Node $node): bool => ($node instanceof BooleanAnd || $node instanceof BooleanOr
                    || $node instanceof LogicalAnd || $node instanceof LogicalOr)
                && $finder->findFirst($node->right, $isPlaceholder) !== null,
        );

        $tokens = $parser->getTokens();
        $edits = [];
        // Innermost first: an inner and an outer operator can end at the same
        // offset, and the inner one's closing text has to come first.
        foreach (\array_reverse($operators) as $operator) {
            /** @var BooleanAnd|BooleanOr|LogicalAnd|LogicalOr $operator */
            $isAnd = $operator instanceof BooleanAnd || $operator instanceof LogicalAnd;
            $token = $this->findOperatorToken($tokens, $operator);

            $edits[] = [$operator->getStartFilePos(), 0, '(('];
            $edits[] = [$token->pos, \strlen($token->text), $isAnd ? ') ? (' : ') ?: ('];
            $edits[] = [$operator->getEndFilePos() + 1, 0, $isAnd ? ') : false)' : '))'];
        }

        return $this->applyEdits($source, $edits);
    }

    private static function textOf(Node $node, string $source): string
    {
        return \substr($source, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /**
     * @param array<\PhpParser\Token> $tokens
     */
    private function findOperatorToken(array $tokens, BooleanAnd|BooleanOr|LogicalAnd|LogicalOr $operator): \PhpParser\Token
    {
        // Only parentheses and comments can sit between the operands and the
        // operator, so the first operator token in the gap is the one.
        for ($i = $operator->left->getEndTokenPos() + 1; $i < $operator->right->getStartTokenPos(); $i++) {
            if (\in_array($tokens[$i]->id, self::OPERATOR_TOKENS, true)) {
                return $tokens[$i];
            }
        }
        throw new \LogicException('Logical operator token not found between its operands.');
    }

    /**
     * @param list<array{int, int, string}> $edits Offset, length to replace, text.
     */
    private function applyEdits(string $source, array $edits): string
    {
        // usort is stable, which keeps the order of edits at the same offset.
        \usort($edits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $output = '';
        $cursor = 0;
        foreach ($edits as [$offset, $length, $text]) {
            $output .= \substr($source, $cursor, $offset - $cursor) . $text;
            $cursor = $offset + $length;
        }
        return $output . \substr($source, $cursor);
    }
}
