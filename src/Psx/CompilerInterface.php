<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Compiles PSX (TSX-like syntax) source into plain PHP.
 *
 * The single concrete implementation is {@see Compiler}. The interface exists
 * so callers (CompileCommand, build pipelines, tests) can depend on the
 * contract rather than the concrete class.
 */
interface CompilerInterface
{
    /**
     * Compile PSX source to PHP.
     *
     * @param NamespaceContext|null $context Optional pre-existing namespace +
     *        use map. Pass when recursively compiling a brace expression
     *        embedded in an outer .psx file so component tag resolution and
     *        reference tracking continue to work for nested PSX.
     */
    public function compile(string $source, ?NamespaceContext $context = null): string;

    /**
     * FQCNs of component tags resolved during the most recent compile() call.
     *
     * @return list<string>
     */
    public function getLastReferences(): array;
}
