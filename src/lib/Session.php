<?php
/**
 * FormFill Secure Session Configuration
 * 
 * Must be included before session_start(). Configures PHP session
 * settings for security: strict mode, HTTP-only cookies, SameSite=Lax,
 * strong session IDs, and 30-minute timeout.
 */

namespace FormFill\Lib;

class Session
{
    /**
     * Initialize secure session configuration.
     * Call once before any session_start().
     */
    public static function init(): void
    {
        // Prevent session fixation
        ini_set('session.use_strict_mode', '1');

        // Cookies only — never pass session ID in URL
        ini_set('session.use_only_cookies', '1');

        // HTTP only — prevent JavaScript access to session cookie
        ini_set('session.cookie_httponly', '1');

        // HTTPS detection for secure cookie flag
        $isHttps = false;
        if (
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        ) {
            $isHttps = true;
        }

        if ($isHttps) {
            ini_set('session.cookie_secure', '1');
        }

        // CSRF protection via SameSite
        ini_set('session.cookie_samesite', 'Lax');

        // Strong session IDs (48 chars × 6 bits = 288 bits of entropy)
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        // 30-minute inactivity timeout
        ini_set('session.gc_maxlifetime', '1800');
    }

    /**
     * Check if the current session is still valid.
     */
    public static function isValid(): bool
    {
        return isset($_SESSION['validity']) && $_SESSION['validity'] > time();
    }

    /**
     * Extend the session if less than 15 minutes remain.
     * Call on every authenticated page load.
     */
    public static function extend(): void
    {
        if (!isset($_SESSION['validity'])) {
            return;
        }

        if ($_SESSION['validity'] - time() < 900) {
            $_SESSION['validity'] = time() + 1800;
        }
    }

    /**
     * Regenerate session ID (prevents session fixation).
     * Call on login, logout, and privilege changes.
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Require authentication. Redirects to /login if not logged in.
     */
    public static function requireLogin(): void
    {
        if (!isset($_SESSION['id']) || !self::isValid()) {
            header('Location: /login');
            exit();
        }

        self::extend();
    }

    /**
     * Require admin privileges. Returns 403 if not admin.
     * Refreshes admin status from DB every 5 minutes to catch demotions.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();

        // Refresh admin status periodically (every 5 minutes)
        $now = time();
        if (empty($_SESSION['admin_checked_at']) || ($now - $_SESSION['admin_checked_at']) > 300) {
            global $db;
            if (isset($db) && !$db->connect_error) {
                $stmt = $db->prepare("SELECT admin FROM cache WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $_SESSION['id']);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $_SESSION['admin'] = !empty($row['admin']);
                    $_SESSION['admin_checked_at'] = $now;
                }
            }
        }

        if (empty($_SESSION['admin'])) {
            http_response_code(403);
            die("Acesso negado. <a href='/'>Voltar</a>");
        }

        // TOTP gate: if admin requires TOTP and hasn't verified, redirect to TOTP page
        if (Config::adminRequiresTotp() && empty($_SESSION['totp_verified'])) {
            // Avoid redirect loop when already on the login page
            $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
            if (!str_starts_with($currentPath, '/login')) {
                // Check if user has TOTP secret — if not, set up enrollment
                global $db;
                if (isset($db) && !$db->connect_error) {
                    $stmt = $db->prepare("SELECT totp_secret FROM cache WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("s", $_SESSION['id']);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$row || empty($row['totp_secret'])) {
                            $_SESSION['pending_totp_setup'] = $_SESSION['id'];
                            $_SESSION['pending_totp_email'] = $_SESSION['email'] ?? '';
                        }
                    }
                }
                header('Location: /login?step=totp');
                exit();
            }
        }
    }
}
