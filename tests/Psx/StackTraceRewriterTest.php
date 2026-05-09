<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\StackTraceRewriter;

class StackTraceRewriterTest extends TestCase
{
    public function testRewriteFileStripsPsxPhpSuffix(): void
    {
        self::assertSame(
            '/path/to/Counter.psx',
            StackTraceRewriter::rewriteFile('/path/to/Counter.psx.php')
        );
    }

    public function testRewriteFileLeavesNonPsxPathsUnchanged(): void
    {
        self::assertSame(
            '/path/to/Plain.php',
            StackTraceRewriter::rewriteFile('/path/to/Plain.php')
        );
        self::assertSame(
            '/path/to/Counter.psx',
            StackTraceRewriter::rewriteFile('/path/to/Counter.psx')
        );
    }

    public function testRewriteFramesUpdatesEachFile(): void
    {
        $frames = [
            ['file' => '/abs/Counter.psx.php', 'line' => 13, 'function' => 'foo'],
            ['file' => '/abs/index.php', 'line' => 42, 'function' => 'bar'],
            ['function' => 'fileless'],
        ];

        $rewritten = StackTraceRewriter::rewriteFrames($frames);

        self::assertSame('/abs/Counter.psx', $rewritten[0]['file']);
        self::assertSame('/abs/index.php', $rewritten[1]['file']);
        self::assertArrayNotHasKey('file', $rewritten[2]);
    }

    public function testFormatExceptionShowsRewrittenPaths(): void
    {
        $compiledFile = \tempnam(\sys_get_temp_dir(), 'psx-trace-') . '.psx.php';
        \file_put_contents(
            $compiledFile,
            "<?php\nthrow new \\RuntimeException('boom');\n"
        );

        try {
            require $compiledFile;
            self::fail('Expected throw');
        } catch (\RuntimeException $e) {
            $formatted = StackTraceRewriter::formatException($e);
            self::assertStringContainsString('boom', $formatted);
            self::assertStringContainsString(\substr($compiledFile, 0, -4), $formatted);
            self::assertStringNotContainsString($compiledFile, $formatted);
        } finally {
            @\unlink($compiledFile);
        }
    }

    public function testFormatExceptionIncludesPreviousExceptionChain(): void
    {
        $cause = new \RuntimeException('root cause');
        $wrapper = new \RuntimeException('wrapped', 0, $cause);
        $formatted = StackTraceRewriter::formatException($wrapper);
        self::assertStringContainsString('wrapped', $formatted);
        self::assertStringContainsString('Caused by:', $formatted);
        self::assertStringContainsString('root cause', $formatted);
    }
}
