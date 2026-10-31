<?php

declare(strict_types=1);

/**
 * Router script for `php -S`: the application the browser tests drive.
 *
 * It serves the real public/usephp.js, so what runs in the browser is what the
 * package ships. See tests/browser/support/php-server.ts for how it is started.
 */

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useFormAction;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

$root = dirname(__DIR__, 3);
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile($root . '/public/usephp.js');

    return;
}

// What an application's own <form> posts to.
if ($path === '/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain');
    echo 'saved:' . ($_POST['appfield'] ?? '');

    return;
}

require $root . '/vendor/autoload.php';

/** Every kind of trigger and form, in one session component. */
#[Component(name: 'controls')]
final class Controls extends BaseComponent
{
    public function render(): Element
    {
        [$last, $setLast] = $this->useState('none');
        [$count, $setCount] = $this->useState(0);
        [$text, $setText] = $this->useState('');
        [$typed, $setTyped] = $this->useState('');
        [$choice, $setChoice] = $this->useState('a');
        [$agreed, $setAgreed] = $this->useState('');
        [$title, $setTitle] = $this->useState('');
        [$memo, $setMemo] = $this->useState('');
        [$flips, $setFlips] = $this->useState(0);
        [$carried, $setCarried] = $this->useState('');

        return H::div(children: [
            H::p(id: 'last', children: "last={$last}"),
            H::p(id: 'text', children: "text={$text}"),
            H::p(id: 'typed', children: "typed={$typed}"),
            H::p(id: 'choice', children: "choice={$choice}"),
            H::p(id: 'agreed', children: "agreed={$agreed}"),
            H::p(id: 'title', children: "title={$title}"),
            H::p(id: 'memo', children: "memo={$memo}"),
            H::p(id: 'carried', children: "carried={$carried}"),

            H::div(id: 'div', onClick: fn() => $setLast('div'), children: 'div'),
            H::span(id: 'span', onClick: fn() => $setLast('span'), children: 'span'),
            H::a(id: 'anchor', href: '#anchor-target', onClick: fn() => $setLast('anchor'), children: 'anchor'),
            H::ul(children: [H::li(id: 'item', onClick: fn() => $setLast('item'), children: 'item')]),
            H::table(children: [H::tbody(children: [
                H::tr(id: 'row', onClick: fn() => $setLast('row'), children: [
                    H::td(id: 'cell', children: 'cell'),
                    H::td(children: [H::button(id: 'row-button', onClick: fn() => $setLast('row-button'), children: 'in row')]),
                ]),
            ])]),
            H::input(id: 'plain-checkbox', type: 'checkbox', onChange: fn() => $setLast('checkbox')),
            H::form(id: 'plain-form', onSubmit: fn() => $setLast('submit'), children: [
                H::button(id: 'plain-submit', children: 'submit'),
            ]),

            H::input(id: 'typed-input', type: 'text', name: 'typed', value: $typed, onInput: useFormAction(['typed' => $setTyped])),
            H::input(id: 'text-input', type: 'text', name: 'text', value: $text, onChange: useFormAction(['text' => $setText])),
            H::select(id: 'choice-select', name: 'choice', onChange: useFormAction(['choice' => $setChoice]), children: [
                H::option(value: 'a', selected: $choice === 'a', children: 'A'),
                H::option(value: 'b', selected: $choice === 'b', children: 'B'),
            ]),
            H::input(id: 'agreed-checkbox', type: 'checkbox', name: 'agreed', value: 'yes', checked: $agreed === 'yes', onChange: useFormAction(['agreed' => $setAgreed])),
            H::form(id: 'article', onSubmit: useFormAction(['title' => $setTitle, 'memo' => $setMemo]), children: [
                H::input(id: 'article-title', name: 'title', value: $title),
                H::textarea(id: 'article-memo', name: 'memo', value: $memo),
                H::button(id: 'article-submit', children: 'send'),
            ]),

            // A change of type makes the morph replace this element.
            H::button(id: 'flip', onClick: fn() => $setFlips($flips + 1), children: 'flip'),
            H::input(id: 'carried-input', name: 'carried', type: $flips % 2 === 0 ? 'text' : 'search', value: $carried, onInput: useFormAction(['carried' => $setCarried])),

            H::form(id: 'app', method: 'post', action: '/save', children: [
                H::input(name: 'appfield', value: 'kept'),
                H::button(id: 'action-button', onClick: fn() => $setCount($count + 1), children: "count={$count}"),
                H::button(id: 'app-save', type: 'submit', children: 'save'),
            ]),
        ]);
    }
}

/** Inside an application's form as a whole. */
#[Component(name: 'wrapped')]
final class Wrapped extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::button(id: 'wrapped-button', onClick: fn() => $setCount($count + 1), children: "wrapped={$count}");
    }
}

#[Component(name: 'session-counter')]
final class SessionCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::button(id: 'session-button', onClick: fn() => $setCount($count + 1), children: "count={$count}");
    }
}

#[Component(name: 'persisted', storage: 'snapshot', persist: 'sessionStorage')]
final class Persisted extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::button(onClick: fn() => $setCount($count + 1), children: "count={$count}");
    }
}

