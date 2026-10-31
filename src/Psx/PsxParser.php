<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Parses one PSX expression from a source string starting at `<`.
 *
 * Produces equivalent PHP source code: createElement() calls for built-in tags
 * and renderPsxComponent() calls for component tags.
 *
 * Built-in tags deliberately bypass H::xxx(): H's parameter types are for
 * hand-written PHP, and under strict_types they reject ordinary markup such as
 * `{0}` or `width="100"`. createElement() is where H's own conversions (event
 * props to actions, renderable children) live, so nothing is lost.
 */
final class PsxParser implements PsxParserInterface
{
    /**
     * Elements whose content HTML parsers treat as text. `{` and `<` are CSS and
     * JS syntax here, so the content is passed through as a single string.
     */
    private const RAW_TEXT_TAGS = ['style', 'script'];

    private const CREATE_ELEMENT = '\\Polidog\\UsePhp\\Html\\createElement';

    /**
     * `<defer>` is the one lowercase tag that is not an HTML element: it builds
     * the placeholder of {@see \Polidog\UsePhp\Html\H::defer()}.
     */
    private const DEFER_PARAMS = ['name', 'params', 'fallback', 'localCache', 'reloadable', 'localCacheTtl'];

    private int $pos;

    public function __construct(
        private readonly string $source,
        int $start,
        private readonly ?NamespaceContext $namespaceContext = null,
    ) {
        $this->pos = $start;
    }

    /**
     * @return array{php: string, end: int}
     */
    public function parseElement(): array
    {
        $this->expect('<');
        $tagName = $this->readTagName();

        if ($tagName === '') {
            // Fragment <>...</>
            $this->expect('>');
            [$children, $trailingNewlines, $loneExpression] = $this->parseChildren(closingTag: '');
            $this->consumeFragmentClose();
            return [
                'php' => $this->emitFragment($children, $trailingNewlines, $loneExpression),
                'end' => $this->pos,
            ];
        }

        $attrs = $this->parseAttributes();
        $this->skipWhitespace();

        if ($this->peek() === '/') {
            // Self-closing tag.
            $this->pos++; // consume '/'
            $this->expect('>');
            return [
                'php' => $this->emitElement($tagName, $attrs, null, 0, false),
                'end' => $this->pos,
            ];
        }

        $this->expect('>');

        if (\in_array($tagName, self::RAW_TEXT_TAGS, true)) {
            $children = $this->readRawText($tagName);
            $this->consumeClosingTag($tagName);
            return [
                'php' => $this->emitElement($tagName, $attrs, $children, 0, false),
                'end' => $this->pos,
            ];
        }

        [$children, $trailingNewlines, $loneExpression] = $this->parseChildren(closingTag: $tagName);
        $this->consumeClosingTag($tagName);

        return [
            'php' => $this->emitElement($tagName, $attrs, $children, $trailingNewlines, $loneExpression),
            'end' => $this->pos,
        ];
    }

    /**
     * @return list<array{name: string, value: string|null, isExpr: bool}>
     */
    private function parseAttributes(): array
    {
        $attrs = [];
        while (true) {
            $this->skipWhitespace();
            $c = $this->peek();
            if ($c === '/' || $c === '>' || $c === null) {
                return $attrs;
            }

            $name = $this->readAttrName();
            if ($name === '') {
                return $attrs;
            }

            $this->skipWhitespace();
            if ($this->peek() !== '=') {
                // Boolean attribute (no value).
                $attrs[] = ['name' => $name, 'value' => 'true', 'isExpr' => true];
                continue;
            }
            $this->pos++; // consume '='
            $this->skipWhitespace();

            $c = $this->peek();
            if ($c === '"' || $c === "'") {
                $value = $this->readStringLiteral();
                $attrs[] = [
                    'name' => $name,
                    'value' => $this->phpString($this->decodeEntities($value)),
                    'isExpr' => false,
                ];
            } elseif ($c === '{') {
                $expr = $this->readBraceExpression();
                if ($this->isBlankExpression($expr)) {
                    throw $this->error("Attribute '$name' has an empty expression");
                }
                $attrs[] = ['name' => $name, 'value' => $expr, 'isExpr' => true];
            } else {
                throw $this->error("Expected attribute value after '=' for '$name'");
            }
        }
    }

