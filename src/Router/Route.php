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
        public ?string $name = null,
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
     * - {param?} - Optional parameter
     * - {param:regex} - Parameter with custom regex
     *
     * @return array{0: string, 1: array<string>}
     */
    private function compilePattern(string $pattern): array
    {
        $paramNames = [];

        // Handle optional parameters with preceding slash
        // Pattern: /{param?} should match both /value and empty
        /** @var string $regex */
        $regex = preg_replace_callback(
            '#/\{(\w+)\?(:[^}]+)?\}#',
            function ($matches) use (&$paramNames) {
                $name = $matches[1];
                $customRegex = isset($matches[2]) ? substr($matches[2], 1) : '[^/]+';

                $paramNames[] = $name;

                return "(?:/(?P<{$name}>{$customRegex}))?";
            },
            $pattern
        ) ?? $pattern;

        // Handle required parameters
        /** @var string $regex */
        $regex = preg_replace_callback(
            '/\{(\w+)(:[^}]+)?\}/',
            function ($matches) use (&$paramNames) {
                $name = $matches[1];
                $customRegex = isset($matches[2]) ? substr($matches[2], 1) : '[^/]+';

                // Only add if not already added (from optional pattern)
                if (!in_array($name, $paramNames, true)) {
                    $paramNames[] = $name;
                }

                return "(?P<{$name}>{$customRegex})";
            },
            $regex
        ) ?? $regex;

        // Escape forward slashes (except those already escaped)
        /** @var string $regex */
        $regex = preg_replace('#(?<!\\\\)/#', '\\/', $regex) ?? $regex;

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
     * Generate a URL for this route.
     *
     * @param array<string, string> $params
     */
    public function generate(array $params = []): string
    {
        $url = $this->pattern;

        // Replace parameters
        /** @var string $url */
        $url = preg_replace_callback(
            '/\{(\w+)(\?)?(:[^}]+)?\}/',
            function ($matches) use ($params) {
                $name = $matches[1];
                $optional = isset($matches[2]) && $matches[2] === '?';

                if (isset($params[$name])) {
                    return $params[$name];
                }

                if ($optional) {
                    return '';
                }

                throw new \InvalidArgumentException("Missing required parameter: {$name}");
            },
            $url
        ) ?? $url;

        // Clean up double slashes from optional params
        /** @var string $url */
        $url = preg_replace('#//+#', '/', $url) ?? $url;

        return $url;
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
            name: $this->name,
            snapshotBehavior: $this->snapshotBehavior,
            sharedGroup: $this->sharedGroup,
        );
    }
}
