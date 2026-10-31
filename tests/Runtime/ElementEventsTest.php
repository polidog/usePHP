<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Event props either work or fail loudly; none of them may be accepted and
 * then ignored (GH-75).
 */
final class ElementEventsTest extends TestCase
{
    private function render(Element $element): string
    {
        return new Renderer('test#0')->renderElement($element);
    }

    private function action(): Action
    {
        return Action::setState(0, 1, 'test#0');
    }

    /**
     * @return iterable<string, array{\Closure(Action): Element, string}>
     */
    public static function triggerProvider(): iterable
    {
        yield 'div click' => [fn(Action $a) => H::div(onClick: $a), 'click'];
        yield 'span click' => [fn(Action $a) => H::span(onClick: $a), 'click'];
        yield 'a click' => [fn(Action $a) => H::a(href: '#x', onClick: $a), 'click'];
        yield 'li click' => [fn(Action $a) => H::li(onClick: $a), 'click'];
        yield 'p click through extras' => [fn(Action $a) => H::p(onClick: $a), 'click'];
        yield 'checkbox change' => [fn(Action $a) => H::input(type: 'checkbox', onChange: $a), 'change'];
        yield 'text input' => [fn(Action $a) => H::input(type: 'text', onInput: $a), 'input'];
        yield 'select change' => [fn(Action $a) => H::select(onChange: $a), 'change'];
        yield 'textarea input' => [fn(Action $a) => H::textarea(onInput: $a), 'input'];
    }

    /**
     * @param \Closure(Action): Element $build
     */
    #[DataProvider('triggerProvider')]
    public function testElementNamesItsActionFormForTheClientScript(\Closure $build, string $event): void
    {
        $html = $this->render($build($this->action()));

        self::assertMatchesRegularExpression(
            '/^<\w+[^>]* data-usephp-on-' . $event . '="(usephp-f-[0-9a-f]{16})"/',
            $html,
        );
        preg_match('/data-usephp-on-' . $event . '="([^"]+)"/', $html, $m);
        self::assertStringContainsString('<form method="post" data-usephp-form id="' . $m[1] . '" hidden>', $html);
    }

    public function testBothInputEventsCanBeBoundOnOneElement(): void
    {
        $html = $this->render(H::input(onChange: $this->action(), onInput: $this->action()));

        self::assertStringContainsString('data-usephp-on-change="', $html);
        self::assertStringContainsString('data-usephp-on-input="', $html);
        self::assertSame(2, substr_count($html, '<form '));
    }

    public function testFormOnSubmitIsAnActionFormItself(): void
    {
        $html = $this->render(H::form(id: 'f', onSubmit: $this->action(), children: [
            H::input(name: 'q'),
            H::button(children: 'go'),
        ]));

        self::assertStringStartsWith('<form method="post" data-usephp-form id="f">', $html);
        self::assertStringContainsString('name="_usephp_action"', $html);
        self::assertStringContainsString('<input name="q" />', $html);
        self::assertSame(1, substr_count($html, '<form '));
    }

    public function testButtonWithOnClickInsideOnSubmitFormGetsItsOwnForm(): void
    {
        $html = $this->render(H::form(onSubmit: $this->action(), children: [
            H::button(onClick: $this->action(), children: 'x'),
        ]));

        self::assertSame(2, substr_count($html, '<form '));
        self::assertMatchesRegularExpression('/<\/form><form method="post" data-usephp-form id="usephp-f-/', $html);
    }

    public function testOnSubmitRefusesAnApplicationActionUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('onSubmit');
        $this->render(H::form(action: '/save', onSubmit: $this->action()));
    }

    public function testOnSubmitRefusesGet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('onSubmit');
        $this->render(H::form(method: 'get', onSubmit: $this->action()));
    }

    public function testOnSubmitOnAnythingButAFormIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('onSubmit');
        $this->render(H::div(onSubmit: $this->action()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedEventProvider(): iterable
    {
        yield ['onFocus'];
        yield ['onBlur'];
        yield ['onLoad'];
        yield ['onError'];
    }

    #[DataProvider('unsupportedEventProvider')]
    public function testUnsupportedEventIsRefused(string $prop): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($prop . ' is not supported');
        H::input(...[$prop => $this->action()]);
    }

    public function testUnsupportedEventIsAlsoRefusedOnTheDynamicPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::__callStatic('img', ['onLoad' => fn() => $this->action()]);
    }

    public function testUnsupportedEventWithNullIsIgnored(): void
    {
        self::assertSame('<input />', $this->render(H::input(onFocus: null)));
    }

    public function testClosureWithRequiredParameterGetsAClearError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('useFormAction');
        H::input(name: 'i', onChange: fn($e) => $e);
    }

    public function testUnknownWireBindingIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wire:focus');
        $this->render(new Element('input', ['wire:focus' => $this->action()], []));
    }
}
