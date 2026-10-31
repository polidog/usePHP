<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #111 over real requests. A name the application wrote is a bug when it doesn't
 * resolve, while a name from a request is only a bad request.
 */
final class ComponentNameResolutionServerTest extends ActionFlowServerTestCase
{
    private const APP = __DIR__ . '/Fixtures/component-name-app.php';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'acting on the page reached by class' => ['/by-class', '/by-name'];
        yield 'acting on the page reached by name' => ['/by-name', '/by-class'];
    }

    #[DataProvider('pageProvider')]
    public function testThePartialUpdateSharesStateBetweenClassAndName(string $page, string $other): void
    {
        $base = $this->startServer(router: self::APP);

        $first = $this->http($base . $page);
        self::assertSame(200, $first['status'], $first['body']);
        self::assertStringContainsString('data-usephp="CustomBoard#k"', $first['body']);
        self::assertNotNull($first['cookie']);

        $partial = $this->http(
            $base . $page,
            self::firstFormFields($first['body']),
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
            $first['cookie'],
        );
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('count=1', $partial['body']);

        $elsewhere = $this->http($base . $other, cookie: $first['cookie']);
        self::assertStringContainsString('data-usephp="CustomBoard#k"', $elsewhere['body']);
        self::assertStringContainsString('count=1', $elsewhere['body']);
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function requestedNameProvider(): iterable
    {
        yield 'unknown defer name' => ['/_defer/nosuch', 404];
        yield 'a component name is not a defer name' => ['/_defer/CustomBoard', 404];
        yield 'a class is not a defer name' => ['/_defer/CustomNameBoard', 404];
        yield 'not a valid defer name' => ['/_defer/Custom%5CBoard', 404];
        yield 'array parameter' => ['/_defer/unregistered-target?x[]=1', 400];
    }

    #[DataProvider('requestedNameProvider')]
    public function testANameFromTheDeferUrlIsNotAServerError(string $path, int $status): void
    {
        $base = $this->startServer(router: self::APP);

        self::assertSame($status, $this->http($base . $path)['status']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function postedInstanceProvider(): iterable
    {
        yield 'unknown name' => ['NoSuch#0'];
        yield 'the class instead of the name' => ['CustomNameBoard#k'];
    }

    #[DataProvider('postedInstanceProvider')]
    public function testANameFromAnActionIsNotAServerError(string $instanceId): void
    {
        $base = $this->startServer(router: self::APP);
        $page = $this->http($base . '/by-class');

        $response = $this->http(
            $base . '/by-class',
            ['_usephp_component' => $instanceId] + self::firstFormFields($page['body']),
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
            $page['cookie'],
        );

        self::assertSame(400, $response['status'], $response['body']);
    }

    public function testANameTheApplicationWroteIsAServerError(): void
    {
        $base = $this->startServer(router: self::APP);

        self::assertSame(500, $this->http($base . '/typo')['status']);
    }

    /**
     * The URL is fine, the registration isn't. The client learns nothing about it.
     */
    public function testADeferTargetThatWasNeverRegisteredFailsWithoutDetails(): void
    {
        $base = $this->startServer(router: self::APP);

        $response = $this->http($base . '/_defer/unregistered-target');

        self::assertSame(500, $response['status'], $response['body']);
        self::assertSame('Failed to render deferred component.', $response['body']);
    }
}
