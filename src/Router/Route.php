<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

/**
 * Internal route definition used by the router.
 */
final readonly class Route
{
    public string $regex;

    /** @var array<string> */
    public array $paramNames;

    /**
     * @param array<callable|string> $middleware
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public mixed $handler,
        public SnapshotBehavior $snapshotBehavior = SnapshotBehavior::Isolated,
        public ?string $sharedGroup = null,
        public array $middleware = [],
    ) {
        [$this->regex, $this->paramNames] = $this->compilePattern($pattern);
    }

    /**
     * Compile a route pattern into a regex.
     *
     * Supports:
     * - {param} - Required parameter
     * - {param?} - Optional parameter (together with the slash in front of it)
     * - {param:regex} / {param?:regex} - Parameter with custom regex
     *
     * Everything outside those tokens is literal text: `/file.txt` matches
     * only `/file.txt`, not `/fileXtxt`. Use a custom-regex parameter when a
     * segment really needs regex behavior.
     *
     * @return array{0: string, 1: array<string>}
     */
    private function compilePattern(string $pattern): array
    {
        // With DELIM_CAPTURE, odd indexes are parameter tokens and even
        // indexes are the literal text between them.
        $parts = preg_split(
            '#(/\{\w+\?(?::[^}]+)?\}|\{\w+(?::[^}]+)?\})#',
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [$pattern];

        $regex = '';
        $paramNames = [];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $regex .= preg_quote($part, '/');
                continue;
            }

            $optional = str_starts_with($part, '/');
            $inner = substr($part, $optional ? 2 : 1, -1);
            [$name, $customRegex] = explode(':', $inner, 2) + [1 => '[^/]+'];
            $name = rtrim($name, '?');
            $paramNames[] = $name;

            // The custom regex is author-supplied regex, so only the pattern
            // delimiter needs escaping (unless it already is).
            $group = "(?P<{$name}>" . preg_replace('#(?<!\\\\)/#', '\\/', $customRegex) . ')';

            $regex .= $optional ? '(?:\\/' . $group . ')?' : $group;
        }

        return ['/^' . $regex . '$/', $paramNames];
    }


    /**
     * Try to match this route against a path.
     *
     * @return array<string, string>|null Parameters if matched, null otherwise
     */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * Create a RouteMatch from this route.
     *
     * @param array<string, string> $params
     */
    public function toRouteMatch(array $params = []): RouteMatch
    {
        return new RouteMatch(
            handler: $this->handler,
            params: $params,
            snapshotBehavior: $this->snapshotBehavior,
            sharedGroup: $this->sharedGroup,
        );
    }
}
