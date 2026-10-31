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

    /**
     * Issue #79: replacing the component's contents wholesale drops unsent
     * input, focus and selection, so partial responses are morphed in place.
     * The behaviour itself needs a browser; this guards the wiring.
     */
    public function testPartialResponseIsMorphedIntoTheExistingDom(): void
    {
        $script = self::clientScript();

        self::assertStringNotContainsString('component.innerHTML', $script);
        self::assertMatchesRegularExpression('/function applyPartial\(component, html, submission\)/', $script);
        self::assertStringContainsString('morphChildren(component, template.content, submission);', $script);
        // The refreshed snapshot is lifted off the parsed response, so the
        // bookkeeping field never reaches the morph.
        self::assertStringContainsString("template.content.querySelector('[data-usephp-snapshot-update]')", $script);
    }

    public function testMorphNeverReusesAnElementWithADifferentIdentity(): void
    {
        $script = self::clientScript();

        // A retyped or renamed field must not inherit the old field's value,
        // and an in-flight defer fetch must not land in another URL's slot.
        foreach (['id', 'name', 'type', 'data-usephp', 'data-usephp-defer-url'] as $attribute) {
            self::assertStringContainsString("'{$attribute}'", self::between($script, 'const MORPH_IDENTITY', ';'));
        }
    }

    public function testSubmitRecordsFieldValuesBeforeTheRequest(): void
    {
        $submit = self::submitHandler();

        $captured = \strpos($submit, 'captureFields(component)');
        $fetched = \strpos($submit, 'await fetch(');

        self::assertNotFalse($captured);
        self::assertNotFalse($fetched);
        self::assertLessThan($fetched, $captured, 'Edits made during the request are detected against this capture.');
        self::assertStringContainsString('applyPartial(component, await response.text(), submission)', $submit);
    }

    private static function clientScript(): string
    {
        $script = \file_get_contents(__DIR__ . '/../../public/usephp.js');
        self::assertIsString($script);

        return $script;
    }

    private static function submitHandler(): string
    {
        return self::between(self::clientScript(), "document.addEventListener('submit'", 'Deferred component hydration');
    }

    private static function between(string $haystack, string $from, string $to): string
    {
        $start = \strpos($haystack, $from);
        self::assertNotFalse($start, "Missing marker: {$from}");
        $end = \strpos($haystack, $to, $start);
        self::assertNotFalse($end, "Missing marker: {$to}");

        return \substr($haystack, $start, $end - $start);
    }
}
