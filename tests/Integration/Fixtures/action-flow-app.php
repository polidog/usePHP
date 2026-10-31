<?php

declare(strict_types=1);

/**
 * Front controller served by `php -S` in the action flow integration tests.
 *
 * Sessions and CSRF only behave like production when every request runs in
 * its own process, with real cookies and real headers, so these tests drive
 * an actual server. Behavior is selected through environment variables:
 *
 *  - USEPHP_TEST_SECRET: snapshot secret (unset = no secret)
 *  - USEPHP_TEST_SESSION_START: the app calls session_start() itself
 *  - USEPHP_TEST_EARLY_OUTPUT: the app writes output before usePHP runs
 *  - USEPHP_TEST_ERROR_LOG: file that receives what usePHP logs
 */

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentId;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\Snapshot;

use function Polidog\UsePhp\Runtime\useRouter;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

abstract class FixtureCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::button(onClick: fn() => $setCount($count + 1), children: "count={$count}");
    }
}

#[Component]
final class SessionClassCounter extends FixtureCounter {}

#[Component(storage: 'memory')]
final class MemoryClassCounter extends FixtureCounter {}

#[Component(storage: 'snapshot')]
final class SnapshotClassCounter extends FixtureCounter {}

#[Component]
final class TaskDetail extends BaseComponent
{
    public function render(): Element
    {
        $id = useRouter()['params']['id'] ?? '(none)';
        [$likes, $setLikes] = $this->useState(0);

        return H::button(onClick: fn() => $setLikes($likes + 1), children: "task={$id} likes={$likes}");
    }
}

#[Component]
#[Defer(name: 'post-likes')]
final class PostLikes extends BaseComponent
{
    public function render(): Element
    {
        $id = $_GET['post_id'] ?? '(none)';
        [$likes, $setLikes] = $this->useState(0);

        return H::button(onClick: fn() => $setLikes($likes + 1), children: "post={$id} likes={$likes}");
    }
}

#[Component(storage: 'memory')]
final class MemoryTwoStates extends BaseComponent
{
    public function render(): Element
    {
        [$a, $setA] = $this->useState(0);
        [$b, $setB] = $this->useState(0);

        return H::div(children: [
            H::button(onClick: fn() => $setA($a + 1), children: "a={$a}"),
            H::button(onClick: fn() => $setB($b + 1), children: "b={$b}"),
        ]);
    }
}

if (getenv('USEPHP_TEST_SESSION_START') !== false) {
    session_start();
}
if (getenv('USEPHP_TEST_EARLY_OUTPUT') !== false) {
    echo ' ';
}

$errorLog = getenv('USEPHP_TEST_ERROR_LOG');
if ($errorLog !== false) {
    ini_set('log_errors', '1');
    ini_set('error_log', $errorLog);
}

$app = new UsePHP();
$secret = getenv('USEPHP_TEST_SECRET');
if ($secret !== false) {
    $app->setSnapshotSecret($secret);
}
$app->register(SessionClassCounter::class);
$app->register(MemoryClassCounter::class);
$app->register(SnapshotClassCounter::class);
$app->register(TaskDetail::class);
$app->register(MemoryTwoStates::class);
$app->register(PostLikes::class);

$counterFc = static fn(string $key, StorageType $storage) => fc(static function (array $props): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "count={$count}");
}, $key, $storage);

$router = $app->getRouter();
$router->get('/session-class', SessionClassCounter::class);
$router->get('/memory-class', MemoryClassCounter::class);
$router->get('/snapshot-class', SnapshotClassCounter::class);
$router->get('/session-fc', static fn(): Element => $counterFc('session-fc', StorageType::Session)([]));
$router->get('/memory-fc', static fn(): Element => $counterFc('memory-fc', StorageType::Memory)([]));
$router->get('/snapshot-fc', static fn(): Element => $counterFc('snapshot-fc', StorageType::Snapshot)([]));

$router->get('/tasks/{id:\d+}', TaskDetail::class);
$router->get('/session-route', SnapshotClassCounter::class)->sessionSnapshot();
$router->get('/persistent-route', SnapshotClassCounter::class)->persistentSnapshot();
$router->get('/shared-route', SnapshotClassCounter::class)->sharedSnapshot('fixture');
$router->get('/session-counter-on-persistent-route', SessionClassCounter::class)->persistentSnapshot();

$router->get('/tasks-fc/{id:\d+}', static fn(): Element => fc(static function (array $props): Element {
    $id = useRouter()['params']['id'] ?? '(none)';
    [$likes, $setLikes] = useState(0);

    return H::button(onClick: fn() => $setLikes($likes + 1), children: "task={$id} likes={$likes}");
}, 'task-fc')([]));
$router->get('/two-session-fc', static fn(): Element => H::div(children: [
    H::p(children: 'page-chrome'),
    $counterFc('first', StorageType::Session)([]),
    $counterFc('second', StorageType::Session)([]),
]));
$router->get('/session-fc-on-persistent-route', static fn(): Element => $counterFc('on-persistent', StorageType::Session)([]))
    ->persistentSnapshot();
