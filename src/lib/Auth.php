<?php
/**
 * FormFill Authentication
 * 
 * Two parallel auth paths:
 *   Path A: Email OTP → Name setup (if new) → optional TOTP (if admin)
 *   Path B: OAuth2 (Microsoft) → Pre-reg migration → optional TOTP (if admin)
 */

namespace FormFill\Lib;

use League\OAuth2\Client\Provider\GenericProvider;
use PragmaRX\Google2FA\Google2FA;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class Auth
{
    // ─── Path A: Email OTP ──────────────────────────────────────────────────

    /**
     * Send a 6-digit OTP code to the given email.
     * Rate limited: 10 codes/hour per IP.
     * Creates user record on first use (INSERT ... ON DUPLICATE KEY UPDATE).
     * 
     * Returns ['success' => bool, 'message' => string]
     */
    public static function sendCode(string $email): array
    {
        $email = Validator::email($email);
        if (!$email) {
            return ['success' => false, 'message' => 'Endereço de email inválido.'];
        }

        // Check blocked emails
        if (self::isEmailBlocked($email)) {
            Logger::log('Tentativa de login com email bloqueado: ' . $email);
            return ['success' => false, 'message' => 'Este email não tem permissão para aceder.'];
        }

        // Rate limit: 10 codes per hour per IP
        if (!RateLimit::reserve('send_code', 10, 3600)) {
            Logger::log('Rate limit excedido (send_code) para ' . $email);
            return ['success' => false, 'message' => 'Demasiadas tentativas. Tente novamente mais tarde.'];
        }

        // Generate 6-digit code
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        global $db;

        // Insert or update user record
        $userId = self::makeUserId($email);
        $stmt = $db->prepare(
            "INSERT INTO cache (id, email, otp_code_hash, otp_expires) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE otp_code_hash = VALUES(otp_code_hash), otp_expires = VALUES(otp_expires)"
        );
        if ($stmt) {
            $stmt->bind_param("ssss", $userId, $email, $codeHash, $expires);
            $stmt->execute();
            $stmt->close();
        }

        // Fetch the actual row ID (may differ from hash for pre-registered users)
        $actualStmt = $db->prepare("SELECT id FROM cache WHERE email = ?");
        if ($actualStmt) {
            $actualStmt->bind_param("s", $email);
            $actualStmt->execute();
            $row = $actualStmt->get_result()->fetch_assoc();
            $actualStmt->close();
            if ($row) {
                $userId = $row['id'];
            }
        }

        // Clear any previous verify_code rate limit so the new code can be tried
        RateLimit::clearUser('verify_code', $userId);

        // Send email
        $sent = Mailer::sendOtp($email, $code);
        if (!$sent) {
            Logger::log('Falha ao enviar OTP para ' . $email);
            return ['success' => false, 'message' => 'Erro ao enviar o email. Tente novamente.'];
        }

        Logger::log('Código OTP enviado para ' . $email);
        return ['success' => true, 'message' => 'Código enviado! Verifique o seu email.'];
    }

    /**
     * Verify a submitted OTP code.
     * Rate limited: 5 wrong attempts per user per OTP.
     * 
     * Returns ['success' => bool, 'message' => string, 'needsNameSetup' => bool]
     */
    public static function verifyCode(string $email, string $code): array
    {
        $email = Validator::email($email);
        if (!$email) {
            return ['success' => false, 'message' => 'Email inválido.', 'needsNameSetup' => false];
        }

        global $db;

        // Look up the user
        $stmt = $db->prepare("SELECT id, nome, email, admin, totp_secret, otp_code_hash, otp_expires FROM cache WHERE email = ?");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Erro interno.', 'needsNameSetup' => false];
        }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !$user['otp_code_hash']) {
            return ['success' => false, 'message' => 'Nenhum código pendente. Peça um novo código.', 'needsNameSetup' => false];
        }

        // Check expiry
        if (!empty($user['otp_expires']) && strtotime($user['otp_expires']) < time()) {
            return ['success' => false, 'message' => 'O código expirou. Peça um novo código.', 'needsNameSetup' => false];
        }

        // Rate limit per user: 5 wrong per OTP
        if (!RateLimit::reserveUser('verify_code', $user['id'], 5, 600)) {
            // Invalidate OTP after too many wrong attempts
            $clearStmt = $db->prepare("UPDATE cache SET otp_code_hash = NULL, otp_expires = NULL WHERE id = ?");
            if ($clearStmt) {
                $clearStmt->bind_param("s", $user['id']);
                $clearStmt->execute();
                $clearStmt->close();
            }
            Logger::log('OTP bloqueado por excesso de tentativas: ' . $email);
            return ['success' => false, 'message' => 'Demasiadas tentativas. Peça um novo código.', 'needsNameSetup' => false];
        }

        // Verify the code
        if (!password_verify($code, $user['otp_code_hash'])) {
            Logger::log('Código OTP incorreto para ' . $email);
            return ['success' => false, 'message' => 'Código incorreto.', 'needsNameSetup' => false];
        }

        // Success: clear OTP state
        $clearStmt = $db->prepare("UPDATE cache SET otp_code_hash = NULL, otp_expires = NULL WHERE id = ?");
        if ($clearStmt) {
            $clearStmt->bind_param("s", $user['id']);
            $clearStmt->execute();
            $clearStmt->close();
        }

        // Clear user-scoped rate limit on success
        RateLimit::clearUser('verify_code', $user['id']);

        // Check if user needs name setup (new user with no nome)
        $needsNameSetup = empty($user['nome']);

        // Race-safe first-user admin claim
        // Attempt atomic INSERT of first_user_admin_id claim
        $claimedAdmin = false;
        if (!$needsNameSetup || !empty($user['admin'])) {
            // Already set up or already admin — just login
        } elseif (defined('IS_FIRST_RUN') && IS_FIRST_RUN) {
            // New user on a fresh installation — atomic first-admin claim
            $claimStmt = $db->prepare(
                "INSERT INTO config (config_key, config_value) VALUES ('first_user_admin_id', ?) 
                 ON DUPLICATE KEY UPDATE config_value = config_value"
            );
            if ($claimStmt) {
                $claimStmt->bind_param("s", $user['id']);
                $claimStmt->execute();
                $claimedAdmin = $claimStmt->affected_rows === 1;
                $claimStmt->close();
            }

            if ($claimedAdmin) {
                $db->query("UPDATE cache SET admin = TRUE WHERE id = '" . $db->real_escape_string($user['id']) . "'");
                Config::set('initial_setup_complete', 'true');
                $user['admin'] = true;
            }
        }

        if ($needsNameSetup) {
            // Store partial auth in session for name setup
            $_SESSION['pending_user_setup'] = $user['id'];
            $_SESSION['pending_user_email'] = $user['email'];
            if ($claimedAdmin) {
                $_SESSION['pending_user_admin'] = true;
            }
            return ['success' => true, 'message' => 'Código verificado.', 'needsNameSetup' => true];
        }

        // Full login — check TOTP requirement
        if (!empty($user['admin']) && Config::adminRequiresTotp()) {
            if (empty($user['totp_secret'])) {
                // Admin needs TOTP setup (first time)
                $_SESSION['pending_totp_setup'] = $user['id'];
                $_SESSION['pending_totp_email'] = $user['email'];
                return ['success' => true, 'message' => 'Autenticação de dois fatores necessária.', 'needsNameSetup' => false, 'needsTotp' => true];
            }
            // Admin has TOTP enrolled — require verification before login
            $_SESSION['pending_totp_verify'] = ['id' => $user['id'], 'nome' => $user['nome'], 'email' => $user['email'], 'admin' => $user['admin']];
            return ['success' => true, 'message' => 'Introduza o código de autenticação de dois fatores.', 'needsNameSetup' => false, 'needsTotp' => true];
        }

        self::login($user, !empty($user['admin']));
        Logger::log('Login via OTP: ' . $email);
        return ['success' => true, 'message' => 'Login bem-sucedido!', 'needsNameSetup' => false];
    }

    /**
     * Complete name setup for a new user.
     */
    public static function setupName(string $name): array
    {
        if (!isset($_SESSION['pending_user_setup'])) {
            return ['success' => false, 'message' => 'Sessão inválida.'];
        }

        $name = Validator::sanitizeString($name);
        if (strlen($name) < 2) {
            return ['success' => false, 'message' => 'O nome deve ter pelo menos 2 caracteres.'];
        }

        $userId = $_SESSION['pending_user_setup'];
        $isAdmin = !empty($_SESSION['pending_user_admin']);

        global $db;
        $stmt = $db->prepare("UPDATE cache SET nome = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $name, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $userEmail = $_SESSION['pending_user_email'] ?? '';
        unset($_SESSION['pending_user_setup'], $_SESSION['pending_user_email'], $_SESSION['pending_user_admin']);

        // Build user array for login
        $user = ['id' => $userId, 'nome' => $name, 'email' => $userEmail, 'admin' => $isAdmin];

        if ($isAdmin && Config::adminRequiresTotp()) {
            $_SESSION['pending_totp_setup'] = $userId;
            return ['success' => true, 'message' => 'Configuração concluída.', 'needsTotp' => true];
        }

        self::login($user, $isAdmin);
        Logger::log('Novo utilizador registado: ' . ($_SESSION['pending_user_email'] ?? ''));
        return ['success' => true, 'message' => 'Conta criada com sucesso!'];
    }

    // ─── Path B: OAuth2 (Microsoft Azure) ───────────────────────────────────

    /**
     * Check if OAuth2 is enabled in config.
     */
    public static function isOAuthEnabled(): bool
    {
        global $oauth2_config;
        return !empty($oauth2_config['enabled']);
    }

    /**
     * Get the Microsoft OAuth2 authorization URL.
     * Returns empty string if OAuth is disabled.
     */
    public static function getOAuthUrl(): string
    {
        if (!self::isOAuthEnabled()) {
            return '';
        }

        // Explicitly generate and store state ourselves — do NOT rely on
        // league/oauth2-client's internal state tracking via getState(),
        // which behaves inconsistently across versions.
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2state'] = $state;

        $provider = self::createOAuthProvider();
        $authUrl = $provider->getAuthorizationUrl(['state' => $state]);
        return $authUrl;
    }

    /**
     * Handle the OAuth2 callback from Microsoft.
     */
    public static function handleOAuthCallback(string $code, string $state): array
    {
        if (!self::isOAuthEnabled()) {
            return ['success' => false, 'message' => 'A autenticação via Microsoft não está disponível.'];
        }
        // Validate state parameter
        if (empty($state) || !isset($_SESSION['oauth2state']) || $state !== $_SESSION['oauth2state']) {
            Logger::log('Falha na validação do estado OAuth2');
            unset($_SESSION['oauth2state']);
            return ['success' => false, 'message' => 'Falha na validação de segurança. Tente novamente.'];
        }
        unset($_SESSION['oauth2state']);

        try {
            $provider = self::createOAuthProvider();
            $accessToken = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $resourceOwner = $provider->getResourceOwner($accessToken);
            $userData = $resourceOwner->toArray();

            $email = $userData['mail'] ?? $userData['userPrincipalName'] ?? '';
            $email = Validator::email($email);
            if (!$email) {
                return ['success' => false, 'message' => 'Não foi possível obter o email da conta Microsoft.'];
            }

            // Check blocked emails
            if (self::isEmailBlocked($email)) {
                Logger::log('Tentativa de login OAuth com email bloqueado: ' . $email);
                return ['success' => false, 'message' => 'Este email não tem permissão para aceder.'];
            }

            global $db;

            // Check for existing user by email
            $stmt = $db->prepare("SELECT id, nome, email, admin, totp_secret FROM cache WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                // Existing user — login directly
                $user = $existing;
            } else {
                // New OAuth user — create record
                $oid = $userData['oid'] ?? $userData['id'] ?? Validator::uuid4();
                $userId = 'ms_' . $oid;
                $displayName = $userData['displayName'] ?? explode('@', $email)[0];

                // Check for pre-registered user to migrate
                $migrated = self::migratePreRegistered($email, $userId, $displayName);

                $stmt = $db->prepare(
                    "INSERT INTO cache (id, nome, email) VALUES (?, ?, ?) 
                     ON DUPLICATE KEY UPDATE nome = VALUES(nome)"
                );
                if ($stmt) {
                    $stmt->bind_param("sss", $userId, $displayName, $email);
                    $stmt->execute();
                    $stmt->close();
                }

                $user = ['id' => $userId, 'nome' => $displayName, 'email' => $email, 'admin' => $migrated, 'totp_secret' => null];

                // Race-safe first-user admin claim (gated on fresh installation only)
                if (!$migrated && defined('IS_FIRST_RUN') && IS_FIRST_RUN) {
                    $claimStmt = $db->prepare(
                        "INSERT INTO config (config_key, config_value) VALUES ('first_user_admin_id', ?) 
                         ON DUPLICATE KEY UPDATE config_value = config_value"
                    );
                    if ($claimStmt) {
                        $claimStmt->bind_param("s", $userId);
                        $claimStmt->execute();
                        if ($claimStmt->affected_rows === 1) {
                            $db->query("UPDATE cache SET admin = TRUE WHERE id = '" . $db->real_escape_string($userId) . "'");
                            Config::set('initial_setup_complete', 'true');
                            $user['admin'] = true;
                        }
                        $claimStmt->close();
                    }
                }
            }

            // TOTP check
            if (!empty($user['admin']) && Config::adminRequiresTotp()) {
                if (empty($user['totp_secret'])) {
                    $_SESSION['pending_totp_setup'] = $user['id'];
                    $_SESSION['pending_totp_email'] = $user['email'];
                    return ['success' => true, 'message' => 'Autenticação de dois fatores necessária.', 'needsTotp' => true];
                }
                // Admin has TOTP enrolled — require verification before login
                $_SESSION['pending_totp_verify'] = ['id' => $user['id'], 'nome' => $user['nome'], 'email' => $user['email'], 'admin' => $user['admin']];
                return ['success' => true, 'message' => 'Introduza o código de autenticação de dois fatores.', 'needsTotp' => true];
            }

            self::login($user, !empty($user['admin']));
            Logger::log('Login via OAuth2: ' . $email);
            return ['success' => true, 'message' => 'Login bem-sucedido!'];

        } catch (\Exception $e) {
            error_log("FormFill OAuth error: " . $e->getMessage());
            Logger::log('Erro no callback OAuth2: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro na autenticação. Tente novamente.'];
        }
    }

    /**
     * Migrate a pre-registered user (pre_ prefix) to their OAuth ID.
     * Uses a transaction: INSERT new record → UPDATE FKs → DELETE old record.
     * Returns true if a migration happened (user was admin), false otherwise.
     */
    private static function migratePreRegistered(string $email, string $newId, string $displayName): bool
    {
        global $db;
        $wasAdmin = false;

        // Find pre-registered records by email
        $stmt = $db->prepare("SELECT id, admin FROM cache WHERE email = ? AND (id LIKE 'pre_%' OR id LIKE 'pending_%' OR id LIKE 'admin_first_%')");
        if (!$stmt) return false;

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $oldUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$oldUser) return false;

        $oldId = $oldUser['id'];
        $wasAdmin = !empty($oldUser['admin']);

        // Atomic migration: update FKs before deleting old record (FK constraints on old ID)
        $db->begin_transaction();
        try {
            // 1. Release the UNIQUE email by renaming old record's email temporarily
            $db->query("UPDATE cache SET email = '" . $db->real_escape_string($oldId . '@migrated.local') . "' WHERE id = '" . $db->real_escape_string($oldId) . "'");

            // 2. Insert new OAuth record with the correct email
            $insertStmt = $db->prepare(
                "INSERT INTO cache (id, nome, email, admin) VALUES (?, ?, ?, ?)"
            );
            if ($insertStmt) {
                $insertStmt->bind_param("sssi", $newId, $displayName, $email, $wasAdmin);
                $insertStmt->execute();
                $insertStmt->close();
            }

            // 3. Update foreign keys in respostas (BEFORE deleting old record)
            $updateStmt = $db->prepare("UPDATE respostas SET enviador_id = ? WHERE enviador_id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("ss", $newId, $oldId);
                $updateStmt->execute();
                $updateStmt->close();
            }

            // 4. Update foreign keys in logs
            $updateStmt2 = $db->prepare("UPDATE logs SET user_id = ? WHERE user_id = ?");
            if ($updateStmt2) {
                $updateStmt2->bind_param("ss", $newId, $oldId);
                $updateStmt2->execute();
                $updateStmt2->close();
            }

            // 5. Update foreign keys in forms_access
            $updateStmt3 = $db->prepare("UPDATE forms_access SET user_id = ? WHERE user_id = ?");
            if ($updateStmt3) {
                $updateStmt3->bind_param("ss", $newId, $oldId);
                $updateStmt3->execute();
                $updateStmt3->close();
            }

            // 6. Delete old record (no more FK references)
            $deleteStmt = $db->prepare("DELETE FROM cache WHERE id = ?");
            if ($deleteStmt) {
                $deleteStmt->bind_param("s", $oldId);
                $deleteStmt->execute();
                $deleteStmt->close();
            }

            $db->commit();
            Logger::log("Utilizador pré-registado migrado: {$oldId} → {$newId}");
        } catch (\Throwable $e) {
            $db->rollback();
            error_log("FormFill migration error: " . $e->getMessage());
        }

        return $wasAdmin;
    }

    // ─── TOTP (Time-based One-Time Password) ────────────────────────────────

    /**
     * Generate a new TOTP secret for 2FA setup.
     */
    public static function generateTotpSecret(): string
    {
        $google2fa = new Google2FA();
        return $google2fa->generateSecretKey();
    }

    /**
     * Verify a TOTP code against a secret.
     */
    public static function verifyTotp(string $secret, string $code): bool
    {
        $google2fa = new Google2FA();
        return $google2fa->verifyKey($secret, $code);
    }

    /**
     * Get a QR code data URI for TOTP setup.
     * Uses chillerlan/php-qrcode to render as inline SVG (no GD/Imagick needed).
     */
    public static function getTotpQrSvg(string $secret, string $email): string
    {
        $google2fa = new Google2FA();
        $brand = Config::brandName();
        $qrUrl = $google2fa->getQRCodeUrl($brand, $email, $secret);

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel'   => QRCode::ECC_L,
        ]);

        return (new QRCode($options))->render($qrUrl);
    }

    /**
     * Complete TOTP setup by storing the secret.
     */
    public static function completeTotpSetup(string $secret): void
    {
        $userId = $_SESSION['pending_totp_setup'] ?? null;
        if (!$userId) return;

        global $db;
        $stmt = $db->prepare("UPDATE cache SET totp_secret = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $secret, $userId);
            $stmt->execute();
            $stmt->close();
        }

        // Complete login
        $userStmt = $db->prepare("SELECT id, nome, email, admin FROM cache WHERE id = ?");
        if ($userStmt) {
            $userStmt->bind_param("s", $userId);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if ($user) {
                $_SESSION['totp_verified'] = true;
                self::login($user, !empty($user['admin']));
            }
        }

        unset($_SESSION['pending_totp_setup'], $_SESSION['pending_totp_email']);
    }

    // ─── Session Management ─────────────────────────────────────────────────

    /**
     * Complete login: set session variables, regenerate IDs.
     */
    public static function login(array $user, bool $isAdmin = false): void
    {
        Session::regenerate();
        Csrf::regenerate();

        // Clear any stale TOTP verification from a previous login session
        unset($_SESSION['totp_verified'], $_SESSION['admin_checked_at']);

        $_SESSION['id']       = $user['id'];
        $_SESSION['nome']     = $user['nome'];
        $_SESSION['email']    = $user['email'];
        $_SESSION['admin']    = $isAdmin || !empty($user['admin']);
        $_SESSION['validity'] = time() + 1800; // 30 minutes

        unset($_SESSION['oauth2state']);
    }

    /**
     * Logout: destroy session and redirect.
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        header('Location: /login');
        exit();
    }

    // ─── Blocked Emails ─────────────────────────────────────────────────────

    /**
     * Check if an email matches the blocked_emails_regex pattern.
     * Admins are always exempt.
     */
    public static function isEmailBlocked(string $email): bool
    {
        $regex = Config::get('blocked_emails_regex', '');
        if (empty($regex)) {
            return false;
        }

        // Never block an already-admin user
        global $db;
        $stmt = $db->prepare("SELECT admin FROM cache WHERE email = ? AND admin = TRUE");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $isAdmin = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($isAdmin) {
                return false;
            }
        }

        $result = @preg_match($regex, $email);
        return $result === 1;
    }

    // ─── Internal Helpers ───────────────────────────────────────────────────

    /**
     * Create a deterministic user ID from an email address.
     */
    private static function makeUserId(string $email): string
    {
        return substr(hash('sha256', strtolower($email)), 0, 16);
    }

    /**
     * Create an OAuth2 provider instance for Microsoft Azure.
     */
    private static function createOAuthProvider(): GenericProvider
    {
        global $oauth2_config;

        $baseUrl = 'https://login.microsoftonline.com/' . ($oauth2_config['tenant'] ?? 'common');

        return new GenericProvider([
            'clientId'                => $oauth2_config['clientId'],
            'clientSecret'            => $oauth2_config['clientSecret'],
            'redirectUri'             => $oauth2_config['redirectUri'],
            'urlAuthorize'            => $baseUrl . '/oauth2/v2.0/authorize',
            'urlAccessToken'          => $baseUrl . '/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
            'scopes'                  => 'openid profile email User.Read',
        ]);
    }
}
