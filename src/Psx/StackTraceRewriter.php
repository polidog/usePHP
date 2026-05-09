<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Psx;

/**
 * Rewrites stack-trace file paths from compiled `.psx.php` files back to their
 * original `.psx` source files. Combined with line-preserving compilation, this
 * makes PHP errors look like they originated in the .psx source.
 *
 * Usage:
 *
 *     try {
 *         $app->run();
 *     } catch (\Throwable $e) {
 *         echo StackTraceRewriter::formatException($e);
 *     }
 *
 * Or install a global exception handler via UsePHP::installPsxErrorHandler().
 */
final class StackTraceRewriter
{
    /**
     * Rewrites a single file path: `.../Counter.psx.php` → `.../Counter.psx`.
     * Other paths are returned unchanged.
     */
    public static function rewriteFile(string $file): string
    {
        if (\str_ends_with($file, '.psx.php')) {
            return \substr($file, 0, -\strlen('.php'));
        }
        return $file;
    }

    /**
     * Rewrites the 'file' entry of each frame in a Throwable::getTrace() result.
     *
     * @param list<array<string, mixed>> $frames
     * @return list<array<string, mixed>>
     */
    public static function rewriteFrames(array $frames): array
    {
        $out = [];
        foreach ($frames as $frame) {
            if (isset($frame['file']) && \is_string($frame['file'])) {
                $frame['file'] = self::rewriteFile($frame['file']);
            }
            $out[] = $frame;
        }
        return $out;
    }

    /**
     * Returns a string representation of the exception with `.psx.php` paths
     * rewritten to their `.psx` source paths in both the leading file:line and
     * each stack frame.
     */
    public static function formatException(\Throwable $e): string
    {
        $file = self::rewriteFile($e->getFile());
        $line = $e->getLine();
        $class = $e::class;
        $message = $e->getMessage();

        $out = "$class: $message at $file:$line\n";
        $out .= self::formatTrace(self::rewriteFrames($e->getTrace()));

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $out .= "\nCaused by: " . self::formatException($previous);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $frames
     */
    private static function formatTrace(array $frames): string
    {
        $lines = [];
        foreach ($frames as $i => $frame) {
            $file = isset($frame['file']) && \is_string($frame['file']) ? $frame['file'] : '[internal]';
            $lineNo = isset($frame['line']) && \is_int($frame['line']) ? $frame['line'] : 0;
            $callable = self::formatCallable($frame);
            $lines[] = "#$i $file($lineNo): $callable";
        }
        $lines[] = '#' . \count($frames) . ' {main}';
        return \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function formatCallable(array $frame): string
    {
        $class = isset($frame['class']) && \is_string($frame['class']) ? $frame['class'] : '';
        $type = isset($frame['type']) && \is_string($frame['type']) ? $frame['type'] : '';
        $function = isset($frame['function']) && \is_string($frame['function']) ? $frame['function'] : '';
        return $class . $type . $function . '()';
    }
}
