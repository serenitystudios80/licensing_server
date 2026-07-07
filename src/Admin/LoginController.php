<?php

declare(strict_types=1);

namespace App\Admin;

use App\Config\Config;
use App\Repository\AdminUserRepository;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;

/**
 * LoginController - Admin authentication with lockout protection.
 *
 * Implements:
 * - Credential validation via password_verify() (Requirement 13.2, 13.3, 13.4)
 * - Generic error messages (don't reveal which field was wrong) (Requirement 13.3)
 * - Field validation (missing/empty/oversized) (Requirement 13.7)
 * - 5-failures/15-minutes lockout mechanism (Requirement 13.9)
 * - Session establishment on success (via SessionAuth)
 * - Logout with session termination (Requirement 13.6)
 *
 * LOCKOUT MECHANISM (Requirement 13.9):
 * - Uses admin_login_attempts table (sliding window, same pattern as rate limiting)
 * - Counts failed attempts for username in last 15 minutes
 * - If count >= 5 → reject with same generic error (don't reveal lockout status)
 * - Lockout is per-username, not per-IP (admins may have dynamic IPs)
 * - Records both successful and failed attempts for audit trail
 *
 * GENERIC ERROR MESSAGES (Requirement 13.3, 13.9):
 * - Invalid credentials → "Invalid username or password"
 * - Locked out → "Invalid username or password" (same message)
 * - Never reveals which field was wrong or whether lockout is active
 *
 * Per Requirements 13.2-13.9 and design.md Admin section.
 */
final class LoginController
{
    private const LOCKOUT_WINDOW_SECONDS = 900; // 15 minutes
    private const LOCKOUT_FAILURE_THRESHOLD = 5;
    private const MAX_USERNAME_LENGTH = 64;
    private const MAX_PASSWORD_LENGTH = 255;

    private Config $config;
    private Clock $clock;
    private SessionAuth $sessionAuth;
    private AdminUserRepository $userRepo;
    private PDO $pdo;

    public function __construct(
        Config $config,
        Clock $clock,
        SessionAuth $sessionAuth,
        AdminUserRepository $userRepo,
    ) {
        $this->config = $config;
        $this->clock = $clock;
        $this->sessionAuth = $sessionAuth;
        $this->userRepo = $userRepo;
        $this->pdo = \App\Repository\Db::getConnection($config);
    }

    /**
     * Handle login form submission.
     *
     * @param array<string, mixed> $postData POST data from login form
     * @return array{success: bool, error?: string, redirect?: string}
     */
    public function handleLogin(array $postData): array
    {
        // Step 1: Field validation (Requirement 13.7)
        $validation = $this->validateLoginFields($postData);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $username = $validation['username'];
        $password = $validation['password'];

        // Step 2: Check lockout BEFORE credential verification (Requirement 13.9)
        if ($this->isLockedOut($username)) {
            // Record this attempt as failed (lockout rejection counts as failure)
            $this->recordLoginAttempt($username, false);
            
            // Return generic error (don't reveal lockout status)
            return ['success' => false, 'error' => 'Invalid username or password'];
        }

        // Step 3: Verify credentials (Requirement 13.2, 13.3, 13.4)
        $user = $this->userRepo->findByUsername($username);

        if ($user === null) {
            // Unknown username → record failure, return generic error
            $this->recordLoginAttempt($username, false);
            return ['success' => false, 'error' => 'Invalid username or password'];
        }

        // Verify password hash (Requirement 13.4)
        if (!password_verify($password, $user->passwordHash)) {
            // Invalid password → record failure, return generic error
            $this->recordLoginAttempt($username, false);
            return ['success' => false, 'error' => 'Invalid username or password'];
        }

        // Step 4: Credentials valid → record success, establish session, redirect
        $this->recordLoginAttempt($username, true);
        $this->sessionAuth->establishSession($user->id);

        return ['success' => true, 'redirect' => '/admin/dashboard.php'];
    }

    /**
     * Handle logout request (Requirement 13.6).
     *
     * Terminates session and redirects to login page.
     * Never returns (SessionAuth::logout() exits).
     *
     * @return never
     */
    public function handleLogout(): never
    {
        $this->sessionAuth->logout('/admin/login.php');
    }

