<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use Dom\Element as DomElement;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Action forms must survive the HTML parser wherever the element using them
 * sits. These tests parse with the HTML5-compliant Dom\HTMLDocument, which
 * applies the same nesting and table rules as a browser (GH-77).
 */
final class ActionFormPlacementTest extends TestCase
{
    private function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);
    }

    private function render(Element $element): HTMLDocument
    {
        return $this->parse(new Renderer('test#0')->renderElement($element));
    }

    private function actionButton(string $label = 'n'): Element
    {
        return H::button(onClick: fn() => Action::setState(0, 1, 'test#0'), children: $label);
    }

    private function byId(HTMLDocument $doc, string $id): DomElement
    {
        $el = $doc->getElementById($id);
        self::assertNotNull($el, "#{$id} not found");

        return $el;
    }

    private function hasAncestor(DomElement $el, string $selector): bool
    {
        return $el->closest($selector) !== null;
    }

    public function testActionButtonInsideApplicationFormDoesNotNestForms(): void
    {
        $doc = $this->render(H::form(id: 'app', method: 'post', action: '/save', children: [
            H::input(name: 'title'),
            $this->actionButton(),
            H::button(type: 'submit', id: 'save', children: 'Save'),
        ]));

        // The application form keeps its own submit button.
        self::assertNotNull($this->byId($doc, 'save')->closest('#app'));

        $forms = $doc->querySelectorAll('form');
        self::assertCount(2, $forms);

        $button = $doc->querySelector('#app button[form]');
        self::assertNotNull($button);
        $actionForm = $this->byId($doc, (string) $button->getAttribute('form'));
        self::assertTrue($actionForm->hasAttribute('hidden'));
        self::assertTrue($actionForm->hasAttribute('data-usephp-form'));
        self::assertFalse($this->hasAncestor($actionForm, '#app'));
        self::assertNotNull($actionForm->querySelector('input[name="_usephp_action"]'));
        self::assertNull($this->byId($doc, 'app')->querySelector('input[name="_usephp_action"]'));
    }

    public function testEveryActionFormHasAUniqueId(): void
    {
        $doc = $this->render(H::div(children: [$this->actionButton('a'), $this->actionButton('b')]));
        $other = $this->render($this->actionButton('c'));

        $ids = [];
        foreach ([$doc, $other] as $d) {
            foreach ($d->querySelectorAll('form') as $form) {
                $ids[] = $form->getAttribute('id');
            }
        }

        self::assertCount(3, $ids);
        self::assertCount(3, array_unique($ids));
    }

    public function testActionFormDoesNotSplitAParagraph(): void
    {
        $doc = $this->render(H::div(children: [H::p(id: 'p', children: ['x ', $this->actionButton()])]));

        self::assertNotNull($this->byId($doc, 'p')->querySelector('button[form]'));
        self::assertCount(1, $doc->querySelectorAll('p'));
    }

    public function testActionFormLeavesTablesIntact(): void
    {
        $doc = $this->render(H::div(children: [H::table(children: [H::tbody(children: [H::tr(children: [
            H::td(id: 'cell', children: [$this->actionButton()]),
        ])])])]));

        $button = $this->byId($doc, 'cell')->querySelector('button');
        self::assertNotNull($button);
        $form = $this->byId($doc, (string) $button->getAttribute('form'));
        self::assertFalse($this->hasAncestor($form, 'table'));
        self::assertNotNull($form->querySelector('input[name="_usephp_action"]'));
    }

    public function testTableRowOnClickUsesAnAttributeInsteadOfAForm(): void
    {
        $doc = $this->render(H::div(children: [H::table(children: [H::tbody(children: [
            H::tr(id: 'row', onClick: fn() => Action::setState(0, 1, 'test#0'), children: [H::td(children: 'x')]),
        ])])]));

        $row = $this->byId($doc, 'row');
        self::assertSame('TBODY', $row->parentElement?->tagName);
        $form = $this->byId($doc, (string) $row->getAttribute('data-usephp-on-click'));
        self::assertTrue($form->hasAttribute('data-usephp-form'));
        self::assertFalse($this->hasAncestor($form, 'table'));
    }

    public function testActionFormStaysInsideItsComponentWrapper(): void
    {
        $doc = $this->render(new Element('div', ['id' => 'outer', 'data-usephp' => 'a#0'], [
            new Element('div', ['id' => 'inner', 'data-usephp' => 'b#0'], [$this->actionButton()]),
        ]));

        $button = $doc->querySelector('#inner button');
        self::assertNotNull($button);
        $form = $this->byId($doc, (string) $button->getAttribute('form'));
        self::assertTrue($this->hasAncestor($form, '#inner'));
    }

    public function testComponentInsideApplicationFormEmitsActionFormAfterTheOuterForm(): void
    {
        $doc = $this->render(H::form(id: 'app', children: [
            new Element('div', ['data-usephp' => 'b#0'], [$this->actionButton()]),
        ]));

        $button = $doc->querySelector('#app button');
        self::assertNotNull($button);
        $form = $this->byId($doc, (string) $button->getAttribute('form'));
        self::assertFalse($this->hasAncestor($form, '#app'));
    }

    public function testRenderedComponentAndPartialIncludeTheirActionForms(): void
    {
        $component = fn(): Element => H::div(children: [$this->actionButton()]);

        $full = $this->parse(new Renderer('test#0')->render($component));
        $wrapper = $full->querySelector('[data-usephp="test#0"]');
        self::assertNotNull($wrapper);
        self::assertNotNull($wrapper->querySelector('form[data-usephp-form]'));

        $partial = $this->parse(new Renderer('test#0')->renderPartial($component));
        self::assertNotNull($partial->querySelector('form[data-usephp-form]'));
    }

    public function testSubmitInputIsAssociatedThroughTheFormAttribute(): void
    {
        $doc = $this->render(H::div(children: [
            H::input(type: 'submit', id: 's', onClick: fn() => Action::setState(0, 1, 'test#0')),
        ]));

        $form = $this->byId($doc, (string) $this->byId($doc, 's')->getAttribute('form'));
        self::assertTrue($form->hasAttribute('data-usephp-form'));
    }

    public function testButtonDoesNotUseStyleOrWrapperMarkup(): void
    {
        $html = new Renderer('test#0')->renderElement($this->actionButton('Go'));

        self::assertMatchesRegularExpression(
            '/^<button type="submit" form="usephp-f-[0-9a-f]{16}">Go<\/button><form method="post" data-usephp-form id="usephp-f-[0-9a-f]{16}" hidden>/',
            $html,
        );
    }

    public function testHoistedAndOnSubmitFormsCarryTheDeferQuery(): void
    {
        $renderer = new Renderer('test#0', deferQuery: 'a=1&b=2');
        $action = Action::setState(0, 1, 'test#0');
        $field = '<input type="hidden" name="_usephp_defer_query" value="a=1&amp;b=2" />';

        self::assertStringContainsString($field, $renderer->renderElement(H::button(onClick: $action)));
        self::assertStringContainsString($field, $renderer->renderElement(H::form(onSubmit: $action)));
    }

    public function testCsrfTokenIsReadWhenTheFormIsEmitted(): void
    {
        $renderer = new Renderer('test#0', csrfToken: static fn(): string => 'late-token');

        self::assertStringContainsString(
            'name="_usephp_csrf" value="late-token"',
            $renderer->renderElement($this->actionButton()),
        );
    }

    public function testRawTextAndForeignContentKeepTheirRulesNextToActionForms(): void
    {
        $html = new Renderer('test#0')->renderElement(H::form(id: 'app', children: [
            H::__callStatic('style', ['children' => 'a > b { color: red }']),
            H::svg(children: [H::__callStatic('style', ['children' => '<x>'])]),
            $this->actionButton(),
        ]));

        self::assertStringContainsString('<style>a > b { color: red }</style>', $html);
        self::assertStringContainsString('<svg><style>&lt;x&gt;</style></svg>', $html);
        self::assertMatchesRegularExpression('/<\/form><form method="post" data-usephp-form id="usephp-f-/', $html);
    }

    public function testNonActionPropInWireNamespaceIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wire:click must be an Action');
        new Renderer('test#0')->renderElement(new Element('button', ['wire:click' => 'x'], []));
    }
}
