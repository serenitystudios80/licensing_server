<?php

declare(strict_types=1);

namespace App\Admin;

use App\Support\Clock;

/**
 * SessionAuth - Session-based authentication for the Admin Panel.
 *
 * Implements:
 * - Session verification with `requireAuthenticated()` (Requirement 13.1)
 * - 30-minute inactivity timeout (Requirement 13.8)
 * - Session establishment after successful login (Requirement 13.2)
 * - Session termination on logout (Requirement 13.6)
 *
 * SESSION KEYS:
 * - 'admin_user_id': numeric user ID from admin_users table
 * - 'last_activity': Unix epoch timestamp of last activity
 *
 * INACTIVITY TIMEOUT:
 * - 30 minutes = 1800 seconds
 * - Checked on every requireAuthenticated() call
 * - Clock-injectable for deterministic testing
 *
 * Per Requirements 13.1, 13.2, 13.6, 13.8 and design.md Admin section.
 */
final class SessionAuth
{
    private const INACTIVITY_TIMEOUT_SECONDS = 1800; // 30 minutes

    private Clock $clock;

    public function __construct(Clock $clock)
    {
        $this->clock = $clock;
    }

    /**
     * Require an authenticated session (Requirement 13.1).
     *
     * Called at the top of every protected admin controller.
     * If no valid session exists → redirects to login page.
     * If valid session exists → touches last_activity and returns normally.
     *
     * NEVER returns on authentication failure - always redirects.
     *
     * @param string $loginUrl URL to redirect to if not authenticated (default: /admin/login.php)
     * @return void Returns normally if authenticated, never returns if not (redirect + exit)
     */
    public function requireAuthenticated(string $loginUrl = '/admin/login.php'): void
    {
        // Start session if not already started
        $this->ensureSessionStarted();

        // Check if admin_user_id is set
        if (!isset($_SESSION['admin_user_id']) || !is_int($_SESSION['admin_user_id'])) {
            $this->redirectToLogin($loginUrl);
        }

        // Check last_activity timestamp
        if (!isset($_SESSION['last_activity']) || !is_int($_SESSION['last_activity'])) {
            $this->redirectToLogin($loginUrl);
        }

        $now = $this->clock->now();
        $lastActivity = $_SESSION['last_activity'];
        $elapsedSeconds = $now - $lastActivity;

        // Check inactivity timeout (Requirement 13.8)
        if ($elapsedSeconds >= self::INACTIVITY_TIMEOUT_SECONDS) {
            // Session expired due to inactivity
            $this->destroySession();
            $this->redirectToLogin($loginUrl);
        }

        // Valid session - touch last_activity
        $_SESSION['last_activity'] = $now;
    }

    /**
     * Establish an authenticated session (Requirement 13.2).
     *
     * Called after successful credential verification in LoginController.
     * Sets admin_user_id and last_activity in the session.
     *
     * @param int $userId The admin user's ID from admin_users table
     * @return void
     */
    public function establishSession(int $userId): void
    {
        $this->ensureSessionStarted();

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        $_SESSION['admin_user_id'] = $userId;
        $_SESSION['last_activity'] = $this->clock->now();
    }

    /**
     * Terminate the authenticated session (Requirement 13.6).
     *
     * Called when the admin user clicks logout.
     * Destroys the session and redirects to login page.
     *
     * @param string $loginUrl URL to redirect to after logout (default: /admin/login.php)
     * @return void Never returns (redirect + exit)
     */
    public function logout(string $loginUrl = '/admin/login.php'): void
    {
        $this->destroySession();
        $this->redirectToLogin($loginUrl);
    }

    /**
     * Check if the current session is authenticated (without redirection).
     *
     * Used by login page to redirect already-authenticated users to dashboard.
     *
     * @return bool True if authenticated and not timed out
     */
    public function isAuthenticated(): bool
    {
        $this->ensureSessionStarted();

        if (!isset($_SESSION['admin_user_id']) || !is_int($_SESSION['admin_user_id'])) {
            return false;
        }

        if (!isset($_SESSION['last_activity']) || !is_int($_SESSION['last_activity'])) {
            return false;
        }

        $now = $this->clock->now();
        $lastActivity = $_SESSION['last_activity'];
        $elapsedSeconds = $now - $lastActivity;

        return $elapsedSeconds < self::INACTIVITY_TIMEOUT_SECONDS;
    }

    /**
     * Get the authenticated user's ID (or null if not authenticated).
     *
     * @return int|null User ID or null
     */
    public function getUserId(): ?int
    {
        $this->ensureSessionStarted();
        return $_SESSION['admin_user_id'] ?? null;
    }

    /**
     * Get the inactivity timeout in seconds (for testing/documentation).
     *
     * @return int Timeout in seconds (1800 = 30 minutes)
     */
    public static function getInactivityTimeoutSeconds(): int
    {
        return self::INACTIVITY_TIMEOUT_SECONDS;
    }

    /**
     * Ensure the PHP session is started.
     *
     * @return void
     */
    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Destroy the current session.
     *
     * @return void
     */
    private function destroySession(): void
    {
        $this->ensureSessionStarted();
        
        // Clear all session variables
        $_SESSION = [];

        // Destroy the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Destroy the session
        session_destroy();
    }

    /**
     * Redirect to login page and exit.
     *
     * @param string $loginUrl URL to redirect to
     * @return never
     */
    private function redirectToLogin(string $loginUrl): never
    {
        header("Location: {$loginUrl}");
        exit;
    }
}
