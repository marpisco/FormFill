<?php
/**
 * FormFill Input Validation & Sanitization
 */

namespace FormFill\Lib;

class Validator
{
    /**
     * Validate and return email, or false if invalid.
     */
    public static function email(string $email): string|false
    {
        $email = trim($email);
        $sanitized = filter_var($email, FILTER_VALIDATE_EMAIL);
        return $sanitized !== false ? $sanitized : false;
    }

    /**
     * Validate RFC 4122 v4 UUID format.
     * Pattern: 8-4-4-4-12 hex, with version 4 and variant bits.
     */
    public static function uuid(string $id): bool
    {
        return (bool) preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
            $id
        );
    }

    /**
     * Generate a RFC 4122 v4 UUID using random_bytes.
     */
    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Validate date string in YYYY-MM-DD format.
     */
    public static function date(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * Check if a value is in an allowed list.
     */
    public static function whitelist(string $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    /**
     * Sanitize a string for safe output. Trims whitespace and normalizes.
     */
    public static function sanitizeString(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * Recursively redact sensitive keys from request data arrays.
     * Keys matched (case-insensitive, substring): password, secret, token,
     * csrf, otp, code, authorization, api_key, private_key, key.
     * 
     * Use before logging $_POST/$_GET or any user-submitted data.
     */
    public static function redactSensitive(array $data): array
    {
        $sensitivePatterns = [
            'password', 'secret', 'token', 'csrf', 'otp',
            'code', 'authorization', 'api_key', 'private_key',
        ];

        $result = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string)$key);
            $isSensitive = false;

            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($keyLower, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = self::redactSensitive($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
