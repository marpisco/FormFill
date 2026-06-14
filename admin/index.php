<?php
/**
 * FormFill Admin Shell
 * 
 * Include this at the top of every admin page. Provides:
 * - Session guard + admin check
 * - CSRF protection on all POST requests
 * - Shared Tailwind navbar
 * - acaoexecutada() helper for audit logging
 * - Dev mode banners
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/db.php';

use FormFill\Lib\Session;
use FormFill\Lib\Csrf;
use FormFill\Lib\Config;
use FormFill\Lib\Logger;
use FormFill\Lib\Validator;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Guards ──────────────────────────────────────────────────────────────────
Session::requireAdmin();

// Extend session on every admin page load
Session::extend();

// CSRF verification on all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Csrf::verify($csrfToken)) {
        http_response_code(403);
        die("<div class='p-8 text-center text-red-500'>Pedido inválido. Atualize a página e tente novamente.</div>");
    }
}

// ─── acaoexecutada() ────────────────────────────────────────────────────────
function acaoexecutada(string $acao): void
{
    $acaoSegura = htmlspecialchars($acao, ENT_QUOTES, 'UTF-8');
    echo "<div id='acao-alert' class='mb-4 px-4 py-3 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-lg flex items-center justify-between'>
        <span class='text-emerald-700 dark:text-emerald-300 text-sm'>✅ Ação executada. <b>{$acaoSegura}</b></span>
        <button onclick='this.parentElement.remove()' class='text-emerald-400 hover:text-emerald-600 ml-4'>&times;</button>
    </div>
    <script>setTimeout(function(){ var a=document.getElementById('acao-alert'); if(a)a.remove(); }, 5000);</script>";

    $safePost = Validator::redactSensitive($_POST);
    $safeGet  = Validator::redactSensitive($_GET);
    Logger::log($acaoSegura . ".\nPOST: " . var_export($safePost, true) . "\nGET: " . var_export($safeGet, true), []);
}

// ─── Helper: active nav link ────────────────────────────────────────────────
function navLink(string $url, string $label): string
{
    $current = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $isActive = ($current === $url) || ($url !== '/admin/' && str_starts_with($current, $url));
    $activeClass = $isActive 
        ? 'text-brand-600 dark:text-brand-400 font-medium' 
        : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white';
    $urlSafe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return "<a href='{$urlSafe}' class='text-sm {$activeClass} transition'>{$labelSafe}</a>";
}

$brand = Config::brandName();
$nomeSafe = htmlspecialchars($_SESSION['nome'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — <?= htmlspecialchars($brand) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { brand: { 50: '#eef2ff', 100: '#e0e7ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca' } } } }
        }
    </script>
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-slate-900">
    <?php if (Config::isDev()): ?>
    <div class="sticky top-0 z-50 bg-red-500 text-white text-xs font-bold text-center py-1">
        ⚠️ MODO DE DESENVOLVIMENTO — <?= htmlspecialchars($brand) ?>
    </div>
    <?php endif; ?>

    <!-- Navbar -->
    <?php $stickyTop = Config::isDev() ? 'top-6' : 'top-0'; ?>
    <nav class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 sticky <?= $stickyTop ?> z-40">
        <div class="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between">
            <div class="flex items-center gap-6">
                <a href="/admin/" class="text-lg font-bold text-brand-600 dark:text-brand-400">
                    <?= htmlspecialchars($brand) ?>
                </a>
                <div class="hidden sm:flex items-center gap-4">
                    <?= navLink('/admin/', 'Dashboard') ?>
                    <?= navLink('/admin/forms.php', 'Formulários') ?>
                    <?= navLink('/admin/responses.php', 'Respostas') ?>
                    <?= navLink('/admin/registos.php', 'Registos') ?>
                    <?= navLink('/admin/users.php', 'Utilizadores') ?>
                    <?= navLink('/admin/settings.php', 'Configurações') ?>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-slate-500 dark:text-slate-400"><?= $nomeSafe ?></span>
                <a href="/" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition">Sair do admin</a>
            </div>
        </div>
    </nav>

    <!-- Global CSRF injector -->
    <?= Csrf::globalInjector() ?>

    <main class="max-w-6xl mx-auto px-4 py-6">
<?php
// ─── Admin Dashboard (shown only on /admin/) ─────────────────────────────────
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/admin/') {
    echo "<h1 class='text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2'>Olá, {$nomeSafe}</h1>";
    echo "<p class='text-slate-500 dark:text-slate-400 mb-8'>O que vamos fazer hoje?</p>";

    global $db;

    // Stats cards
    $formsCount = $db->query("SELECT COUNT(*) as c FROM forms")->fetch_assoc()['c'] ?? 0;
    $activeForms = $db->query("SELECT COUNT(*) as c FROM forms WHERE ativado = TRUE")->fetch_assoc()['c'] ?? 0;
    $usersCount = $db->query("SELECT COUNT(*) as c FROM cache")->fetch_assoc()['c'] ?? 0;
    $pendingCount = $db->query("SELECT COUNT(*) as c FROM respostas WHERE respondido = FALSE")->fetch_assoc()['c'] ?? 0;

    $stats = [
        ['label' => 'Formulários', 'value' => $formsCount, 'sub' => "{$activeForms} ativos", 'color' => 'brand'],
        ['label' => 'Utilizadores', 'value' => $usersCount, 'sub' => 'registados', 'color' => 'emerald'],
        ['label' => 'Pendentes', 'value' => $pendingCount, 'sub' => 'por responder', 'color' => 'amber'],
    ];

    echo '<div class="grid gap-4 sm:grid-cols-3 mb-8">';
    foreach ($stats as $stat) {
        $colors = [
            'brand'   => 'border-brand-200 dark:border-brand-700 bg-brand-50 dark:bg-brand-950',
            'emerald' => 'border-emerald-200 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-950',
            'amber'   => 'border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950',
        ];
        $textColors = [
            'brand'   => 'text-brand-700 dark:text-brand-300',
            'emerald' => 'text-emerald-700 dark:text-emerald-300',
            'amber'   => 'text-amber-700 dark:text-amber-300',
        ];
        $labelColors = [
            'brand'   => 'text-slate-500 dark:text-brand-200/70',
            'emerald' => 'text-slate-500 dark:text-emerald-200/70',
            'amber'   => 'text-slate-500 dark:text-amber-200/70',
        ];
        $subColors = [
            'brand'   => 'text-slate-400 dark:text-brand-300/50',
            'emerald' => 'text-slate-400 dark:text-emerald-300/50',
            'amber'   => 'text-slate-400 dark:text-amber-300/50',
        ];
        echo "<div class='rounded-xl border {$colors[$stat['color']]} p-5'>
            <p class='text-sm {$labelColors[$stat['color']]}'>{$stat['label']}</p>
            <p class='text-3xl font-bold {$textColors[$stat['color']]} mt-1'>{$stat['value']}</p>
            <p class='text-xs {$subColors[$stat['color']]} mt-1'>{$stat['sub']}</p>
        </div>";
    }
    echo '</div>';

    // Quick links
    echo '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">';
    $links = [
        ['url' => '/admin/forms.php', 'label' => 'Gerir formulários', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['url' => '/admin/responses.php', 'label' => 'Ver respostas', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['url' => '/admin/users.php', 'label' => 'Gerir utilizadores', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ['url' => '/admin/settings.php', 'label' => 'Configurações', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ];
    foreach ($links as $link) {
        $urlSafe = htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8');
        $labelSafe = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
        echo "<a href='{$urlSafe}' class='flex items-center gap-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:border-brand-300 dark:hover:border-brand-600 hover:shadow-sm transition group'>
            <svg class='w-5 h-5 text-slate-400 group-hover:text-brand-500 transition' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
                <path stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='{$link['icon']}' />
            </svg>
            <span class='text-sm font-medium text-slate-700 dark:text-slate-200 group-hover:text-brand-600 dark:group-hover:text-brand-400 transition'>{$labelSafe}</span>
        </a>";
    }
    echo '</div>';
    // Close tags for dashboard (subpages add their own)
    echo '</main>';
}

// Dev mode bottom banner
if (Config::isDev()) {
    echo '<div class="fixed bottom-0 left-0 right-0 bg-red-500 text-white text-xs font-bold text-center py-1 z-50">⚠️ MODO DE DESENVOLVIMENTO</div>';
}
?>
