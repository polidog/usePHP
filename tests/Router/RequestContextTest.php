<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Router\RequestContext;

class RequestContextTest extends TestCase
{
    public function testConstructor(): void
    {
        $request = new RequestContext(
            method: 'GET',
            path: '/users',
            queryString: 'page=1&limit=10',
            query: ['page' => '1', 'limit' => '10'],
            post: [],
            headers: ['content-type' => 'text/html'],
        );

        $this->assertEquals('GET', $request->method);
        $this->assertEquals('/users', $request->path);
        $this->assertEquals('page=1&limit=10', $request->queryString);
        $this->assertEquals(['page' => '1', 'limit' => '10'], $request->query);
        $this->assertEquals([], $request->post);
        $this->assertEquals(['content-type' => 'text/html'], $request->headers);
    }

    public function testIsPost(): void
    {
        $getRequest = new RequestContext('GET', '/');
        $postRequest = new RequestContext('POST', '/');

        $this->assertFalse($getRequest->isPost());
        $this->assertTrue($postRequest->isPost());
    }

    public function testIsPartialRequestWithXRequestedWith(): void
    {
        $normalRequest = new RequestContext('GET', '/', headers: []);
        $ajaxRequest = new RequestContext('GET', '/', headers: [
            'x-requested-with' => 'XMLHttpRequest',
        ]);

        $this->assertFalse($normalRequest->isPartialRequest());
        $this->assertTrue($ajaxRequest->isPartialRequest());
    }

    public function testIsPartialRequestWithUsephpHeader(): void
    {
        $request = new RequestContext('GET', '/', headers: [
            'x-usephp-partial' => '1',
        ]);

        $this->assertTrue($request->isPartialRequest());
    }

    public function testIsPartialRequestWithAcceptHeader(): void
    {
        $request = new RequestContext('GET', '/', headers: [
            'accept' => 'application/usephp-partial',
        ]);

        $this->assertTrue($request->isPartialRequest());
    }

    public function testGetQuery(): void
    {
        $request = new RequestContext(
            method: 'GET',
            path: '/',
            query: ['foo' => 'bar', 'baz' => 'qux'],
        );

        $this->assertEquals('bar', $request->getQuery('foo'));
        $this->assertEquals('qux', $request->getQuery('baz'));
        $this->assertNull($request->getQuery('missing'));
        $this->assertEquals('default', $request->getQuery('missing', 'default'));
    }

    public function testGetPost(): void
    {
        $request = new RequestContext(
            method: 'POST',
            path: '/',
            post: ['username' => 'john', 'password' => 'secret'],
        );

        $this->assertEquals('john', $request->getPost('username'));
        $this->assertEquals('secret', $request->getPost('password'));
        $this->assertNull($request->getPost('missing'));
        $this->assertEquals('default', $request->getPost('missing', 'default'));
    }

    public function testGetHeader(): void
    {
        $request = new RequestContext(
            method: 'GET',
            path: '/',
            headers: [
                'content-type' => 'application/json',
                'accept' => 'text/html',
            ],
        );

        $this->assertEquals('application/json', $request->getHeader('content-type'));
        $this->assertEquals('application/json', $request->getHeader('Content-Type'));
        $this->assertEquals('text/html', $request->getHeader('accept'));
        $this->assertNull($request->getHeader('x-custom'));
        $this->assertEquals('default', $request->getHeader('x-custom', 'default'));
    }

    public function testWithQuery(): void
    {
        $original = new RequestContext(
            method: 'GET',
            path: '/users',
            query: ['page' => '1'],
        );

        $modified = $original->withQuery(['page' => '2', 'limit' => '20']);

        // Original unchanged
        $this->assertEquals(['page' => '1'], $original->query);

        // New request has updated query
        $this->assertEquals(['page' => '2', 'limit' => '20'], $modified->query);
        $this->assertEquals('page=2&limit=20', $modified->queryString);
    }

    public function testGetUrl(): void
    {
        $requestWithQuery = new RequestContext(
            method: 'GET',
            path: '/users',
            queryString: 'page=1&limit=10',
        );

        $requestWithoutQuery = new RequestContext(
            method: 'GET',
            path: '/users',
        );

        $this->assertEquals('/users?page=1&limit=10', $requestWithQuery->getUrl());
        $this->assertEquals('/users', $requestWithoutQuery->getUrl());
    }
}