$app = new UsePHP();
$app->setSnapshotSecret('browser-test-secret-browser-test-secret');
$app->register(Controls::class);
$app->register(Wrapped::class);
$app->register(SessionCounter::class);
$app->register(Persisted::class);
$router = $app->getRouter();

// ---- Fields around a counter ------------------------------------------------

$router->get('/fields', static fn(): Element => fc(static function (array $props): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::input(id: 'memo', name: 'memo', type: 'text'),
        H::textarea(id: 'note', name: 'note', children: ''),
        H::input(id: 'rendered', name: 'rendered', type: 'text', value: "server-{$count}"),
        H::button(id: 'increment', onClick: fn() => $setCount($count + 1), children: 'increment'),
        H::button(className: 'no-id', onClick: fn() => $setCount($count + 1), children: 'increment'),
        H::span(id: 'count', children: (string) $count),
    ]);
}, 'fields', StorageType::Snapshot)([]));

$router->get('/session-counter', SessionCounter::class);
$router->get('/persisted', Persisted::class);

// ---- Triggers, and components inside an application's form ------------------

$router->get('/controls', static fn(): string => $app->render('controls')
    . $app->renderElement(H::form(id: 'outer', method: 'post', action: '/save', children: [
        H::input(name: 'appfield', value: 'outer'),
        $app->createElement('wrapped'),
        H::button(id: 'outer-save', type: 'submit', children: 'save outer'),
    ])));

// Printing the page leaves nothing to replay, so an action answers with a
// redirect (PRG) even for a request that asked for a partial.
$router->get('/redirecting-input', static function () use ($app): string {
    echo $app->renderElement(fc(static function (array $props): Element {
        [$value, $setValue] = useState('');

        return H::input(id: 'redirecting', name: 'v', value: $value, onInput: useFormAction(['v' => $setValue]));
    }, 'redirecting-input')([]));

    return '';
});

// ---- Parents and children ---------------------------------------------------

$labeled = static fn(string $label, ?string $key = null): Element => fc(static function (array $props): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "{$props['label']}={$count}");
}, $key, StorageType::Snapshot)(['label' => $label]);
$pair = static fn(): Element => H::div(children: [$labeled('a'), $labeled('b')]);

$router->get('/pair', $pair);
$router->get('/pair-session', $pair)->sessionSnapshot();
$router->get('/string-handler', static fn(): string => '<main>' . $app->renderElement($labeled('str', 'str')) . '</main>');

$grandchild = fc(static function (array $props): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "grandchild={$count}");
}, 'grandchild', StorageType::Snapshot);
$child = fc(static function (array $props) use ($grandchild): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::button(onClick: fn() => $setCount($count + 1), children: "child={$count}"),
        $grandchild([]),
    ]);
}, 'child', StorageType::Snapshot);
$router->get('/nested', static fn(): Element => fc(static function (array $props) use ($child): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::button(onClick: fn() => $setCount($count + 1), children: "parent={$count}"),
        $child([]),
    ]);
}, 'parent', StorageType::Snapshot)([]));

// The parent's field decides whether the child is rendered.
$router->get('/field-in-parent', static fn(): Element => fc(static function (array $props) use ($child): Element {
    [$value, $setValue] = useState('');

    return H::div(children: [
        H::input(id: 'field', name: 'v', value: $value, onInput: useFormAction(['v' => $setValue])),
        H::span(id: 'value', children: "value={$value}"),
        $value === 'hide' ? H::p(children: 'no child') : $child([]),
    ]);
}, 'field-parent', StorageType::Snapshot)([]));

$fieldChild = fc(static function (array $props): Element {
    [$value, $setValue] = useState('');

    return H::div(children: [
        H::input(id: 'field', name: 'v', value: $value, onInput: useFormAction(['v' => $setValue])),
        H::span(id: 'value', children: "value={$value}"),
    ]);
}, 'field-child', StorageType::Snapshot);
$router->get('/field-in-child', static fn(): Element => fc(static function (array $props) use ($fieldChild): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::button(onClick: fn() => $setCount($count + 1), children: "parent={$count}"),
        $fieldChild([]),
    ]);
}, 'button-parent', StorageType::Snapshot)([]));

// ---- Deferred components ----------------------------------------------------

$deferred = fc(static function (array $props): Element {
    [$count, $setCount] = useState(0);
    $tag = (string) ($props['tag'] ?? 'none');

    return H::button(onClick: fn() => $setCount($count + 1), children: "deferred={$count} tag={$tag}");
}, 'deferred', StorageType::Snapshot, defer: new Defer('deferred'));
$app->registerComponent('Deferred\\deferred', $deferred);
$app->registerDeferred('deferred', 'Deferred\\deferred');
$router->get('/deferred', static fn(): Element => H::div(children: [
    H::p(children: 'page-chrome'),
    $deferred(['tag' => 7]),
]));

// -----------------------------------------------------------------------------

$isDocument = $_SERVER['REQUEST_METHOD'] === 'GET' && !str_starts_with($path, '/_defer');

ob_start();
if ($isDocument) {
    echo '<!doctype html><meta charset="utf-8">';
}
$app->run();
if ($isDocument) {
    echo UsePHP::renderClientScript();
}
ob_end_flush();
