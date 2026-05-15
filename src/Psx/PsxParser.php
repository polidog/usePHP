<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Parses one PSX expression from a source string starting at `<`.
 *
 * Produces equivalent PHP source code (calls to H::xxx() or renderPsxComponent()).
 */
final class PsxParser implements PsxParserInterface
{
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
            [$children, $trailingNewlines] = $this->parseChildren(closingTag: '');
            $this->consumeFragmentClose();
            return [
                'php' => $this->emitFragment($children, $trailingNewlines),
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
                'php' => $this->emitElement($tagName, $attrs, null, 0),
                'end' => $this->pos,
            ];
        }

        $this->expect('>');
        [$children, $trailingNewlines] = $this->parseChildren(closingTag: $tagName);
        $this->consumeClosingTag($tagName);

        return [
            'php' => $this->emitElement($tagName, $attrs, $children, $trailingNewlines),
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
                    'value' => "'" . \addcslashes($value, "'\\") . "'",
                    'isExpr' => false,
                ];
            } elseif ($c === '{') {
                $expr = $this->readBraceExpression();
                $attrs[] = ['name' => $name, 'value' => $expr, 'isExpr' => true];
            } else {
                throw $this->error("Expected attribute value after '=' for '$name'");
            }
        }
    }

    /**
     * @return array{0: list<string>, 1: int} children PHP expressions, plus
     *         the number of newlines between the last child and the closing
     *         tag (so the emitter can place `])` on the right line).
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

        while ($this->pos < \strlen($this->source)) {
            $c = $this->peek();

            if ($c === '<') {
                $next = $this->source[$this->pos + 1] ?? '';
                if ($next === '/') {
                    $this->flushTextBuffer($textBuffer, $children);
                    $trailing = \substr_count(\substr($this->source, $lastChildEnd, $this->pos - $lastChildEnd), "\n");
                    return [$children, $trailing];
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
                $children[] = $this->prefixForNewlinesBetween($lastChildEnd, $childStart) . $expr;
                $lastChildEnd = $this->pos;
                continue;
            }

            $textBuffer .= $c;
            $this->pos++;
        }

        $this->flushTextBuffer($textBuffer, $children);
        return [$children, 0];
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
            $children[] = "'" . \addcslashes($trimmed, "'\\") . "'";
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
            if ($c === '/' && ($this->source[$this->pos + 1] ?? '') === '/') {
                $this->skipUntil("\n");
                continue;
            }
            if ($c === '/' && ($this->source[$this->pos + 1] ?? '') === '*') {
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
     * Generates an H::xxx(...) call for HTML tags, or renderPsxComponent(...)
     * for component tags (PascalCase).
     *
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children Each child is a PHP expression. Null = self-closing.
     * @param int $trailingNewlines Newlines between last child and closing tag (for line preservation).
     */
    private function emitElement(string $tagName, array $attrs, ?array $children, int $trailingNewlines): string
    {
        $isComponent = \ctype_upper($tagName[0]);

        if ($isComponent) {
            return $this->emitComponent($tagName, $attrs, $children, $trailingNewlines);
        }

        return $this->emitHtmlElement($tagName, $attrs, $children, $trailingNewlines);
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function emitHtmlElement(string $tagName, array $attrs, ?array $children, int $trailingNewlines): string
    {
        if ($this->requiresCallStaticDispatch($tagName, $attrs)) {
            return $this->emitCallStaticElement($tagName, $attrs, $children, $trailingNewlines);
        }

        $namedArgs = [];
        foreach ($attrs as $attr) {
            $name = $this->normalizeAttrName($attr['name']);
            $value = $attr['value'];
            $namedArgs[] = "$name: $value";
        }

        if ($children !== null && $children !== []) {
            $namedArgs[] = 'children: ' . $this->emitChildrenArg($children, $trailingNewlines);
        }

        return 'H::' . $tagName . '(' . \implode(', ', $namedArgs) . ')';
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     */
    private function requiresCallStaticDispatch(string $tagName, array $attrs): bool
    {
        $known = HMethodRegistry::getParams($tagName);
        if ($known === null) {
            // Unknown tag (e.g., custom element). __callStatic is the only path.
            return true;
        }
        foreach ($attrs as $attr) {
            if (!\in_array($this->normalizeAttrName($attr['name']), $known, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function emitCallStaticElement(string $tagName, array $attrs, ?array $children, int $trailingNewlines): string
    {
        $entries = [];
        foreach ($attrs as $attr) {
            $name = $attr['name']; // raw HTML attribute name (e.g. 'data-id')
            $value = $attr['value'];
            $entries[] = "'$name' => $value";
        }
        if ($children !== null && $children !== []) {
            $entries[] = "'children' => " . $this->emitChildrenArg($children, $trailingNewlines);
        }
        $args = '[' . \implode(', ', $entries) . ']';
        return "H::__callStatic('$tagName', $args)";
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

        // Pull off the magic `defer` and `fallback` attributes if present so
        // they don't end up in the props bag.
        $deferNameExpr = null;
        $fallbackExpr = null;
        $remainingAttrs = [];
        foreach ($attrs as $attr) {
            if ($attr['name'] === 'defer') {
                if ($attr['isExpr'] && $attr['value'] === 'true') {
                    throw $this->error(
                        "<$tagName defer /> requires a registered name. "
                        . 'Use <' . $tagName . ' defer="name" /> instead.',
                    );
                }
                // String literal form (`defer="user-header"`): the value
                // arrives here already quoted as PHP source, e.g. `'user-header'`.
                // Validate the URL-safe shape at compile time so typos surface
                // during build, not as runtime exceptions in production.
                if (!$attr['isExpr']) {
                    $literalSource = $attr['value'] ?? '';
                    if (\preg_match("/^'([A-Za-z0-9_-]+)'$/", $literalSource) !== 1) {
                        $literal = \substr($literalSource, 1, -1);
                        throw $this->error(
                            "<$tagName defer=\"$literal\"> name must match `[A-Za-z0-9_-]+`. "
                            . 'Names appear as URL path segments and are validated by the runtime.',
                        );
                    }
                }
                // Brace expressions (`defer={$dynamicName}`) cannot be
                // validated here — the runtime check in Renderer::renderDeferred
                // and the endpoint match in UsePHP::doHandleDeferred take over.
                $deferNameExpr = $attr['value'];
                continue;
            }
            if ($attr['name'] === 'fallback') {
                $fallbackExpr = $attr['value'];
                continue;
            }
            $remainingAttrs[] = $attr;
        }

        $propsEntries = [];
        foreach ($remainingAttrs as $attr) {
            $name = $attr['name'];
            $value = $attr['value'];
            $propsEntries[] = "'$name' => $value";
        }
        if ($children !== null && $children !== []) {
            $propsEntries[] = "'children' => " . $this->emitChildrenArg($children, $trailingNewlines);
        }
        $props = '[' . \implode(', ', $propsEntries) . ']';

        if ($deferNameExpr !== null) {
            if ($children !== null && $children !== []) {
                throw $this->error(
                    "<$tagName defer> cannot have children. Move them into the fallback element.",
                );
            }
            $fallbackArg = $fallbackExpr ?? 'null';
            return "\\Polidog\\UsePhp\\Html\\H::defer($deferNameExpr, $props, $fallbackArg)";
        }

        $escapedFqcn = \str_replace('\\', '\\\\', $fqcn);

        return "\\Polidog\\UsePhp\\Runtime\\RenderContext::getApp()->renderPsxComponent('$escapedFqcn', $props)";
    }

    /**
     * @param list<string> $children
     */
    private function emitFragment(array $children, int $trailingNewlines): string
    {
        // H::Fragment yields an Element with type='Fragment', which the Renderer
        // unwraps (children are emitted directly without a surrounding tag).
        $body = \implode(', ', $children) . \str_repeat("\n", $trailingNewlines);
        return 'H::Fragment([' . $body . '])';
    }

    /**
     * @param list<string> $children
     */
    private function emitChildrenArg(array $children, int $trailingNewlines): string
    {
        // Single child: pass directly so expressions returning arrays
        // (e.g. {array_map(...)}) are accepted as-is by createElement's
        // is_array branch instead of being wrapped in an outer [...].
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
        if ($expr === '' || !\str_contains($expr, '<')) {
            return $expr;
        }
        $wrapped = '<?php ' . $expr;
        $compiled = new Compiler()->compile($wrapped, $this->namespaceContext);
        return \substr($compiled, \strlen('<?php '));
    }

    private function normalizeAttrName(string $name): string
    {
        // Pass-through. Routing of data-*/aria-* attributes happens earlier in
        // requiresCallStaticDispatch() / emitCallStaticElement().
        return $name;
    }
}
