<?php
/**
 * FormFill Dashboard
 * 
 * Lists available forms for the authenticated user.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/db.php';

use FormFill\Lib\Session;
use FormFill\Lib\Csrf;
use FormFill\Lib\Config;
use FormFill\Lib\FormBuilder;

Session::requireLogin();

$forms = FormBuilder::list();
$brand = Config::brandName();
?>
<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($brand) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { brand: { 50: '#eef2ff', 100: '#e0e7ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca' } } } }
        }
    </script>
    <script>
        // Dark mode detection
        if (window.matchMedia('(prefers-color-scheme: dark)').matches || localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-slate-900">
    <?php if (Config::isDev()): ?>
    <div class="sticky top-0 z-50 bg-red-500 text-white text-xs font-bold text-center py-1">
        ⚠️ MODO DE DESENVOLVIMENTO
    </div>
    <?php endif; ?>

    <!-- Navbar -->
    <nav class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 sticky top-0 z-40 <?= Config::isDev() ? 'top-6' : 'top-0' ?>">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
            <a href="/" class="text-lg font-bold text-brand-600 dark:text-brand-400"><?= htmlspecialchars($brand) ?></a>
            <div class="flex items-center gap-4">
                <?php if (!empty($_SESSION['admin'])): ?>
                <a href="/admin/" class="text-sm text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 transition">Admin</a>
                <?php endif; ?>
                <div class="relative group">
                    <button class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                        <div class="w-8 h-8 rounded-full bg-brand-100 dark:bg-brand-900 flex items-center justify-center text-brand-600 dark:text-brand-400 font-medium text-sm">
                            <?= htmlspecialchars(mb_substr($_SESSION['nome'] ?? '?', 0, 2)) ?>
                        </div>
                        <span class="hidden sm:inline"><?= htmlspecialchars($_SESSION['nome'] ?? 'Utilizador') ?></span>
                    </button>
                    <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
                        <div class="py-1">
                            <div class="px-4 py-2 text-xs text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                                <?= htmlspecialchars($_SESSION['email'] ?? '') ?>
                            </div>
                            <a href="/login?step=logout" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-slate-50 dark:hover:bg-slate-700">Sair</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-5xl mx-auto px-4 py-8">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Formulários disponíveis</h1>
            <p class="text-slate-500 dark:text-slate-400 mt-1">Selecione um formulário para preencher.</p>
        </div>

        <?php if (empty($forms)): ?>
        <div class="text-center py-16">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 mb-4">
                <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            <h3 class="text-lg font-medium text-slate-700 dark:text-slate-300">Nenhum formulário disponível</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Volte mais tarde ou contacte um administrador.</p>
        </div>
        <?php else: ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($forms as $form): ?>
            <a href="/form.php?id=<?= urlencode($form['id']) ?>"
               class="block bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 hover:shadow-md hover:border-brand-300 dark:hover:border-brand-600 transition-all group">
                <h3 class="font-semibold text-slate-900 dark:text-slate-100 group-hover:text-brand-600 dark:group-hover:text-brand-400 transition">
                    <?= htmlspecialchars($form['nome']) ?>
                </h3>
                <?php if (!empty($form['descricao'])): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 line-clamp-2">
                    <?= htmlspecialchars($form['descricao']) ?>
                </p>
                <?php endif; ?>
                <div class="mt-4 flex items-center justify-between">
                    <span class="text-xs text-slate-400 dark:text-slate-500">
                        <?= htmlspecialchars($form['total_respostas']) ?> respostas
                    </span>
                    <span class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 dark:text-brand-400">
                        Preencher
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
    <?= Csrf::globalInjector() ?>
</body>
</html>
