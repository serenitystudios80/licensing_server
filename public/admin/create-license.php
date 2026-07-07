<?php

declare(strict_types=1);

/**
 * Admin Manual License Issuance
 *
 * Form to manually create licenses without payment integration.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Admin\SessionAuth;
use App\Config\Config;
use App\Repository\LicenseRepository;
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

$error = null;
$success = null;
$createdLicense = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $licenseRepo = new LicenseRepository($config);
    $eventRepo = new LicenseEventRepository($config);
    
    // Validate required fields
    $email = trim($_POST['email'] ?? '');
    $customerName = trim($_POST['customer_name'] ?? '');
    $product = trim($_POST['product'] ?? '');
    $tier = trim($_POST['tier'] ?? '');
    $activationLimit = (int)($_POST['activation_limit'] ?? 1);
    $expiresAt = trim($_POST['expires_at'] ?? '');
    $priceAmount = trim($_POST['price_amount'] ?? '');
    $currency = trim($_POST['currency'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Valid email is required';
    } elseif (empty($customerName)) {
        $error = 'Customer name is required';
    } elseif (empty($product)) {
        $error = 'Product is required';
    } elseif (!in_array($tier, ['annual', 'lifetime'])) {
        $error = 'Tier must be either "annual" or "lifetime"';
    } elseif ($activationLimit < 1) {
        $error = 'Activation limit must be at least 1';
    } elseif ($tier === 'annual' && empty($expiresAt)) {
        $error = 'Expiration date is required for annual licenses';
    } elseif (($priceAmount !== '' && $currency === '') || ($priceAmount === '' && $currency !== '')) {
        $error = 'Price and currency must be both present or both empty';
    } else {
        try {
            // Create license
            $now = gmdate('Y-m-d H:i:s', $clock->now());
            
            // For lifetime, clear expires_at
            if ($tier === 'lifetime') {
                $expiresAt = null;
            } elseif (!empty($expiresAt)) {
                // Convert to MySQL datetime format
                $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
            }
            
            $license = $licenseRepo->create([
                'email' => $email,
                'customer_name' => $customerName,
                'product' => $product,
                'tier' => $tier,
                'status' => 'active',
                'purchased_at' => $now,
                'expires_at' => $expiresAt,
                'activation_limit' => $activationLimit,
                'price_amount' => $priceAmount !== '' ? $priceAmount : null,
                'currency' => $currency !== '' ? $currency : null,
                'notes' => $notes,
            ]);
            
            // Log admin issuance event
            $eventRepo->append($license->id, 'admin_issue', [
                'issued_by_admin_id' => $sessionAuth->getUserId(),
                'timestamp' => $now,
            ]);
            
            $success = true;
            $createdLicense = $license;
            
        } catch (\Exception $e) {
            $error = 'Failed to create license: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create License - License Server</title>
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
            --danger: #ef4444;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
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
            max-width: 800px;
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

        .page-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 24px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 6px;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            outline: none;
            transition: all 0.2s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-hint {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .success-box {
            background: var(--gray-50);
            border: 2px solid var(--success);
            border-radius: 8px;
            padding: 20px;
            margin-top: 24px;
        }

        .success-box h3 {
            font-size: 18px;
            margin-bottom: 12px;
            color: var(--success);
        }

        .license-key-display {
            font-family: 'Courier New', monospace;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            padding: 12px;
            background: white;
            border-radius: 6px;
            margin: 12px 0;
            text-align: center;
        }

        @media (max-width: 768px) {
            .form-row {
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
        <div class="breadcrumb">
            <a href="/admin/dashboard.php">Dashboard</a>
            <span>›</span>
            <a href="/admin/licenses.php">Licenses</a>
            <span>›</span>
            <span>Create</span>
        </div>

        <h1 class="page-title">Create New License</h1>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success && $createdLicense): ?>
            <div class="alert alert-success">
                License created successfully!
            </div>
            
            <div class="success-box">
                <h3>✓ License Created</h3>
                <div class="license-key-display">
                    <?= htmlspecialchars($createdLicense->licenseKey) ?>
                </div>
                <p style="margin-top: 12px;">
                    <a href="/admin/license-detail.php?id=<?= $createdLicense->id ?>" class="btn btn-primary">
                        View License Details
                    </a>
                    <a href="/admin/licenses.php" class="btn btn-secondary" style="margin-left: 8px;">
                        Back to List
                    </a>
                </p>
            </div>
        <?php else: ?>
            <div class="card">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">
                            Email <span class="required">*</span>
                        </label>
                        <input type="email" name="email" class="form-input" required 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Customer Name <span class="required">*</span>
                        </label>
                        <input type="text" name="customer_name" class="form-input" required
                               value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Product <span class="required">*</span>
                            </label>
                            <input type="text" name="product" class="form-input" required
                                   value="<?= htmlspecialchars($_POST['product'] ?? 'serenity-booking') ?>"
                                   placeholder="serenity-booking">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Tier <span class="required">*</span>
                            </label>
                            <select name="tier" class="form-select" required id="tier-select">
                                <option value="annual" <?= ($_POST['tier'] ?? '') === 'annual' ? 'selected' : '' ?>>Annual</option>
                                <option value="lifetime" <?= ($_POST['tier'] ?? '') === 'lifetime' ? 'selected' : '' ?>>Lifetime</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Activation Limit <span class="required">*</span>
                            </label>
                            <input type="number" name="activation_limit" class="form-input" required min="1"
                                   value="<?= htmlspecialchars($_POST['activation_limit'] ?? '1') ?>">
                            <div class="form-hint">Number of sites that can use this license</div>
                        </div>

                        <div class="form-group" id="expires-at-group">
                            <label class="form-label">
                                Expires At <span class="required">*</span>
                            </label>
                            <input type="date" name="expires_at" class="form-input" id="expires-at-input"
                                   value="<?= htmlspecialchars($_POST['expires_at'] ?? date('Y-m-d', strtotime('+1 year'))) ?>">
                            <div class="form-hint">Leave empty for lifetime licenses</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Price Amount</label>
                            <input type="text" name="price_amount" class="form-input"
                                   value="<?= htmlspecialchars($_POST['price_amount'] ?? '') ?>"
                                   placeholder="999.00">
                            <div class="form-hint">Optional</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency" class="form-input" maxlength="3"
                                   value="<?= htmlspecialchars($_POST['currency'] ?? 'INR') ?>"
                                   placeholder="INR">
                            <div class="form-hint">3-letter currency code</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea"
                                  placeholder="Optional notes about this license"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Create License</button>
                        <a href="/admin/licenses.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // Toggle expires_at field based on tier selection
        document.getElementById('tier-select').addEventListener('change', function() {
            const expiresGroup = document.getElementById('expires-at-group');
            const expiresInput = document.getElementById('expires-at-input');
            
            if (this.value === 'lifetime') {
                expiresInput.removeAttribute('required');
                expiresInput.value = '';
                expiresGroup.style.opacity = '0.5';
            } else {
                expiresInput.setAttribute('required', 'required');
                expiresGroup.style.opacity = '1';
                if (!expiresInput.value) {
                    // Set default to 1 year from now
                    const oneYearFromNow = new Date();
                    oneYearFromNow.setFullYear(oneYearFromNow.getFullYear() + 1);
                    expiresInput.value = oneYearFromNow.toISOString().split('T')[0];
                }
            }
        });
        
        // Initialize on page load
        document.getElementById('tier-select').dispatchEvent(new Event('change'));
    </script>
</body>
</html>
