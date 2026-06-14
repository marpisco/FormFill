<?php
/**
 * FormFill Rate Limiting
 * 
 * Atomic, DB-backed per-IP and per-user rate limiting using SELECT ... FOR UPDATE.
 * MySQL's NOW() is the single clock to prevent timezone desynchronization.
 * 
 * Budgets:
 *   send_code:       10 attempts / hour (per IP)
 *   verify_code:      5 wrong attempts → invalidate OTP (per user)
 *   verify_totp:      5 attempts / 15 min (per IP)
 *   verify_totp_setup: 5 attempts / 15 min (per IP)
 */

namespace FormFill\Lib;

class RateLimit
{
    /**
     * Get the client's real IP address, walking trusted proxy chain if configured.
     */
    private static function getClientIp(): string
    {
        $trustedProxies = Config::get('trusted_proxies', '');
        $trustedList = $trustedProxies ? array_map('trim', explode(',', $trustedProxies)) : [];

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // If REMOTE_ADDR is a trusted proxy, walk X-Forwarded-For from right to left
        if (in_array($remoteAddr, $trustedList, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xffParts = array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            foreach ($xffParts as $ip) {
                $ip = trim($ip);
                if (!empty($ip) && !in_array($ip, $trustedList, true)) {
                    return $ip;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Look up a rate_limits row for (ip, action).
     */
    private static function getRow(string $ip, string $action): ?array
    {
        global $db;
        $stmt = $db->prepare("SELECT attempts, window_start, blocked_until FROM rate_limits WHERE ip = ? AND action = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ss", $ip, $action);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Check if the current (ip, action) is explicitly blocked.
     */
    public static function isBlocked(string $action): bool
    {
        $ip = self::getClientIp();
        $row = self::getRow($ip, $action);
        if (!$row) {
            return false;
        }
        if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
            return true;
        }
        return false;
    }

    /**
     * Read-only check: is a new attempt allowed under the budget?
     * For atomic check-and-reserve, use reserve() instead.
     */
    public static function check(string $action, int $maxAttempts, int $windowSeconds): bool
    {
        $ip = self::getClientIp();

        if (self::isBlocked($action)) {
            return false;
        }

        $row = self::getRow($ip, $action);
        if ($row === null) {
            return true;
        }

        $windowEnd = strtotime($row['window_start']) + $windowSeconds;
        if ($windowEnd <= time()) {
            return true;
        }

        return (int)$row['attempts'] < $maxAttempts;
    }

    /**
     * Atomic check-and-reserve. Locks the row with SELECT ... FOR UPDATE,
     * checks the budget, and increments the counter in a single transaction.
     * MySQL's NOW() is the single clock for both write and comparison.
     */
    public static function reserve(string $action, int $maxAttempts, int $windowSeconds): bool
    {
        global $db;
        $ip = self::getClientIp();

        $db->begin_transaction();
        try {
            $selectStmt = $db->prepare(
                "SELECT attempts,
                        (window_start >= DATE_SUB(NOW(), INTERVAL ? SECOND)) AS active
                 FROM rate_limits
                 WHERE ip = ? AND action = ?
                 FOR UPDATE"
            );
            if (!$selectStmt) {
                $db->rollback();
                return false; // fail closed on DB error
            }
            $selectStmt->bind_param("iss", $windowSeconds, $ip, $action);
            $selectStmt->execute();
            $row = $selectStmt->get_result()->fetch_assoc();
            $selectStmt->close();

            // No row: first attempt — always allowed
            if ($row === null) {
                $insertStmt = $db->prepare(
                    "INSERT INTO rate_limits (ip, action, attempts, window_start) VALUES (?, ?, 1, NOW())"
                );
                if ($insertStmt) {
                    $insertStmt->bind_param("ss", $ip, $action);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                $db->commit();
                return true;
            }

            // Window expired: reset counter
            if ((int)$row['active'] !== 1) {
                $resetStmt = $db->prepare(
                    "UPDATE rate_limits SET attempts = 1, window_start = NOW() WHERE ip = ? AND action = ?"
                );
                if ($resetStmt) {
                    $resetStmt->bind_param("ss", $ip, $action);
                    $resetStmt->execute();
                    $resetStmt->close();
                }
                $db->commit();
                return true;
            }

            // Window active: increment and check
            $newCount = (int)$row['attempts'] + 1;
            if ($newCount > $maxAttempts) {
                $db->commit();
                return false;
            }

            $updateStmt = $db->prepare("UPDATE rate_limits SET attempts = ? WHERE ip = ? AND action = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("iss", $newCount, $ip, $action);
                $updateStmt->execute();
                $updateStmt->close();
            }
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $ignored) {}
            return false;
        }
    }

    /**
     * Record an attempt (non-atomic read-then-write).
     * Prefer reserve() for new code. This is useful for post-hoc incrementing.
     */
    public static function record(string $action, int $windowSeconds = 3600): void
    {
        global $db;
        $ip = self::getClientIp();

        $row = self::getRow($ip, $action);
        if ($row === null) {
            $stmt = $db->prepare("INSERT INTO rate_limits (ip, action, attempts, window_start) VALUES (?, ?, 1, NOW())");
            if ($stmt) {
                $stmt->bind_param("ss", $ip, $action);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $stmt = $db->prepare(
            "UPDATE rate_limits 
             SET attempts = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, attempts + 1),
                 window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), window_start)
             WHERE ip = ? AND action = ?"
        );
        if ($stmt) {
            $stmt->bind_param("iiss", $windowSeconds, $windowSeconds, $ip, $action);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Block the current IP for an action for $seconds.
     */
    public static function block(string $action, int $seconds): void
    {
        global $db;
        $ip = self::getClientIp();
        $blockedUntil = date('Y-m-d H:i:s', time() + $seconds);

        $stmt = $db->prepare(
            "INSERT INTO rate_limits (ip, action, attempts, window_start, blocked_until) 
             VALUES (?, ?, 0, NOW(), ?) 
             ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)"
        );
        if ($stmt) {
            $stmt->bind_param("sss", $ip, $action, $blockedUntil);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Clear attempt/block state for the current IP/action (e.g. on success).
     */
    public static function clear(string $action): void
    {
        global $db;
        $ip = self::getClientIp();
        $stmt = $db->prepare("DELETE FROM rate_limits WHERE ip = ? AND action = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $ip, $action);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Clear attempt/block state for a user-scoped limit (uses sentinel IP).
     */
    public static function clearUser(string $action, string $userId): void
    {
        global $db;
        $scopedAction = self::userAction($action, $userId);
        $ip = self::USER_SENTINEL;
        $stmt = $db->prepare("DELETE FROM rate_limits WHERE ip = ? AND action = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $ip, $scopedAction);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Build a user-scoped action key for verify_code.
     * Uses sha256 hash to fit within the VARCHAR(50) action column.
     */
    private static function userAction(string $action, string $userId): string
    {
        // Reserve space for action + ':' separator; truncate hash to fit VARCHAR(50)
        $maxHashLen = max(8, 49 - strlen($action));
        return $action . ':' . substr(hash('sha256', $userId), 0, $maxHashLen);
    }

    /**
     * Sendinel IP used for user-scoped rate limit rows.
     */
    private const USER_SENTINEL = '0.0.0.0';

    /**
     * Read-only check for user-scoped rate limit.
     */
    public static function checkUser(string $action, string $userId, int $maxAttempts, int $windowSeconds): bool
    {
        $scopedAction = self::userAction($action, $userId);
        $ip = self::USER_SENTINEL;

        $row = self::getRow($ip, $scopedAction);
        if ($row === null) {
            return true;
        }

        if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
            return false;
        }

        $windowEnd = strtotime($row['window_start']) + $windowSeconds;
        if ($windowEnd <= time()) {
            return true;
        }

        return (int)$row['attempts'] < $maxAttempts;
    }

    /**
     * Record a user-scoped attempt.
     */
    public static function reserveUser(string $action, string $userId, int $maxAttempts, int $windowSeconds): bool
    {
        global $db;
        $scopedAction = self::userAction($action, $userId);
        $ip = self::USER_SENTINEL;

        $db->begin_transaction();
        try {
            $selectStmt = $db->prepare(
                "SELECT attempts,
                        (window_start >= DATE_SUB(NOW(), INTERVAL ? SECOND)) AS active
                 FROM rate_limits
                 WHERE ip = ? AND action = ?
                 FOR UPDATE"
            );
            if (!$selectStmt) {
                $db->rollback();
                return false;
            }
            $selectStmt->bind_param("iss", $windowSeconds, $ip, $scopedAction);
            $selectStmt->execute();
            $row = $selectStmt->get_result()->fetch_assoc();
            $selectStmt->close();

            if ($row === null) {
                $insertStmt = $db->prepare(
                    "INSERT INTO rate_limits (ip, action, attempts, window_start) VALUES (?, ?, 1, NOW())"
                );
                if ($insertStmt) {
                    $insertStmt->bind_param("ss", $ip, $scopedAction);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                $db->commit();
                return true;
            }

            if ((int)$row['active'] !== 1) {
                $resetStmt = $db->prepare(
                    "UPDATE rate_limits SET attempts = 1, window_start = NOW() WHERE ip = ? AND action = ?"
                );
                if ($resetStmt) {
                    $resetStmt->bind_param("ss", $ip, $scopedAction);
                    $resetStmt->execute();
                    $resetStmt->close();
                }
                $db->commit();
                return true;
            }

            $newCount = (int)$row['attempts'] + 1;
            if ($newCount > $maxAttempts) {
                $db->commit();
                return false;
            }

            $updateStmt = $db->prepare("UPDATE rate_limits SET attempts = ? WHERE ip = ? AND action = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("iss", $newCount, $ip, $scopedAction);
                $updateStmt->execute();
                $updateStmt->close();
            }
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $ignored) {}
            return false;
        }
    }
}
