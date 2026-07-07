<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Router - Match HTTP requests to handlers.
 *
 * Simple match()-based router for API endpoints. Not a full framework router,
 * just maps paths to handler identifiers.
 *
 * Supported routes:
 * - POST /activate
 * - POST /validate
 * - POST /deactivate
 * - POST /webhook/razorpay
 * - POST /create-license
 *
 * Per Requirements 20.2, 20.3 and design.md Request lifecycle section.
 */
final class Router
{
    /**
     * @var array<string, string> Map of path => handler identifier
     */
    private const ROUTES = [
        '/activate' => 'activate',
        '/validate' => 'validate',
        '/deactivate' => 'deactivate',
        '/webhook/razorpay' => 'webhook',
        '/create-license' => 'create-license',
    ];

    /**
     * Match a request path to a handler identifier.
     *
     * @param string $path Request path (e.g., '/activate')
     * @return string|null Handler identifier, or null if no match
     */
    public static function match(string $path): ?string
    {
        return self::ROUTES[$path] ?? null;
    }

    /**
     * Check if a path is a valid route.
     *
     * @param string $path Request path
     * @return bool True if route exists
     */
    public static function isValidRoute(string $path): bool
    {
        return isset(self::ROUTES[$path]);
    }

    /**
     * Get all registered routes.
     *
     * @return array<string, string> Map of path => handler
     */
    public static function getRoutes(): array
    {
        return self::ROUTES;
    }
}
