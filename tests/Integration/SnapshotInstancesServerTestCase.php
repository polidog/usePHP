<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Integration;

/**
 * Pages holding several snapshot instances, driven the way a browser does:
 * find the form behind a button, post it, follow the redirect.
 */
abstract class SnapshotInstancesServerTestCase extends ActionFlowServerTestCase
{
    protected const SECRET_VALUE = 'integration-secret';

    protected const SECRET = ['USEPHP_TEST_SECRET' => self::SECRET_VALUE];

    protected const PARTIAL = ['X-UsePHP-Partial' => '1'];

    /** @var list<string> */
    private array $logFiles = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->logFiles as $file) {
            @unlink($file);
        }
        $this->logFiles = [];
    }

    /**
     * Fields of the form holding the button whose text starts with $label.
     * Fields named `x[]` come back as a list under `x`.
     *
     * @return array<string, mixed>
     */
    protected static function formFor(string $html, string $label): array
    {
        preg_match_all('/<form\b.*?<\/form>/s', $html, $forms);
        foreach ($forms[0] as $form) {
            if (!str_contains($form, '>' . $label . '=')) {
                continue;
            }
            preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form, $inputs, PREG_SET_ORDER);
            $fields = [];
            foreach ($inputs as $input) {
                $value = html_entity_decode($input[2], ENT_QUOTES, 'UTF-8');
                if (str_ends_with($input[1], '[]')) {
                    $fields[substr($input[1], 0, -2)][] = $value;
                } else {
                    $fields[$input[1]] = $value;
                }
            }

            return $fields;
        }
        self::fail("no form for {$label} in: {$html}");
    }

    /**
     * @param array<string, mixed> $extra Added to the form's fields
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string, cookie: ?string}
     */
    protected function press(
        string $base,
        string $path,
        string $html,
        string $label,
        ?string $cookie,
        array $headers = [],
        array $extra = [],
    ): array {
        return $this->post($base . $path, $extra + self::formFor($html, $label), ['Origin' => $base] + $headers, $cookie);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string, cookie: ?string}
     */
    protected function post(string $url, array $fields, array $headers, ?string $cookie): array
    {
        /** @var array<string, string> $fields http_build_query() takes the nested lists as well */
        return $this->http($url, $fields, $headers, $cookie);
    }

    /**
     * Press a button on a redirecting route and return the page it lands on.
     */
    protected function pressAndFollow(string $base, string $path, string $html, string $label, ?string $cookie): string
    {
        $response = $this->press($base, $path, $html, $label, $cookie);
        self::assertSame(303, $response['status'], $response['body']);
        $target = self::locationOf($response);
        self::assertNotNull($target);

        return $this->http($base . $target, cookie: $cookie)['body'];
    }

    /**
     * @param array{headers: list<string>} $response
     */
    protected static function locationOf(array $response): ?string
    {
        foreach ($response['headers'] as $line) {
            if (preg_match('/^Location:\s*(.+)$/i', $line, $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * The `data-usephp` ids in rendered HTML, in document order.
     *
     * @return list<string>
     */
    protected static function instanceIds(string $html): array
    {
        preg_match_all('/data-usephp="([^"]+)"/', $html, $m);

        return array_map(static fn(string $id): string => html_entity_decode($id, ENT_QUOTES, 'UTF-8'), $m[1]);
    }

    /**
     * The `data-usephp-snapshot` values in rendered HTML, in document order.
     * This is what usephp.js reads off the DOM.
     *
     * @return list<string>
     */
    protected static function wrapperSnapshots(string $html): array
    {
        preg_match_all('/data-usephp-snapshot=(?:"([^"]*)"|\'([^\']*)\')/', $html, $m, PREG_SET_ORDER);

        return array_map(
            static fn(array $match): string => html_entity_decode(($match[2] ?? '') !== '' ? $match[2] : $match[1], ENT_QUOTES, 'UTF-8'),
            $m,
        );
    }

    protected function errorLogFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'usephp-log-');
        self::assertIsString($file);
        $this->logFiles[] = $file;

        return $file;
    }
}
