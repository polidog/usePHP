<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\UsePHP;

final class ClientScriptTest extends TestCase
{
    public function testRenderClientScriptAddsExternalScriptWithDeferAndFallback(): void
    {
        $html = UsePHP::renderClientScript();

        self::assertStringContainsString('window.usePHPDeferFallback=function(root)', $html);
        self::assertStringContainsString('querySelectorAll(\'[data-usephp-defer-url]', $html);
        self::assertStringContainsString('<script src="/usephp.js" defer', $html);
        self::assertStringContainsString(
            'onerror="window.usePHPDeferFallback&amp;&amp;window.usePHPDeferFallback()"',
            $html,
        );
        self::assertStringContainsString(
            '<script>window.usePHPDeferFallbackCheck&&window.usePHPDeferFallbackCheck()</script>',
            $html,
        );
    }

    public function testRenderClientScriptEscapesCustomSource(): void
    {
        $html = UsePHP::renderClientScript('/assets/usephp.js?v=1&x="y"');

        self::assertStringContainsString(
            'src="/assets/usephp.js?v=1&amp;x=&quot;y&quot;"',
            $html,
        );
    }
}
