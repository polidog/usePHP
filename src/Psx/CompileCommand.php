<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Implements `usephp compile [options] [paths...]`.
 *
 * - Walks each given path (file or directory) for .psx files.
 * - Compiles each into the cache directory, naming the file by the
 *   sha1 of the source's absolute path so source trees stay clean
 *   (no `.psx.php` files alongside `.psx`).
 * - Aggregates a manifest (FQCN -> compiled cache path) at
 *   `<cacheDir>/manifest.php`. Only PascalCase-named files are entered
 *   in the manifest; lowercase-named files (e.g., App Router's
 *   `page.psx`, `layout.psx`) are compiled but loaded directly by path.
 * - Skips recompiling a file when its source hash (mixed with
 *   {@see self::CACHE_VERSION}) matches the sidecar `*.meta` file and
 *   every previously resolved component reference still resolves.
 *
 * Options:
 *   --check          Don't write files; exit non-zero if anything is out of date.
 *   --clean          Remove the cache directory's contents.
 *   --watch          Re-run compile when any .psx file changes (Ctrl+C to stop).
 *   --cache=PATH     Cache directory (default: <cwd>/var/cache/psx).
 */
final class CompileCommand
{
    public const DEFAULT_CACHE_SUBDIR = 'var/cache/psx';
    public const MANIFEST_FILENAME = 'manifest.php';
    public const DEFERRED_MANIFEST_FILENAME = 'deferred-manifest.php';

    /**
     * Bumped whenever the compiler's output format changes in a way that
     * existing caches must be re-generated. Mixed into the source hash so a
     * version bump invalidates every cached entry without touching any .psx.
     */
    public const CACHE_VERSION = 1;

    /**
     * @param list<string> $argv Argument list (after `compile` subcommand).
     */
    public function run(array $argv, string $cwd): int
    {
        [$flags, $paths] = $this->splitArgs($argv);

        $cacheDir = $this->absPath($flags['cache'] ?? $cwd . '/' . self::DEFAULT_CACHE_SUBDIR);
        $manifestPath = $cacheDir . \DIRECTORY_SEPARATOR . self::MANIFEST_FILENAME;
        $check = isset($flags['check']);
        $clean = isset($flags['clean']);
        $watch = isset($flags['watch']);

        if ($paths === []) {
            $paths = [$cwd . '/components'];
        }

        if ($clean) {
            return $this->doClean($cacheDir);
        }

        if (!$this->ensureCacheDir($cacheDir)) {
            $this->println("\033[31mError: cannot create cache directory $cacheDir\033[0m");
            return 1;
        }

        if ($watch) {
            return $this->runWatch($paths, $cacheDir, $manifestPath);
        }

        return $this->doCompile($paths, $cacheDir, $manifestPath, $check);
    }

    /**
     * Compute the cache file path for a given .psx source file. Used by
     * both the compiler and any external runtime (e.g., AppRouter) so
     * the convention stays consistent.
     */
    public static function cachePathFor(string $cacheDir, string $sourcePath): string
    {
        $abs = \realpath($sourcePath);
        if ($abs === false) {
            // Source might not exist yet (e.g., a freshly created file
            // about to be compiled); fall back to a normalised abspath.
            $abs = $sourcePath;
        }
        return \rtrim($cacheDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . \sha1($abs)
            . '.php';
    }

    /**
     * Sidecar metadata file for a given source. Stores the source hash and the
     * list of component FQCNs the file referenced at compile time, so a
     * subsequent run can skip recompilation when the source is unchanged and
     * those references still resolve.
     */
    public static function metaPathFor(string $cacheDir, string $sourcePath): string
    {
        return self::cachePathFor($cacheDir, $sourcePath) . '.meta';
    }

    private function computeSourceHash(string $source): string
    {
        return \hash('sha256', self::CACHE_VERSION . "\0" . $source);
    }

    /**
     * @return array{hash: string, refs: list<string>}|null
     */
    private function readMeta(string $metaPath): ?array
    {
        if (!\is_file($metaPath)) {
            return null;
        }
        $content = \file_get_contents($metaPath);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            return null;
        }
        $hash = $data['hash'] ?? null;
        $refs = $data['refs'] ?? null;
        if (!\is_string($hash) || !\is_array($refs)) {
            return null;
        }
        foreach ($refs as $r) {
            if (!\is_string($r)) {
                return null;
            }
        }
        return ['hash' => $hash, 'refs' => \array_values($refs)];
    }

