<?php
/**
 * FormFill Database Bootstrap
 * 
 * Establishes MySQLi connection, auto-creates all tables, handles schema
 * migrations, and configures IS_FIRST_RUN detection.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Session.php';

use FormFill\Lib\Session;

Session::init();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$db = new mysqli(
    $db_config['servidor'],
    $db_config['user'],
    $db_config['password'],
    $db_config['db'],
    $db_config['porta']
);

if ($db->connect_error) {
    error_log("FormFill DB connection failed: " . $db->connect_error);
    http_response_code(500);
    die("Ligação ao servidor falhou.");
}

$db->set_charset("utf8mb4");

// ─── Table Creation ───────────────────────────────────────────────────────────

$db->query("CREATE TABLE IF NOT EXISTS cache (
    id VARCHAR(99) NOT NULL UNIQUE,
    nome VARCHAR(255),
    email VARCHAR(255),
    admin BOOLEAN DEFAULT FALSE,
    totp_secret VARCHAR(255),
    otp_code_hash VARCHAR(255),
    otp_expires DATETIME,
    PRIMARY KEY (id),
    INDEX idx_cache_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Schema migration: add TOTP/OTP columns for existing installs
$result = $db->query("SHOW COLUMNS FROM cache LIKE 'totp_secret'");
if ($result && $result->num_rows == 0) {
    $db->query("ALTER TABLE cache ADD COLUMN totp_secret VARCHAR(255)");
}
$result = $db->query("SHOW COLUMNS FROM cache LIKE 'otp_code_hash'");
if ($result && $result->num_rows == 0) {
    $db->query("ALTER TABLE cache ADD COLUMN otp_code_hash VARCHAR(255)");
}
$result = $db->query("SHOW COLUMNS FROM cache LIKE 'otp_expires'");
if ($result && $result->num_rows == 0) {
    $db->query("ALTER TABLE cache ADD COLUMN otp_expires DATETIME");
}

$db->query("CREATE TABLE IF NOT EXISTS forms (
    id VARCHAR(99) NOT NULL UNIQUE,
    nome VARCHAR(255) NOT NULL,
    ativado BOOLEAN DEFAULT TRUE,
    descricao TEXT,
    instrucoes TEXT,
    campos JSON NOT NULL,
    doc JSON NOT NULL,
    email JSON NOT NULL,
    criado_por VARCHAR(99),
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (criado_por) REFERENCES cache(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS respostas (
    id VARCHAR(99) NOT NULL UNIQUE,
    form_id VARCHAR(99) NOT NULL,
    enviador_id VARCHAR(99) NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    respondido BOOLEAN DEFAULT FALSE,
    resposta TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (form_id) REFERENCES forms(id),
    FOREIGN KEY (enviador_id) REFERENCES cache(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS rate_limits (
    ip VARCHAR(45) NOT NULL,
    action VARCHAR(50) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    blocked_until DATETIME DEFAULT NULL,
    PRIMARY KEY (ip, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS config (
    config_key VARCHAR(99) NOT NULL UNIQUE,
    config_value TEXT,
    PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS logs (
    id VARCHAR(99) NOT NULL,
    loginfo TEXT,
    user_id VARCHAR(99),
    ip_address VARCHAR(45),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES cache(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─── Default Config Values ────────────────────────────────────────────────────
// NOTE: first_user_admin_id is intentionally NOT pre-seeded.
// It is created atomically by Auth when the first user logs in.

$defaultConfigs = [
    'brand_name'            => 'FormFill',
    'internal_email_domain' => '',
    'admin_requires_totp'   => 'true',
    'blocked_emails_regex'  => '',
    'email_account_name'    => 'FormFill',
    'initial_setup_complete' => 'false',
    'app_mode'              => 'production',
    'trusted_proxies'       => '',
];

foreach ($defaultConfigs as $key => $value) {
    $stmt = $db->prepare("INSERT IGNORE INTO config (config_key, config_value) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

// ─── IS_FIRST_RUN Detection ───────────────────────────────────────────────────
// True ONLY when: cache table is empty AND first_user_admin_id has not been claimed.
// Both conditions must be true to prevent existing installs from re-granting admin.

$userCountResult = $db->query("SELECT COUNT(*) as count FROM cache");
$userCount = $userCountResult->fetch_assoc()['count'];

$firstAdminClaimed = false;
$firstAdminStmt = $db->prepare("SELECT config_value FROM config WHERE config_key = 'first_user_admin_id'");
if ($firstAdminStmt) {
    $firstAdminStmt->execute();
    $row = $firstAdminStmt->get_result()->fetch_assoc();
    $firstAdminStmt->close();
    $firstAdminClaimed = !empty($row['config_value']);
}

define('IS_FIRST_RUN', $userCount == 0 && !$firstAdminClaimed);
