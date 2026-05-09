<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Implements `usephp compile [options] [paths...]`.
 *
 * - Walks each given path (file or directory) for .psx files.
 * - Compiles each into a sibling .psx.php.
 * - Aggregates a manifest (FQCN -> path) at the configured location.
 *
 * Options:
 *   --check    Don't write files; exit non-zero if anything is out of date.
 *   --clean    Remove all generated .psx.php and the manifest.
 *   --manifest=PATH   Manifest output path (default: psx-manifest.php in CWD).
 */
final class CompileCommand
{
    /**
     * @param list<string> $argv Argument list (after `compile` subcommand).
     */
    public function run(array $argv, string $cwd): int
    {
        [$flags, $paths] = $this->splitArgs($argv);

        $manifestPath = $flags['manifest'] ?? $cwd . '/psx-manifest.php';
        $check = isset($flags['check']);
        $clean = isset($flags['clean']);

        if ($paths === []) {
            $paths = [$cwd . '/components'];
        }

        if ($clean) {
            return $this->doClean($paths, $manifestPath);
        }

        $sourceFiles = $this->collectPsxFiles($paths);
        if ($sourceFiles === []) {
            $this->println("\033[33mNo .psx files found in: " . \implode(', ', $paths) . "\033[0m");
            return 0;
        }

        // Pass 1 — collect FQCNs and @psx-runtime declarations from each file.
        $manifestEntries = [];
        $runtimeDeclarations = [];
        $sourceContents = [];

        foreach ($sourceFiles as $sourceFile) {
            $source = \file_get_contents($sourceFile);
            if ($source === false) {
                $this->println("\033[31mError: cannot read $sourceFile\033[0m");
                return 1;
            }
            $sourceContents[$sourceFile] = $source;

            $tokens = \token_get_all($source);
            $ctx = NamespaceContext::parse($tokens);
            $base = \pathinfo($sourceFile, \PATHINFO_FILENAME);
            $fqcn = $ctx->namespace !== '' ? $ctx->namespace . '\\' . $base : $base;

            if (isset($manifestEntries[$fqcn])) {
                $this->println("\033[31mError: duplicate component FQCN '$fqcn'\033[0m");
                $this->println('  defined in: ' . $manifestEntries[$fqcn]);
                $this->println('  also in:    ' . $sourceFile . '.php');
                return 1;
            }
            $manifestEntries[$fqcn] = $sourceFile . '.php';

            foreach ($ctx->getRuntimeDeclarations() as $rd) {
                $runtimeDeclarations[$rd] = true;
            }
        }

        // Pass 2 — compile each file and validate component references.
        $compiler = new Compiler();
        $stale = [];
        $knownFqcns = $manifestEntries + $runtimeDeclarations;

        foreach ($sourceFiles as $sourceFile) {
            $compiledPath = $sourceFile . '.php';
            $source = $sourceContents[$sourceFile];

            try {
                $compiled = $compiler->compile($source);
            } catch (\Throwable $e) {
                $this->println("\033[31m$sourceFile: " . $e->getMessage() . "\033[0m");
                return 1;
            }

            foreach ($compiler->getLastReferences() as $ref) {
                if (!isset($knownFqcns[$ref])) {
                    $this->println("\033[31m$sourceFile: unresolved component '$ref'\033[0m");
                    $this->println("  Add a 'use' statement, define {$this->shortName($ref)}.psx, or declare `// @psx-runtime $ref`.");
                    return 1;
                }
            }

            $existing = \file_exists($compiledPath) ? \file_get_contents($compiledPath) : null;
            if ($existing !== $compiled) {
                $stale[] = $sourceFile;
                if (!$check) {
                    \file_put_contents($compiledPath, $compiled);
                }
            }
        }

        $manifestSource = $this->buildManifestSource($manifestEntries);
        $existingManifest = \file_exists($manifestPath) ? \file_get_contents($manifestPath) : null;
        if ($existingManifest !== $manifestSource) {
            $stale[] = $manifestPath;
            if (!$check) {
                \file_put_contents($manifestPath, $manifestSource);
            }
        }

        if ($check && $stale !== []) {
            $this->println("\033[31mOut of date:\033[0m");
            foreach ($stale as $f) {
                $this->println("  $f");
            }
            return 1;
        }

        $count = \count($sourceFiles);
        $this->println("\033[32mCompiled $count .psx file" . ($count === 1 ? '' : 's') . "\033[0m");
        $this->println("Manifest: $manifestPath");
        return 0;
    }

    private function shortName(string $fqcn): string
    {
        $parts = \explode('\\', \trim($fqcn, '\\'));
        return \end($parts);
    }

    /**
     * @param array<string, string> $entries
     */
    private function buildManifestSource(array $entries): string
    {
        \ksort($entries);
        $lines = ["<?php\n", "// Auto-generated by `usephp compile`. Do not edit.\n", "return [\n"];
        foreach ($entries as $fqcn => $path) {
            $lines[] = '    ' . \var_export($fqcn, true) . ' => ' . \var_export($path, true) . ",\n";
        }
        $lines[] = "];\n";
        return \implode('', $lines);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function collectPsxFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (\is_file($path) && \str_ends_with($path, '.psx')) {
                $files[] = $path;
                continue;
            }
            if (!\is_dir($path)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iter as $file) {
                if ($file->isFile() && \str_ends_with($file->getPathname(), '.psx')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        \sort($files);
        return $files;
    }

    /**
     * @param list<string> $paths
     */
    private function doClean(array $paths, string $manifestPath): int
    {
        $removed = 0;
        foreach ($this->collectPsxFiles($paths) as $sourceFile) {
            $compiledPath = $sourceFile . '.php';
            if (\file_exists($compiledPath) && @\unlink($compiledPath)) {
                $removed++;
            }
        }
        if (\file_exists($manifestPath) && @\unlink($manifestPath)) {
            $removed++;
        }
        $this->println("\033[32mRemoved $removed file(s)\033[0m");
        return 0;
    }

    /**
     * @param list<string> $argv
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function splitArgs(array $argv): array
    {
        $flags = [];
        $paths = [];
        foreach ($argv as $arg) {
            if (\str_starts_with($arg, '--')) {
                $eq = \strpos($arg, '=');
                if ($eq !== false) {
                    $flags[\substr($arg, 2, $eq - 2)] = \substr($arg, $eq + 1);
                } else {
                    $flags[\substr($arg, 2)] = '1';
                }
                continue;
            }
            $paths[] = $arg;
        }
        return [$flags, $paths];
    }

    private function println(string $message): void
    {
        echo $message . "\n";
    }
}
