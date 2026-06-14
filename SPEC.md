# FormFill Modernization — Implementation Spec

**Branch**: `feat/modernization`  
**Status**: in_progress  
**Based on**: ClassLink patterns (not file-by-file copies)

---

## 1. Overview

Complete modernization of FormFill: migrate from SQLite3 to MySQL, implement CSRF/rate-limiting/secure-session tokens, switch to OAuth2+TOTP authentication, replace static JSON forms with a database-driven drag-and-drop form builder, split the monolithic admin panel, and redesign the UI with Tailwind CSS.

### Goals

- Drop SQLite3 → MySQL/MariaDB with prepared statements everywhere
- Full security: CSRF tokens, rate limiting, secure sessions, XSS prevention
- Auth: email OTP **or** OAuth2 (Microsoft) + configurable TOTP for admins
- Form builder: drag-and-drop visual editor, forms stored in DB
- Admin panel: split into modular pages under `admin/`
- UI: Tailwind CSS, dark mode support, responsive, modern minimalist design
- PHP ≥8.2, no deprecated functions

---

## 2. Directory Structure (Target)

```
FormFill/
├── index.php                         # Dashboard — lists available forms
├── form.php                          # Renders a form from DB definition
├── filled.php                        # Processes submission, generates PDF, emails
├── .htaccess                         # Security headers, file protection
├── composer.json                     # PHP dependencies
├── .gitignore
│
├── src/
│   ├── config.php                    # MySQL, SMTP, OAuth2 credentials (gitignored)
│   ├── config.sample.php             # Template for config.php (safe for git)
│   ├── db.php                        # MySQLi connection + auto-create tables
│   │
│   └── lib/
│       ├── Session.php               # Secure session configuration (class)
│       ├── Csrf.php                  # CSRF token generation & verification
│       ├── RateLimit.php             # Atomic rate limiting (SELECT FOR UPDATE)
│       ├── Auth.php                  # Auth flow: OTP, OAuth2, TOTP
│       ├── Validator.php             # Input validation + UUID generation
│       ├── Mailer.php                # PHPMailer wrapper with HTML templates
│       ├── Config.php                # DB-backed app configuration with cache
│       ├── Logger.php                # Audit logging + request redaction
│       └── FormBuilder.php           # Form CRUD operations (DB)
│
├── login/
│   └── index.php                     # Multi-step auth (email OTP OR OAuth2 → optional TOTP)
│
├── admin/
│   ├── index.php                     # Admin shell (navbar, CSRF guard, session)
│   ├── forms.php                     # Form list (enable/disable/delete)
│   ├── forms_edit.php                # Drag-and-drop form builder
│   ├── responses.php                 # Response management (view, respond, search)
│   ├── users.php                     # User management
│   └── settings.php                  # App settings
│
├── assets/
│   ├── js/
│   │   ├── form-builder.js           # Drag-and-drop builder (SortableJS)
│   │   └── theme.js                  # Dark/light mode toggle
│   └── img/
│       ├── logoaejics.png            # AEJICS logo (PDF header)
│       └── logominedu.png            # Ministry of Education logo (PDF header)
│
├── filledforms/                      # Generated PDFs (gitignored)
└── vendor/                           # Composer (gitignored)
```

### Files to Remove

- `formlist/` directory (all .json form definitions)
- `mail.php` → replaced by `src/lib/Mailer.php`
- `login.php` → replaced by `login/index.php`
- `admin.php` → split into `admin/` directory
- `config.php` (broken) → replaced by `src/config.php`
- `configexemplo.json` → replaced by `src/config.sample.php`
- `src/header.php` → integrated into page layouts
- `src/footer.php` → integrated into page layouts
- `src/main.css` → replaced by Tailwind
- `src/.htaccess` → consolidated into root `.htaccess`

---

## 3. Database Schema

All tables auto-created by `src/db.php` on first connection. Schema migrations via `SHOW COLUMNS` checks.

