<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Installer;

use Composer\Script\Event;

/**
 * Composer script to publish usephp.js to the project's public directory.
 */
final class AssetInstaller
{
    /**
     * Publish assets after composer install/update.
     */
    public static function publish(Event $event): void
    {
        $io = $event->getIO();
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');

        $source = dirname(__DIR__, 2) . '/public/usephp.js';
        $targetDir = dirname($vendorDir) . '/public';
        $target = $targetDir . '/usephp.js';

        if (!file_exists($source)) {
            $io->writeError('<warning>usephp.js source not found</warning>');
            return;
        }

        // Create public directory if it doesn't exist
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0o755, true)) {
                $io->writeError('<warning>Could not create public directory</warning>');
                return;
            }
        }

        // Copy the file
        if (copy($source, $target)) {
            $io->write('<info>usePHP:</info> Published usephp.js to public/usephp.js');
        } else {
            $io->writeError('<warning>Could not copy usephp.js</warning>');
        }
    }
}
