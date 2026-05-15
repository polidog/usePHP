<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\CompileCommand;

class CompileCommandTest extends TestCase
{
    private string $workDir;
    private string $cacheDir;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/psx-cmd-test-' . \uniqid();
        \mkdir($this->workDir . '/components', 0o777, true);
        $this->cacheDir = $this->workDir . '/var/cache/psx';
        $this->manifestPath = $this->cacheDir . '/manifest.php';
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testCompilesFilesAndGeneratesManifestInsideCacheDir(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App\\Components;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>hi</div>;\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);

        self::assertSame(0, $exitCode);
        self::assertFileDoesNotExist($this->workDir . '/components/Counter.psx.php', 'No sibling .psx.php — output goes to cache dir.');
        self::assertFileExists($this->manifestPath);
        self::assertDirectoryExists($this->cacheDir);

        $manifest = require $this->manifestPath;
        self::assertArrayHasKey('App\\Components\\Counter', $manifest);

        $expected = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->workDir . '/components/Counter.psx',
        );
        self::assertSame($expected, $manifest['App\\Components\\Counter']);
        self::assertFileExists($expected);
    }

    public function testCustomCacheDirViaFlag(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>x</div>;\n"
        );
        $customCache = $this->workDir . '/build/psx';

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [
            $this->workDir . '/components',
            '--cache=' . $customCache,
        ]);

        self::assertSame(0, $exitCode);
        self::assertFileExists($customCache . '/manifest.php');
    }

    public function testCheckExitsNonZeroWhenStale(): void
    {
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>old</div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>new</div>;\n"
        );

        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components', '--check']);
        self::assertSame(1, $exitCode);
    }

    public function testCleanEmptiesCacheDir(): void
    {
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nreturn fn() => <div></div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        $compiledPath = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->workDir . '/components/A.psx',
        );
        self::assertFileExists($compiledPath);
        self::assertFileExists($this->manifestPath);

        $this->runCmd($cmd, [$this->workDir . '/components', '--clean']);

        self::assertFileDoesNotExist($compiledPath);
        self::assertFileDoesNotExist($this->manifestPath);
    }

    public function testErrorsOnUnresolvedComponentReference(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Page.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <NonExistent />;\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(1, $exitCode);
    }

    public function testPsxRuntimeAnnotationSuppressesUnresolvedError(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Page.psx',
            "<?php\nnamespace App;\n// @psx-runtime App\\Legacy\\Counter\nuse App\\Legacy\\Counter;\nreturn fn() => <Counter />;\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(0, $exitCode);
    }

    public function testResolvedComponentInManifestPasses(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>c</div>;\n"
        );
        \file_put_contents(
            $this->workDir . '/components/Page.psx',
            "<?php\nnamespace App;\nuse App\\Counter;\nreturn fn() => <Counter />;\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(0, $exitCode);
    }

    public function testRejectsDuplicateFqcn(): void
    {
        \mkdir($this->workDir . '/components/sub', 0o777, true);
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nreturn fn() => <div>1</div>;\n"
        );
        \file_put_contents(
            $this->workDir . '/components/sub/A.psx',
            "<?php\nnamespace X;\nreturn fn() => <div>2</div>;\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(1, $exitCode);
    }

    public function testLowercaseBasenamesAreCompiledButOmittedFromManifest(): void
    {
        // App Router pattern: multiple `page.psx` files in different
        // directories must all compile without colliding on a manifest FQCN.
        \mkdir($this->workDir . '/components/about', 0o777, true);
        \mkdir($this->workDir . '/components/counter', 0o777, true);

        \file_put_contents(
            $this->workDir . '/components/page.psx',
            "<?php\nnamespace App;\nreturn fn() => fn() => 'root';\n"
        );
        \file_put_contents(
            $this->workDir . '/components/about/page.psx',
            "<?php\nnamespace App\\About;\nreturn fn() => fn() => 'about';\n"
        );
        \file_put_contents(
            $this->workDir . '/components/counter/page.psx',
            "<?php\nnamespace App\\Counter;\nreturn fn() => fn() => 'counter';\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(0, $exitCode);

        $manifest = require $this->manifestPath;
        self::assertSame([], $manifest, 'Lowercase basenames must not appear in the manifest.');

        // All three files still get compiled to the cache.
        foreach (['page.psx', 'about/page.psx', 'counter/page.psx'] as $rel) {
            $compiled = CompileCommand::cachePathFor(
                $this->cacheDir,
                $this->workDir . '/components/' . $rel,
            );
            self::assertFileExists($compiled, "Expected $rel to be compiled to cache.");
        }
    }

    public function testMixedPascalCaseAndLowercaseFilenames(): void
    {
        \mkdir($this->workDir . '/components/about', 0o777, true);

        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>c</div>;\n"
        );
        \file_put_contents(
            $this->workDir . '/components/about/page.psx',
            "<?php\nnamespace App\\About;\nreturn fn() => fn() => 'about';\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(0, $exitCode);

        $manifest = require $this->manifestPath;
        self::assertArrayHasKey('App\\Counter', $manifest);
        self::assertArrayNotHasKey('App\\About\\page', $manifest);
    }

    public function testSecondCompileSkipsUnchangedFiles(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>x</div>;\n"
        );
        $cmd = new CompileCommand();
        self::assertSame(0, $this->runCmd($cmd, [$this->workDir . '/components']));

        $compiledPath = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->workDir . '/components/Counter.psx',
        );
        $metaPath = CompileCommand::metaPathFor(
            $this->cacheDir,
            $this->workDir . '/components/Counter.psx',
        );
        self::assertFileExists($metaPath);
        $firstMtime = \filemtime($compiledPath);
        // Filesystem mtime resolution is 1s on most systems; force a gap so a
        // recompile would be observable.
        \sleep(1);

        self::assertSame(0, $this->runCmd($cmd, [$this->workDir . '/components']));

        \clearstatcache();
        self::assertSame(
            $firstMtime,
            \filemtime($compiledPath),
            'Second compile with unchanged source must not rewrite cache.',
        );
    }

    public function testCacheBustsWhenSourceChanges(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>old</div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        $compiledPath = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->workDir . '/components/Counter.psx',
        );
        $firstContent = \file_get_contents($compiledPath);
        \sleep(1);

        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>new</div>;\n"
        );
        self::assertSame(0, $this->runCmd($cmd, [$this->workDir . '/components']));

        \clearstatcache();
        self::assertNotSame(
            $firstContent,
            \file_get_contents($compiledPath),
            'Source change must trigger recompile.',
        );
    }

    public function testCacheBustsWhenCacheVersionChangesViaMetaTamper(): void
    {
        // Simulate a CACHE_VERSION bump: rewrite the meta hash so the stored
        // value no longer matches what compute would produce for the current
        // source. The compiler must re-run.
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>x</div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        $metaPath = CompileCommand::metaPathFor(
            $this->cacheDir,
            $this->workDir . '/components/Counter.psx',
        );
        $compiledPath = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->workDir . '/components/Counter.psx',
        );
        \file_put_contents($metaPath, \json_encode(['hash' => 'stale', 'refs' => []]));
        \unlink($compiledPath);

        self::assertSame(0, $this->runCmd($cmd, [$this->workDir . '/components']));
        self::assertFileExists($compiledPath, 'Cache miss on bad meta must regenerate the compiled file.');
    }

    public function testCachedFileStillRevalidatesReferences(): void
    {
        // First pass: Page.psx references Counter (which exists).
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>c</div>;\n"
        );
        \file_put_contents(
            $this->workDir . '/components/Page.psx',
            "<?php\nnamespace App;\nuse App\\Counter;\nreturn fn() => <Counter />;\n"
        );
        $cmd = new CompileCommand();
        self::assertSame(0, $this->runCmd($cmd, [$this->workDir . '/components']));

        // Remove Counter.psx — Page.psx is unchanged (cache hit candidate) but
        // its cached reference now fails to resolve.
        \unlink($this->workDir . '/components/Counter.psx');
        self::assertSame(
            1,
            $this->runCmd($cmd, [$this->workDir . '/components']),
            'Cache hit must still report unresolved references against the current FQCN set.',
        );
    }

    public function testCleanRemovesMetaFiles(): void
    {
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nreturn fn() => <div></div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        $metaPath = CompileCommand::metaPathFor(
            $this->cacheDir,
            $this->workDir . '/components/A.psx',
        );
        self::assertFileExists($metaPath);

        $this->runCmd($cmd, [$this->workDir . '/components', '--clean']);
        self::assertFileDoesNotExist($metaPath);
    }

    public function testEmitsDeferredManifestForFcWithDefer(): void
    {
        \file_put_contents(
            $this->workDir . '/components/UserHeaderDeferred.psx',
            "<?php\n"
            . "namespace App\\Components;\n"
            . "use Polidog\\UsePhp\\Component\\Defer;\n"
            . "use Polidog\\UsePhp\\Html\\H;\n"
            . "use function Polidog\\UsePhp\\Runtime\\fc;\n"
            . "return fc(\n"
            . "    fn(array \$props) => H::header(children: 'hi'),\n"
            . "    defer: new Defer(name: 'user-header', cacheControl: 'private, no-store'),\n"
            . ");\n",
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);
        self::assertSame(0, $exitCode);

        $deferredPath = $this->cacheDir . '/' . CompileCommand::DEFERRED_MANIFEST_FILENAME;
        self::assertFileExists($deferredPath, 'Compile must emit a deferred manifest sidecar.');

        $entries = require $deferredPath;
        self::assertArrayHasKey('user-header', $entries);
        self::assertSame('App\\Components\\UserHeaderDeferred', $entries['user-header']['component']);
        self::assertSame('private, no-store', $entries['user-header']['cacheControl']);
    }

    public function testOmitsDeferredManifestWhenNoDeferredComponents(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Plain.psx',
            "<?php\n"
            . "namespace App;\n"
            . "use Polidog\\UsePhp\\Html\\H;\n"
            . "return fn() => <div>plain</div>;\n",
        );

        $cmd = new CompileCommand();
        self::assertSame(0, $this->runCmd($cmd, [$this->workDir . '/components']));

        $deferredPath = $this->cacheDir . '/' . CompileCommand::DEFERRED_MANIFEST_FILENAME;
        self::assertFileDoesNotExist(
            $deferredPath,
            'No deferred components → no sidecar manifest written.',
        );
    }

    public function testRejectsDuplicateDeferredName(): void
    {
        $template = static fn(string $class) => "<?php\n"
            . "namespace App\\Components;\n"
            . "use Polidog\\UsePhp\\Component\\Defer;\n"
            . "use Polidog\\UsePhp\\Html\\H;\n"
            . "use function Polidog\\UsePhp\\Runtime\\fc;\n"
            . "return fc(\n"
            . "    fn(array \$props) => H::div(),\n"
            . "    defer: new Defer(name: 'dup'),\n"
            . ");\n";

        \file_put_contents($this->workDir . '/components/A.psx', $template('A'));
        \file_put_contents($this->workDir . '/components/B.psx', $template('B'));

        $cmd = new CompileCommand();
        self::assertSame(1, $this->runCmd($cmd, [$this->workDir . '/components']));
    }

    public function testCachePathForIsStable(): void
    {
        $source = $this->workDir . '/components/A.psx';
        \file_put_contents($source, '<?php');
        $a = CompileCommand::cachePathFor($this->cacheDir, $source);
        $b = CompileCommand::cachePathFor($this->cacheDir, $source);
        self::assertSame($a, $b);
        self::assertStringStartsWith($this->cacheDir, $a);
        self::assertStringEndsWith('.php', $a);
    }

    /**
     * @param list<string> $argv
     */
    private function runCmd(CompileCommand $cmd, array $argv): int
    {
        \ob_start();
        try {
            return $cmd->run($argv, $this->workDir);
        } finally {
            \ob_end_clean();
        }
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        $entries = \scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
