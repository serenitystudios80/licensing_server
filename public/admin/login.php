<?php

declare(strict_types=1);

/**
 * Admin Login Page
 *
 * Provides username/password authentication for the single admin user.
 * Implements Requirements 13.2, 13.3, 13.5, 13.7, 13.9.
 *
 * This is a simple functional login page for now. The UI will be styled
 * in Task 20+ per the user's "fully modern, custom-styled admin UI" requirement.
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

// Redirect if already authenticated
if ($sessionAuth->isAuthenticated()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = null;

// Handle POST request (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $controller->handleLogin($_POST);
    
    if ($result['success']) {
        // Redirect to dashboard on success
        header('Location: ' . $result['redirect']);
        exit;
    } else {
        // Show error message
        $error = $result['error'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - License Server</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .login-header p {
            font-size: 14px;
            color: #718096;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            font-size: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .error-message {
            background: #fed7d7;
            border: 1px solid #fc8181;
            color: #c53030;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .login-button {
            width: 100%;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .login-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: #a0aec0;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Admin Login</h1>
            <p>License Server Management</p>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    required 
                    autocomplete="username"
                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required 
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="login-button">
                Sign In
            </button>
        </form>

        <div class="footer-text">
            &copy; <?= date('Y') ?> License Server. All rights reserved.
        </div>
    </div>
</body>
</html>