    /**
     * @return array{0: list<string>, 1: int, 2: bool} children PHP expressions,
     *         the number of newlines between the last child and the closing
     *         tag (so the emitter can place `])` on the right line), and whether
     *         the only child is a `{...}` expression.
     *
     * Each child string is prefixed with '\n' characters matching the newlines
     * in the source between the parent's `>` and that child's start (or the
     * previous child's end and this child's start), giving per-tag line
     * preservation in multi-line markup.
     */
    private function parseChildren(string $closingTag): array
    {
        $children = [];
        $textBuffer = '';
        $lastChildEnd = $this->pos; // right after parent's `>`
        $expressions = 0;

        while ($this->pos < \strlen($this->source)) {
            $c = $this->peek();

            if ($c === '<') {
                $next = $this->source[$this->pos + 1] ?? '';
                if ($next === '/') {
                    $this->flushTextBuffer($textBuffer, $children);
                    $trailing = \substr_count(\substr($this->source, $lastChildEnd, $this->pos - $lastChildEnd), "\n");
                    return [$children, $trailing, $expressions === 1 && \count($children) === 1];
                }
                $this->flushTextBuffer($textBuffer, $children);
                $childStart = $this->pos;
                $sub = new self($this->source, $this->pos, $this->namespaceContext)->parseElement();
                $children[] = $this->prefixForNewlinesBetween($lastChildEnd, $childStart) . $sub['php'];
                $this->pos = $sub['end'];
                $lastChildEnd = $sub['end'];
                continue;
            }

            if ($c === '{') {
                $this->flushTextBuffer($textBuffer, $children);
                $childStart = $this->pos;
                $expr = $this->readBraceExpression();
                if ($this->isBlankExpression($expr)) {
                    // `{/* comment */}` is not a child. Leaving $lastChildEnd
                    // alone carries the comment's newlines over to the next
                    // child's prefix, so line numbers stay aligned.
                    continue;
                }
                $children[] = $this->prefixForNewlinesBetween($lastChildEnd, $childStart) . $expr;
                $expressions++;
                $lastChildEnd = $this->pos;
                continue;
            }

            $textBuffer .= $c;
            $this->pos++;
        }

        $this->flushTextBuffer($textBuffer, $children);
        return [$children, 0, false];
    }

    /**
     * Reads the content of a raw text element verbatim, up to its closing tag.
     * Whitespace is significant in JS and entities are not decoded, matching how
     * HTML parsers treat these elements.
     *
     * @return list<string> The content as a PHP string literal; empty if none.
     */
    private function readRawText(string $tagName): array
    {
        $found = \preg_match('~</' . $tagName . '(?=[\s/>])~i', $this->source, $match, \PREG_OFFSET_CAPTURE, $this->pos);
        if ($found !== 1) {
            throw $this->error("Unclosed <$tagName>");
        }
        $end = $match[0][1];
        $text = \substr($this->source, $this->pos, $end - $this->pos);
        $this->pos = $end;
        return $text === '' ? [] : [$this->phpString($text)];
    }

    /**
     * Returns a string of '\n' characters matching the newlines in the source
     * span between two positions.
     */
    private function prefixForNewlinesBetween(int $start, int $end): string
    {
        $span = \substr($this->source, $start, $end - $start);
        $count = \substr_count($span, "\n");
        return $count > 0 ? \str_repeat("\n", $count) : '';
    }

    /**
     * @param list<string> $children
     * @param-out list<string> $children
     */
    private function flushTextBuffer(string &$textBuffer, array &$children): void
    {
        if ($textBuffer === '') {
            return;
        }
        $trimmed = $this->normalizeText($textBuffer);
        if ($trimmed !== '') {
            // Decoded after normalizing, so `&nbsp;` and `&#32;` keep the
            // whitespace they asked for.
            $children[] = $this->phpString($this->decodeEntities($trimmed));
        }
        $textBuffer = '';
    }

    private function consumeClosingTag(string $expected): void
    {
        $this->expect('<');
        $this->expect('/');
        $name = $this->readTagName();
        if ($name !== $expected) {
            throw $this->error("Mismatched closing tag: expected </$expected>, got </$name>");
        }
        $this->skipWhitespace();
        $this->expect('>');
    }

