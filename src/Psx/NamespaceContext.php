<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Tracks the current PHP namespace and use map for a .psx file so PSX
 * component tags (PascalCase) can be resolved to fully qualified class names.
 *
 * Resolution order for `<Counter />`:
 * 1. If `Counter` is a key in the use map, return the mapped FQCN.
 * 2. If a current namespace exists, return `{namespace}\Counter`.
 * 3. Otherwise return the bare name (best-effort, may fail at runtime).
 */
final class NamespaceContext
{
    public string $namespace = '';

    /** @var array<string, string> short name => FQCN */
    private array $useMap = [];

    /** @var list<string> FQCNs declared with `// @psx-runtime FQCN` */
    private array $runtimeDeclarations = [];

    /** @var list<string> FQCNs returned by resolve() during compilation */
    private array $resolvedReferences = [];

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    public static function parse(array $tokens): self
    {
        $ctx = new self();
        $count = \count($tokens);
        $i = 0;

        while ($i < $count) {
            $tok = $tokens[$i];

            if (\is_array($tok) && $tok[0] === \T_NAMESPACE) {
                $i = $ctx->consumeNamespace($tokens, $i + 1);
                continue;
            }

            if (\is_array($tok) && $tok[0] === \T_USE) {
                if ($ctx->isTopLevelUse($tokens, $i)) {
                    $i = $ctx->consumeUse($tokens, $i + 1);
                    continue;
                }
            }

            // Recognise `// @psx-runtime FQCN` and `# @psx-runtime FQCN` comments
            // and `/** @psx-runtime FQCN */` doc comments.
            if (\is_array($tok) && ($tok[0] === \T_COMMENT || $tok[0] === \T_DOC_COMMENT)) {
                if (\preg_match_all('/@psx-runtime\s+([A-Za-z_][A-Za-z0-9_\\\\]*)/', $tok[1], $matches) > 0) {
                    foreach ($matches[1] as $fqcn) {
                        $ctx->runtimeDeclarations[] = \ltrim($fqcn, '\\');
                    }
                }
            }

            $i++;
        }

        return $ctx;
    }

    /**
     * @return list<string>
     */
    public function getRuntimeDeclarations(): array
    {
        return $this->runtimeDeclarations;
    }

    public function addUse(string $fqcn, ?string $alias = null): void
    {
        $alias ??= self::shortName($fqcn);
        $this->useMap[$alias] = \ltrim($fqcn, '\\');
    }

    /**
     * Resolve a PSX component tag short name to a fully qualified class name.
     */
    public function resolve(string $shortName): string
    {
        if (isset($this->useMap[$shortName])) {
            $fqcn = $this->useMap[$shortName];
        } elseif ($this->namespace !== '') {
            $fqcn = $this->namespace . '\\' . $shortName;
        } else {
            $fqcn = $shortName;
        }
        $this->resolvedReferences[] = $fqcn;
        return $fqcn;
    }

    /**
     * @return list<string>
     */
    public function getResolvedReferences(): array
    {
        return $this->resolvedReferences;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function consumeNamespace(array $tokens, int $i): int
    {
        $name = '';
        $count = \count($tokens);
        while ($i < $count) {
            $tok = $tokens[$i];
            if (\is_array($tok)) {
                if ($tok[0] === \T_STRING || $tok[0] === \T_NS_SEPARATOR || $tok[0] === \T_NAME_QUALIFIED || $tok[0] === \T_NAME_FULLY_QUALIFIED) {
                    $name .= $tok[1];
                    $i++;
                    continue;
                }
                if (\in_array($tok[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    $i++;
                    continue;
                }
                break;
            }
            // Single-char: ; or { ends the namespace declaration.
            if ($tok === ';' || $tok === '{') {
                $i++;
                break;
            }
            $i++;
        }
        $this->namespace = \trim($name, '\\');
        return $i;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function consumeUse(array $tokens, int $i): int
    {
        $name = '';
        $alias = null;
        $count = \count($tokens);
        $expectingAlias = false;

        while ($i < $count) {
            $tok = $tokens[$i];

            if (\is_array($tok)) {
                if ($tok[0] === \T_AS) {
                    $expectingAlias = true;
                    $i++;
                    continue;
                }
                if ($tok[0] === \T_FUNCTION || $tok[0] === \T_CONST) {
                    // `use function`/`use const` — not a class import. Skip the rest.
                    return $this->skipToSemicolon($tokens, $i);
                }
                if ($tok[0] === \T_STRING || $tok[0] === \T_NS_SEPARATOR || $tok[0] === \T_NAME_QUALIFIED || $tok[0] === \T_NAME_FULLY_QUALIFIED) {
                    if ($expectingAlias) {
                        $alias = $tok[1];
                    } else {
                        $name .= $tok[1];
                    }
                    $i++;
                    continue;
                }
                if (\in_array($tok[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    $i++;
                    continue;
                }
            } else {
                if ($tok === ',') {
                    if ($name !== '') {
                        $this->addUse($name, $alias);
                    }
                    $name = '';
                    $alias = null;
                    $expectingAlias = false;
                    $i++;
                    continue;
                }
                if ($tok === ';') {
                    if ($name !== '') {
                        $this->addUse($name, $alias);
                    }
                    return $i + 1;
                }
                if ($tok === '{' || $tok === '}') {
                    // Grouped use { ... } — for Phase 1 minimal we skip grouped form.
                    return $this->skipToSemicolon($tokens, $i);
                }
            }
            $i++;
        }
        return $i;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function skipToSemicolon(array $tokens, int $i): int
    {
        $count = \count($tokens);
        while ($i < $count) {
            $tok = $tokens[$i];
            if ($tok === ';') {
                return $i + 1;
            }
            $i++;
        }
        return $i;
    }

    /**
     * Detect whether a T_USE token is a top-level statement (vs inside a class).
     *
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function isTopLevelUse(array $tokens, int $i): bool
    {
        // Walk back to the previous non-whitespace token. If it's `;`, `{` (after namespace),
        // T_OPEN_TAG, or T_NAMESPACE end, we are likely top-level. If it's `}` of a class
        // body or after T_CLASS, we are inside a class — but use-trait is what we'd skip.
        // Heuristic: count braces from start up to $i. If brace depth is 0, top-level.
        $depth = 0;
        for ($j = 0; $j < $i; $j++) {
            $t = $tokens[$j];
            if (\is_string($t)) {
                if ($t === '{') {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                }
            } elseif ($t[0] === \T_CURLY_OPEN) {
                $depth++;
            }
        }
        return $depth === 0;
    }

    private static function shortName(string $fqcn): string
    {
        $parts = \explode('\\', \trim($fqcn, '\\'));
        return \end($parts);
    }
}
