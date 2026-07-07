<?php

declare(strict_types=1);

/**
 * Admin Licenses List Page
 *
 * Protected by SessionAuth::requireAuthenticated().
 * Displays all licenses with search, filter, and pagination.
 *
 * Modern, custom-styled UI matching the dashboard design.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

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

// Require authentication
$sessionAuth->requireAuthenticated('/admin/login.php');

// Initialize repository
$licenseRepo = new LicenseRepository($config);

// Get filter parameters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$tierFilter = $_GET['tier'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build filters
$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($tierFilter !== '') {
    $filters['tier'] = $tierFilter;
}

// Fetch licenses
try {
    if ($search !== '') {
        $licenses = $licenseRepo->search($search, $perPage, $offset);
        $totalCount = count($licenseRepo->search($search, 10000, 0)); // Rough count for pagination
    } else if (!empty($filters)) {
        $licenses = $licenseRepo->filter($filters, $perPage, $offset);
        $totalCount = $licenseRepo->countBy($filters);
    } else {
        $licenses = $licenseRepo->filter([], $perPage, $offset);
        $totalCount = $licenseRepo->countBy([]);
    }
    
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
} catch (\Exception $e) {
    http_response_code(500);
    echo "Failed to load licenses: {$e->getMessage()}";
    exit;
}

// Helper functions
function formatDate(?string $datetime): string {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licenses - License Server</title>
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--gray-600);
        }

        .filters {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-700);
        }

        .form-input,
        .form-select {
            padding: 8px 12px;
            font-size: 14px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            outline: none;
            transition: all 0.2s;
            background: white;
        }

        .form-input:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            padding: 8px 20px;
            height: fit-content;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .table-container {
            overflow-x: auto;
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
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: var(--gray-50);
        }

        td {
            padding: 16px;
            font-size: 14px;
        }

        .license-key {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--primary);
            transition: color 0.2s;
        }

        .license-key:hover {
            color: var(--primary-hover);
            text-decoration: underline;
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

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px;
        }

        .page-link {
            padding: 8px 12px;
            font-size: 14px;
            color: var(--gray-700);
            text-decoration: none;
            border-radius: 6px;
            border: 1px solid var(--gray-300);
            transition: all 0.2s;
        }

        .page-link:hover {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        .empty-state {
            padding: 64px 24px;
            text-align: center;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .empty-state-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .empty-state-text {
            font-size: 14px;
            color: var(--gray-600);
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .header-content {
                flex-direction: column;
                align-items: stretch;
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
            <div class="header-right">
                <a href="/admin/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <div class="page-header">
            <h1 class="page-title">Licenses</h1>
            <p class="page-subtitle">
                Manage all licenses - Total: <?= number_format($totalCount) ?>
                <span style="margin-left: 16px;">
                    <a href="/admin/create-license.php" class="btn btn-primary">+ Create License</a>
                    <a href="/admin/export-csv.php?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&tier=<?= urlencode($tierFilter) ?>" 
                       class="btn btn-success">
                        Export CSV
                    </a>
                </span>
            </p>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Search</label>
                    <input 
                        type="text" 
                        name="search" 
                        class="form-input" 
                        placeholder="Search by email or license key..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="grace" <?= $statusFilter === 'grace' ? 'selected' : '' ?>>Grace</option>
                        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="revoked" <?= $statusFilter === 'revoked' ? 'selected' : '' ?>>Revoked</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Tier</label>
                    <select name="tier" class="form-select">
                        <option value="">All Tiers</option>
                        <option value="annual" <?= $tierFilter === 'annual' ? 'selected' : '' ?>>Annual</option>
                        <option value="lifetime" <?= $tierFilter === 'lifetime' ? 'selected' : '' ?>>Lifetime</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>

        <!-- Licenses Table -->
        <div class="card">
            <?php if (empty($licenses)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <div class="empty-state-title">No licenses found</div>
                    <div class="empty-state-text">
                        <?php if ($search || !empty($filters)): ?>
                            Try adjusting your search or filters
                        <?php else: ?>
                            No licenses have been created yet
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>License Key</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Tier</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th>Purchased</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($licenses as $license): ?>
                                <tr>
                                    <td>
                                        <a href="/admin/license-detail.php?id=<?= $license->id ?>" style="text-decoration: none;">
                                            <div class="license-key"><?= htmlspecialchars($license->licenseKey, ENT_QUOTES, 'UTF-8') ?></div>
                                        </a>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($license->customerName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div style="font-size: 13px; color: var(--gray-500);">
                                            <?= htmlspecialchars($license->email, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($license->product, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= ucfirst($license->tier) ?></td>
                                    <td>
                                        <span class="badge <?= getBadgeClass($license->status) ?>">
                                            <?= ucfirst($license->status) ?>
                                        </span>
                                    </td>
                                    <td><?= $license->expiresAt ? formatDate($license->expiresAt) : '∞ Lifetime' ?></td>
                                    <td><?= formatDate($license->purchasedAt) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&tier=<?= urlencode($tierFilter) ?>" 
                               class="page-link">← Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&tier=<?= urlencode($tierFilter) ?>" 
                               class="page-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&tier=<?= urlencode($tierFilter) ?>" 
                               class="page-link">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
