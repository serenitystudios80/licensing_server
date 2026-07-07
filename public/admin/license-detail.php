<?php

declare(strict_types=1);

/**
 * Admin License Detail View
 *
 * Shows complete license information including activations and event history.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Admin\SessionAuth;
use App\Config\Config;
use App\Repository\LicenseRepository;
use App\Repository\ActivationRepository;
use App\Repository\LicenseEventRepository;
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

// Require authentication
$sessionAuth->requireAuthenticated('/admin/login.php');

// Get license ID from query string
$licenseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($licenseId <= 0) {
    http_response_code(400);
    echo "Invalid license ID";
    exit;
}

// Initialize repositories
$licenseRepo = new LicenseRepository($config);
$activationRepo = new ActivationRepository($config);
$eventRepo = new LicenseEventRepository($config);

// Fetch license
try {
    $license = $licenseRepo->findById($licenseId);
    
    if ($license === null) {
        http_response_code(404);
        echo "License not found";
        exit;
    }
    
    // Fetch activations (descending by activated_at)
    $activations = $activationRepo->findAllForLicense($licenseId);
    
    // Fetch events (ascending by created_at)
    $events = $eventRepo->findAllForLicense($licenseId);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo "Failed to load license: {$e->getMessage()}";
    exit;
}

// Helper functions
function formatDate(?string $datetime): string {
    if ($datetime === null) return 'Never';
    return date('M j, Y g:i A', strtotime($datetime));
}

function formatDateShort(?string $datetime): string {
    if ($datetime === null) return 'Never';
    return date('M j, Y', strtotime($datetime));
}

function getBadgeClass(string $status): string {
    return match($status) {
        'active' => 'badge-success',
        'grace' => 'badge-warning',
        'expired' => 'badge-danger',
        'revoked' => 'badge-gray',
        default => 'badge-gray',
    };
}

function getEventIcon(string $eventType): string {
    return match($eventType) {
        'activation' => '✓',
        'deactivation' => '✗',
        'reactivation' => '↻',
        'silent_lapse_grace' => '⏱',
        'webhook_charged' => '💳',
        'webhook_charge_failed' => '⚠',
        'admin_issue' => '👤',
        default => '•',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Detail - <?= htmlspecialchars($license->licenseKey) ?></title>
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
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .breadcrumb {
            display: flex;
            gap: 8px;
            align-items: center;
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 24px;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
            font-family: 'Courier New', monospace;
        }

        .page-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
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
            padding: 24px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .info-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 15px;
            color: var(--gray-900);
        }

        .badge {
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 12px;
            white-space: nowrap;
            display: inline-block;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-gray {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .badge-info {
            background: var(--primary-light);
            color: var(--primary);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--gray-50);
            border-bottom: 2px solid var(--gray-200);
        }

        th {
            padding: 12px 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
        }

        tbody tr {
            border-bottom: 1px solid var(--gray-100);
        }

        td {
            padding: 16px;
            font-size: 14px;
        }

        .empty-state {
            padding: 48px 24px;
            text-align: center;
            color: var(--gray-500);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.3;
        }

        .timeline {
            position: relative;
            padding-left: 40px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 24px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-icon {
            position: absolute;
            left: -40px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .timeline-content {
            background: var(--gray-50);
            padding: 12px 16px;
            border-radius: 8px;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .timeline-meta {
            font-size: 13px;
            color: var(--gray-600);
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
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
                    <a href="/admin/dashboard.php" class="nav-link">Dashboard</a>
                    <a href="/admin/licenses.php" class="nav-link active">Licenses</a>
                </nav>
            </div>
            <div>
                <a href="/admin/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="/admin/dashboard.php">Dashboard</a>
            <span>›</span>
            <a href="/admin/licenses.php">Licenses</a>
            <span>›</span>
            <span>Detail</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><?= htmlspecialchars($license->licenseKey) ?></h1>
            <div class="page-actions">
                <a href="/admin/licenses.php" class="btn btn-secondary">← Back to List</a>
            </div>
        </div>

        <!-- License Info Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">License Information</h2>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="badge <?= getBadgeClass($license->status) ?>">
                                <?= ucfirst($license->status) ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Tier</div>
                        <div class="info-value">
                            <span class="badge badge-info"><?= ucfirst($license->tier) ?></span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Customer Name</div>
                        <div class="info-value"><?= htmlspecialchars($license->customerName) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($license->email) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Product</div>
                        <div class="info-value"><?= htmlspecialchars($license->product) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Activation Limit</div>
                        <div class="info-value"><?= $license->activationLimit ?> site(s)</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Purchased</div>
                        <div class="info-value"><?= formatDateShort($license->purchasedAt) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Expires</div>
                        <div class="info-value">
                            <?= $license->expiresAt ? formatDateShort($license->expiresAt) : '∞ Lifetime' ?>
                        </div>
                    </div>
                    <?php if ($license->graceStartAt): ?>
                    <div class="info-item">
                        <div class="info-label">Grace Start</div>
                        <div class="info-value"><?= formatDateShort($license->graceStartAt) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($license->priceAmount): ?>
                    <div class="info-item">
                        <div class="info-label">Price</div>
                        <div class="info-value">
                            <?= htmlspecialchars($license->currency) ?> <?= htmlspecialchars($license->priceAmount) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($license->razorpaySubscriptionId): ?>
                    <div class="info-item">
                        <div class="info-label">Razorpay Subscription</div>
                        <div class="info-value" style="font-family: monospace; font-size: 13px;">
                            <?= htmlspecialchars($license->razorpaySubscriptionId) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($license->notes): ?>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="info-label">Notes</div>
                        <div class="info-value"><?= nl2br(htmlspecialchars($license->notes)) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activations Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Site Activations (<?= count($activations) ?>)</h2>
            </div>
            <?php if (empty($activations)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🌐</div>
                    <div>No activations yet</div>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Site URL</th>
                            <th>Activated</th>
                            <th>Last Validated</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activations as $activation): ?>
                            <tr>
                                <td><?= htmlspecialchars($activation->siteUrl) ?></td>
                                <td><?= formatDate($activation->activatedAt) ?></td>
                                <td><?= formatDate($activation->lastValidatedAt) ?></td>
                                <td>
                                    <?php if ($activation->deactivatedAt): ?>
                                        <span class="badge badge-gray">Deactivated</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Event History Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Event History (<?= count($events) ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <div>No events recorded</div>
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($events as $event): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon">
                                    <?= getEventIcon($event->eventType) ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?= ucwords(str_replace('_', ' ', $event->eventType)) ?>
                                    </div>
                                    <div class="timeline-meta">
                                        <?= formatDate($event->createdAt) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
