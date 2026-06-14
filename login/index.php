<?php
/**
 * FormFill Authentication Page
 * 
 * Two parallel paths:
 *   A — Email OTP → name setup → optional TOTP
 *   B — Microsoft OAuth2 → optional TOTP
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/db.php';

use FormFill\Lib\Session;
use FormFill\Lib\Csrf;
use FormFill\Lib\Auth;
use FormFill\Lib\Config;
use FormFill\Lib\RateLimit;

// Redirect if already logged in (unless doing OAuth callback or logout)
$isCallback = isset($_GET['code']) && isset($_GET['state']);
$isLogout = ($_GET['step'] ?? '') === 'logout';
if (!$isCallback && !$isLogout && isset($_SESSION['id']) && Session::isValid()) {
    header('Location: /');
    exit();
}

$step  = $_GET['step'] ?? ($_POST['step'] ?? 'email');
$error = null;
$message = null;
$needsTotp = false;

// ─── Route: OAuth2 Callback ──────────────────────────────────────────────────
if ($isCallback) {
    $result = Auth::handleOAuthCallback($_GET['code'], $_GET['state']);
    if (!$result['success']) {
        $error = $result['message'];
        $step = 'email';
    } elseif (!empty($result['needsTotp'])) {
        $needsTotp = true;
        $step = 'totp';
    } else {
        header('Location: /');
        exit();
    }
}

// ─── Route: Email OTP — Send Code ────────────────────────────────────────────
if ($step === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Pedido inválido. Atualize a página.';
    } else {
        $result = Auth::sendCode($_POST['email'] ?? '');
        if ($result['success']) {
            $_SESSION['pending_email'] = $_POST['email'];
            $message = $result['message'];
            $step = 'verify';
        } else {
            $error = $result['message'];
            $step = 'email';
        }
    }
}

// ─── Route: Email OTP — Verify Code ──────────────────────────────────────────
if ($step === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['code'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Pedido inválido.';
    } else {
        $email = $_SESSION['pending_email'] ?? '';
        $result = Auth::verifyCode($email, $_POST['code'] ?? '');
        if (!$result['success']) {
            $error = $result['message'];
        } elseif (!empty($result['needsNameSetup'])) {
            $step = 'setup';
        } elseif (!empty($result['needsTotp'])) {
            $needsTotp = true;
            $step = 'totp';
        } else {
            header('Location: /');
            exit();
        }
    }
}

// ─── Route: Name Setup ───────────────────────────────────────────────────────
if ($step === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['nome'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Pedido inválido.';
    } else {
        $result = Auth::setupName($_POST['nome'] ?? '');
        if (!$result['success']) {
            $error = $result['message'];
        } elseif (!empty($result['needsTotp'])) {
            $needsTotp = true;
            $step = 'totp';
        } else {
            header('Location: /');
            exit();
        }
    }
}

// ─── Route: TOTP Setup/Verify ────────────────────────────────────────────────
$totpSecret = null;
$totpQrSvg = null;

if ($step === 'totp') {
    // Case A — New admin needs TOTP setup (first time enrollment)
    if (isset($_SESSION['pending_totp_setup'])) {
        if (!isset($_SESSION['pending_totp_secret'])) {
            $_SESSION['pending_totp_secret'] = Auth::generateTotpSecret();
        }
        $totpSecret = $_SESSION['pending_totp_secret'];
        $totpQrSvg = Auth::getTotpQrSvg($totpSecret, $_SESSION['pending_totp_email'] ?? '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = 'Pedido inválido.';
            } elseif (!RateLimit::reserveUser('verify_totp_setup', $_SESSION['pending_totp_setup'], 5, 900)) {
                $error = 'Demasiadas tentativas. Aguarde 15 minutos.';
            } elseif (Auth::verifyTotp($totpSecret, $_POST['totp_code'] ?? '')) {
                RateLimit::clearUser('verify_totp_setup', $_SESSION['pending_totp_setup']);
                Auth::completeTotpSetup($totpSecret);
                unset($_SESSION['pending_totp_secret']);
                header('Location: /');
                exit();
            } else {
                $error = 'Código TOTP incorreto. Tente novamente.';
            }
        }
    }
    // Case B — Enrolled admin needs TOTP verification during fresh login
    elseif (isset($_SESSION['pending_totp_verify'])) {
        $pendingUser = $_SESSION['pending_totp_verify'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = 'Pedido inválido.';
            } elseif (!RateLimit::reserveUser('verify_totp_login', $pendingUser['id'], 5, 900)) {
                $error = 'Demasiadas tentativas. Aguarde 15 minutos.';
            } else {
                global $db;
                $stmt = $db->prepare("SELECT totp_secret FROM cache WHERE id = ?");
                $stmt->bind_param("s", $pendingUser['id']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($row && $row['totp_secret'] && Auth::verifyTotp($row['totp_secret'], $_POST['totp_code'] ?? '')) {
                    RateLimit::clearUser('verify_totp_login', $pendingUser['id']);
                    $_SESSION['totp_verified'] = true;
                    Auth::login($pendingUser, !empty($pendingUser['admin']));
                    unset($_SESSION['pending_totp_verify']);
                    header('Location: /');
                    exit();
                } else {
                    $error = 'Código TOTP incorreto.';
                }
            }
        }
    }
    // Case C — Returning admin with active session verifies TOTP
    elseif (!empty($_SESSION['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $error = 'Pedido inválido.';
        } elseif (!RateLimit::reserveUser('verify_totp_return', $_SESSION['id'], 5, 900)) {
            $error = 'Demasiadas tentativas. Aguarde 15 minutos.';
        } else {
            global $db;
            $stmt = $db->prepare("SELECT totp_secret FROM cache WHERE id = ?");
            $stmt->bind_param("s", $_SESSION['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && $row['totp_secret'] && Auth::verifyTotp($row['totp_secret'], $_POST['totp_code'] ?? '')) {
                RateLimit::clearUser('verify_totp_return', $_SESSION['id']);
                // TOTP verified — full session
                $_SESSION['totp_verified'] = true;
                header('Location: /admin/');
                exit();
            } else {
                $error = 'Código TOTP incorreto.';
            }
        }
    }
}

// ─── Route: Logout ───────────────────────────────────────────────────────────
if ($step === 'logout') {
    Auth::logout();
}
?>
<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(Config::brandName()) ?> — Entrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe',
                            500: '#6366f1', 600: '#4f46e5', 700: '#4338ca'
                        }
                    }
                }
            }
        }
    </script>
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches || localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-brand-50 via-white to-indigo-50 dark:from-slate-950 dark:via-slate-900 dark:to-brand-950">
    <div class="w-full max-w-md">
        <!-- Logo / Brand -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-brand-600 dark:text-brand-400">
                <?= htmlspecialchars(Config::brandName()) ?>
            </h1>
            <p class="text-slate-500 dark:text-slate-400 mt-1">Preenchimento de formulários</p>
        </div>

        <!-- Card -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg border border-slate-200 dark:border-slate-700 p-8">
            
            <?php if (Config::isDev()): ?>
            <div class="mb-4 px-3 py-2 bg-red-500 text-white text-xs font-bold text-center rounded-lg">
                ⚠️ MODO DE DESENVOLVIMENTO
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="mb-4 px-4 py-3 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg">
                <p class="text-red-700 dark:text-red-300 text-sm"><?= htmlspecialchars($error) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($message): ?>
            <div class="mb-4 px-4 py-3 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                <p class="text-emerald-700 dark:text-emerald-300 text-sm"><?= htmlspecialchars($message) ?></p>
            </div>
            <?php endif; ?>

            <!-- ═══ STEP: Email Entry ═══ -->
            <?php if ($step === 'email'): ?>
                <h2 class="text-xl font-semibold text-slate-800 dark:text-slate-200 mb-2">Entrar</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Insira o seu email para receber um código de verificação.</p>

                <form method="POST" action="?step=send" class="space-y-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email</label>
                        <input type="email" id="email" name="email" required autofocus
                               class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                               placeholder="voce@exemplo.com">
                    </div>
                    <button type="submit"
                            class="w-full py-2.5 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition duration-150">
                        Enviar código
                    </button>
                </form>

                <!-- OAuth2 button (only when enabled) -->
                <?php $oauthUrl = Auth::getOAuthUrl(); ?>
                <?php if ($oauthUrl): ?>
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200 dark:border-slate-700"></div></div>
                    <div class="relative flex justify-center text-sm"><span class="px-3 bg-white dark:bg-slate-800 text-slate-500">ou</span></div>
                </div>
                <a href="<?= $oauthUrl ?>"
                   class="w-full py-2.5 px-4 flex items-center justify-center gap-2 bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white font-medium rounded-lg transition duration-150">
                    <svg class="w-5 h-5" viewBox="0 0 21 21"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
                    Entrar com Microsoft
                </a>
                <?php endif; ?>

            <!-- ═══ STEP: Verify OTP ═══ -->
            <?php elseif ($step === 'verify'): ?>
                <h2 class="text-xl font-semibold text-slate-800 dark:text-slate-200 mb-2">Verificar código</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                    Enviámos um código de 6 dígitos para <strong><?= htmlspecialchars($_SESSION['pending_email'] ?? '') ?></strong>.
                </p>

                <form method="POST" action="?step=verify" class="space-y-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label for="code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Código</label>
                        <input type="text" id="code" name="code" required autofocus maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                               class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-center text-2xl tracking-[0.5em] font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                               placeholder="000000">
                    </div>
                    <button type="submit"
                            class="w-full py-2.5 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition duration-150">
                        Verificar
                    </button>
                </form>
                <p class="text-center mt-4 text-sm">
                    <a href="?step=email" class="text-brand-600 dark:text-brand-400 hover:underline">← Voltar e usar outro email</a>
                </p>

            <!-- ═══ STEP: Name Setup ═══ -->
            <?php elseif ($step === 'setup'): ?>
                <h2 class="text-xl font-semibold text-slate-800 dark:text-slate-200 mb-2">Completar registo</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Como se chama?</p>

                <form method="POST" action="?step=setup" class="space-y-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label for="nome" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Nome completo</label>
                        <input type="text" id="nome" name="nome" required autofocus minlength="2"
                               class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                               placeholder="O seu nome">
                    </div>
                    <button type="submit"
                            class="w-full py-2.5 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition duration-150">
                        Continuar
                    </button>
                </form>

            <!-- ═══ STEP: TOTP ═══ -->
            <?php elseif ($step === 'totp'): ?>
                <h2 class="text-xl font-semibold text-slate-800 dark:text-slate-200 mb-2">Autenticação de dois fatores</h2>

                <?php if ($totpQrSvg): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                    Use uma aplicação de autenticação (Google Authenticator, Microsoft Authenticator) para digitalizar o QR code abaixo.
                </p>
                <div class="flex justify-center mb-4 p-4 bg-white rounded-lg">
                    <?= $totpQrSvg ?>
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500 mb-4 text-center">
                    Código secreto: <code class="bg-slate-100 dark:bg-slate-700 px-1 rounded"><?= htmlspecialchars($totpSecret) ?></code>
                </p>
                <?php else: ?>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                    Insira o código da sua aplicação de autenticação.
                </p>
                <?php endif; ?>

                <form method="POST" action="?step=totp" class="space-y-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label for="totp_code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Código de 6 dígitos</label>
                        <input type="text" id="totp_code" name="totp_code" required autofocus maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                               class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-center text-2xl tracking-[0.5em] font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                               placeholder="000000">
                    </div>
                    <button type="submit"
                            class="w-full py-2.5 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition duration-150">
                        Verificar
                    </button>
                </form>

            <?php endif; ?>
        </div>

        <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-6">
            &copy; <?= date('Y') ?> <?= htmlspecialchars(Config::brandName()) ?>
        </p>
    </div>
    <?= Csrf::globalInjector() ?>
</body>
</html>
