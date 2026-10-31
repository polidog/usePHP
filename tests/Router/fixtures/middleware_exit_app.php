<?php

declare(strict_types=1);

/*
 * Front controller for RouteMiddlewareTest's exit scenarios: a guard that calls
 * `exit` ends the process, so it can't run inside PHPUnit.
 *
 * Usage: php middleware_exit_app.php <scenario>
 * The shutdown function reports whether the protected handler ran.
 */

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\UsePHP;

require __DIR__ . '/../../../vendor/autoload.php';

$scenario = $argv[1] ?? '';

$handlerRan = false;
register_shutdown_function(static function () use (&$handlerRan): void {
    echo "\nhandler-ran=" . ($handlerRan ? 'yes' : 'no') . "\n";
});

$app = new UsePHP();

$guard = match ($scenario) {
    'get-exit', 'action-exit', 'head-exit' => static function (): void {
        exit('blocked by middleware');
    },
    'get-redirect' => static function () use ($app): void {
        $app->redirect('/login');
    },
    'get-allow' => static function (): void {},
    default => throw new InvalidArgumentException("Unknown scenario: {$scenario}"),
};

$app->getRouter()->get('/admin', static function () use (&$handlerRan): Element {
    $handlerRan = true;

    return H::p(children: 'secret');
})->middleware($guard);

if ($scenario === 'action-exit') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/admin';
    $_POST = ['_usephp_component' => 'Counter#0', '_usephp_action' => '{"type":"restore","payload":[]}'];
    $app->run(new RequestContext('POST', '/admin'));
} else {
    $app->run(new RequestContext($scenario === 'head-exit' ? 'HEAD' : 'GET', '/admin'));
}
