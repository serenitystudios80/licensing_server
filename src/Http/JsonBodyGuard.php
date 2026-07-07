<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * JsonBodyGuard - Stages 1-3 of the API request pipeline.
 *
 * Implements the first three validation stages per design.md Request lifecycle:
 * 1. HTTP method == POST?
 * 2. Route defined?
 * 3. Body size ≤64KB AND valid JSON?
 *
 * These checks run BEFORE rate limiting and HMAC auth (stages 4-5), so invalid
 * requests are rejected early without hitting the database or expensive crypto.
 *
 * Per Requirements 20.1, 20.2, 20.3, 20.9 and design.md pipeline ordering.
 */
final class JsonBodyGuard
{
    /**
     * Maximum allowed request body size: 64KB.
     * Per Requirement 20.3.
     */
    private const MAX_BODY_SIZE = 65536; // 64 * 1024

    public function __construct(
        private Router $router,
    ) {
    }

    /**
     * Validate stages 1-3 of the API pipeline.
     *
     * Returns a Response with structured error if any stage fails,
     * or null if all stages pass (caller proceeds to stage 4).
     *
     * If stage 3 passes (valid JSON), the Request is updated to include
     * the parsed JSON payload via Request::withJson().
     *
     * @param Request $request The incoming request
     * @return ValidationResult Result indicating success or error response
     */
    public function validate(Request $request): ValidationResult
    {
        // Stage 1: HTTP method == POST?
        if ($request->method !== 'POST') {
            return ValidationResult::error(
                ErrorResponder::build(
                    'method_not_allowed',
                    'Only POST requests are allowed on API endpoints. ' .
                    "Received: {$request->method} {$request->path}",
                    405
                )
            );
        }

        // Stage 2: Route defined?
        $routeMatch = $this->router->match($request->path);
        if (!$routeMatch->found) {
            return ValidationResult::error(
                ErrorResponder::notFound(
                    "Route not found: {$request->path}. Valid routes: " .
                    implode(', ', $this->router->getRoutes()),
                    'route_not_found'
                )
            );
        }

        // Stage 3a: Body size ≤64KB?
        $bodySize = $request->getBodySize();
        if ($bodySize > self::MAX_BODY_SIZE) {
            // Oversized body → 413 without attempting JSON parsing
            // (prevents DoS via huge malformed JSON payloads)
            return ValidationResult::error(
                ErrorResponder::payloadTooLarge(
                    "Request body too large: {$bodySize} bytes. Maximum allowed: " .
                    self::MAX_BODY_SIZE . " bytes (64KB). Reduce payload size."
                )
            );
        }

        // Stage 3b: Valid JSON?
        if ($request->rawBody === '') {
            // Empty body → malformed (API endpoints require JSON payload)
            return ValidationResult::error(
                ErrorResponder::badRequest(
                    'Request body is empty. API endpoints require a JSON payload.',
                    'malformed_body'
                )
            );
        }

        try {
            $json = json_decode($request->rawBody, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($json)) {
                // Top-level JSON must be an object (decoded as assoc array)
                return ValidationResult::error(
                    ErrorResponder::badRequest(
                        'Request body must be a JSON object, not a primitive or array. ' .
                        'Expected: {"license_key": "...", ...}',
                        'malformed_body'
                    )
                );
            }

            // All stages passed - attach parsed JSON to request
            $requestWithJson = $request->withJson($json);
            return ValidationResult::success($requestWithJson);

        } catch (\JsonException $e) {
            // Malformed JSON → 400 with specific error message
            $context = ErrorContext::describe($e, [
                'method' => 'JsonBodyGuard::validate',
                'path' => $request->path,
                'body_size' => $bodySize,
            ]);
            Logger::warning($context);

            return ValidationResult::error(
                ErrorResponder::badRequest(
                    'Request body is not valid JSON. Parse error: ' . $e->getMessage() . '. ' .
                    'Ensure the payload is properly JSON-encoded.',
                    'malformed_body'
                )
            );
        }
    }

    /**
     * Get the maximum allowed body size (for testing/documentation).
     *
     * @return int Maximum body size in bytes (64KB)
     */
    public static function getMaxBodySize(): int
    {
        return self::MAX_BODY_SIZE;
    }
}

/**
 * ValidationResult value object.
 *
 * Returned by JsonBodyGuard::validate() to indicate success (with updated Request)
 * or failure (with error Response).
 */
final readonly class ValidationResult
{
    private function __construct(
        public bool $success,
        public ?Request $request,
        public ?Response $errorResponse,
    ) {
    }

    /**
     * Create a success result.
     *
     * @param Request $request Request with parsed JSON attached
     * @return self
     */
    public static function success(Request $request): self
    {
        return new self(
            success: true,
            request: $request,
            errorResponse: null,
        );
    }

    /**
     * Create an error result.
     *
     * @param Response $errorResponse Structured error response
     * @return self
     */
    public static function error(Response $errorResponse): self
    {
        return new self(
            success: false,
            request: null,
            errorResponse: $errorResponse,
        );
    }
}