```sql
-- Users (replaces cache_giae)
CREATE TABLE cache (
    id VARCHAR(99) NOT NULL UNIQUE,
    nome VARCHAR(255),
    email VARCHAR(255),
    admin BOOLEAN DEFAULT FALSE,
    totp_secret VARCHAR(255),
    otp_code_hash VARCHAR(255),
    otp_expires DATETIME,
    PRIMARY KEY (id),
    INDEX idx_cache_email (email)
);

-- Forms (replaces formlist/*.json)
CREATE TABLE forms (
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
    FOREIGN KEY (criado_por) REFERENCES cache(id)
);

-- Responses (filled form submissions)
CREATE TABLE respostas (
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
);

-- Rate limiting (per-IP, per-action)
CREATE TABLE rate_limits (
    ip VARCHAR(45) NOT NULL,
    action VARCHAR(50) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    blocked_until DATETIME DEFAULT NULL,
    PRIMARY KEY (ip, action)
);

-- App configuration (DB-backed)
CREATE TABLE config (
    config_key VARCHAR(99) NOT NULL UNIQUE,
    config_value TEXT,
    PRIMARY KEY (config_key)
);

-- Audit logs
CREATE TABLE logs (
    id VARCHAR(99) NOT NULL,
    loginfo TEXT,
    user_id VARCHAR(99),
    ip_address VARCHAR(45),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES cache(id)
);
```

### Default Config Values

| Key | Default | Description |
|-----|---------|-------------|
| `brand_name` | `FormFill` | App name shown in UI |
| `internal_email_domain` | `''` | Auto-approved domain for autonomous features |
| `admin_requires_totp` | `true` | Whether admins need TOTP 2FA |
| `blocked_emails_regex` | `''` | Regex for blocking email addresses |
| `email_account_name` | `FormFill` | Display name in email From header |
| `initial_setup_complete` | `false` | Tracks first-run setup |
| `app_mode` | `production` | `production` or `development` |
| `trusted_proxies` | `''` | Comma-separated trusted proxy IPs |

**Intentionally absent from defaults**: `first_user_admin_id` — this key is NOT pre-seeded. It is created atomically by `Auth::verifyCode()` when the first user logs in, using `INSERT ... ON DUPLICATE KEY UPDATE`. Pre-seeding it with any value would cause `affected_rows === 0` for every attempt and no user would ever claim admin. See Section 7, steps 3-4 for the race-safe mechanism.

---

## 4. Component Specifications

### 4.1 `src/config.php` (gitignored)

```php
<?php
$db_config = [
    'servidor' => 'localhost',
    'porta'    => 3306,
    'user'     => 'formfill',
    'password' => '...',
    'db'       => 'formfill',
];

$smtp_config = [
    'host'     => 'smtp.example.com',
    'port'     => 587,
    'auth'     => true,
    'security' => 'tls',
    'username' => 'user@example.com',
    'password' => '...',
];

$oauth2_config = [
    'clientId'     => '...',
    'clientSecret' => '...',
    'tenant'       => 'common',         // or specific tenant ID
    'redirectUri'  => 'https://example.com/login/',
];
```

### 4.2 `src/db.php`

- `require_once` config, session, autoload
- Start session
- Connect mysqli, set `utf8mb4`
- Create all tables with `CREATE TABLE IF NOT EXISTS`
- Run `SHOW COLUMNS` migrations for existing installs
- Insert `INSERT IGNORE` default configs (except `first_user_admin_id` — see Section 3)
- Set `IS_FIRST_RUN` constant: true ONLY when `COUNT(*) FROM cache === 0` AND `first_user_admin_id` config row is absent or empty. Both conditions must be true. This prevents an existing install with users but no claim row from granting admin to the next login.
- Expose global `$db` (mysqli instance)

### 4.3 `src/lib/Session.php`

```php
class Session {
    public static function init(): void {
        // ini_set: strict_mode, only_cookies, httponly, samesite=Lax
        // sid_length=48, sid_bits_per_character=6
        // gc_maxlifetime=1800 (30 min)
        // cookie_secure if HTTPS detected
    }
    
    public static function isValid(): bool {
        // Check $_SESSION['validity'] > time()
    }
    
    public static function extend(): void {
        // If <15min remaining, add 30min
    }
    
    public static function regenerate(): void {
        // session_regenerate_id(true)
    }
    
    public static function requireLogin(): void {
        // Redirect to /login if not authenticated
    }
    
    public static function requireAdmin(): void {
        // 403 if not admin
    }
}
```