    /**
     * Validate login form fields (Requirement 13.7).
     *
     * Checks:
     * - Both username and password are present
     * - Neither is empty
     * - Neither exceeds maximum length
     *
     * @param array<string, mixed> $postData POST data
     * @return array{valid: bool, username?: string, password?: string, error?: string}
     */
    private function validateLoginFields(array $postData): array
    {
        // Check username presence
        if (!isset($postData['username']) || !is_string($postData['username'])) {
            return [
                'valid' => false,
                'error' => 'Username is required',
            ];
        }

        // Check password presence
        if (!isset($postData['password']) || !is_string($postData['password'])) {
            return [
                'valid' => false,
                'error' => 'Password is required',
            ];
        }

        $username = trim($postData['username']);
        $password = $postData['password']; // Don't trim password (may have intentional whitespace)

        // Check username not empty
        if ($username === '') {
            return [
                'valid' => false,
                'error' => 'Username cannot be empty',
            ];
        }

        // Check password not empty
        if ($password === '') {
            return [
                'valid' => false,
                'error' => 'Password cannot be empty',
            ];
        }

        // Check username length
        if (strlen($username) > self::MAX_USERNAME_LENGTH) {
            return [
                'valid' => false,
                'error' => 'Username exceeds maximum length of ' . self::MAX_USERNAME_LENGTH . ' characters',
            ];
        }

        // Check password length
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return [
                'valid' => false,
                'error' => 'Password exceeds maximum length of ' . self::MAX_PASSWORD_LENGTH . ' characters',
            ];
        }

        return [
            'valid' => true,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Check if a username is locked out (Requirement 13.9).
     *
     * Lockout condition: 5+ failed attempts in last 15 minutes.
     * Uses sliding window (same pattern as rate limiting).
     *
     * @param string $username Username to check
     * @return bool True if locked out, false otherwise
     */
    private function isLockedOut(string $username): bool
    {
        try {
            $now = $this->clock->now();
            $windowStart = $now - self::LOCKOUT_WINDOW_SECONDS;
            $windowStartDate = date('Y-m-d H:i:s', $windowStart);

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM admin_login_attempts 
                 WHERE username = ? 
                   AND succeeded = 0
                   AND attempted_at >= ?'
            );

            $stmt->execute([$username, $windowStartDate]);
            $failureCount = (int) $stmt->fetchColumn();

            return $failureCount >= self::LOCKOUT_FAILURE_THRESHOLD;

        } catch (\Exception $e) {
            // Lockout check failure → fail open (allow login attempt)
            // Log the error but don't block legitimate users
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
            ]);
            Logger::error($context);

            return false; // Fail open
        }
    }

    /**
     * Record a login attempt in admin_login_attempts table.
     *
     * Records both successful and failed attempts for audit trail.
     * Failures are logged but don't abort the login response.
     *
     * @param string $username Username attempted
     * @param bool $succeeded Whether login succeeded
     * @return void
     */
    private function recordLoginAttempt(string $username, bool $succeeded): void
    {
        try {
            $now = $this->clock->now();
            $attemptedAt = date('Y-m-d H:i:s', $now);

            $stmt = $this->pdo->prepare(
                'INSERT INTO admin_login_attempts (username, succeeded, attempted_at) 
                 VALUES (?, ?, ?)'
            );

            $stmt->execute([$username, $succeeded ? 1 : 0, $attemptedAt]);

        } catch (\Exception $e) {
            // Recording failure → log but don't throw
            // (Similar to rate limit recording - fail open)
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
                'succeeded' => $succeeded,
            ]);
            Logger::error($context);
        }
    }

    /**
     * Get lockout configuration (for testing/documentation).
     *
     * @return array{windowSeconds: int, threshold: int}
     */
    public static function getLockoutConfig(): array
    {
        return [
            'windowSeconds' => self::LOCKOUT_WINDOW_SECONDS,
            'threshold' => self::LOCKOUT_FAILURE_THRESHOLD,
        ];
    }
}