    /**
     * @param list<string> $refs
     * @param array<string, mixed> $knownFqcns
     */
    private function refsResolveAgainst(array $refs, array $knownFqcns): bool
    {
        foreach ($refs as $ref) {
            if (!isset($knownFqcns[$ref])) {
                return false;
            }
        }
        return true;
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
     * @param array<string, array{component: string, cacheControl: ?string}> $entries
     */
    private function buildDeferredManifestSource(array $entries): string
    {
        \ksort($entries);
        $lines = ["<?php\n", "// Auto-generated by `usephp compile`. Do not edit.\n", "return [\n"];
        foreach ($entries as $name => $entry) {
            $lines[] = '    ' . \var_export($name, true) . " => [\n";
            $lines[] = "        'component' => " . \var_export($entry['component'], true) . ",\n";
            $lines[] = "        'cacheControl' => " . \var_export($entry['cacheControl'], true) . ",\n";
            $lines[] = "    ],\n";
        }
        $lines[] = "];\n";
        return \implode('', $lines);
    }

    /**
     * Require each compiled .psx and harvest any `Defer` config attached to a
     * returned {@see \Polidog\UsePhp\Runtime\FunctionComponent}.
     *
     * Returns null if any file raises a duplicate-name conflict or other fatal
     * error so the caller can short-circuit with a non-zero exit code. Returns
     * `[]` when no deferred components are present.
     *
     * @param array<string, string> $manifestEntries FQCN => compiled file path
     * @return array<string, array{component: string, cacheControl: ?string}>|null
     */
    private function collectDeferredEntries(array $manifestEntries): ?array
    {
        /** @var array<string, array{component: string, cacheControl: ?string}> $entries */
        $entries = [];

        foreach ($manifestEntries as $fqcn => $compiledPath) {
            if (!\is_file($compiledPath) || !\is_readable($compiledPath)) {
                // Skipping silently here matches `--check` mode where the file
                // may not have been written. Render-time errors will surface
                // the missing file separately.
                continue;
            }

            try {
                $value = require $compiledPath;
            } catch (\Throwable $e) {
                $this->println(
                    "\033[31mError: failed to load compiled $compiledPath while scanning for "
                    . "deferred components: " . $e->getMessage() . "\033[0m",
                );
                return null;
            }

            if (!$value instanceof \Polidog\UsePhp\Runtime\FunctionComponent) {
                continue;
            }
            $defer = $value->defer;
            if ($defer === null) {
                continue;
            }

            if (isset($entries[$defer->name])) {
                $existing = $entries[$defer->name]['component'];
                $this->println(
                    "\033[31mError: duplicate deferred component name '{$defer->name}'\033[0m",
                );
                $this->println("  registered by: $existing");
                $this->println("  also by:       $fqcn");
                return null;
            }

            $entries[$defer->name] = [
                'component' => $fqcn,
                'cacheControl' => $defer->cacheControl,
            ];
        }

        return $entries;
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
                $files[] = $this->absPath($path);
                continue;
            }
            if (!\is_dir($path)) {
                continue;
            }
            // SKIP_DOTS only — do not pass FOLLOW_SYMLINKS so that a symlinked
            // .psx (or a symlinked dir) doesn't risk an infinite loop.
            $dirIter = new \RecursiveDirectoryIterator(
                $path,
                \RecursiveDirectoryIterator::SKIP_DOTS,
            );
            $iter = new \RecursiveIteratorIterator($dirIter);
            foreach ($iter as $file) {
                if (\is_link($file->getPathname())) {
                    continue;
                }
                if ($file->isFile() && \str_ends_with($file->getPathname(), '.psx')) {
                    $files[] = $this->absPath($file->getPathname());
                }
            }
        }
        \sort($files);
        return $files;
    }

    /**
     * Normalise a path to an absolute one. We avoid realpath() on the leaf
     * because the target file (e.g. cache file or new manifest) may not
     * exist yet; instead we resolve the directory and recombine.
     */
    private function absPath(string $path): string
    {
        $dir = \dirname($path);
        $base = \basename($path);
        $absDir = \realpath($dir);
        if ($absDir === false) {
            return $path;
        }
        return $absDir . \DIRECTORY_SEPARATOR . $base;
    }

    private function ensureCacheDir(string $cacheDir): bool
    {
        if (\is_dir($cacheDir)) {
            return true;
        }
        return \mkdir($cacheDir, 0o755, true) || \is_dir($cacheDir);
    }

    /**
     * Poll-based watch loop. Re-runs the compile pass whenever any .psx file's
     * mtime changes. Exits via SIGINT (Ctrl+C).
     *
     * @param list<string> $paths
     */
    private function runWatch(array $paths, string $cacheDir, string $manifestPath): int
    {
        $this->println("\033[36mWatching for .psx changes (Ctrl+C to stop)…\033[0m");

        $lastMtimes = [];
        $warnedUnreadable = [];
        // @phpstan-ignore-next-line while.alwaysTrue — watch loop runs until SIGINT
        while (true) {
            $files = $this->collectPsxFiles($paths);
            $changed = false;
            $currentMtimes = [];

            foreach ($files as $file) {
                $mtime = \filemtime($file);
                if ($mtime === false) {
                    if (!isset($warnedUnreadable[$file])) {
                        $this->println("\033[33mWarning: cannot stat $file (skipped)\033[0m");
                        $warnedUnreadable[$file] = true;
                    }
                    continue;
                }
                unset($warnedUnreadable[$file]);
                $currentMtimes[$file] = $mtime;
                if (!isset($lastMtimes[$file]) || $lastMtimes[$file] !== $mtime) {
                    $changed = true;
                }
            }

            if ($changed || \array_keys($lastMtimes) !== \array_keys($currentMtimes)) {
                $this->println("\033[33m" . \date('H:i:s') . " — recompiling…\033[0m");
                $exitCode = $this->doCompile($paths, $cacheDir, $manifestPath, false);
                if ($exitCode !== 0) {
                    \usleep(500_000);
                    continue;
                }
            }

            $lastMtimes = $currentMtimes;
            \usleep(500_000);
        }
    }

    /**
     * Single compile pass. Extracted from run() so --watch can invoke it.
     * Returns 0 on success, 1 on failure.
     *
     * @param list<string> $paths
     */
    private function doCompile(array $paths, string $cacheDir, string $manifestPath, bool $check): int
    {
        $sourceFiles = $this->collectPsxFiles($paths);
        if ($sourceFiles === []) {
            $this->println("\033[33mNo .psx files found in: " . \implode(', ', $paths) . "\033[0m");
            return 0;
        }

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

            $ctx = NamespaceContext::fromSource($source);

            foreach ($ctx->getRuntimeDeclarations() as $rd) {
                $runtimeDeclarations[$rd] = true;
            }

            $base = \pathinfo($sourceFile, \PATHINFO_FILENAME);

            // PSX component tags must be PascalCase (`<Counter />`), and the
            // manifest exists solely to resolve those tags. Files whose
            // basename starts with a lowercase letter (App Router pattern:
            // `page.psx`, `layout.psx`) are loaded directly by path and
            // cannot be tag-referenced, so they don't belong in the manifest.
            // Without this skip, multiple `page.psx` files in different
            // directories would collide on the same namespace-qualified FQCN.
            if ($base === '' || !\ctype_upper($base[0])) {
                continue;
            }

            $fqcn = $ctx->getNamespace() !== ''
                ? $ctx->getNamespace() . '\\' . $base
                : $base;

            $compiledPath = self::cachePathFor($cacheDir, $sourceFile);

            if (isset($manifestEntries[$fqcn])) {
                $this->println("\033[31mError: duplicate component FQCN '$fqcn'\033[0m");
                $this->println('  defined in: ' . $manifestEntries[$fqcn]);
                $this->println('  also in:    ' . $compiledPath);
                return 1;
            }
            $manifestEntries[$fqcn] = $compiledPath;
        }

        $compiler = new Compiler();
        $stale = [];
        $knownFqcns = $manifestEntries + $runtimeDeclarations;
        $cachedCount = 0;

        foreach ($sourceFiles as $sourceFile) {
            $compiledPath = self::cachePathFor($cacheDir, $sourceFile);
            $metaPath = self::metaPathFor($cacheDir, $sourceFile);
            $source = $sourceContents[$sourceFile];
            $sourceHash = $this->computeSourceHash($source);

            // Cache hit: source unchanged AND every previously resolved ref is
            // still in scope. The ref re-check matters because another file may
            // have removed a component this file depends on — even though this
            // file itself didn't change, it would now fail validation.
            $cached = $this->readMeta($metaPath);
            if (
                $cached !== null
                && $cached['hash'] === $sourceHash
                && \is_file($compiledPath)
                && $this->refsResolveAgainst($cached['refs'], $knownFqcns)
            ) {
                $cachedCount++;
                continue;
            }

            try {
                $compiled = $compiler->compile($source);
            } catch (\Throwable $e) {
                $this->println("\033[31m$sourceFile: " . $e->getMessage() . "\033[0m");
                return 1;
            }

            $refs = $compiler->getLastReferences();
            foreach ($refs as $ref) {
                if (!isset($knownFqcns[$ref])) {
                    $this->println("\033[31m$sourceFile: unresolved component '$ref'\033[0m");
                    $this->println("  Add a 'use' statement, define {$this->shortName($ref)}.psx, or declare `// @psx-runtime $ref`.");
                    return 1;
                }
            }

            $existing = \file_exists($compiledPath) ? \file_get_contents($compiledPath) : null;
            if ($existing !== $compiled) {
                $stale[] = $sourceFile;
                if (!$check && \file_put_contents($compiledPath, $compiled) === false) {
                    $this->println("\033[31mError: failed to write $compiledPath (disk full or permissions?)\033[0m");
                    return 1;
                }
            }

            if (!$check) {
                $metaJson = \json_encode(
                    ['hash' => $sourceHash, 'refs' => $refs],
                    \JSON_THROW_ON_ERROR,
                );
                if (\file_put_contents($metaPath, $metaJson) === false) {
                    $this->println("\033[31mError: failed to write $metaPath\033[0m");
                    return 1;
                }
            }
        }

        $manifestSource = $this->buildManifestSource($manifestEntries);
        $existingManifest = \file_exists($manifestPath) ? \file_get_contents($manifestPath) : null;
        if ($existingManifest !== $manifestSource) {
            $stale[] = $manifestPath;
            if (!$check && \file_put_contents($manifestPath, $manifestSource) === false) {
                $this->println("\033[31mError: failed to write manifest $manifestPath\033[0m");
                return 1;
            }
        }

        // Discover deferred endpoints by inspecting each compiled file's return
        // value. A .psx that returned `fc(..., defer: new Defer(...))` carries
        // its Defer config on the FunctionComponent — we mirror it into a
        // sidecar manifest so `loadComponentManifest()` can auto-register
        // every endpoint without the developer touching `registerDeferred()`.
        $deferredEntries = $this->collectDeferredEntries($manifestEntries);
        $deferredManifestPath = \dirname($manifestPath) . \DIRECTORY_SEPARATOR . self::DEFERRED_MANIFEST_FILENAME;

        if ($deferredEntries === null) {
            return 1;
        }

        if ($deferredEntries !== []) {
            $deferredSource = $this->buildDeferredManifestSource($deferredEntries);
            $existingDeferred = \file_exists($deferredManifestPath)
                ? \file_get_contents($deferredManifestPath)
                : null;
            if ($existingDeferred !== $deferredSource) {
                $stale[] = $deferredManifestPath;
                if (!$check && \file_put_contents($deferredManifestPath, $deferredSource) === false) {
                    $this->println("\033[31mError: failed to write deferred manifest $deferredManifestPath\033[0m");
                    return 1;
                }
            }
        } elseif (\file_exists($deferredManifestPath)) {
            // No more deferred entries — remove a stale sidecar so a previous
            // build's registrations don't keep leaking into the runtime.
            $stale[] = $deferredManifestPath;
            if (!$check) {
                @\unlink($deferredManifestPath);
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
        $plural = $count === 1 ? '' : 's';
        $suffix = $cachedCount > 0 ? " ($cachedCount cached)" : '';
        $this->println("\033[32mCompiled $count .psx file$plural$suffix → $cacheDir\033[0m");
        $this->println("Manifest: $manifestPath");
        return 0;
    }

    private function doClean(string $cacheDir): int
    {
        if (!\is_dir($cacheDir)) {
            $this->println("\033[33mCache dir does not exist: $cacheDir\033[0m");
            return 0;
        }

        $removed = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                @\rmdir($path);
            } elseif (@\unlink($path)) {
                $removed++;
            } else {
                $this->println("\033[33mWarning: could not remove $path\033[0m");
            }
        }
        $this->println("\033[32mRemoved $removed file(s) from $cacheDir\033[0m");
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
