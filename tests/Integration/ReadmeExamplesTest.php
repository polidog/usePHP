<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Polidog\UsePhp\UsePHP;

/**
 * Runs the code in the READMEs, read from the READMEs, so the two can't drift:
 * the quick start as the files it describes, and each block marked
 * `<!-- readme-test: name -->` inside a small app.
 */
final class ReadmeExamplesTest extends ActionFlowServerTestCase
{
    private const REPOSITORY = __DIR__ . '/../..';

    private ?string $appDir = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->appDir !== null) {
            self::removeTree($this->appDir);
            $this->appDir = null;
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function readmeProvider(): iterable
    {
        yield 'README.md' => ['README.md'];
        yield 'README.ja.md' => ['README.ja.md'];
    }

    #[DataProvider('readmeProvider')]
    public function testTheQuickStartRuns(string $readme): void
    {
        $base = $this->startApp(self::quickStartFiles($readme), 'public/index.php');

        $page = $this->http($base . '/');
        self::assertSame(200, $page['status'], $page['body']);
        self::assertStringStartsWith('<!DOCTYPE html>', $page['body']);
        self::assertStringContainsString('<script src="/usephp.js" defer>', $page['body']);
        self::assertStringContainsString('Count: 0', $page['body']);
        $cookie = $page['cookie'];
        self::assertNotNull($cookie);

        $partial = $this->http(
            $base . '/',
            self::firstFormFields($page['body']),
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
            $cookie,
        );
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('Count: 1', $partial['body']);
        self::assertStringNotContainsString('<html', $partial['body']);

        $reloaded = $this->http($base . '/', null, [], $cookie);
        self::assertStringContainsString('Count: 1', $reloaded['body']);

        $plain = $this->http($base . '/', self::firstFormFields($reloaded['body']), ['Origin' => $base], $cookie);
        self::assertSame(303, $plain['status'], $plain['body']);
        self::assertStringContainsString('Count: 2', $this->http($base . '/', null, [], $cookie)['body']);

        $about = $this->http($base . '/about');
        self::assertSame(200, $about['status'], $about['body']);
        self::assertStringContainsString('A usePHP app.', $about['body']);

        $script = $this->http($base . '/usephp.js');
        self::assertSame(200, $script['status']);
        self::assertStringContainsString('usePHP', $script['body']);
    }

    /**
     * The comments are translated. The code isn't.
     */
    public function testBothReadmesShowTheSameCode(): void
    {
        $code = static fn(string $readme): array => array_map(self::withoutComments(...), self::quickStartFiles($readme));
        self::assertSame($code('README.md'), $code('README.ja.md'));

        foreach (['route-key-fc', 'route-key-class', 'middleware', 'form-input'] as $name) {
            self::assertSame(
                self::withoutComments('<?php ' . self::markedBlock('README.md', $name)),
                self::withoutComments('<?php ' . self::markedBlock('README.ja.md', $name)),
                $name,
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function routeKeyProvider(): iterable
    {
        foreach (['README.md', 'README.ja.md'] as $readme) {
            yield "{$readme}: function component" => [$readme, 'route-key-fc'];
            yield "{$readme}: class component" => [$readme, 'route-key-class'];
        }
    }

    /**
     * #92: each record has its own state, and an action updates in place.
     */
    #[DataProvider('routeKeyProvider')]
    public function testARouteParameterInTheKeySeparatesTheState(string $readme, string $block): void
    {
        $base = $this->startMarkedBlock($readme, $block);

        $first = $this->http($base . '/tasks/1');
        self::assertSame(200, $first['status'], $first['body']);
        self::assertStringContainsString('Task 1: 0 likes', $first['body']);
        $cookie = $first['cookie'];
        self::assertNotNull($cookie);

        $partial = $this->http(
            $base . '/tasks/1',
            self::firstFormFields($first['body']),
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
            $cookie,
        );
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('Task 1: 1 likes', $partial['body']);

        self::assertStringContainsString('Task 1: 1 likes', $this->http($base . '/tasks/1', null, [], $cookie)['body']);
        self::assertStringContainsString('Task 2: 0 likes', $this->http($base . '/tasks/2', null, [], $cookie)['body']);
    }

    #[DataProvider('readmeProvider')]
    public function testTheMiddlewareExampleGuardsItsRoute(string $readme): void
    {
        $base = $this->startMarkedBlock($readme, 'middleware');

        $response = $this->http($base . '/account');

        self::assertSame(403, $response['status'], $response['body']);
        self::assertStringNotContainsString('Your account', $response['body']);
    }

    #[DataProvider('readmeProvider')]
    public function testTheFormInputExampleKeepsTheSubmittedValue(string $readme): void
    {
        $base = $this->startMarkedBlock($readme, 'form-input');

        $page = $this->http($base . '/profile');
        self::assertSame(200, $page['status'], $page['body']);

        $partial = $this->http(
            $base . '/profile',
            ['name' => 'Ada'] + self::firstFormFields($page['body']),
            ['Origin' => $base, 'X-UsePHP-Partial' => '1'],
            $page['cookie'],
        );

        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('Saved: Ada', $partial['body']);
    }

    /**
     * #98: every `UsePHP::xxx()` in the docs has to be callable that way.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function documentProvider(): iterable
    {
        yield 'README.md' => ['README.md'];
        yield 'README.ja.md' => ['README.ja.md'];
        yield 'docs/PSX.md' => ['docs/PSX.md'];
    }

    #[DataProvider('documentProvider')]
    public function testDocumentedStaticCallsAreStatic(string $document): void
    {
        $text = self::read($document);
        preg_match_all('/\bUsePHP::([a-z]\w*)\(?/', $text, $calls);

        $class = new \ReflectionClass(UsePHP::class);
        foreach (array_unique($calls[1]) as $method) {
            self::assertTrue($class->hasMethod($method), "UsePHP::{$method}() does not exist");
            self::assertTrue($class->getMethod($method)->isStatic(), "UsePHP::{$method}() is an instance method");
        }
    }

    /**
     * #98: these were documented without existing. Each is decided: not implemented.
     */
    #[DataProvider('readmeProvider')]
    public function testRemovedApiStaysOutOfTheReadme(string $readme): void
    {
        $text = self::read($readme);

        foreach (['->name(', '->generate(', "['navigate']", "['isActive']", '`navigate', '`isActive', '~40'] as $gone) {
            self::assertStringNotContainsString($gone, $text);
        }
    }

    /**
     * Every `// path` block of the quick start section, keyed by path.
     *
     * @return array<string, string>
     */
    private static function quickStartFiles(string $readme): array
    {
        self::assertSame(
            1,
            preg_match('/^## (?:Quick Start|クイックスタート)\n(.*?)^## /ms', self::read($readme), $section),
            'no quick start section',
        );
        preg_match_all('/^```php\n(<\?php\n\/\/ (\S+)\n.*?)^```$/ms', $section[1], $blocks, PREG_SET_ORDER);

        $files = [];
        foreach ($blocks as $block) {
            $files[$block[2]] = $block[1];
        }
        self::assertSame(['components/Counter.php', 'public/index.php'], array_keys($files));

        return $files;
    }

    private static function markedBlock(string $readme, string $name): string
    {
        self::assertSame(
            1,
            preg_match('/^<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```php\n(.*?)^```$/ms', self::read($readme), $block),
            "no block marked {$name} in {$readme}",
        );

        return $block[1];
    }

    /**
     * Start a marked block in an app that gives it the `$app` and `$router`
     * the README assumes.
     */
    private function startMarkedBlock(string $readme, string $name): string
    {
        $source = "<?php\n"
            . "require __DIR__ . '/vendor/autoload.php';\n"
            . "\$app = new \\Polidog\\UsePhp\\UsePHP();\n"
            . "\$router = \$app->getRouter();\n"
            . self::markedBlock($readme, $name)
            . "\$app->run();\n";

        return $this->startApp(['app.php' => $source], 'app.php');
    }

    /**
     * @param array<string, string> $files Relative path => content
     */
    private function startApp(array $files, string $router): string
    {
        $this->appDir = sys_get_temp_dir() . '/usephp-readme-' . bin2hex(random_bytes(6));

        foreach ($files as $path => $content) {
            $target = $this->appDir . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }
            file_put_contents($target, $content);
        }

        // What `composer require` and `usephp publish` would leave behind.
        symlink((string) realpath(self::REPOSITORY . '/vendor'), $this->appDir . '/vendor');
        if (is_dir($this->appDir . '/public')) {
            copy(self::REPOSITORY . '/public/usephp.js', $this->appDir . '/public/usephp.js');
        }

        return $this->startServer([], $this->appDir . '/' . $router);
    }

    private static function read(string $document): string
    {
        $text = file_get_contents(self::REPOSITORY . '/' . $document);
        self::assertIsString($text);

        return $text;
    }

    private static function withoutComments(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_string($token)) {
                $code .= $token;
            } elseif ($token[0] === T_WHITESPACE) {
                $code .= ' ';
            } elseif ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $code .= $token[1];
            }
        }

        return (string) preg_replace('/\s+/', ' ', $code);
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
