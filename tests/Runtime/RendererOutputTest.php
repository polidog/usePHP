<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;

class RendererOutputTest extends TestCase
{
    private function render(Element $element): string
    {
        return (new Renderer('test'))->renderElement($element);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousUrlProvider(): iterable
    {
        yield 'tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'newline inside the scheme' => ["java\nscript:alert(1)"];
        yield 'carriage return inside the scheme' => ["java\rscript:alert(1)"];
        yield 'tab before the colon' => ["javascript\t:alert(1)"];
        yield 'mixed with leading controls' => ["\x01 \tj\ra\nvascript:alert(1)"];
        yield 'data scheme with a tab' => ["da\tta:text/html,x"];
        yield 'vbscript with a newline' => ["vb\nscript:x"];
    }

    #[DataProvider('dangerousUrlProvider')]
    public function testUrlWithStrippedCharactersIsBlocked(string $url): void
    {
        self::assertSame('<a>x</a>', $this->render(H::a(href: $url, children: 'x')));
    }

    public function testUrlAttributeLookupIgnoresCase(): void
    {
        self::assertSame(
            '<a>x</a>',
            $this->render(new Element('a', ['HREF' => 'javascript:alert(1)'], ['x'])),
        );
    }

    public function testHarmlessUrlWithTabIsLeftAlone(): void
    {
        self::assertSame(
            "<a href=\"/pa\tth?x=a:b\">x</a>",
            $this->render(H::a(href: "/pa\tth?x=a:b", children: 'x')),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function srcdocSpellingProvider(): iterable
    {
        yield 'JSX name' => ['srcDoc'];
        yield 'HTML name' => ['srcdoc'];
        yield 'upper case' => ['SRCDOC'];
    }

    #[DataProvider('srcdocSpellingProvider')]
    public function testSrcdocWithValueIsRefused(string $prop): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('srcdoc');

        $this->render(new Element('iframe', [$prop => '<script>parent.document.title="PWNED"</script>'], []));
    }

    public function testSrcdocWithHarmlessLookingValueIsRefusedToo(): void
    {
        // The value is HTML, not a URL: no scheme check can tell it apart.
        $this->expectException(\RuntimeException::class);

        $this->render(new Element('iframe', ['srcdoc' => 'https://example.com/'], []));
    }

    public function testEmptySrcdocIsAllowed(): void
    {
        self::assertSame(
            '<iframe srcdoc=""></iframe>',
            $this->render(new Element('iframe', ['srcdoc' => ''], [])),
        );
    }

    public function testInvalidUtf8TextIsSubstitutedNotEmptied(): void
    {
        self::assertSame("<p>abc\u{FFFD}def</p>", $this->render(H::p(children: "abc\xB1def")));
        self::assertSame("abc\u{FFFD}def", (new Renderer('test'))->renderElement("abc\xB1def"));
    }

    public function testInvalidUtf8AttributeIsSubstitutedNotEmptied(): void
    {
        self::assertSame(
            "<a href=\"/a\u{FFFD}\">x</a>",
            $this->render(H::a(href: "/a\xB1", children: 'x')),
        );
    }

    public function testInvalidUtf8StateValueDoesNotBreakTheActionForm(): void
    {
        $html = $this->render(H::button(
            onClick: fn() => Action::setState(0, "\xB1\x31", 'test#0'),
            children: 'x',
        ));

        self::assertSame(1, preg_match('/name="_usephp_action" value="([^"]*)"/', $html, $m));
        $action = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($action);
        self::assertSame("\u{FFFD}1", $action['payload']['value']);
    }

    public function testInvalidUtf8SnapshotStateSurvivesTheChecksum(): void
    {
        $serializer = new SnapshotSerializer('test-secret-key-with-enough-entropy');

        $json = $serializer->serialize(new Snapshot('Comp', '0', [0 => "\xB1\x31"]));
        $restored = $serializer->deserialize($json);

        self::assertSame([0 => "\u{FFFD}1"], $restored->state);
    }
}
