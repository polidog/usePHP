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
 */

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
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

if (getenv('USEPHP_TEST_SESSION_START') !== false) {
    session_start();
}
if (getenv('USEPHP_TEST_EARLY_OUTPUT') !== false) {
    echo ' ';
}

$app = new UsePHP();
$secret = getenv('USEPHP_TEST_SECRET');
if ($secret !== false) {
    $app->setSnapshotSecret($secret);
}
$app->register(SessionClassCounter::class);
$app->register(MemoryClassCounter::class);
$app->register(SnapshotClassCounter::class);

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

$router->get('/session-route', SnapshotClassCounter::class)->sessionSnapshot();
$router->get('/persistent-route', SnapshotClassCounter::class)->persistentSnapshot();
$router->get('/shared-route', SnapshotClassCounter::class)->sharedSnapshot('fixture');
$router->get('/session-counter-on-persistent-route', SessionClassCounter::class)->persistentSnapshot();

$app->run();
