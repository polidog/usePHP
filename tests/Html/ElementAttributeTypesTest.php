<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Html;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * This file runs under strict_types on purpose: HTML attribute values are
 * text, so numbers and numeric strings must both be accepted (GH-84).
 */
final class ElementAttributeTypesTest extends TestCase
{
    private function render(Element $element): string
    {
        return new Renderer('test#0')->renderElement($element);
    }

    public function testInputStepKeepsNonIntegerValues(): void
    {
        self::assertSame('<input step="0.5" />', $this->render(H::input(step: '0.5')));
        self::assertSame('<input step="0.5" />', $this->render(H::input(step: 0.5)));
        self::assertSame('<input step="any" />', $this->render(H::input(step: 'any')));
        self::assertSame('<input step="2" />', $this->render(H::input(step: 2)));
    }

    public function testInputNumericAttributesAcceptStringsAndNumbers(): void
    {
        self::assertSame(
            '<input value="3" min="1" max="1.5" minlength="2" maxlength="10" />',
            $this->render(H::input(value: 3, min: 1, max: 1.5, minLength: '2', maxLength: '10')),
        );
    }

    public function testDimensionsAcceptStrings(): void
    {
        self::assertSame('<img width="100" height="50%" />', $this->render(H::img(width: '100', height: '50%')));
        self::assertSame('<svg width="10"></svg>', $this->render(H::svg(width: '10')));
        self::assertSame('<canvas width="10"></canvas>', $this->render(H::canvas(width: '10')));
    }

    public function testTextareaAndTableAttributesAcceptStrings(): void
    {
        self::assertSame('<textarea rows="3"></textarea>', $this->render(H::textarea(rows: '3')));
        self::assertSame('<td colspan="2"></td>', $this->render(H::td(colspan: '2')));
    }

    public function testProgressAcceptsNumericStrings(): void
    {
        self::assertSame('<progress value="1" max="2"></progress>', $this->render(H::progress(value: '1', max: 2)));
    }

    public function testScalarChildrenAreAccepted(): void
    {
        self::assertSame('<p>0</p>', $this->render(H::p(children: 0)));
        self::assertSame('<p>1.5</p>', $this->render(H::p(children: 1.5)));
    }
}