### 4.4 `src/lib/Csrf.php`

```php
class Csrf {
    public static function generate(): string;
    public static function regenerate(): string;      // On auth change
    public static function verify(string $token): bool; // hash_equals
    public static function field(): string;            // Hidden input HTML
    public static function globalInjector(): string;   // JS snippet for all POST forms
}
```

Matches ClassLink's `func/csrf.php` logic exactly: 64-char hex tokens, hash_equals verification, regeneration on login/logout, global JS injector that appends the hidden field to every POST form.

### 4.5 `src/lib/RateLimit.php`

```php
class RateLimit {
    // Per-IP rate limiting (all actions default to IP scope)
    public static function check(string $action, int $max, int $window): bool;
    public static function reserve(string $action, int $max, int $window): bool; // Atomic
    public static function record(string $action, int $window): void;
    public static function block(string $action, int $seconds): void;
    public static function clear(string $action): void;
    public static function isBlocked(string $action): bool;
    
    // User-scoped rate limiting (for verify_code)
    // Uses a sentinel IP "0.0.0.0" + action suffix "verify_code:<sha256(userId)>"
    public static function checkUser(string $action, string $userId, int $max, int $window): bool;
    public static function reserveUser(string $action, string $userId, int $max, int $window): bool;
}
```

**Scoping rules**:
- `send_code`: per-IP (10/hour) — prevents a single IP from flooding the SMTP server
- `verify_code`: per-user (5 wrong per OTP) — prevents brute-forcing a single user's OTP from a shared IP
- `verify_totp`: per-IP (5/15min) — prevents brute-forcing TOTP from a single source
- `verify_totp_setup`: per-IP (5/15min) — same for initial TOTP setup

User-scoped actions use `action = "verify_code:" . hash('sha256', $userId)` and `ip = '0.0.0.0'` as the sentinel. The sha256 hash ensures the action column (VARCHAR 50) fits.

### 4.6 `src/lib/Auth.php`

```php
class Auth {
    // Step 1: Email entry → send 6-digit OTP
    public static function sendCode(string $email): array; // Returns [success, message]
    
    // Step 2: Verify OTP → if new user, go to name setup
    public static function verifyCode(string $email, string $code): array;
    
    // Step 3: Name setup for new users
    public static function setupName(string $name): array;
    
    // OAuth2: Microsoft Azure callback
    public static function handleOAuthCallback(string $code, string $state): array;
    public static function getOAuthUrl(): string;
    
    // TOTP: Generate secret, verify, enable/disable
    public static function generateTotpSecret(): string;
    public static function verifyTotp(string $secret, string $code): bool;
    public static function getTotpQrUrl(string $secret, string $email): string;
    
    // Session management
    public static function login(array $user, bool $isAdmin = false): void;
    public static function logout(): void;
    
    // Blocked email check
    public static function isEmailBlocked(string $email): bool;
}
```

### 4.7 `src/lib/Validator.php`

```php
class Validator {
    public static function email(string $email): string|false;
    public static function uuid(string $id): bool;        // Validates v4 UUID format: /[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/i
    public static function uuid4(): string;                // Generates RFC 4122 v4 UUID via random_bytes(16)
    public static function date(string $date): bool;
    public static function whitelist(string $value, array $allowed): bool;
    public static function sanitizeString(string $input): string;
    public static function redactSensitive(array $data): array;
        // Strips keys matching: password, secret, token, csrf, otp, code,
        // authorization, api_key, private_key, key (case-insensitive, substring match)
}
```

### 4.8 `src/lib/Mailer.php`

```php
class Mailer {
    public static function send(string $to, string $subject, string $htmlBody, ?string $attachment = null): bool;
    public static function sendOtp(string $to, string $code): bool;
    public static function sendFormConfirmation(string $to, string $nome, string $subject, string $body, string $pdfPath): bool;
    public static function sendResponseNotification(string $to, string $nome, string $resposta, string $pdfPath): bool;
}
```

### 4.9 `src/lib/Config.php`

