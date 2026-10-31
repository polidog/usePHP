<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;

use function Polidog\UsePhp\Runtime\useFormAction;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

/**
 * Field values travel outside the signature; which hook each field may write
 * to travels inside it (GH-76).
 */
final class FormActionTest extends TestCase
{
    private const INSTANCE = 'form#0';

    /** @var array<string, mixed> */
    private array $savedPost = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    private UsePHP $app;

    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        StorageFactory::reset();
        RenderContext::beginRender();
        $this->savedPost = $_POST;
        $this->savedServer = $_SERVER;

        $this->app = new UsePHP();
        $this->app->disableCsrfProtection();
        $this->app->withHeaderEmitter(static function (string $header): void {});
        $this->app->withExitHandler(static function (): void {});
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_SERVER = $this->savedServer;
        \http_response_code(200);
    }

    /**
     * POST a form action the server signed, with the given client fields.
     *
     * @param array<string, int> $fields
     * @param array<string, mixed> $post
     */
    private function submit(array $fields, array $post): int
    {
        $json = Action::setFields($fields, self::INSTANCE)->toJson();
        $signature = $this->app->signAction(self::INSTANCE, $json);
        self::assertNotNull($signature);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = [
            ...$post,
            '_usephp_component' => self::INSTANCE,
            '_usephp_action' => $json,
            '_usephp_action_sig' => $signature,
        ];
        \http_response_code(200);
        $this->app->handleAction();

        $code = \http_response_code();

        return is_int($code) ? $code : 0;
    }

    private function state(int $index): mixed
    {
        return ComponentState::getInstance(self::INSTANCE)->getState($index, 'untouched');
    }

    public function testFieldValuesAreWrittenToTheSignedHooks(): void
    {
        self::assertSame(303, $this->submit(['title' => 0, 'memo' => 1], ['title' => 'hello', 'memo' => '<b>x</b>']));

        self::assertSame('hello', $this->state(0));
        self::assertSame('<b>x</b>', $this->state(1));
    }

    public function testFieldsOutsideTheSignedMapAreIgnored(): void
    {
        $this->submit(['title' => 0], ['title' => 'a', 'other' => 'b', '1' => 'c']);

        self::assertSame('a', $this->state(0));
        self::assertSame('untouched', $this->state(1));
    }

    public function testAMissingFieldIsAnEmptyString(): void
    {
        // An unchecked checkbox sends nothing; that has to be able to clear it.
        self::assertSame(303, $this->submit(['done' => 0], []));

        self::assertSame('', $this->state(0));
    }

    public function testNonStringValueIsRejectedAndNothingIsWritten(): void
    {
        self::assertSame(400, $this->submit(['a' => 0, 'b' => 1], ['a' => 'ok', 'b' => ['x']]));

        self::assertSame('untouched', $this->state(0));
        self::assertSame('untouched', $this->state(1));
    }

    public function testTooLongValueIsRejected(): void
    {
        self::assertSame(400, $this->submit(['a' => 0], ['a' => str_repeat('x', UsePHP::MAX_FORM_FIELD_BYTES + 1)]));
        self::assertSame('untouched', $this->state(0));
    }

    public function testValueAtTheLimitIsAccepted(): void
    {
        $value = str_repeat('x', UsePHP::MAX_FORM_FIELD_BYTES);

        self::assertSame(303, $this->submit(['a' => 0], ['a' => $value]));
        self::assertSame($value, $this->state(0));
    }

    public function testInvalidUtf8IsRejected(): void
    {
        // It could not be serialized into a snapshot.
        self::assertSame(400, $this->submit(['a' => 0], ['a' => "\xFF\xFE"]));
        self::assertSame('untouched', $this->state(0));
    }

    public function testTamperingWithTheFieldMapBreaksTheSignature(): void
    {
        $json = Action::setFields(['title' => 0], self::INSTANCE)->toJson();
        $signature = $this->app->signAction(self::INSTANCE, $json);
        self::assertNotNull($signature);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = [
            'title' => 'x',
            '_usephp_component' => self::INSTANCE,
            '_usephp_action' => Action::setFields(['title' => 1], self::INSTANCE)->toJson(),
            '_usephp_action_sig' => $signature,
        ];
        \http_response_code(200);
        $this->app->handleAction();

        self::assertSame(400, \http_response_code());
        self::assertSame('untouched', $this->state(1));
    }

    public function testASignedActionWithAnOutOfRangeHookIsRejected(): void
    {
        self::assertSame(400, $this->submit(['a' => 256], ['a' => 'x']));
    }

    public function testUseFormActionFixesFieldsToHooksOfTheComponent(): void
    {
        $state = ComponentState::getInstance('comp#0', StorageType::Session);
        ComponentState::reset();

        [, $setA] = useState('');
        [, $setB] = useState('');
        $action = useFormAction(['a' => $setA, 'b' => $setB]);

        self::assertSame('setFields', $action->type);
        self::assertSame(['fields' => ['a' => 0, 'b' => 1]], $action->payload);
        self::assertSame('comp#0', $action->componentId);
        self::assertSame($state->getStorageType(), $action->storageType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFieldNameProvider(): iterable
    {
        yield 'reserved' => ['_usephp_action'];
        yield 'array syntax' => ['tags[]'];
        yield 'mangled by php' => ['a.b'];
        yield 'empty' => [''];
        yield 'numeric' => ['1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFieldNameProvider')]
    public function testInvalidFieldNamesAreRefused(string $name): void
    {
        ComponentState::getInstance('comp#0', StorageType::Session);
        ComponentState::reset();
        [, $set] = useState('');

        $this->expectException(\InvalidArgumentException::class);
        useFormAction([$name => $set]);
    }

    public function testUseFormActionRequiresSetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        useFormAction(['a' => 'phpinfo']);
    }

    public function testUseFormActionRequiresAtLeastOneField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        useFormAction([]);
    }

    public function testUseFormActionRefusesSettersOfDifferentComponents(): void
    {
        ComponentState::getInstance('a#0', StorageType::Session);
        ComponentState::reset();
        [, $setA] = useState('');

        ComponentState::getInstance('b#0', StorageType::Session);
        ComponentState::reset();
        [, $setB] = useState('');

        $this->expectException(\InvalidArgumentException::class);
        useFormAction(['a' => $setA, 'b' => $setB]);
    }

    public function testSnapshotFunctionComponentReturnsThePostedValueInItsPartial(): void
    {
        $component = fc(static function (array $props): Element {
            [$title, $setTitle] = useState('');

            return H::form(onSubmit: useFormAction(['title' => $setTitle]), children: [
                H::p(children: "title={$title}"),
                H::input(name: 'title', value: $title),
            ]);
        }, 'fields', StorageType::Snapshot);

        $app = new UsePHP();
        $app->disableCsrfProtection();
        $app->setSnapshotSecret('test-secret-key-for-form-action-tests');
        $app->getRouter()->get('/page', static function () use ($component): Element {
            RenderContext::beginRender();

            return $component();
        });

        RenderContext::setApp($app);
        RenderContext::beginRender();
        $page = $app->renderElement($component());
        RenderContext::clearApp();

        $doc = HTMLDocument::createFromString('<!doctype html><body>' . $page, LIBXML_NOERROR);
        $post = [];
        foreach ($doc->querySelectorAll('form[data-usephp-form] input[type="hidden"]') as $input) {
            $post[(string) $input->getAttribute('name')] = (string) $input->getAttribute('value');
        }
        self::assertArrayHasKey('_usephp_snapshot', $post);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/page';
        $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
        $_POST = [...$post, 'title' => '<b>hi</b>'];
        ComponentState::clearInstances();
        StorageFactory::reset();

        $partial = $app->handleAction();

        self::assertIsString($partial);
        self::assertStringContainsString('title=&lt;b&gt;hi&lt;/b&gt;', $partial);
        self::assertStringContainsString('value="&lt;b&gt;hi&lt;/b&gt;"', $partial);
        self::assertStringContainsString('data-usephp-snapshot-update', $partial);
    }

    private function render(Element $element): string
    {
        return new Renderer('form#0')->renderElement($element);
    }

    public function testFormOnSubmitRendersTheMapInTheSignedAction(): void
    {
        $action = Action::setFields(['title' => 0], self::INSTANCE);
        $html = $this->render(H::form(onSubmit: $action, children: [H::input(name: 'title')]));

        self::assertStringContainsString(htmlspecialchars($action->toJson(), ENT_QUOTES), $html);
    }

    public function testOnChangeSendsTheValueOfItsOwnField(): void
    {
        $html = $this->render(H::input(name: 'q', onChange: Action::setFields(['q' => 0], self::INSTANCE)));

        self::assertStringContainsString('data-usephp-with-value', $html);
        self::assertStringContainsString('data-usephp-on-change="', $html);
    }

    public function testPlainActionDoesNotSendTheElementsValue(): void
    {
        $html = $this->render(H::input(name: 'q', onChange: Action::setState(0, 1, self::INSTANCE)));

        self::assertStringNotContainsString('data-usephp-with-value', $html);
    }

    /**
     * @return iterable<string, array{\Closure(Action): Element}>
     */
    public static function misusedFormActionProvider(): iterable
    {
        yield 'button click has no fields' => [fn(Action $a) => H::button(onClick: $a)];
        yield 'div click has no fields' => [fn(Action $a) => H::div(onClick: $a)];
        yield 'other field name' => [fn(Action $a) => H::input(name: 'other', onChange: $a)];
        yield 'no name' => [fn(Action $a) => H::input(onInput: $a)];
        yield 'not a control' => [fn(Action $a) => H::div(onChange: $a)];
        yield 'multiple select' => [fn(Action $a) => H::select(name: 'q', multiple: true, onChange: $a)];
        yield 'file input' => [fn(Action $a) => H::input(type: 'file', name: 'q', onChange: $a)];
    }

    /**
     * @param \Closure(Action): Element $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('misusedFormActionProvider')]
    public function testFormActionWhereItsFieldsCannotBeSentIsRefused(\Closure $build): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('useFormAction');
        $this->render($build(Action::setFields(['q' => 0], self::INSTANCE)));
    }
}
