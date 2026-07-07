<?php

declare(strict_types=1);

/**
 * Admin Logout Endpoint
 *
 * Terminates the authenticated session and redirects to login page.
 * Implements Requirement 13.6.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Admin\LoginController;
use App\Admin\SessionAuth;
use App\Config\Config;
use App\Repository\AdminUserRepository;
use App\Support\SystemClock;

// Load configuration
try {
    $config = Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    echo "Configuration error: {$e->getMessage()}";
    exit;
}

// Create dependencies
$clock = new SystemClock();
$sessionAuth = new SessionAuth($clock);
$userRepo = new AdminUserRepository($config);
$controller = new LoginController($config, $clock, $sessionAuth, $userRepo);

// Handle logout (never returns - redirects and exits)
$controller->handleLogout();
