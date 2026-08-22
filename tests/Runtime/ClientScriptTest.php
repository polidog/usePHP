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

    public function testRenderClientScriptOmitsPrefixByDefault(): void
    {
        $html = UsePHP::renderClientScript();

        self::assertStringNotContainsString('window.usePHP.deferPrefix="', $html);
        // The fallback still enforces the built-in default prefix and origin.
        self::assertStringContainsString("'/_defer'", $html);
        self::assertStringContainsString('u.origin===location.origin', $html);
        self::assertStringContainsString("indexOf('text/html')!==0", $html);
    }

    public function testRenderClientScriptEmitsCustomDeferPrefixBeforeScripts(): void
    {
        $html = UsePHP::renderClientScript('/usephp.js', '/api/_d');

        self::assertStringStartsWith(
            '<script>window.usePHP=window.usePHP||{};window.usePHP.deferPrefix="/api/_d";</script>',
            $html,
        );
    }

    public function testRenderClientScriptEscapesDeferPrefixForScriptContext(): void
    {
        $html = UsePHP::renderClientScript('/usephp.js', '/x</script><script>alert(1)</script>');

        self::assertStringNotContainsString('</script><script>alert(1)', $html);
        self::assertStringContainsString('deferPrefix="/x\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"', $html);
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
