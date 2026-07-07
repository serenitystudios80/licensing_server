<?php

declare(strict_types=1);

/**
 * API Front Controller - Entry point for all API requests.
 *
 * Simple router for now - handles basic routing to API endpoints.
 * Full pipeline validation will be added as we implement each component.
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
use App\Support\SystemClock;
use App\Support\Logger;
use App\Support\ErrorContext;

// Top-level exception handler
try {
    $config = Config::load();
} catch (ConfigException $e) {
    Logger::error("Configuration error: {$e->getMessage()}");
    
    $response = ErrorResponder::internalError(
        'Server configuration error. Please contact the administrator.'
    );
    $response->send();
    exit(1);
} catch (\Throwable $e) {
    $context = ErrorContext::describe($e, ['location' => 'index.php startup']);
    Logger::error($context);
    
    $response = ErrorResponder::internalError('Failed to initialize server');
    $response->send();
    exit(1);
}

// Initialize dependencies
$clock = new SystemClock();

// Parse incoming request
$request = Request::fromGlobals();

// Stage 1-3: Validate method, route, and JSON
$validationResult = JsonBodyGuard::validate($request);

// If validation failed, return error
if ($validationResult instanceof Response) {
    $validationResult->send();
    exit(0);
}

// Validation passed, $validationResult is the Request with parsed JSON
$request = $validationResult;

// Stage 4: Rate limiting (skip for webhook endpoint)
if ($request->path !== '/webhook/razorpay') {
    $rateLimitRepo = new \App\RateLimit\RateLimitRepository($config);
    $rateLimiter = new \App\RateLimit\RateLimiter($config, $rateLimitRepo);
    
    // Resolve client IP
    $trustedProxyRanges = \App\Security\TrustedProxyRanges::fromConfig($config->get('TRUSTED_PROXY_RANGES'));
    $clientIpResolver = new \App\Security\ClientIpResolver();
    $clientIp = $clientIpResolver->resolve($_SERVER, $trustedProxyRanges);
    
    // Extract license_key for per-key rate limiting
    $licenseKey = $request->json['license_key'] ?? null;
    
    $rateLimitDecision = $rateLimiter->check($clientIp, $licenseKey, $clock->now());
    
    if ($rateLimitDecision->exceeded) {
        $response = ErrorResponder::build(
            'rate_limit_exceeded',
            $rateLimitDecision->reason ?? 'Rate limit exceeded',
            429
        );
        $response->send();
        exit(0);
    }
}

// Stage 5: HMAC authentication (skip for webhook endpoint - it has its own signature check)
if ($request->path !== '/webhook/razorpay') {
    $hmacAuthenticator = new \App\Security\HmacAuthenticator($clock);
    $hmacResult = $hmacAuthenticator->verify($request, $config->get('HMAC_SHARED_SECRET'));
    
    if (!$hmacResult->success) {
        $response = ErrorResponder::build(
            $hmacResult->errorCode ?? 'hmac_failed',
            $hmacResult->errorMessage ?? 'HMAC authentication failed',
            401
        );
        $response->send();
        exit(0);
    }
}

// Stages 6-8: Route to appropriate handler
$route = Router::match($request->path);

if ($route === null) {
    $response = ErrorResponder::notFound('not_found', "Route not found: {$request->path}");
    $response->send();
    exit(0);
}

try {
    $response = match ($route) {
        'activate' => handleActivate($request, $config, $clock),
        'validate' => handleValidate($request, $config, $clock),
        'deactivate' => handleDeactivate($request, $config, $clock),
        'webhook' => handleRazorpayWebhook($request, $config, $clock),
        'create-license' => handleCreateLicense($request, $config, $clock),
        default => ErrorResponder::notFound('not_found', "Handler not implemented: {$route}"),
    };
} catch (\Throwable $e) {
    // Catch-all for unexpected handler errors
    $context = ErrorContext::describe($e, [
        'path' => $request->path,
        'method' => $request->method,
    ]);
    Logger::error($context);
    
    $response = ErrorResponder::internalError(
        'An unexpected error occurred. Please try again or contact support.'
    );
}

// Send response
$response->send();
exit(0);

// ============================================================================
// Handler Functions (Stages 6-8)
// ============================================================================

/**
 * Handle /create-license endpoint (Payment integration).
 */
function handleCreateLicense(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\CreateLicenseHandler($config, $clock);
    return $handler->handle($request->json);
}

/**
 * Handle /activate endpoint.
 */
function handleActivate(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\ActivateHandler($config, $clock);
    return $handler->handle($request->json);
}

/**
 * Handle /validate endpoint.
 */
function handleValidate(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\ValidateHandler($config, $clock);
    return $handler->handle($request->json);
}

/**
 * Handle /deactivate endpoint.
 */
function handleDeactivate(Request $request, Config $config, $clock): Response
{
    $handler = new App\Api\DeactivateHandler($config, $clock);
    return $handler->handle($request->json);
}

/**
 * Handle /webhook/razorpay endpoint.
 * TODO: Implement in Task 16
 */
function handleRazorpayWebhook(Request $request, Config $config, $clock): Response
{
    return ErrorResponder::internalError(
        'The /webhook/razorpay endpoint is not yet implemented. Coming soon!',
    );
}