```php
class Config {
    private static array $cache = [];
    
    public static function get(string $key, mixed $default = null): mixed;
    public static function set(string $key, mixed $value): void;  // Writes to DB AND updates static cache
    public static function isDev(): bool;
    public static function brandName(): string;
    public static function adminRequiresTotp(): bool;
}
```

**Cache consistency**: `Config::set()` writes to DB via `INSERT ... ON DUPLICATE KEY UPDATE`, then immediately updates `self::$cache[$key]` so subsequent `Config::get()` calls in the same request return the new value. The static cache is per-request only (rebuilt on each PHP process).

### 4.10 `src/lib/Logger.php`

```php
class Logger {
    public static function log(string $action, array $context = []): void;
    public static function getClientIp(): string; // Trusted proxy chain walking
}
```

**Redaction**: Before writing any log entry, `Logger::log()` automatically calls `Validator::redactSensitive()` on `$context` and on `$_POST`/`$_GET` included in the action description. The sensitive key patterns include: `password`, `secret`, `token`, `csrf`, `otp`, `code`, `authorization`, `api_key`, `private_key`, `key`. This matches ClassLink's `request_redaction.php`.

### 4.11 `src/lib/FormBuilder.php`

```php
class FormBuilder {
    public static function list(bool $includeDisabled = false): array;
    public static function get(string $id): ?array;
    public static function create(array $data): string; // Returns new form ID
    public static function update(string $id, array $data): bool;
    public static function delete(string $id): bool;
    public static function toggle(string $id): bool;
    public static function validate(array $data): array; // Returns [valid, errors]
}
```

---

## 5. Page Specifications

### 5.1 `login/index.php` — Multi-step Auth

**Two parallel authentication paths** (email OTP OR OAuth2 — never both sequentially):

**Path A — Email OTP**:
| Step | Screen | Logic |
|------|--------|-------|
| (default) | Email entry | Form: email input → POST to `?step=send` |
| `send` | Code sent | Auth::sendCode() → rate limit check (per-IP) → send email → show code form |
| `verify` | Code entry | Auth::verifyCode() → rate limit check (per-user) → if new user → `?step=setup` |
| `setup` | Name setup | Form: name input → Auth::setupName() → complete login |
| `totp` | TOTP verify | If admin + config enabled: code input → verify → complete login |

**Path B — OAuth2 (Microsoft)**:
| Step | Screen | Logic |
|------|--------|-------|
| (init) | Login page | "Sign in with Microsoft" button → Auth::getOAuthUrl() → redirect |
| (callback) | OAuth callback | `?code=&state=` → Auth::handleOAuthCallback() handles state validation, new/existing user, pre-registered migration |
| `totp` | TOTP verify | Same as Path A — if admin + config enabled |

**Design**: Centered card on gradient background, Tailwind-styled inputs, clean typography. Both auth options visible: email input form AND "Sign in with Microsoft" button.

### 5.2 `index.php` — Dashboard

- Session guard via `Session::requireLogin()`
- Header with logo, user avatar dropdown (logout, admin link if admin)
- Grid of form cards, each showing: form name, description, "Preencher" button
- Only shows enabled forms
- Empty state if no forms
- Dev mode banner if applicable

### 5.3 `form.php` — Form Rendering

- Accepts `?id=` query param (form UUID)
- Loads form definition from DB via `FormBuilder::get()`
- Renders fields from `campos` JSON:
  - Each field: label + input (type from config)
  - Required fields marked with red asterisk
  - Hidden field for form ID + CSRF token
  - Submit button
- Supports HTML5 input types: text, date, email, number, color, file, datetime-local, time, url, tel, range
- Also supports: textarea, select, checkbox, radio (rendered as appropriate HTML elements)
- Hidden fields excluded from rendering (used for CSRF token, form ID)
- File upload: `enctype="multipart/form-data"`

### 5.4 `filled.php` — Form Processing

- Session guard + CSRF verification
- Load form definition from DB
- Substitute template variables:
  - `§nomecompleto§`, `§nome§`, `§id§`, `§email§` → user data from `cache` table
  - `&fieldid&` → POST values
  - `#data#` → current date
  - `§resposta§` → admin response (in response flow)
