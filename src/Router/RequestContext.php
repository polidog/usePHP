<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Router;

/**
 * Immutable value object representing the current HTTP request.
 */
final readonly class RequestContext
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $queryString = '',
        public array $query = [],
        public array $post = [],
        public array $headers = [],
    ) {}

    /**
     * Create a RequestContext from PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Parse path and query string
        $parsedUrl = parse_url($uri);
        $path = $parsedUrl['path'] ?? '/';
        $queryString = $parsedUrl['query'] ?? '';

        // Normalize path
        $path = '/' . ltrim($path, '/');

        // Extract headers
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        // Add Content-Type and Content-Length if present
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return new self(
            method: strtoupper($method),
            path: $path,
            queryString: $queryString,
            query: $_GET,
            post: $_POST,
            headers: $headers,
        );
    }

    /**
     * Check if this is a partial (AJAX) request for component updates.
     */
    public function isPartialRequest(): bool
    {
        // Check X-Requested-With header (common for AJAX)
        if (($this->headers['x-requested-with'] ?? '') === 'XMLHttpRequest') {
            return true;
        }

        // Check custom usePHP partial header
        if (isset($this->headers['x-usephp-partial'])) {
            return true;
        }

        // Check Accept header for partial content
        $accept = $this->headers['accept'] ?? '';
        if (str_contains($accept, 'application/usephp-partial')) {
            return true;
        }

        return false;
    }

    /**
     * Check if this is a POST request (typically an action submission).
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Get a query parameter value.
     */
    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a POST parameter value.
     */
    public function getPost(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get a header value.
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Create a new context with modified query parameters.
     *
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            queryString: http_build_query($query),
            query: $query,
            post: $this->post,
            headers: $this->headers,
        );
    }

    /**
     * Get the full URL (path + query string).
     */
    public function getUrl(): string
    {
        if ($this->queryString === '') {
            return $this->path;
        }

        return $this->path . '?' . $this->queryString;
    }
}
