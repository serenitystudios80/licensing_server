<?php

declare(strict_types=1);

/**
 * Admin Dashboard (Placeholder)
 *
 * Protected by SessionAuth::requireAuthenticated() (Requirement 13.1).
 * Full dashboard implementation will be in Task 20.
 *
 * This is a minimal placeholder to demonstrate session protection works.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Admin\SessionAuth;
use App\Config\Config;
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

// Require authentication (redirects to login if not authenticated)
$sessionAuth->requireAuthenticated('/admin/login.php');

// If we reach here, user is authenticated
$userId = $sessionAuth->getUserId();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - License Server Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f7fafc;
            min-height: 100vh;
        }

        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 20px;
            font-weight: 600;
            color: #1a202c;
        }

        .logout-button {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .logout-button:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px;
        }

        .welcome-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 32px;
            text-align: center;
        }

        .welcome-card h2 {
            font-size: 24px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
        }

        .welcome-card p {
            font-size: 16px;
            color: #718096;
            line-height: 1.6;
        }

        .info-box {
            background: #edf2f7;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            text-align: left;
        }

        .info-box strong {
            color: #2d3748;
        }

        .info-box code {
            background: #cbd5e0;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>License Server Admin</h1>
        <a href="/admin/logout.php" class="logout-button">Logout</a>
    </div>

    <div class="container">
        <div class="welcome-card">
            <h2>Welcome to the Admin Dashboard</h2>
            <p>
                You are successfully authenticated and protected by session authentication.<br>
                Your session will expire after 30 minutes of inactivity.
            </p>

            <div class="info-box">
                <p><strong>Authentication Status:</strong> ✓ Authenticated</p>
                <p><strong>User ID:</strong> <code><?= htmlspecialchars((string)$userId, ENT_QUOTES, 'UTF-8') ?></code></p>
                <p><strong>Task Status:</strong> Task 19 (Admin Authentication) - Complete</p>
                <p><strong>Next:</strong> Task 20 (Full Dashboard Implementation)</p>
            </div>

            <div class="info-box" style="margin-top: 16px;">
                <p><strong>Note:</strong> This is a placeholder dashboard demonstrating that session authentication works correctly per Requirement 13.1. The full dashboard with license statistics, MRR calculations, and management features will be implemented in Task 20 and beyond.</p>
            </div>
        </div>
    </div>
</body>
</html>
