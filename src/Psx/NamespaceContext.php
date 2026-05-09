<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

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
     * Build a NamespaceContext from a (possibly PSX-laden) source string.
     *
     * The PSX regions don't parse as valid PHP so we use nikic's collecting
     * error handler — namespace/use declarations come before any PSX so the
     * partial AST has everything we need.
     */
    public static function fromSource(string $source): self
    {
        $ctx = new self();

        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $ast = $parser->parse($source, new Collecting());
        if ($ast !== null) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new class ($ctx) extends NodeVisitorAbstract {
                public function __construct(private readonly NamespaceContext $ctx) {}

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Stmt\Namespace_ && $node->name !== null) {
                        $this->ctx->namespace = $node->name->toString();
                    }
                    if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
                        foreach ($node->uses as $use) {
                            $alias = $use->alias?->toString() ?? $use->name->getLast();
                            $this->ctx->addUse($use->name->toString(), $alias);
                        }
                    }
                    return null;
                }
            });
            $traverser->traverse($ast);
        }

        // @psx-runtime annotations are extracted directly from the source
        // because nikic discards comments outside attached docblocks for our
        // purposes here.
        if (\preg_match_all(
            '/(?:\/\/|#)\s*@psx-runtime\s+([A-Za-z_][A-Za-z0-9_\\\\]*)|@psx-runtime\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*\*\//',
            $source,
            $matches
        ) > 0) {
            foreach ($matches[1] as $i => $line) {
                $fqcn = $line !== '' ? $line : ($matches[2][$i] ?? '');
                if ($fqcn !== '') {
                    $ctx->runtimeDeclarations[] = \ltrim($fqcn, '\\');
                }
            }
        }

        return $ctx;
    }

    /**
     * Legacy entry point kept for backward compatibility with the previous
     * token-array-based API. Internally rebuilds the source string from the
     * tokens and delegates to fromSource(). Most callers should switch to
     * fromSource() directly.
     *
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    public static function parse(array $tokens): self
    {
        $source = '';
        foreach ($tokens as $tok) {
            $source .= \is_array($tok) ? $tok[1] : $tok;
        }
        return self::fromSource($source);
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

    private static function shortName(string $fqcn): string
    {
        $parts = \explode('\\', \trim($fqcn, '\\'));
        return \end($parts);
    }
}