$router->get('/snapshot-fc-on-session-route', static fn(): Element => $counterFc('on-session', StorageType::Snapshot)([]))
    ->sessionSnapshot();
$router->get('/snapshot-fc-on-persistent-route', static fn(): Element => $counterFc('on-persistent-snap', StorageType::Snapshot)([]))
    ->persistentSnapshot();
$router->get('/snapshot-fc-on-shared-route', static fn(): Element => $counterFc('on-shared', StorageType::Snapshot)([]))
    ->sharedSnapshot('fixture');
// A handler that prints its page and returns nothing cannot be replayed.
$router->get('/session-fc-printed', static function () use ($app, $counterFc): string {
    echo $app->renderElement($counterFc('printed', StorageType::Session)([]));

    return '';
});

$router->get('/memory-two-states-class', MemoryTwoStates::class);
$router->get('/memory-two-states-fc', static fn(): Element => fc(static function (array $props): Element {
    [$a, $setA] = useState(0);
    [$b, $setB] = useState(0);

    return H::div(children: [
        H::button(onClick: fn() => $setA($a + 1), children: "a={$a}"),
        H::button(onClick: fn() => $setB($b + 1), children: "b={$b}"),
    ]);
}, 'memory-two-states', StorageType::Memory)([]));

// Several snapshot instances of one component on a page, told apart by label.
$labeled = static fn(string $label, ?string $key = null): Element => fc(static function (array $props): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "{$props['label']}={$count}");
}, $key, StorageType::Snapshot)(['label' => $label]);
$pair = static fn(): Element => H::div(children: [$labeled('a'), $labeled('b')]);

$router->get('/pair-session', $pair)->sessionSnapshot();
$router->get('/pair-persistent', $pair)->persistentSnapshot();
$router->get('/pair-shared-1', $pair)->sharedSnapshot('pair');
$router->get('/pair-shared-2', $pair)->sharedSnapshot('pair');
$router->get('/pair-other-group', $pair)->sharedSnapshot('other');
$router->get('/keyed-session', static fn(): Element => $labeled('keyed', (string) ($_GET['k'] ?? '0')))->sessionSnapshot();

// Writes a value into a session slot directly, standing in for what an older
// release (or a foreign session) left behind. Test fixture only.
$router->get('/plant', static function () use ($app): string {
    session_status() === PHP_SESSION_ACTIVE || session_start();
    $id = ComponentId::fromLegacy((string) ($_GET['id'] ?? ''));
    $snapshot = Snapshot::fromComponentId($id, [(int) ($_GET['value'] ?? 0)]);
    $serializer = $app->getSnapshotSerializer();
    $slot = (string) ($_GET['slot'] ?? '_usephp_snapshot');
    $bound = $serializer->withContext(session_id() ?: '')->serialize($snapshot);

    $_SESSION[$slot] = match ((string) ($_GET['mode'] ?? 'legacy')) {
        'legacy' => $bound,
        'legacy-foreign' => $serializer->withContext('another-session')->serialize($snapshot),
        'legacy-garbage' => 'not a snapshot',
        'not-a-map' => 42,
        'map-foreign' => [$id->toLegacyString() => $serializer->withContext('another-session')->serialize($snapshot)],
        'map-unbound' => [$id->toLegacyString() => $serializer->serialize($snapshot)],
        'map-wrong-key' => [$id->componentName . '#other' => $bound],
        'map-non-string' => [$id->toLegacyString() => ['state' => [99]]],
    };

    return 'planted';
});

// A snapshot parent that re-renders its snapshot descendants.
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
$parent = fc(static function (array $props) use ($child): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::button(onClick: fn() => $setCount($count + 1), children: "parent={$count}"),
        // Odd counts of a hideable parent leave the child out.
        ($props['hideable'] ?? false) && $count % 2 === 1 ? H::p(children: 'no child') : $child([]),
    ]);
}, 'parent', StorageType::Snapshot);
$sessionParent = fc(static function (array $props) use ($child): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::button(onClick: fn() => $setCount($count + 1), children: "session-parent={$count}"),
        $child([]),
    ]);
}, 'session-parent', StorageType::Session);

$router->get('/nested', static fn(): Element => $parent([]));
$router->get('/nested-hideable', static fn(): Element => $parent(['hideable' => true]));
$router->get('/nested-session-parent', static fn(): Element => $sessionParent([]));
$router->get('/nested-with-sibling', static fn(): Element => H::div(children: [
    $parent([]),
    $labeled('sibling', 'sibling'),
]));
$router->get('/nested-on-session-route', static fn(): Element => $parent([]))->sessionSnapshot();

$app->run();
