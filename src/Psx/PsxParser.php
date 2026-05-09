<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Parses one PSX expression from a source string starting at `<`.
 *
 * Produces equivalent PHP source code (calls to H::xxx() or renderPsxComponent()).
 */
final class PsxParser
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
            $children = $this->parseChildren(closingTag: '');
            $this->consumeFragmentClose();
            return ['php' => $this->emitFragment($children), 'end' => $this->pos];
        }

        $attrs = $this->parseAttributes();
        $this->skipWhitespace();

        if ($this->peek() === '/') {
            // Self-closing tag.
            $this->pos++; // consume '/'
            $this->expect('>');
            return [
                'php' => $this->emitElement($tagName, $attrs, null),
                'end' => $this->pos,
            ];
        }

        $this->expect('>');
        $children = $this->parseChildren(closingTag: $tagName);
        $this->consumeClosingTag($tagName);

        return [
            'php' => $this->emitElement($tagName, $attrs, $children),
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
     * @return list<string> Each entry is a PHP expression representing a child.
     */
    private function parseChildren(string $closingTag): array
    {
        $children = [];
        $textBuffer = '';

        while ($this->pos < \strlen($this->source)) {
            $c = $this->peek();

            if ($c === '<') {
                $next = $this->source[$this->pos + 1] ?? '';
                if ($next === '/') {
                    $this->flushTextBuffer($textBuffer, $children);
                    return $children;
                }
                $this->flushTextBuffer($textBuffer, $children);
                $sub = (new self($this->source, $this->pos, $this->namespaceContext))->parseElement();
                $children[] = $sub['php'];
                $this->pos = $sub['end'];
                continue;
            }

            if ($c === '{') {
                $this->flushTextBuffer($textBuffer, $children);
                $children[] = $this->readBraceExpression();
                continue;
            }

            $textBuffer .= $c;
            $this->pos++;
        }

        $this->flushTextBuffer($textBuffer, $children);
        return $children;
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
        $this->expect('<');
        $this->expect('/');
        $this->expect('>');
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
        $this->pos++; // consume opening quote
        $start = $this->pos;
        while ($this->pos < \strlen($this->source) && $this->source[$this->pos] !== $quote) {
            // No escape handling for Phase 0.
            $this->pos++;
        }
        $value = \substr($this->source, $start, $this->pos - $start);
        $this->pos++; // consume closing quote
        return $value;
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
        $this->pos++; // opening quote
        while ($this->pos < \strlen($this->source)) {
            $c = $this->source[$this->pos];
            if ($c === '\\') {
                $this->pos += 2;
                continue;
            }
            if ($c === $quote) {
                $this->pos++;
                return;
            }
            $this->pos++;
        }
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
        $line = \substr_count(\substr($this->source, 0, $this->pos), "\n") + 1;
        return new \RuntimeException("PSX parse error at line $line: $message");
    }

    /**
     * Generates an H::xxx(...) call for HTML tags, or renderPsxComponent(...)
     * for component tags (PascalCase).
     *
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children Each child is a PHP expression. Null = self-closing.
     */
    private function emitElement(string $tagName, array $attrs, ?array $children): string
    {
        $isComponent = \ctype_upper($tagName[0]);

        if ($isComponent) {
            return $this->emitComponent($tagName, $attrs, $children);
        }

        return $this->emitHtmlElement($tagName, $attrs, $children);
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function emitHtmlElement(string $tagName, array $attrs, ?array $children): string
    {
        if ($this->requiresCallStaticDispatch($tagName, $attrs)) {
            return $this->emitCallStaticElement($tagName, $attrs, $children);
        }

        $namedArgs = [];
        foreach ($attrs as $attr) {
            $name = $this->normalizeAttrName($attr['name']);
            $value = $attr['value'];
            $namedArgs[] = "$name: $value";
        }

        if ($children !== null && $children !== []) {
            $namedArgs[] = 'children: ' . $this->emitChildrenArg($children);
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
    private function emitCallStaticElement(string $tagName, array $attrs, ?array $children): string
    {
        $entries = [];
        foreach ($attrs as $attr) {
            $name = $attr['name']; // raw HTML attribute name (e.g. 'data-id')
            $value = $attr['value'];
            $entries[] = "'$name' => $value";
        }
        if ($children !== null && $children !== []) {
            $entries[] = "'children' => " . $this->emitChildrenArg($children);
        }
        $args = '[' . \implode(', ', $entries) . ']';
        return "H::__callStatic('$tagName', $args)";
    }

    /**
     * @param list<array{name: string, value: string|null, isExpr: bool}> $attrs
     * @param list<string>|null $children
     */
    private function emitComponent(string $tagName, array $attrs, ?array $children): string
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
            $propsEntries[] = "'children' => " . $this->emitChildrenArg($children);
        }
        $props = '[' . \implode(', ', $propsEntries) . ']';
        $escapedFqcn = \str_replace('\\', '\\\\', $fqcn);
        return "\\Polidog\\UsePhp\\Runtime\\RenderContext::getApp()->renderPsxComponent('$escapedFqcn', $props)";
    }

    /**
     * @param list<string> $children
     */
    private function emitFragment(array $children): string
    {
        return '[' . \implode(', ', $children) . ']';
    }

    /**
     * @param list<string> $children
     */
    private function emitChildrenArg(array $children): string
    {
        if (\count($children) === 1) {
            return $children[0];
        }
        return '[' . \implode(', ', $children) . ']';
    }

    /**
     * Recursively compile a brace-expression body so that PSX tags appearing
     * inside `{...}` (e.g. inside array_map) are also transformed.
     */
    private function compileNestedExpression(string $expr): string
    {
        if ($expr === '' || !\str_contains($expr, '<')) {
            return $expr;
        }
        $wrapped = '<?php ' . $expr;
        $compiled = (new Compiler())->compile($wrapped);
        return \substr($compiled, \strlen('<?php '));
    }

    private function normalizeAttrName(string $name): string
    {
        // Phase 0: pass-through. Phase 1 will add data-*/aria-* dispatch routing.
        return $name;
    }
}
