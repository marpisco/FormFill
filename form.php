<?php
/**
 * FormFill — Form Rendering
 * 
 * Renders a dynamic HTML form from a database form definition.
 * Accepts ?id= (form UUID) query parameter.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/db.php';

use FormFill\Lib\Session;
use FormFill\Lib\Csrf;
use FormFill\Lib\Config;
use FormFill\Lib\FormBuilder;

Session::requireLogin();

$formId = $_GET['id'] ?? '';
if (empty($formId)) {
    http_response_code(400);
    die("<div class='p-8 text-center text-red-500'>Formulário não especificado.</div>");
}

$form = FormBuilder::get($formId);
if (!$form || empty($form['ativado'])) {
    http_response_code(404);
    die("<div class='p-8 text-center text-red-500'>Formulário não encontrado ou desativado.</div>");
}

$brand = Config::brandName();
$campos = $form['campos'] ?? [];
?>
<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($form['nome']) ?> — <?= htmlspecialchars($brand) ?></title>
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
    <div class="sticky top-0 z-50 bg-red-500 text-white text-xs font-bold text-center py-1">⚠️ MODO DE DESENVOLVIMENTO</div>
    <?php endif; ?>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <!-- Header -->
        <a href="/" class="text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 transition mb-4 inline-block">← Voltar</a>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-2"><?= htmlspecialchars($form['nome']) ?></h1>
        <?php if (!empty($form['instrucoes'])): ?>
        <p class="text-slate-500 dark:text-slate-400 mt-1"><?= htmlspecialchars($form['instrucoes']) ?></p>
        <?php endif; ?>

        <!-- Form -->
        <form action="/filled.php" method="POST" enctype="multipart/form-data" class="mt-8 space-y-6">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_id" value="<?= htmlspecialchars($form['id']) ?>">

            <?php foreach ($campos as $campo): ?>
                <?php
                $tipo = $campo['tipo'] ?? 'text';
                // Skip hidden fields from rendering (they're for internal use)
                if ($tipo === 'hidden') continue;
                ?>
                <div>
                    <label for="field_<?= htmlspecialchars($campo['idcampo']) ?>" 
                           class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        <?= htmlspecialchars($campo['descricao']) ?>
                        <?php if (!empty($campo['obrigatorio'])): ?>
                        <span class="text-red-500">*</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($tipo === 'textarea'): ?>
                    <textarea id="field_<?= htmlspecialchars($campo['idcampo']) ?>"
                              name="<?= htmlspecialchars($campo['idcampo']) ?>"
                              rows="<?= (int)($campo['rows'] ?? 4) ?>"
                              placeholder="<?= htmlspecialchars($campo['placeholder'] ?? '') ?>"
                              <?= !empty($campo['obrigatorio']) ? 'required' : '' ?>
                              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"></textarea>

                    <?php elseif ($tipo === 'select'): ?>
                    <select id="field_<?= htmlspecialchars($campo['idcampo']) ?>"
                            name="<?= htmlspecialchars($campo['idcampo']) ?>"
                            <?= !empty($campo['obrigatorio']) ? 'required' : '' ?>
                            class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                        <option value="">Selecionar...</option>
                        <?php foreach ($campo['opcoes'] ?? [] as $opcao): ?>
                        <option value="<?= htmlspecialchars($opcao) ?>"><?= htmlspecialchars($opcao) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php elseif ($tipo === 'checkbox'): ?>
                    <div class="space-y-2">
                        <?php foreach ($campo['opcoes'] ?? [] as $opcao): ?>
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="<?= htmlspecialchars($campo['idcampo']) ?>[]" 
                                   value="<?= htmlspecialchars($opcao) ?>"
                                   class="rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500">
                            <?= htmlspecialchars($opcao) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <?php elseif ($tipo === 'radio'): ?>
                    <div class="space-y-2">
                        <?php foreach ($campo['opcoes'] ?? [] as $opcao): ?>
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="radio" name="<?= htmlspecialchars($campo['idcampo']) ?>" 
                                   value="<?= htmlspecialchars($opcao) ?>"
                                   <?= !empty($campo['obrigatorio']) ? 'required' : '' ?>
                                   class="border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500">
                            <?= htmlspecialchars($opcao) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <?php elseif ($tipo === 'file'): ?>
                    <input type="file" id="field_<?= htmlspecialchars($campo['idcampo']) ?>"
                           name="<?= htmlspecialchars($campo['idcampo']) ?>"
                           <?= !empty($campo['obrigatorio']) ? 'required' : '' ?>
                           class="w-full text-sm text-slate-600 dark:text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand-50 dark:file:bg-brand-900 file:text-brand-700 dark:file:text-brand-300 hover:file:bg-brand-100 transition">

                    <?php else: ?>
                    <input type="<?= htmlspecialchars($tipo) ?>" 
                           id="field_<?= htmlspecialchars($campo['idcampo']) ?>"
                           name="<?= htmlspecialchars($campo['idcampo']) ?>"
                           placeholder="<?= htmlspecialchars($campo['placeholder'] ?? '') ?>"
                           <?= !empty($campo['obrigatorio']) ? 'required' : '' ?>
                           class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <button type="submit"
                    class="w-full py-3 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition duration-150 shadow-sm hover:shadow-md">
                Submeter formulário
            </button>
        </form>
    </main>
    <?= Csrf::globalInjector() ?>
</body>
</html>
