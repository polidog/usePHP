<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Router\NullRouter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\RouteMatch;
use Polidog\UsePhp\Router\SnapshotBehavior;

class NullRouterTest extends TestCase
{
    public function testMatchReturnsNullByDefault(): void
    {
        $router = new NullRouter();
        $request = new RequestContext('GET', '/');

        $this->assertNull($router->match($request));
    }

    public function testMatchReturnsConfiguredMatch(): void
    {
        $match = new RouteMatch(
            handler: 'TestComponent',
            params: ['id' => '123'],
        );
        $router = new NullRouter($match);
        $request = new RequestContext('GET', '/');

        $result = $router->match($request);

        $this->assertSame($match, $result);
    }

    public function testSetCurrentMatch(): void
    {
        $router = new NullRouter();
        $match = new RouteMatch(handler: 'TestComponent');

        $router->setCurrentMatch($match);

        $request = new RequestContext('GET', '/');
        $result = $router->match($request);

        $this->assertSame($match, $result);
    }

    public function testForComponent(): void
    {
        $router = NullRouter::forComponent(
            'CartComponent',
            SnapshotBehavior::Persistent,
        );

        $request = new RequestContext('GET', '/');
        $match = $router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals('CartComponent', $match->handler);
        $this->assertEquals(SnapshotBehavior::Persistent, $match->snapshotBehavior);
    }

    public function testExtractSnapshotFromQueryParam(): void
    {
        $router = new NullRouter();
        $request = new RequestContext(
            method: 'GET',
            path: '/',
            query: ['_snapshot' => 'encoded_data'],
        );

        $this->assertEquals('encoded_data', $router->extractSnapshot($request));
    }

    public function testExtractSnapshotFromAlternateParam(): void
    {
        $router = new NullRouter();
        $request = new RequestContext(
            method: 'GET',
            path: '/',
            query: ['snapshot' => 'alternate_data'],
        );

        $this->assertEquals('alternate_data', $router->extractSnapshot($request));
    }

    public function testExtractSnapshotReturnsNullWhenNotPresent(): void
    {
        $router = new NullRouter();
        $request = new RequestContext('GET', '/');

        $this->assertNull($router->extractSnapshot($request));
    }

    public function testCreateRedirectUrlDoesNotModifyUrl(): void
    {
        $router = new NullRouter();

        $url = $router->createRedirectUrl('/target');

        $this->assertEquals('/target', $url);
    }

    public function testAddRouteReturnsNoOpBuilder(): void
    {
        $router = new NullRouter();

        // Should not throw, but also should not register anything
        $builder = $router->addRoute('GET', '/test', 'TestHandler');

        // Call builder methods (they should be no-op)
        $builder->persistentSnapshot();

        // Route should not be registered
        $request = new RequestContext('GET', '/test');
        $this->assertNull($router->match($request));
    }

    public function testGetCurrentUrl(): void
    {
        $router = new NullRouter();
        $request = new RequestContext(
            method: 'GET',
            path: '/users',
            queryString: 'page=1',
        );

        $router->match($request);

        $this->assertEquals('/users?page=1', $router->getCurrentUrl());
    }
}