    private function consumeFragmentClose(): void
    {
        if ($this->peek() !== '<' || ($this->source[$this->pos + 1] ?? '') !== '/') {
            throw $this->error('Expected fragment close `</>`');
        }
        $this->pos += 2; // consume `</`
        if ($this->peek() !== '>') {
            // A named tag where a fragment close was expected.
            $name = $this->readTagName();
            throw $this->error(
                $name !== ''
                    ? "Expected fragment close `</>`, got `</$name>`"
                    : 'Expected fragment close `</>`',
            );
        }
        $this->pos++; // consume `>`
    }

    private function readTagName(): string
    {
        $start = $this->pos;
        while ($this->pos < \strlen($this->source)) {
            $c = $this->source[$this->pos];
            if (\ctype_alnum($c) || $c === '_' || $c === '-') {
                $this->pos++;
            } else {
                break;
            }
        }
        return \substr($this->source, $start, $this->pos - $start);
    }

    private function readAttrName(): string
    {
        $start = $this->pos;
        while ($this->pos < \strlen($this->source)) {
            $c = $this->source[$this->pos];
            if (\ctype_alnum($c) || $c === '_' || $c === '-') {
                $this->pos++;
            } else {
                break;
            }
        }
        return \substr($this->source, $start, $this->pos - $start);
    }

    private function readStringLiteral(): string
    {
        $quote = $this->source[$this->pos];
        $openPos = $this->pos;
        $this->pos++; // consume opening quote
        $value = '';
        while ($this->pos < \strlen($this->source)) {
            $c = $this->source[$this->pos];
            if ($c === '\\' && isset($this->source[$this->pos + 1])) {
                // Pass the escape sequence through unchanged so authors can
                // write `\"` inside a double-quoted attribute.
                $next = $this->source[$this->pos + 1];
                if ($next === $quote || $next === '\\') {
                    $value .= $next;
                } else {
                    $value .= $c . $next;
                }
                $this->pos += 2;
                continue;
            }
            if ($c === $quote) {
                $this->pos++; // consume closing quote
                return $value;
            }
            $value .= $c;
            $this->pos++;
        }
        // Reached EOF without finding the closing quote — restore position to
        // the opening quote so the error caret points at the start of the
        // unterminated literal.
        $this->pos = $openPos;
        throw $this->error('Unterminated attribute string literal');
    }

