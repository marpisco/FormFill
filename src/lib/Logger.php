<?php
/**
 * FormFill Audit Logger
 * 
 * Logs security-relevant events with redacted request data and client IP.
 * IP resolution walks trusted proxy chain (X-Forwarded-For right-to-left).
 */

namespace FormFill\Lib;

class Logger
{
    /**
     * Log an action to the audit trail.
     * Automatically redacts sensitive data before writing.
     */
    public static function log(string $action, array $context = []): void
    {
        global $db;

        $userId = $_SESSION['id'] ?? null;
        $ip = self::getClientIp();

        // Merge context with redacted POST/GET for complete audit trail
        $redactedPost = Validator::redactSensitive($_POST);
        $redactedGet  = Validator::redactSensitive($_GET);
        $fullContext = array_merge($context, [
            'post' => $redactedPost,
            'get'  => $redactedGet,
        ]);

        $logInfo = $action . "\n" . var_export($fullContext, true);

        $id = Validator::uuid4();

        $stmt = $db->prepare("INSERT INTO logs (id, loginfo, user_id, ip_address) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $id, $logInfo, $userId, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Resolve the client's real IP address.
     * 
     * If REMOTE_ADDR is a trusted proxy, walks the X-Forwarded-For chain
     * from right to left (closest to server → furthest from server) and
     * returns the first non-trusted IP. Rejects HTTP_CLIENT_IP entirely
     * (easily spoofed, never trustworthy).
     * 
     * If no trusted proxies are configured, always returns REMOTE_ADDR.
     */
    public static function getClientIp(): string
    {
        $trustedProxies = Config::get('trusted_proxies', '');
        $trustedList = $trustedProxies ? array_map('trim', explode(',', $trustedProxies)) : [];

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Only walk the proxy chain if the immediate peer is trusted.
        if (in_array($remoteAddr, $trustedList, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Walk right-to-left: the rightmost IP is the closest to our server.
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
}
