<?php
/**
 * One-time setup script to create the first admin user.
 * 
 * IMPORTANT: DELETE THIS FILE after running it!
 * 
 * Usage: php create-admin.php
 * Or access via browser: yourdomain.com/create-admin.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Config\Config;
use App\Repository\Db;
use App\Repository\AdminUserRepository;
use App\Domain\AdminUser;

try {
    // Load config
    Config::load(__DIR__ . '/.env');
    
    // Connect to database
    $pdo = Db::getInstance();
    
    // Check if admin already exists
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM admin_users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        die("❌ Admin user already exists! Delete this file for security.\n");
    }
    
    // Create default admin
    $username = 'admin';
    $password = 'Admin@123'; // Change this after first login!
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    $stmt = $pdo->prepare(
        "INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, NOW())"
    );
    $stmt->execute([$username, $passwordHash]);
    
    echo "✅ Admin user created successfully!\n\n";
    echo "Login credentials:\n";
    echo "Username: {$username}\n";
    echo "Password: {$password}\n\n";
    echo "⚠️  IMPORTANT: Change the password after first login!\n";
    echo "⚠️  DELETE THIS FILE (create-admin.php) for security!\n\n";
    echo "Admin panel: https://yourdomain.com/admin/login.php\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Make sure:\n";
    echo "1. Database is created\n";
    echo "2. Migrations have been run\n";
    echo "3. .env file is configured correctly\n";
}
