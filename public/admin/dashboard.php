<?php

declare(strict_types=1);

/**
 * Admin Dashboard
 *
 * Protected by SessionAuth::requireAuthenticated() (Requirement 13.1).
 * Displays license health and revenue metrics (Requirement 14).
 *
 * Modern, custom-styled UI with:
 * - Card-based layout with subtle shadows
 * - Custom-styled elements (no native browser components)
 * - Fully mobile-responsive
 * - Toast notifications instead of alerts
 * - Indigo accent color (#6366f1) for professional SaaS feel
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Admin\DashboardController;
use App\Admin\SessionAuth;
use App\Config\Config;
use App\Repository\LicenseRepository;
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

// Load dashboard data
$licenseRepo = new LicenseRepository($config);
$dashboardController = new DashboardController($config, $licenseRepo, $clock);

try {
    $data = $dashboardController->getDashboardData();
} catch (\Exception $e) {
    http_response_code(500);
    echo "Failed to load dashboard: {$e->getMessage()}";
    exit;
}

// If we reach here, user is authenticated and data is loaded
$userId = $sessionAuth->getUserId();


// Helper function to format currency
function formatCurrency(float $amount): string {
    return '₹' . number_format($amount, 2);
}

// Helper function to format relative time
function timeAgo(string $datetime): string {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $timestamp);
}

// Helper function to format date
function formatDate(?string $datetime): string {
    if ($datetime === null) return 'Never';
    return date('M j, Y g:i A', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - License Server</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-light: #eef2ff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .nav {
            display: flex;
            gap: 8px;
        }

        .nav-link {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--gray-600);
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .nav-link.active {
            background: var(--primary-light);
            color: var(--primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-secondary {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon.primary { background: var(--primary-light); }
        .stat-icon.success { background: #d1fae5; }
        .stat-icon.warning { background: #fef3c7; }

        .stat-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--gray-600);
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1;
        }

        .stat-value.currency {
            font-size: 28px;
        }

        /* Section */
        .section {
            margin-bottom: 32px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
        }

        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .card-body {
            padding: 0;
        }

        /* License List */
        .license-list {
            list-style: none;
        }

        .license-item {
            padding: 16px 24px;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s;
        }

        .license-item:last-child {
            border-bottom: none;
        }

        .license-item:hover {
            background: var(--gray-50);
        }

        .license-main {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 8px;
        }

        .license-info {
            flex: 1;
        }

        .license-key {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .license-email {
            font-size: 14px;
            color: var(--gray-600);
        }

        .license-name {
            font-size: 13px;
            color: var(--gray-500);
        }

        .license-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: var(--gray-500);
        }

        .badge {
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 12px;
            white-space: nowrap;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            padding: 48px 24px;
            text-align: center;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .empty-state-text {
            font-size: 14px;
            color: var(--gray-600);
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .nav {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .license-main {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo">License Server</div>
                <nav class="nav">
                    <a href="/admin/dashboard.php" class="nav-link active">Dashboard</a>
                    <a href="/admin/licenses.php" class="nav-link">Licenses</a>
                </nav>
            </div>
            <div class="header-right">
                <a href="/admin/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Stats Cards -->
        <div class="stats-grid">
            <!-- Active Licenses -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon primary">✓</div>
                </div>
                <div class="stat-label">Active Licenses</div>
                <div class="stat-value"><?= number_format($data['activeCount']) ?></div>
            </div>

            <!-- Monthly Recurring Revenue -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon success">₹</div>
                </div>
                <div class="stat-label">Monthly Recurring Revenue</div>
                <div class="stat-value currency"><?= formatCurrency($data['mrr']) ?></div>
            </div>

            <!-- Lifetime Licenses -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon warning">∞</div>
                </div>
                <div class="stat-label">Lifetime Licenses</div>
                <div class="stat-value"><?= number_format($data['lifetimeCount']) ?></div>
            </div>
        </div>

        <!-- Licenses Expiring Soon -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Expiring Soon (Next 7 Days)</h2>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Active licenses expiring within the next week</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($data['expiringSoon'])): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">✓</div>
                            <div class="empty-state-title">All clear!</div>
                            <div class="empty-state-text">No licenses expiring in the next 7 days</div>
                        </div>
                    <?php else: ?>
                        <ul class="license-list">
                            <?php foreach ($data['expiringSoon'] as $license): ?>
                                <li class="license-item">
                                    <div class="license-main">
                                        <div class="license-info">
                                            <div class="license-key"><?= htmlspecialchars($license['license_key'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="license-email"><?= htmlspecialchars($license['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="license-name"><?= htmlspecialchars($license['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span class="badge badge-warning">Expires <?= formatDate($license['expires_at']) ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Licenses in Grace Period -->
        <section class="section">
            <div class="section-header">
                <h2 class="section-title">Grace Period</h2>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Licenses in grace period (payment issues)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($data['graceStatus'])): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">✓</div>
                            <div class="empty-state-title">All good!</div>
                            <div class="empty-state-text">No licenses in grace period</div>
                        </div>
                    <?php else: ?>
                        <ul class="license-list">
                            <?php foreach ($data['graceStatus'] as $license): ?>
                                <li class="license-item">
                                    <div class="license-main">
                                        <div class="license-info">
                                            <div class="license-key"><?= htmlspecialchars($license['license_key'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="license-email"><?= htmlspecialchars($license['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="license-name"><?= htmlspecialchars($license['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span class="badge badge-danger">Grace since <?= formatDate($license['grace_start_at']) ?></span>
                                    </div>
                                    <div class="license-meta">
                                        <span>Expires: <?= formatDate($license['expires_at']) ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