- Generate PDF via FPDF (same template system)
- Save to `filledforms/YYYYMMDDHHmmssms.pdf`
- Insert into `respostas` table
- Send confirmation email with PDF attachment
- Display success + embedded PDF preview

### 5.5 `admin/index.php` — Admin Shell

- `require` at top of every admin page
- Session guard + admin check + `Session::extend()` on every page load
- CSRF verification on all POST requests
- Tailwind navbar with links: Dashboard, Forms, Responses, Users, Settings
- Global CSRF JS injector (same pattern as ClassLink: DOMContentLoaded + submit event listener)
- `acaoexecutada()` helper defined in this file:
  - Outputs a Tailwind-styled success alert: "Ação executada. **{action}**"
  - Calls `Logger::log($action, [...])` with redacted `$_POST`/`$_GET` data
  - The alert auto-dismisses after 5 seconds
- Dev mode banners (top sticky + bottom fixed, red background, if `Config::isDev()`)

### 5.6 `admin/forms.php` — Form List

- Table of all forms: name, status (enabled/disabled), responses count, created date
- Actions: Edit (opens builder), Toggle (enable/disable), Delete (with confirmation)
- "Create New Form" button → `admin/forms_edit.php`
- Search/filter

### 5.7 `admin/forms_edit.php` — Form Builder

**Layout**: Two-panel design
- **Left sidebar** (w-80): Field type palette + form-level settings
- **Right canvas** (flex-1): Drop zone for fields, live preview

**Field types** (draggable from palette — must match what `form.php` renders):
- Text input, Textarea, Number, Email, Date, Time, DateTime-local, URL, Tel, Color
- Select dropdown, Checkbox group, Radio group
- File upload, Range slider
- Hidden field (for internal use, not shown to end users)
- Paragraph / display-only text (rendered as `<p>` in form, not an input)

**Field properties** (click to configure):
- Field ID (auto-generated, editable)
- Label text
- Placeholder
- Required toggle
- For select/checkbox/radio: options list (add/remove/reorder)
- For textarea: rows

