<?php

declare(strict_types=1);

namespace App\Http;

/**
 * ErrorResponder - Build uniform structured error responses.
 *
 * Single point for creating error responses across all handlers, ensuring
 * consistent {error_code, message} shape (Requirement 20.4).
 *
 * Used by every stage/handler to produce errors without duplicating the
 * response structure.
 *
 * Per Requirements 20.4 and design.md Error handling section.
 */
final class ErrorResponder
{
    /**
     * Build a structured error response.
     *
     * Always produces the uniform shape:
     * {
     *   "error_code": "<code>",
     *   "message": "<message>"
     * }
     *
     * @param string $code Error code (snake_case identifier)
     * @param string $message Human-readable error message
     * @param int $httpStatus HTTP status code (400, 403, 404, 500, etc.)
     * @return Response JSON error response
     */
    public static function build(string $code, string $message, int $httpStatus): Response
    {
        return Response::json([
            'error_code' => $code,
            'message' => $message,
        ], $httpStatus);
    }

    /**
     * Build a 500 Internal Server Error response.
     *
     * Generic server error with no sensitive details exposed.
     *
     * @param string $message Optional custom message
     * @return Response
     */
    public static function internalError(string $message = 'Internal server error'): Response
    {
        return self::build('internal_error', $message, 500);
    }

    /**
     * Build a 400 Bad Request response.
     *
     * @param string $code Error code
     * @param string $message Error message
     * @return Response
     */
    public static function badRequest(string $code, string $message): Response
    {
        return self::build($code, $message, 400);
    }

    /**
     * Build a 403 Forbidden response.
     *
     * @param string $code Error code
     * @param string $message Error message
     * @return Response
     */
    public static function forbidden(string $code, string $message): Response
    {
        return self::build($code, $message, 403);
    }

    /**
     * Build a 404 Not Found response.
     *
     * @param string $code Error code
     * @param string $message Error message
     * @return Response
     */
    public static function notFound(string $code, string $message): Response
    {
        return self::build($code, $message, 404);
    }

    /**
     * Build a 429 Too Many Requests response.
     *
     * @param string $message Error message
     * @return Response
     */
    public static function tooManyRequests(string $message = 'Rate limit exceeded'): Response
    {
        return self::build('rate_limit_exceeded', $message, 429);
    }
}
