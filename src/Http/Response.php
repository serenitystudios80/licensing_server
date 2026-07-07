<?php

declare(strict_types=1);

namespace App\Http;

/**
 * HTTP Response wrapper.
 *
 * Plain value object representing an HTTP response:
 * status code, headers, and body.
 *
 * Handlers return Response objects instead of echoing directly,
 * making them testable with plain input/output (no global state).
 *
 * Per design.md Request lifecycle (API) section and Requirements 20.2, 20.3.
 */
final class Response
{
    /**
     * @param int $statusCode HTTP status code (200, 400, 404, 500, etc.)
     * @param array<string, string> $headers Associative array of HTTP headers
     * @param string $body Response body
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * Create a JSON response.
     *
     * Automatically sets Content-Type: application/json header and JSON-encodes the data.
     *
     * @param array<string, mixed>|object $data Data to JSON-encode
     * @param int $statusCode HTTP status code (default 200)
     * @return self
     * @throws \JsonException if JSON encoding fails
     */
    public static function json(array|object $data, int $statusCode = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self(
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
            body: $body,
        );
    }

    /**
     * Create a plain text response.
     *
     * @param string $body Response body
     * @param int $statusCode HTTP status code (default 200)
     * @return self
     */
    public static function text(string $body, int $statusCode = 200): self
    {
        return new self(
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/plain'],
            body: $body,
        );
    }

    /**
     * Create an HTML response.
     *
     * Used by admin panel controllers (server-rendered PHP templates).
     *
     * @param string $body HTML content
     * @param int $statusCode HTTP status code (default 200)
     * @return self
     */
    public static function html(string $body, int $statusCode = 200): self
    {
        return new self(
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: $body,
        );
    }

    /**
     * Send the response to the client.
     *
     * Sets HTTP status code, headers, and outputs body.
     * Called once at the end of request processing.
     */
    public function send(): void
    {
        // Set HTTP status code
        http_response_code($this->statusCode);

        // Set headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Output body
        echo $this->body;
    }

    /**
     * Create a redirect response.
     *
     * Used by admin panel for login redirects, etc.
     *
     * @param string $url URL to redirect to
     * @param int $statusCode HTTP status code (default 302 Found)
     * @return self
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self(
            statusCode: $statusCode,
            headers: ['Location' => $url],
            body: '',
        );
    }
}
