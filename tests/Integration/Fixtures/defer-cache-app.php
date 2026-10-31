<?php

declare(strict_types=1);

/**
 * Front controller for the shared-cache safety tests of the defer endpoint.
 * `/_defer/{name}` is the only thing it serves; every endpoint but `private-*`
 * and `static-max-age` asks for `public, s-maxage=60`.
 *
 *  - USEPHP_TEST_SECRET: snapshot secret (unset = no secret)
 *  - USEPHP_TEST_SESSION_START: the app calls session_start() itself
 *  - USEPHP_TEST_SESSION_CLOSED: the app also calls session_write_close()
 *  - USEPHP_TEST_EARLY_OUTPUT: the app flushes output before usePHP runs
 *  - USEPHP_TEST_CACHE_LIMITER: session.cache_limiter, `none` for an empty one
 *    (proc_open() does not pass an empty variable on)
 *  - USEPHP_TEST_ERROR_LOG: file that receives what usePHP logs
 */

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;
use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

#[Component(storage: 'session')]
#[Defer(name: 'session-class', cacheControl: 'public, s-maxage=60')]
final class PublicDeferSessionCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::button(onClick: fn() => $setCount($count + 1), children: "count={$count}");
    }
}

$limiter = getenv('USEPHP_TEST_CACHE_LIMITER');
if ($limiter !== false) {
    ini_set('session.cache_limiter', $limiter === 'none' ? '' : $limiter);
}
$errorLog = getenv('USEPHP_TEST_ERROR_LOG');
if ($errorLog !== false) {
    ini_set('log_errors', '1');
    ini_set('error_log', $errorLog);
}
if (getenv('USEPHP_TEST_SESSION_START') !== false) {
    session_start();
    if (getenv('USEPHP_TEST_SESSION_CLOSED') !== false) {
        session_write_close();
    }
}
if (getenv('USEPHP_TEST_EARLY_OUTPUT') !== false) {
    echo ' ';
    flush();
}

$app = new UsePHP();
$secret = getenv('USEPHP_TEST_SECRET');
if ($secret !== false) {
    $app->setSnapshotSecret($secret);
}
$app->register(PublicDeferSessionCounter::class);

$endpoint = static function (string $name, callable $render, StorageType $storage, string $policy = 'public, s-maxage=60') use ($app): void {
    $component = fc($render(...), $name, $storage, defer: new Defer($name, cacheControl: $policy));
    $app->registerComponent('Deferred\\' . $name, $component);
    $app->registerDeferred($name, 'Deferred\\' . $name, $policy);
};
$counter = static function (array $props): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "count={$count}");
};
$staticBody = static fn(array $props): Element => H::p(children: 'static');

$endpoint('static', $staticBody, StorageType::Memory);
$endpoint('static-max-age', $staticBody, StorageType::Memory, 'max-age=60');
$endpoint('session-fc', $counter, StorageType::Session);
$endpoint('button-snapshot', $counter, StorageType::Snapshot);
$endpoint('button-memory', $counter, StorageType::Memory);
$endpoint('private-session', $counter, StorageType::Session, 'private, no-store');
$endpoint('app-cookie', static function (array $props): Element {
    setcookie('flavor', 'plain');

    return H::p(children: 'static');
}, StorageType::Memory);
$endpoint('reads-then-closes', static function (array $props): Element {
    session_start();
    $seen = isset($_SESSION['seen']);
    session_write_close();

    return H::p(children: $seen ? 'seen' : 'new');
}, StorageType::Memory);

$app->run();
