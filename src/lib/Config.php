<?php
/**
 * FormFill DB-Backed Configuration
 * 
 * Provides cached access to application settings stored in the `config` table.
 * Values are stored as JSON in the database and decoded on retrieval.
 */

namespace FormFill\Lib;

class Config
{
    private static array $cache = [];

    /**
     * Retrieve a configuration value from the database.
     * Results are cached per-request via a static array.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        global $db;

        if (!isset($db) || $db->connect_error) {
            return $default;
        }

        $stmt = $db->prepare("SELECT config_value FROM config WHERE config_key = ?");
        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $raw = $row['config_value'];
            $decoded = json_decode($raw, true);
            // Only use decoded value if it was stored as a real JSON structure (object/array)
            // or a literal JSON value (null, bool). Preserve string scalars and numbers.
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded) || is_object($decoded)) {
                    $value = $decoded; // Real JSON structure
                } elseif ($decoded === null && strtolower($raw) === 'null') {
                    $value = $decoded; // Literal null
                } elseif (is_bool($decoded) && in_array(strtolower($raw), ['true', 'false'], true)) {
                    $value = $decoded; // Literal boolean
                } else {
                    $value = $raw; // Preserve string/number as-is
                }
            } else {
                $value = $raw; // Not valid JSON
            }
            self::$cache[$key] = $value;
            $stmt->close();
            return $value;
        }

        $stmt->close();
        self::$cache[$key] = $default;
        return $default;
    }

    /**
     * Set a configuration value. Writes to DB and updates the static cache
     * so subsequent get() calls in the same request return the new value.
     */
    public static function set(string $key, mixed $value): void
    {
        global $db;

        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare(
            "INSERT INTO config (config_key, config_value) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );

        if ($stmt) {
            $stmt->bind_param("ss", $key, $encoded);
            $stmt->execute();
            $stmt->close();
        }

        // Update the static cache so the same request sees the new value
        self::$cache[$key] = $value;
    }

    public static function isDev(): bool
    {
        return self::get('app_mode', 'production') === 'development';
    }

    public static function brandName(): string
    {
        return self::get('brand_name', 'FormFill');
    }

    public static function adminRequiresTotp(): bool
    {
        $val = self::get('admin_requires_totp', false);
        return $val === true || $val === 'true' || $val === '1' || $val === 1;
    }
}
