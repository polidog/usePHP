<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\CompileCommand;

class CompileCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/psx-cmd-test-' . \uniqid();
        \mkdir($this->workDir . '/components', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testCompilesFilesAndGeneratesManifest(): void
    {
        \file_put_contents(
            $this->workDir . '/components/Counter.psx',
            "<?php\nnamespace App\\Components;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>hi</div>;\n"
        );

        $cmd = new CompileCommand();
        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components']);

        self::assertSame(0, $exitCode);
        self::assertFileExists($this->workDir . '/components/Counter.psx.php');
        self::assertFileExists($this->workDir . '/psx-manifest.php');

        $manifest = require $this->workDir . '/psx-manifest.php';
        self::assertArrayHasKey('App\\Components\\Counter', $manifest);
        self::assertSame(
            $this->workDir . '/components/Counter.psx.php',
            $manifest['App\\Components\\Counter']
        );
    }

    public function testCheckExitsNonZeroWhenStale(): void
    {
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>old</div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        // Modify source so compiled output would differ.
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nuse Polidog\\UsePhp\\Html\\H;\nreturn fn() => <div>new</div>;\n"
        );

        $exitCode = $this->runCmd($cmd, [$this->workDir . '/components', '--check']);
        self::assertSame(1, $exitCode);
    }

    public function testCleanRemovesGeneratedFiles(): void
    {
        \file_put_contents(
            $this->workDir . '/components/A.psx',
            "<?php\nnamespace X;\nreturn fn() => <div></div>;\n"
        );
        $cmd = new CompileCommand();
        $this->runCmd($cmd, [$this->workDir . '/components']);

        self::assertFileExists($this->workDir . '/components/A.psx.php');
        self::assertFileExists($this->workDir . '/psx-manifest.php');

        $this->runCmd($cmd, [$this->workDir . '/components', '--clean']);

        self::assertFileDoesNotExist($this->workDir . '/components/A.psx.php');
        self::assertFileDoesNotExist($this->workDir . '/psx-manifest.php');
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
