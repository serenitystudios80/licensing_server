<?php

declare(strict_types=1);

namespace App\Http;

/**
 * JsonBodyGuard - Stages 1-3 of request validation.
 *
 * Validates incoming requests before they reach handler business logic:
 * 1. HTTP method must be POST (Requirement 20.1)
 * 2. Route must be defined (Requirement 20.2)
 * 3. Body size <= 64KB and valid JSON (Requirements 20.3, 20.9)
 *
 * Returns ErrorResponse or validated Request with parsed JSON.
 *
 * Per Requirements 20.1, 20.2, 20.3, 20.9 and design.md Request lifecycle.
 */
final class JsonBodyGuard
{
    /**
     * Maximum allowed request body size (64KB).
     */
    private const MAX_BODY_SIZE = 65536; // 64KB

    /**
     * Stage 1: Validate HTTP method is POST.
     *
     * @param Request $request The incoming request
     * @return Response|null Null if valid, Response if validation failed
     */
    public static function checkMethod(Request $request): ?Response
    {
        if ($request->method !== 'POST') {
            return ErrorResponder::build(
                'method_not_allowed',
                'Only POST requests are allowed',
                405
            );
        }

        return null;
    }

    /**
     * Stage 2: Validate route is defined.
     *
     * @param Request $request The incoming request
     * @return Response|null Null if valid, Response if validation failed
     */
    public static function checkRoute(Request $request): ?Response
    {
        if (!Router::isValidRoute($request->path)) {
            return ErrorResponder::build(
                'not_found',
                "Route {$request->path} not found",
                404
            );
        }

        return null;
    }

    /**
     * Stage 3: Validate body size and parse JSON.
     *
     * Returns either:
     * - Error Response if body too large or invalid JSON
     * - Validated Request with parsed JSON
     *
     * @param Request $request The incoming request
     * @return Request|Response Validated Request or error Response
     */
    public static function checkBodyAndParseJson(Request $request): Request|Response
    {
        // Check body size
        $bodySize = $request->getBodySize();
        if ($bodySize > self::MAX_BODY_SIZE) {
            return ErrorResponder::build(
                'payload_too_large',
                'Request body exceeds 64KB limit',
                413
            );
        }

        // Parse JSON
        if ($request->rawBody === '') {
            return $request->withJson([]); // Empty body = empty JSON
        }

        try {
            $json = json_decode($request->rawBody, true, 512, JSON_THROW_ON_ERROR);
            
            if (!is_array($json)) {
                return ErrorResponder::build(
                    'malformed_body',
                    'Request body must be a JSON object',
                    400
                );
            }

            return $request->withJson($json);
        } catch (\JsonException $e) {
            return ErrorResponder::build(
                'malformed_body',
                'Invalid JSON in request body',
                400
            );
        }
    }

    /**
     * Run all three validation stages.
     *
     * Convenience method that runs all stages in order and returns either:
     * - Error Response if any stage failed
     * - Validated Request with parsed JSON if all stages passed
     *
     * @param Request $request The incoming request
     * @return Request|Response Validated Request or error Response
     */
    public static function validate(Request $request): Request|Response
    {
        // Stage 1: Method check
        $methodError = self::checkMethod($request);
        if ($methodError !== null) {
            return $methodError;
        }

        // Stage 2: Route check
        $routeError = self::checkRoute($request);
        if ($routeError !== null) {
            return $routeError;
        }

        // Stage 3: Body size and JSON parsing
        return self::checkBodyAndParseJson($request);
    }
}
