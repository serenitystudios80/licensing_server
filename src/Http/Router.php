<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Simple HTTP router using PHP 8 match() expressions.
 *
 * Not a full-featured routing library — just a minimal dispatcher for the
 * fixed set of API endpoints: /activate, /validate, /deactivate, /webhook/razorpay.
 *
 * Returns a route match result indicating whether the route is defined and
 * which handler should process it.
 *
 * Per design.md Request lifecycle (API) section and Requirements 20.2, 20.3.
 */
final class Router
{
    /**
     * Match a request path to a route.
     *
     * Returns a RouteMatch indicating:
     * - found: whether the route is defined
     * - handlerName: which handler class should process it (if found)
     *
     * Used by JsonBodyGuard (stage 2: route defined?) and front controller
     * (stage 8: dispatch to business logic handler).
     *
     * @param string $path Request path (e.g., '/activate')
     * @return RouteMatch
     */
    public function match(string $path): RouteMatch
    {
        $handlerName = match ($path) {
            '/activate' => 'ActivateHandler',
            '/validate' => 'ValidateHandler',
            '/deactivate' => 'DeactivateHandler',
            '/webhook/razorpay' => 'RazorpayWebhookHandler',
            default => null,
        };

        return new RouteMatch(
            found: $handlerName !== null,
            handlerName: $handlerName,
        );
    }

    /**
     * Get all defined routes (for debugging/testing).
     *
     * @return array<string> Array of route paths
     */
    public function getRoutes(): array
    {
        return [
            '/activate',
            '/validate',
            '/deactivate',
            '/webhook/razorpay',
        ];
    }
}

/**
 * RouteMatch result value object.
 *
 * Returned by Router::match() to indicate whether a route was found
 * and which handler should process it.
 */
final readonly class RouteMatch
{
    public function __construct(
        public bool $found,
        public ?string $handlerName,
    ) {
    }
}
