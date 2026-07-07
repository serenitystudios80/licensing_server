<?php

declare(strict_types=1);

/**
 * API Front Controller - Entry point for all API requests.
 *
 * Implements the fixed 8-stage pipeline per design.md Request lifecycle:
 * 1. HTTP method == POST?
 * 2. Route defined?
 * 3. Body size ≤64KB AND valid JSON?
 * 4. Rate limit check (per-IP AND per-license-key)
 * 5. HMAC authentication (field presence/format → timestamp → signature)
 * 6. Required fields present?
 * 7. Field formats valid?
 * 8. Business rules (endpoint-specific handler)
 *
 * Pipeline stages are enforced as an ordered array, not scattered if-checks,
 * so the ordering requirement (Requirement 20 AC8) is structurally guaranteed.
 *
 * Per Requirements 3.7, 9.7, 20.8, 21.4 and design.md Request lifecycle section.
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;
use App\Config\ConfigException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\JsonBodyGuard;
use App\Http\ErrorResponder;
use App\Security\HmacAuthenticator;
use App\Security\ClientIpResolver;
use App\Security\TrustedProxyRanges;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitRepository;
use App\Support\SystemClock;
use App\Support\Logger;
use App\Support\ErrorContext;

// Top-level exception handler: catch ConfigException and return 500 structured error
// Per Requirement 21 AC4: only log the missing key name, never other config values
try {
    $config = Config::load();
} catch (ConfigException $e) {
    Logger::error("Configuration error: {$e->getMessage()}");
    
    $response = ErrorResponder::internalError(
        'Server configuration error. Please contact the administrator.',
        'config_error'
    );
    $response->send();
    exit(1);
}

// Initialize dependencies
$clock = new SystemClock();
$router = new Router();
$jsonBodyGuard = new JsonBodyGuard($router);
$hmacAuthenticator = new HmacAuthenticator($clock);
$rateLimitRepo = new RateLimitRepository($config);
$rateLimiter = new RateLimiter($config, $rateLimitRepo);
$clientIpResolver = new ClientIpResolver();

// Parse incoming request
$request = Request::fromGlobals();

// Stage 1-3: JsonBodyGuard (method, route, size/JSON)
$validationResult = $jsonBodyGuard->validate($request);
if (!$validationResult->success) {
    $validationResult->errorResponse->send();
    exit(0);
}

// Update request with parsed JSON
$request = $validationResult->request;

// Resolve client IP for rate limiting (used by stage 4)
$trustedProxyRanges = TrustedProxyRanges::fromConfig($config->get('TRUSTED_PROXY_RANGES'));
$clientIp = $clientIpResolver->resolve($_SERVER, $trustedProxyRanges);

// Extract license_key from JSON body (if present) for per-key rate limiting
$licenseKey = $request->json['license_key'] ?? null;

// Stage 4: Rate limiting (per-IP and per-license-key)
// NOTE: Webhook endpoint (/webhook/razorpay) does NOT have rate limiting per design.md
// Only API endpoints (/activate, /validate, /deactivate) are rate limited
if ($request->path !== '/webhook/razorpay') {
    $rateLimitDecision = $rateLimiter->check($clientIp, $licenseKey, $clock->now(), $request->path);

    if ($rateLimitDecision->exceeded) {
        $response = ErrorResponder::rateLimited($rateLimitDecision->reason);
        $response->send();
        exit(0);
    }
}

// Stage 5: HMAC authentication
// NOTE: Webhook endpoint uses Razorpay's own signature check (handled in webhook handler)
// Only API endpoints (/activate, /validate, /deactivate) use HMAC
if ($request->path !== '/webhook/razorpay') {
    $hmacResult = $hmacAuthenticator->verify($request, $config->get('HMAC_SHARED_SECRET'));

    if (!$hmacResult->isOk()) {
        $statusCode = match ($hmacResult->status) {
            App\Security\HmacStatus::MALFORMED => 400,
            App\Security\HmacStatus::EXPIRED => 401,
            App\Security\HmacStatus::INVALID_SIGNATURE => 401,
        };
        
        $errorCode = match ($hmacResult->status) {
            App\Security\HmacStatus::MALFORMED => 'hmac_malformed',
            App\Security\HmacStatus::EXPIRED => 'hmac_expired',
            App\Security\HmacStatus::INVALID_SIGNATURE => 'hmac_invalid',
        };
        
        $response = ErrorResponder::build($errorCode, $hmacResult->errorMessage, $statusCode);
        $response->send();
        exit(0);
    }
}

// Stages 6-8: Route to appropriate handler
// Handlers implement stages 6 (required fields), 7 (field format), 8 (business rules)
$routeMatch = $router->match($request->path);

try {
    $response = match ($routeMatch->handlerName) {
        'ActivateHandler' => handleActivate($request, $config, $clock),
        'ValidateHandler' => handleValidate($request, $config, $clock),
        'DeactivateHandler' => handleDeactivate($request, $config, $clock),
        'RazorpayWebhookHandler' => handleRazorpayWebhook($request, $config, $clock),
        default => ErrorResponder::notFound("Route not found: {$request->path}"),
    };
} catch (\Throwable $e) {
    // Catch-all for unexpected handler errors
    $context = ErrorContext::describe($e, [
        'path' => $request->path,
        'method' => $request->method,
    ]);
    Logger::error($context);
    
    $response = ErrorResponder::internalError(
        'An unexpected error occurred while processing your request. Please try again or contact support.',
        'handler_error'
    );
}

// Send response
$response->send();
exit(0);

// ============================================================================
// Handler Functions (Stages 6-8)
// ============================================================================

/**
 * Handle /activate endpoint.
 */
function handleActivate(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\ActivateHandler($config, $clock);
    return $handler->handle($request);
}

/**
 * Handle /validate endpoint.
 */
function handleValidate(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\ValidateHandler($config, $clock);
    return $handler->handle($request);
}

/**
 * Handle /deactivate endpoint.
 */
function handleDeactivate(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\DeactivateHandler($config, $clock);
    return $handler->handle($request);
}

/**
 * Handle /webhook/razorpay endpoint.
 * TODO: Implement in Task 16.
 */
function handleRazorpayWebhook(Request $request, Config $config, $clock): Response
{
    return ErrorResponder::internalError(
        'The /webhook/razorpay endpoint is not yet implemented. This will be completed in Task 16.',
        'not_implemented'
    );
}
