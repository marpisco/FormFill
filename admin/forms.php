<?php
/**
 * Admin — Form List
 */

require 'index.php';

use FormFill\Lib\FormBuilder;
use FormFill\Lib\Csrf;

$action = $_GET['action'] ?? 'list';
$message = null;
$error = null;

// Toggle form
if ($action === 'toggle' && !empty($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (FormBuilder::toggle($_GET['id'])) {
        acaoexecutada("Formulário ativado/desativado");
    }
}

// Delete form
if ($action === 'delete' && !empty($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (FormBuilder::delete($_GET['id'])) {
        acaoexecutada("Formulário eliminado");
    }
}

$forms = FormBuilder::list(true);
?>

<h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2">Formulários</h1>
<p class="text-slate-500 dark:text-slate-400 mb-6">Crie e gira os formulários disponíveis para preenchimento.</p>

<div class="mb-4">
    <a href="/admin/forms_edit.php" class="inline-flex items-center gap-2 py-2 px-4 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Novo formulário
    </a>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Nome</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden sm:table-cell">Estado</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden md:table-cell">Respostas</th>
                <th class="text-right px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Ações</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <?php if (empty($forms)): ?>
            <tr>
                <td colspan="4" class="px-4 py-8 text-center text-slate-400">Nenhum formulário criado.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($forms as $form): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                <td class="px-4 py-3">
                    <p class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($form['nome']) ?></p>
                    <?php if (!empty($form['descricao'])): ?>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 truncate max-w-xs"><?= htmlspecialchars($form['descricao']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden sm:table-cell">
                    <?php if ($form['ativado']): ?>
                    <span class="inline-flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-full">Ativo</span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded-full">Inativo</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-500 hidden md:table-cell"><?= (int)($form['total_respostas'] ?? 0) ?></td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <a href="/admin/forms_edit.php?id=<?= urlencode($form['id']) ?>" 
                           class="px-2 py-1 text-xs text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-900/20 rounded transition">Editar</a>
                        <a href="/form.php?id=<?= urlencode($form['id']) ?>" target="_blank"
                           class="px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded transition">Ver</a>
                        <form method="POST" action="?action=toggle&id=<?= urlencode($form['id']) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <button type="submit" class="px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded transition">
                                <?= $form['ativado'] ? 'Desativar' : 'Ativar' ?>
                            </button>
                        </form>
                        <form method="POST" action="?action=delete&id=<?= urlencode($form['id']) ?>" class="inline" onsubmit="return confirm('Eliminar este formulário?')">
                            <?= Csrf::field() ?>
                            <button type="submit" class="px-2 py-1 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">Eliminar</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</main></body></html>
