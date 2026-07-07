<?php

declare(strict_types=1);

namespace App\Http;

/**
 * ErrorResponder - Uniform structured error response builder.
 *
 * Every error response in the system (API pipeline stages 1-8, handler business
 * logic errors, admin panel errors) uses this single builder to produce the
 * uniform {error_code, message} JSON shape (Requirement 20.4).
 *
 * This structural guarantee ensures Correctness Property 26: all errors have
 * the same shape, regardless of where in the pipeline they originate.
 *
 * Per design.md Request lifecycle and Requirements 20.1, 20.2, 20.3, 20.4, 20.9.
 */
final class ErrorResponder
{
    /**
     * Build a structured error response.
     *
     * Produces a JSON response with the uniform shape:
     * {
     *   "error_code": "...",
     *   "message": "..."
     * }
     *
     * Used by:
     * - JsonBodyGuard (stages 1-3 errors)
     * - RateLimitGuard (stage 4 errors)
     * - HmacAuthGuard (stage 5 errors)
     * - API handlers (stages 6-8 business logic errors)
     * - Webhook handler (signature/validation errors)
     *
     * @param string $code Error code (e.g., 'malformed_body', 'rate_limited', 'unknown_license')
     * @param string $message Human-readable error message (specific and dynamic per error-handling policy)
     * @param int $httpStatus HTTP status code (400, 401, 404, 413, 429, 500, etc.)
     * @return Response JSON response with error_code and message
     */
    public static function build(string $code, string $message, int $httpStatus): Response
    {
        try {
            return Response::json(
                [
                    'error_code' => $code,
                    'message' => $message,
                ],
                $httpStatus
            );
        } catch (\JsonException $e) {
            // JSON encoding should never fail for simple error structures,
            // but if it does, fall back to a plain text response to avoid
            // leaving the client with no response at all.
            return Response::text(
                "Internal error: failed to encode error response. Error code: {$code}",
                500
            );
        }
    }

    /**
     * Build a 400 Bad Request error.
     *
     * Convenience wrapper for validation errors.
     *
     * @param string $message Error message
     * @param string $code Error code (default 'validation_error')
     * @return Response
     */
    public static function badRequest(string $message, string $code = 'validation_error'): Response
    {
        return self::build($code, $message, 400);
    }

    /**
     * Build a 401 Unauthorized error.
     *
     * Convenience wrapper for authentication errors.
     *
     * @param string $message Error message
     * @param string $code Error code (default 'authentication_failed')
     * @return Response
     */
    public static function unauthorized(string $message, string $code = 'authentication_failed'): Response
    {
        return self::build($code, $message, 401);
    }

    /**
     * Build a 404 Not Found error.
     *
     * Convenience wrapper for resource-not-found errors.
     *
     * @param string $message Error message
     * @param string $code Error code (default 'not_found')
     * @return Response
     */
    public static function notFound(string $message, string $code = 'not_found'): Response
    {
        return self::build($code, $message, 404);
    }

    /**
     * Build a 413 Payload Too Large error.
     *
     * Convenience wrapper for oversized request body.
     *
     * @param string $message Error message
     * @return Response
     */
    public static function payloadTooLarge(string $message): Response
    {
        return self::build('payload_too_large', $message, 413);
    }

    /**
     * Build a 429 Too Many Requests error.
     *
     * Convenience wrapper for rate limit exceeded.
     *
     * @param string $message Error message
     * @return Response
     */
    public static function rateLimited(string $message): Response
    {
        return self::build('rate_limited', $message, 429);
    }

    /**
     * Build a 500 Internal Server Error.
     *
     * Convenience wrapper for unexpected server errors.
     *
     * @param string $message Error message (should be generic for external clients, specific in logs)
     * @param string $code Error code (default 'internal_error')
     * @return Response
     */
    public static function internalError(string $message, string $code = 'internal_error'): Response
    {
        return self::build($code, $message, 500);
    }
}
