<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Drives `tests/Integration/Fixtures/action-flow-app.php` under a real
 * `php -S`, so every request gets its own process, cookies and headers.
 */
abstract class ActionFlowServerTestCase extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    private ?string $sessionDir = null;

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        $this->servers = [];

        if ($this->sessionDir !== null) {
            foreach (glob($this->sessionDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->sessionDir);
            $this->sessionDir = null;
        }
    }

    /**
     * Start a server and return its base URL.
     *
     * @param array<string, string> $env
     */
    protected function startServer(array $env = [], string $router = __DIR__ . '/Fixtures/action-flow-app.php'): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $name = stream_socket_get_name($probe, false);
        self::assertIsString($name);
        fclose($probe);

        if ($this->sessionDir === null) {
            $this->sessionDir = sys_get_temp_dir() . '/usephp-action-flow-' . bin2hex(random_bytes(6));
            mkdir($this->sessionDir);
        }

        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'session.save_path=' . $this->sessionDir,
                '-d', 'display_errors=0',
                '-d', 'log_errors=0',
                '-S', $name,
                $router,
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env + getenv(),
        );
        self::assertIsResource($process);
        $this->servers[] = $process;

        for ($i = 0; $i < 200; $i++) {
            $socket = @stream_socket_client('tcp://' . $name, $errno, $errstr, 0.05);
            if ($socket !== false) {
                fclose($socket);

                return 'http://' . $name;
            }
            usleep(10_000);
        }
        self::fail('php -S did not start on ' . $name);
    }

    /**
     * @param array<string, string>|null $post Form body; null sends a GET.
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string, cookie: ?string}
     */
    protected function http(string $url, ?array $post = null, array $headers = [], ?string $cookie = null): array
    {
        if ($cookie !== null) {
            $headers['Cookie'] = $cookie;
        }
        if ($post !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $body = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $post === null ? '' : http_build_query($post),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]));
        self::assertIsString($body);

        $responseHeaders = http_get_last_response_headers() ?? [];
        self::assertSame(1, preg_match('/^HTTP\/\S+ (\d+)/', $responseHeaders[0] ?? '', $status));

        $setCookie = null;
        foreach ($responseHeaders as $line) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $line, $m) === 1) {
                $setCookie = $m[1];
            }
        }

        return ['status' => (int) $status[1], 'headers' => $responseHeaders, 'body' => $body, 'cookie' => $setCookie];
    }

    /**
     * Field values of the first usePHP form in rendered HTML, the way the
     * browser would submit them.
     *
     * @return array<string, string>
     */
    protected static function firstFormFields(string $html): array
    {
        self::assertSame(1, preg_match('/<form\b.*?<\/form>/s', $html, $form), 'no form in: ' . $html);
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form[0], $inputs, PREG_SET_ORDER);

        $fields = [];
        foreach ($inputs as $input) {
            $fields[$input[1]] = html_entity_decode($input[2], ENT_QUOTES, 'UTF-8');
        }

        return $fields;
    }
}