**Form-level settings** (in sidebar):
- Form name, description, instructions
- Toggle: generate PDF (doc.criar)
- Document template editor (with placeholder buttons: §nome§, §email§, &field&, #data#)
- Email templates: confirmation subject+body, notification subject+body

**Tech**: SortableJS for drag-and-drop, vanilla JS for field configuration modals, Tailwind for layout.

### 5.8 `admin/responses.php` — Response Management

- Table: form name, submitter name, date, status (responded/pending), actions
- Search by form, submitter
- View PDF in modal/iframe
- Respond: textarea → mark as responded → send notification email
- Delete response

### 5.9 `admin/users.php` — User Management

- Table: name, email, admin status, TOTP status
- Actions: toggle admin, delete, remove TOTP
- Pre-register new users
- Search

### 5.10 `admin/settings.php` — App Settings

- Form with fields for all `config` table keys
- Brand name, email domain, TOTP requirement toggle, blocked emails regex, app mode
- Save updates to `config` table
- Regex tester for blocked emails

---

## 6. UI Design System (Tailwind)

### Color Palette
```
Primary:   indigo-600 (#4F46E5) — buttons, links, accents
Success:   emerald-500 (#10B981) — enabled badges, confirmations
Danger:    red-500 (#EF4444) — delete, errors, required asterisks
Warning:   amber-500 (#F59E0B) — pending status
Neutral:   slate-50 to slate-900 — backgrounds, text
```

### Typography
- Font: system font stack (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto...`)
- Headings: `font-semibold`, tracking tight
- Body: `text-slate-700` on light, `text-slate-300` on dark

### Layout
- Max-width container: `max-w-5xl mx-auto`
- Cards: `bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700`
- Buttons: `rounded-lg` with hover/focus states
- Forms: floating labels or standard labels-above pattern

### Dark Mode
- Uses Tailwind `dark:` variant + `prefers-color-scheme` media query
- Manual toggle stored in localStorage
- `theme.js` handles initial detection + toggle persistence

---

## 7. Authentication Flow (Detailed)

Two parallel, independent authentication paths:

### Path A: Email OTP

1. **Email entry**: User enters email → `POST /login?step=send`
2. **Send code**: Rate limit check (10/hr/IP via `RateLimit::reserve('send_code', 10, 3600)`) → generate 6-digit OTP → `password_hash()` → store in `cache.otp_code_hash` + `cache.otp_expires` (10 min expiry) → `INSERT INTO cache ... ON DUPLICATE KEY UPDATE` for new users (id = email-based UUID, nome = NULL) → send HTML email via `Mailer::sendOtp()` → show code form
3. **Verify code**: Rate limit check (5 per-user via `RateLimit::checkUser('verify_code', $userId, 5, 600)`) → `password_verify()` submitted code against stored hash → on success: `RateLimit::clear('verify_code:' . $userId)` → if `cache.nome IS NULL` → redirect to `/login?step=setup` for name setup, else proceed
4. **Name setup**: User sets their name → update `cache.nome` → check if this is the **first user**: attempt atomic `INSERT INTO config (config_key, config_value) VALUES ('first_user_admin_id', ?) ON DUPLICATE KEY UPDATE config_value = config_value` using the user's ID. If `affected_rows === 1`, the user won the race → set `cache.admin = TRUE` and `Config::set('initial_setup_complete', 'true')`. If `affected_rows === 0`, this is a regular user → complete login
5. **TOTP** (if user is admin AND `Config::adminRequiresTotp()` → `true`): Generate secret via `Auth::generateTotpSecret()` → show QR code (inline SVG via chillerlan `QRMarkupSVG`, no third-party API) → user enters code → `Auth::verifyTotp()` → store `cache.totp_secret` → complete login

### Path B: OAuth2 (Microsoft Azure)

1. **Initiate**: User clicks "Sign in with Microsoft" → `Auth::getOAuthUrl()` generates state parameter, stores in session → redirects to Microsoft login
2. **Callback** (`?code=&state=`):
   - Validate state parameter (secure comparison) → exchange code for token → fetch user info
   - If email matches existing user (`cache.email`): login as that user → skip to step 4
   - If new user: create record in `cache` with UUID generated from OAuth2 `sub` claim
   - **Pre-registered user migration**: If the user's email matches a pre-registered record (`id LIKE 'pre_%'` or `id LIKE 'pending_%'` or `id LIKE 'admin_first_%'`), perform an **atomic transaction**: INSERT the real OAuth record → UPDATE all foreign key references in `respostas` and `logs` → DELETE the old prefixed record → COMMIT. This preserves history while transitioning to the real account.
   - If `blocked_emails_regex` is configured and the email matches → reject login (admins exempt)
3. **First-user admin claim**: Same race-safe mechanism as Path A step 4 (first OAuth user also gets admin if `cache` is empty)
4. **TOTP**: Same as Path A step 5

After login: `Auth::login()` sets `$_SESSION['id']`, `$_SESSION['nome']`, `$_SESSION['email']`, `$_SESSION['admin']`, `$_SESSION['validity']` (time + 1800), regenerates session ID + CSRF token. Calls `Logger::log('Login bem-sucedido', ['method' => 'otp'|'oauth'])`.

---

## 8. Form JSON Schema (DB Format)

Same as existing `formlist/*.json` but stored in `forms` table columns:

```json
// forms.campos — array position IS the sort order
// After drag-and-drop, the builder splices/reorders the array.
// Each campo object:
{
  "idcampo": "datainicio",
  "descricao": "Data de início",
  "tipo": "date",
  "obrigatorio": true,
  "placeholder": "",
  "opcoes": [],  // for select/checkbox/radio: ["Option A", "Option B"]
  "rows": null   // for textarea: number of rows
}

// forms.doc
{
  "criar": true,
  "texto": "Eu, §nomecompleto§, declaro..."
}

// forms.email
{
  "assuntoconfirmacao": "Confirmação...",
  "confirmacao": "Estimado §nome§...",
  "assuntonotificacao": "Resposta ao...",
  "notificacao": "Estimado §nome§, §resposta§..."
}
```

Template placeholders:
- `§nomecompleto§` → user's full name (from cache.nome)
- `§nome§` → user's first name
- `§id§` → user's ID
- `§email§` → user's email
- `&fieldid&` → submitted field value
- `#data#` → current date (d/m/Y)
- `§resposta§` → admin's response text

---

## 9. Dependencies (composer.json)

```json
{
    "require": {
        "php": ">=8.2",
        "phpmailer/phpmailer": "^7.1",
        "fpdf/fpdf": "^1.86",
        "league/oauth2-client": "^2.7",
        "pragmarx/google2fa": "^8.0",
        "chillerlan/php-qrcode": "^5.0",
        "ezyang/htmlpurifier": "^4.17"
    }
}
```

**Removed**: `juoum/giaeconnect` (GIAE auth), `setasign/fpdf` (broken package)

---

## 10. Security Measures

| Measure | Implementation |
|---------|---------------|
| SQL Injection | Prepared statements for all queries (mysqli `prepare` + `bind_param`) |
| XSS | `htmlspecialchars(ENT_QUOTES, 'UTF-8')` on all output |
| CSRF | Per-session tokens, `hash_equals` verification, global JS injector |
| Session | httponly, SameSite=Lax, strict mode, 48-char SIDs, session_regenerate_id |
| Rate Limiting | Atomic `SELECT FOR UPDATE`, sliding windows, MySQL `NOW()` clock |
| OAuth2 | State parameter validation, secure comparison |
| TOTP | Local QR rendering (no third-party API), `password_verify` for OTP |
| File Protection | `.htaccess` blocks config, .md, .git, .env |
| Security Headers | X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy |
| Input Validation | Email format, UUID format, date format, action whitelist |
| HTML Sanitization | HTMLPurifier for rich text content |
| Log Redaction | Strips passwords/tokens from audit logs |

---

## 10a. `.htaccess` Specification

```apache
# Security headers
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Block directory listing
Options -Indexes

# Protect sensitive files
<FilesMatch "^(config\.php|\.env|composer\.(json|lock))$">
    Require all denied
</FilesMatch>

# Block access to hidden files/dirs
RedirectMatch 404 /\..*

# URL rewriting (optional, for clean URLs)
RewriteEngine On
RewriteBase /
```

---

## 11. Implementation Order (Dependency Graph)

```
Phase 1    Phase 2       Phase 3    Phase 4    Phase 5     Phase 6        Phase 7   Phase 8
config   → Session    → Auth     → index   → Validator → FormBuilder  → admin/  → .htaccess
db       → Csrf      → login/   → form    → Mailer    → admin/forms  → respon  → cleanup
Config   → RateLimit → Logger   → filled             → forms_edit   → users   → composer
                                                      → form-builder → settings → CI
```

**Batches**:
- Batch A: Phase 1 (config, db, Config) — foundation
- Batch B: Phase 2 (Session, Csrf, RateLimit) — security layer
- Batch C: Phase 3 (Auth, login/, Logger) — auth + logging (Logger needed by Auth's login events)
- Batch D: Phase 4 (index, form, filled) — depends on B + C
- Batch E: Phase 5 (Validator, Mailer) — validation and email utilities
- Batch F: Phase 6 (FormBuilder, admin forms) — depends on D + E
- Batch G: Phase 7 (admin pages) — depends on F
- Batch H: Phase 8 (polish) — depends on G

---

## 12. Exit Criteria

- [ ] All SQLite3 code removed; MySQLi prepared statements everywhere
- [ ] CSRF protected on all POST forms
- [ ] Rate limiting on auth endpoints
- [ ] OAuth2 + TOTP authentication working
- [ ] Forms stored in DB, CRUD via admin panel
- [ ] Drag-and-drop form builder functional
- [ ] Admin panel split into modular files under `admin/`
- [ ] Tailwind CSS design applied, dark mode working
- [ ] No `utf8_encode`/`utf8_decode` calls
- [ ] No `FILTER_UNSAFE_RAW` for validation
- [ ] Security headers in `.htaccess`
- [ ] `formlist/`, `mail.php`, `login.php`, old `admin.php`, `config.php` removed
- [ ] Psalm static analysis passes (no errors)
- [ ] PHP ≥8.2 compatible (no deprecated features)
- [ ] Existing `formlist/*.json` forms migrated to database (manual or scripted)
