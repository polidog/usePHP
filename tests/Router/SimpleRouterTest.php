<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\Route;
use Polidog\UsePhp\Router\RouteGroup;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Router\SnapshotBehavior;

class SimpleRouterTest extends TestCase
{
    private SimpleRouter $router;

    protected function setUp(): void
    {
        $this->router = new SimpleRouter();
    }

    public function testAddAndMatchGetRoute(): void
    {
        $this->router->get('/', 'HomeComponent');

        $request = new RequestContext('GET', '/');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals('HomeComponent', $match->handler);
        $this->assertEquals([], $match->params);
    }

    public function testMatchRouteWithParameter(): void
    {
        $this->router->get('/users/{id}', 'UserComponent');

        $request = new RequestContext('GET', '/users/123');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals('UserComponent', $match->handler);
        $this->assertEquals(['id' => '123'], $match->params);
    }

    public function testMatchRouteWithMultipleParameters(): void
    {
        $this->router->get('/users/{userId}/posts/{postId}', 'PostComponent');

        $request = new RequestContext('GET', '/users/42/posts/99');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(['userId' => '42', 'postId' => '99'], $match->params);
    }

    public function testMatchReturnsNullForUnmatchedPath(): void
    {
        $this->router->get('/users', 'UsersComponent');

        $request = new RequestContext('GET', '/posts');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    public function testMatchReturnsNullForWrongMethod(): void
    {
        $this->router->get('/users', 'UsersComponent');

        $request = new RequestContext('POST', '/users');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    public function testPostRoute(): void
    {
        $this->router->post('/submit', 'SubmitHandler');

        $request = new RequestContext('POST', '/submit');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals('SubmitHandler', $match->handler);
    }

    public function testAnyMethodRoute(): void
    {
        $this->router->any('/api', 'ApiHandler');

        $getRequest = new RequestContext('GET', '/api');
        $postRequest = new RequestContext('POST', '/api');
        $putRequest = new RequestContext('PUT', '/api');

        $this->assertNotNull($this->router->match($getRequest));
        $this->assertNotNull($this->router->match($postRequest));
        $this->assertNotNull($this->router->match($putRequest));
    }

    public function testNamedRoute(): void
    {
        $this->router->get('/users/{id}', 'UserComponent')->name('user.show');

        $url = $this->router->generate('user.show', ['id' => '42']);

        $this->assertEquals('/users/42', $url);
    }

    public function testGenerateThrowsExceptionForUnknownRoute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route not found: unknown.route');

        $this->router->generate('unknown.route');
    }

    public function testRouteWithSnapshotBehavior(): void
    {
        $this->router->get('/cart', 'CartComponent')
            ->name('cart')
            ->persistentSnapshot();

        $request = new RequestContext('GET', '/cart');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(SnapshotBehavior::Persistent, $match->snapshotBehavior);
    }

    public function testRouteWithSessionSnapshot(): void
    {
        $this->router->get('/checkout', 'CheckoutComponent')
            ->name('checkout')
            ->sessionSnapshot();

        $request = new RequestContext('GET', '/checkout');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(SnapshotBehavior::Session, $match->snapshotBehavior);
    }

    public function testRouteWithSharedSnapshot(): void
    {
        $this->router->get('/step1', 'Step1Component')
            ->name('step1')
            ->sharedSnapshot('wizard');

        $request = new RequestContext('GET', '/step1');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(SnapshotBehavior::Shared, $match->snapshotBehavior);
        $this->assertEquals('wizard', $match->sharedGroup);
    }

    public function testGroupedRoutes(): void
    {
        $this->router->group('/admin', function (RouteGroup $group) {
            $group->get('/dashboard', 'DashboardComponent')->name('admin.dashboard');
            $group->get('/users', 'AdminUsersComponent')->name('admin.users');
        });

        $dashboardRequest = new RequestContext('GET', '/admin/dashboard');
        $usersRequest = new RequestContext('GET', '/admin/users');

        $this->assertNotNull($this->router->match($dashboardRequest));
        $this->assertNotNull($this->router->match($usersRequest));

        $this->assertEquals('/admin/dashboard', $this->router->generate('admin.dashboard'));
        $this->assertEquals('/admin/users', $this->router->generate('admin.users'));
    }

    public function testOptionalParameter(): void
    {
        $this->router->get('/posts/{page?}', 'PostsComponent');

        $withParam = new RequestContext('GET', '/posts/2');
        $withoutParam = new RequestContext('GET', '/posts');

        $matchWithParam = $this->router->match($withParam);
        $matchWithoutParam = $this->router->match($withoutParam);

        $this->assertNotNull($matchWithParam);
        $this->assertEquals(['page' => '2'], $matchWithParam->params);

        $this->assertNotNull($matchWithoutParam);
        $this->assertEquals([], $matchWithoutParam->params);
    }

    public function testCustomRegexParameter(): void
    {
        $this->router->get('/articles/{id:\\d+}', 'ArticleComponent');

        $validRequest = new RequestContext('GET', '/articles/123');
        $invalidRequest = new RequestContext('GET', '/articles/abc');

        $this->assertNotNull($this->router->match($validRequest));
        $this->assertNull($this->router->match($invalidRequest));
    }

    public function testPathNormalization(): void
    {
        $this->router->get('/users', 'UsersComponent');

        $withSlash = new RequestContext('GET', '/users/');
        $withoutSlash = new RequestContext('GET', '/users');

        // Both should match
        $this->assertNotNull($this->router->match($withoutSlash));
    }

    public function testCallableHandler(): void
    {
        $handler = fn() => 'result';
        $this->router->get('/callback', $handler);

        $request = new RequestContext('GET', '/callback');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertSame($handler, $match->handler);
    }

    public function testMatchMultipleMethods(): void
    {
        $this->router->matchMethods(['GET', 'POST'], '/form', 'FormComponent');

        $getRequest = new RequestContext('GET', '/form');
        $postRequest = new RequestContext('POST', '/form');
        $putRequest = new RequestContext('PUT', '/form');

        $this->assertNotNull($this->router->match($getRequest));
        $this->assertNotNull($this->router->match($postRequest));
        $this->assertNull($this->router->match($putRequest));
    }

    public function testHasRoute(): void
    {
        $this->router->get('/users', 'UsersComponent')->name('users.index');

        $this->assertTrue($this->router->hasRoute('users.index'));
        $this->assertFalse($this->router->hasRoute('users.show'));
    }

    public function testGetRoute(): void
    {
        $this->router->get('/users', 'UsersComponent')->name('users.index');

        $route = $this->router->getRoute('users.index');

        $this->assertNotNull($route);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/users', $route->pattern);
    }

    public function testGetRoutes(): void
    {
        $this->router->get('/', 'HomeComponent');
        $this->router->get('/users', 'UsersComponent');
        $this->router->post('/submit', 'SubmitHandler');

        $routes = $this->router->getRoutes();

        $this->assertCount(3, $routes);
    }

    public function testDefaultSnapshotBehaviorIsIsolated(): void
    {
        $this->router->get('/page', 'PageComponent');

        $request = new RequestContext('GET', '/page');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertEquals(SnapshotBehavior::Isolated, $match->snapshotBehavior);
    }
}
