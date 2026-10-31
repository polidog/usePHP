<?php

declare(strict_types=1);

/**
 * Front controller for ComponentNameResolutionServerTest: a component with a
 * custom name, reached both by its class and by that name.
 */

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\UsePHP;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

#[Component(name: 'CustomBoard')]
final class CustomNameBoard extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::button(onClick: fn() => $setCount($count + 1), children: "count={$count}");
    }
}

final class NeverRegisteredBoard extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'never registered');
    }
}

$app = new UsePHP();
$app->register(CustomNameBoard::class);
$app->registerDeferred('unregistered-target', NeverRegisteredBoard::class);

$router = $app->getRouter();
$router->get('/by-class', static fn(): Element => $app->createElement(CustomNameBoard::class, 'k'));
$router->get('/by-name', static fn(): Element => $app->createElement('CustomBoard', 'k'));
$router->get('/typo', static fn(): Element => $app->createElement('CustomBord'));

$app->run();
