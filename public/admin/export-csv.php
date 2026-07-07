<?php

declare(strict_types=1);

/**
 * Admin CSV Export
 *
 * Exports licenses to CSV using the same filters as the licenses list.
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

// Get filter parameters (same as licenses.php)
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$tierFilter = $_GET['tier'] ?? '';

// Build filters
$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($tierFilter !== '') {
    $filters['tier'] = $tierFilter;
}

// Fetch licenses (no pagination - export all matching)
try {
    if ($search !== '') {
        $licenses = $licenseRepo->search($search, 10000, 0);
    } else if (!empty($filters)) {
        $licenses = $licenseRepo->filter($filters, 10000, 0);
    } else {
        $licenses = $licenseRepo->filter([], 10000, 0);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo "Failed to export licenses: {$e->getMessage()}";
    exit;
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="licenses-' . date('Y-m-d-His') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Write UTF-8 BOM (for Excel compatibility)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header
fputcsv($output, [
    'License Key',
    'Email',
    'Customer Name',
    'Product',
    'Tier',
    'Status',
    'Purchased At',
    'Expires At',
    'Activation Limit',
    'Price Amount',
    'Currency',
]);

// Write data rows
foreach ($licenses as $license) {
    fputcsv($output, [
        $license->licenseKey,
        $license->email,
        $license->customerName,
        $license->product,
        ucfirst($license->tier),
        ucfirst($license->status),
        $license->purchasedAt,
        $license->expiresAt ?? 'Lifetime',
        $license->activationLimit,
        $license->priceAmount ?? '',
        $license->currency ?? '',
    ]);
}

fclose($output);
exit;
