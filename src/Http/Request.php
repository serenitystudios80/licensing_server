<?php

declare(strict_types=1);

namespace App\Http;

/**
 * HTTP Request wrapper.
 *
 * Plain value object wrapping incoming HTTP request data:
 * method, headers, raw body, and parsed JSON (if applicable).
 *
 * Not an ORM or framework abstraction — just a typed container to avoid
 * passing $_SERVER, php://input, etc. directly to handlers.
 *
 * Per design.md Request lifecycle (API) section and Requirements 20.2, 20.3.
 */
final class Request
{
    /**
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array<string, string> $headers Associative array of HTTP headers
     * @param string $rawBody Raw request body (unparsed)
     * @param array<string, mixed>|null $json Parsed JSON body (null if not JSON or not yet parsed)
     * @param string $path Request path (e.g., '/activate')
     */
    public function __construct(
        public readonly string $method,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly ?array $json,
        public readonly string $path,
    ) {
    }

    /**
     * Create a Request from PHP globals ($_SERVER, php://input).
     *
     * Does NOT parse JSON yet — that's done by JsonBodyGuard after size/method checks.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $rawBody = file_get_contents('php://input') ?: '';

        // Extract headers from $_SERVER
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                // Convert HTTP_X_SERB_TIMESTAMP to X-Serb-Timestamp
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }

        // Also capture CONTENT_TYPE and CONTENT_LENGTH (not prefixed with HTTP_)
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return new self(
            method: $method,
            headers: $headers,
            rawBody: $rawBody,
            json: null, // Not parsed yet
            path: $path,
        );
    }

    /**
     * Get a specific header value (case-insensitive lookup).
     *
     * @param string $name Header name (case-insensitive)
     * @return string|null Header value, or null if not present
     */
    public function getHeader(string $name): ?string
    {
        $nameLower = strtolower($name);
        
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Check if a header exists (case-insensitive).
     *
     * @param string $name Header name
     * @return bool True if header exists
     */
    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== null;
    }

    /**
     * Create a new Request with parsed JSON body.
     *
     * Immutable update pattern (returns new instance).
     *
     * @param array<string, mixed> $json Parsed JSON data
     * @return self New Request instance with JSON set
     */
    public function withJson(array $json): self
    {
        return new self(
            method: $this->method,
            headers: $this->headers,
            rawBody: $this->rawBody,
            json: $json,
            path: $this->path,
        );
    }

    /**
     * Get the raw body size in bytes.
     *
     * @return int Body size
     */
    public function getBodySize(): int
    {
        return strlen($this->rawBody);
    }
}