    /**
     * Reads a {...} block, returning the inner PHP expression source (verbatim).
     * Tracks brace depth, ignoring braces inside PHP strings.
     */
    private function readBraceExpression(): string
    {
        $this->expect('{');
        $start = $this->pos;
        $depth = 1;

        while ($this->pos < \strlen($this->source)) {
            $c = $this->source[$this->pos];

            if ($c === "'" || $c === '"') {
                $this->skipPhpString($c);
                continue;
            }
            $next = $this->source[$this->pos + 1] ?? '';
            if (($c === '/' && $next === '/') || ($c === '#' && $next !== '[')) {
                $this->skipUntil("\n");
                continue;
            }
            if ($c === '/' && $next === '*') {
                $this->pos += 2;
                $end = \strpos($this->source, '*/', $this->pos);
                $this->pos = $end === false ? \strlen($this->source) : $end + 2;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $expr = \substr($this->source, $start, $this->pos - $start);
                    $this->pos++; // consume closing '}'
                    return $this->compileNestedExpression(\trim($expr));
                }
            }
            $this->pos++;
        }
        throw $this->error('Unclosed brace expression');
    }

    private function skipPhpString(string $quote): void
    {
        $openPos = $this->pos;
        $this->pos++; // opening quote
        while ($this->pos < \strlen($this->source)) {
            $c = $this->source[$this->pos];
            if ($c === '\\') {
                if (!isset($this->source[$this->pos + 1])) {
                    $this->pos = $openPos;
                    throw $this->error('Unterminated PHP string literal in brace expression');
                }
                $this->pos += 2;
                continue;
            }
            if ($c === $quote) {
                $this->pos++;
                return;
            }
            $this->pos++;
        }
        $this->pos = $openPos;
        throw $this->error('Unterminated PHP string literal in brace expression');
    }

    private function skipUntil(string $needle): void
    {
        $end = \strpos($this->source, $needle, $this->pos);
        $this->pos = $end === false ? \strlen($this->source) : $end + \strlen($needle);
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < \strlen($this->source) && \ctype_space($this->source[$this->pos])) {
            $this->pos++;
        }
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw $this->error("Expected '$char', got '" . ($this->peek() ?? 'EOF') . "'");
        }
        $this->pos++;
    }

    private function peek(): ?string
    {
        return $this->source[$this->pos] ?? null;
    }

    /**
     * JSX-style: entities in PSX source mean the character they name. The
     * renderer escapes the result, so `&amp;` must not reach it encoded.
     */
    private function decodeEntities(string $text): string
    {
        return \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function phpString(string $value): string
    {
        return "'" . \addcslashes($value, "'\\") . "'";
    }

    private function normalizeText(string $text): string
    {
        // JSX-style: if the text contains a newline AND consists only of whitespace,
        // discard it entirely (it's just indentation between tags). Otherwise collapse
        // whitespace runs to single spaces but preserve leading/trailing spaces that
        // aren't pure indentation.
        if (\str_contains($text, "\n")) {
            // Multi-line: drop pure-whitespace runs at start/end (they're indentation).
            $text = \preg_replace('/^[ \t]*\n[\s]*/', '', $text) ?? $text;
            $text = \preg_replace('/[\s]*\n[ \t]*$/', '', $text) ?? $text;
            // Collapse internal whitespace.
            $text = \preg_replace('/\s+/', ' ', $text) ?? $text;
            return $text;
        }
        // Single-line: only collapse multi-space runs, preserve leading/trailing single space.
        $text = \preg_replace('/\s+/', ' ', $text) ?? $text;
        return $text;
    }

    private function error(string $message): \RuntimeException
    {
        $upTo = \substr($this->source, 0, $this->pos);
        $line = \substr_count($upTo, "\n") + 1;
        $lineStart = \strrpos($upTo, "\n");
        $column = $this->pos - ($lineStart === false ? 0 : $lineStart + 1) + 1;

        $sourceLines = \explode("\n", $this->source);
        $offendingLine = $sourceLines[$line - 1] ?? '';
        $caret = \str_repeat(' ', \max(0, $column - 1)) . '^';

        $context = '    ' . $offendingLine . "\n    " . $caret;
        return new \RuntimeException("PSX parse error at line $line, column $column: $message\n$context");
    }

    /**
     * Generates a createElement(...) call for HTML tags, or renderPsxComponent(...)
     * for component tags (PascalCase).
     *
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children Each child is a PHP expression. Null = self-closing.
     * @param int $trailingNewlines Newlines between last child and closing tag (for line preservation).
     * @param bool $loneExpression Whether the only child is a `{...}` expression.
     */
    private function emitElement(string $tagName, array $attrs, ?array $children, int $trailingNewlines, bool $loneExpression): string
    {
        $isComponent = \ctype_upper($tagName[0]);

        if ($isComponent) {
            return $this->emitComponent($tagName, $attrs, $children, $trailingNewlines);
        }

        if ($tagName === 'defer' && $this->isDeferCall($attrs, $children)) {
            return $this->emitDefer($attrs);
        }

        return $this->emitHtmlElement($tagName, $attrs, $children, $trailingNewlines, $loneExpression);
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function emitHtmlElement(string $tagName, array $attrs, ?array $children, int $trailingNewlines, bool $loneExpression): string
    {
        $entries = [];
        foreach ($attrs as $attr) {
            // H::label() takes `for` as a parameter name and stores it as the
            // JSX prop; the renderer maps htmlFor back to the `for` attribute.
            $name = $tagName === 'label' && $attr['name'] === 'for' ? 'htmlFor' : $attr['name'];
            $value = $attr['value'];
            $entries[] = "'$name' => $value";
        }
        if ($children !== null && $children !== []) {
            $entries[] = "'children' => " . $this->emitElementChildren($children, $trailingNewlines, $loneExpression);
        }
        return self::CREATE_ELEMENT . "('$tagName', [" . \implode(', ', $entries) . '])';
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function isDeferCall(array $attrs, ?array $children): bool
    {
        if ($children !== null && $children !== []) {
            return false;
        }
        $names = \array_column($attrs, 'name');
        return \in_array('name', $names, true) && \array_diff($names, self::DEFER_PARAMS) === [];
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     */
    private function emitDefer(array $attrs): string
    {
        $args = \array_map(
            static fn(array $attr): string => $attr['name'] . ': ' . $attr['value'],
            $attrs,
        );
        return '\\Polidog\\UsePhp\\Html\\H::defer(' . \implode(', ', $args) . ')';
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function emitComponent(string $tagName, array $attrs, ?array $children, int $trailingNewlines): string
    {
        $fqcn = $this->namespaceContext !== null
            ? $this->namespaceContext->resolve($tagName)
            : $tagName;

        $propsEntries = [];
        foreach ($attrs as $attr) {
            $name = $attr['name'];
            $value = $attr['value'];
            $propsEntries[] = "'$name' => $value";
        }
        if ($children !== null && $children !== []) {
            $propsEntries[] = "'children' => " . $this->emitChildrenArg($children, $trailingNewlines);
        }
        $props = '[' . \implode(', ', $propsEntries) . ']';

        $escapedFqcn = \str_replace('\\', '\\\\', $fqcn);

        return "\\Polidog\\UsePhp\\Runtime\\RenderContext::getApp()->renderPsxComponent('$escapedFqcn', $props)";
    }

    /**
     * @param list<string> $children
     */
    private function emitFragment(array $children, int $trailingNewlines, bool $loneExpression): string
    {
        // An Element with type='Fragment' is unwrapped by the Renderer
        // (children are emitted directly without a surrounding tag).
        $args = $children === []
            ? '[]'
            : "['children' => " . $this->emitElementChildren($children, $trailingNewlines, $loneExpression) . ']';
        return self::CREATE_ELEMENT . "('Fragment', $args)";
    }

    /**
     * Children of a built-in tag are always an array, except a lone `{...}`
     * expression: it may itself return the list of children (`{array_map(...)}`),
     * and createElement() does not flatten a nested array. Passing it directly
     * means the same thing for every other value, since createElement() wraps
     * non-arrays itself.
     *
     * @param list<string> $children
     */
    private function emitElementChildren(array $children, int $trailingNewlines, bool $loneExpression): string
    {
        if ($loneExpression) {
            return $children[0];
        }
        return '[' . \implode(', ', $children) . \str_repeat("\n", $trailingNewlines) . ']';
    }

    /**
     * Children prop of a component tag: a single child is passed as itself, so
     * the component receives an Element or string rather than a one-item array.
     *
     * @param list<string> $children
     */
    private function emitChildrenArg(array $children, int $trailingNewlines): string
    {
        // The newline prefix on the child (if any) keeps line preservation
        // working; the block-level padding in Compiler tops up trailing
        // newlines for the closing tag's line.
        if (\count($children) === 1) {
            return $children[0];
        }
        $body = \implode(', ', $children) . \str_repeat("\n", $trailingNewlines);
        return '[' . $body . ']';
    }

    /**
     * Recursively compile a brace-expression body so that PSX tags appearing
     * inside `{...}` (e.g. inside array_map) are also transformed. The outer
     * file's NamespaceContext is propagated so component tag resolution and
     * reference tracking continue to work for nested PSX.
     */
    private function compileNestedExpression(string $expr): string
    {
        if ($expr !== '' && \str_contains($expr, '<')) {
            $expr = new Compiler()->compileExpression($expr, $this->namespaceContext);
        }
        return $this->endsWithLineComment($expr) ? $expr . "\n" : $expr;
    }

    /**
     * True when the expression holds nothing but whitespace and comments.
     */
    private function isBlankExpression(string $expr): bool
    {
        foreach (\array_slice(\token_get_all('<?php ' . $expr), 1) as $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The closing `}` was on the line after a `//` comment. The surrounding
     * call is emitted right after the expression, so that newline has to be
     * restored or the comment would swallow the rest of the call.
     */
    private function endsWithLineComment(string $expr): bool
    {
        $tokens = \token_get_all('<?php ' . $expr);
        $last = \end($tokens);
        if (\is_array($last) && $last[0] === \T_WHITESPACE) {
            $last = \prev($tokens);
        }
        return \is_array($last) && $last[0] === \T_COMMENT && !\str_starts_with($last[1], '/*');
    }
}
