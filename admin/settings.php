<?php
/**
 * Admin — App Settings
 */

require 'index.php';

use FormFill\Lib\Csrf;
use FormFill\Lib\Config;

$action = $_GET['action'] ?? 'view';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'brand_name', 'internal_email_domain', 'admin_requires_totp',
        'blocked_emails_regex', 'email_account_name', 'app_mode', 'trusted_proxies',
        'admin_response_subject', 'admin_response_body'
    ];

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? null;
        if ($field === 'admin_requires_totp') {
            $value = ($value === 'true') ? 'true' : 'false';
        }
        if ($field === 'blocked_emails_regex' && !empty($value)) {
            // Validate regex before saving — silent failure on invalid regex
            if (@preg_match($value, '') === false) {
                continue; // skip invalid regex
            }
        }
        if ($value !== null) {
            Config::set($field, $value);
        }
    }

    acaoexecutada("Configurações guardadas");
}
?>

<h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2">Configurações</h1>
<p class="text-slate-500 dark:text-slate-400 mb-6">Definições gerais da aplicação.</p>

<form method="POST" action="?action=save" class="max-w-2xl space-y-6">
    <?= Csrf::field() ?>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Nome da aplicação</label>
            <input type="text" name="brand_name" value="<?= htmlspecialchars(Config::get('brand_name', 'FormFill')) ?>"
                   class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Domínio de email interno</label>
            <input type="text" name="internal_email_domain" value="<?= htmlspecialchars(Config::get('internal_email_domain', '')) ?>"
                   placeholder="exemplo.com"
                   class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="text-xs text-slate-400 mt-1">Usado para funcionalidades autónomas.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Nome da conta de email</label>
            <input type="text" name="email_account_name" value="<?= htmlspecialchars(Config::get('email_account_name', 'FormFill')) ?>"
                   class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="text-xs text-slate-400 mt-1">Nome exibido no remetente dos emails.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Assunto do email de resposta</label>
            <input type="text" name="admin_response_subject" value="<?= htmlspecialchars(Config::get('admin_response_subject', '')) ?>"
                   placeholder="§brand§ — Resposta ao seu formulário"
                   class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="text-xs text-slate-400 mt-1">Assunto do email enviado ao utilizador quando respondes a um formulário.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Template do email de resposta</label>
            <textarea name="admin_response_body" rows="6"
                      placeholder="<p>Olá, §nome§!</p><p>Recebeu uma resposta: §resposta§</p>"
                      class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent"><?= htmlspecialchars(Config::get('admin_response_body', '')) ?></textarea>
            <p class="text-xs text-slate-400 mt-1">Template HTML do email. Use §nome§ e §resposta§ como placeholders. Vazio = template padrão.</p>
        </div>

        <div>
            <label class="flex items-center gap-3">
                <input type="checkbox" name="admin_requires_totp" value="true" <?= Config::adminRequiresTotp() ? 'checked' : '' ?>
                       class="rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500">
                <span class="text-sm text-slate-700 dark:text-slate-300">Exigir autenticação de dois fatores para administradores</span>
            </label>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Regex de emails bloqueados</label>
            <input type="text" name="blocked_emails_regex" value="<?= htmlspecialchars(Config::get('blocked_emails_regex', '')) ?>"
                   placeholder="/@exemplo\.com$/"
                   class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm font-mono focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="text-xs text-slate-400 mt-1">Expressão regular para bloquear emails. Vazio = sem bloqueio. Admins são sempre isentos.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Modo da aplicação</label>
            <select name="app_mode" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <option value="production" <?= Config::get('app_mode') === 'production' ? 'selected' : '' ?>>Produção</option>
                <option value="development" <?= Config::get('app_mode') === 'development' ? 'selected' : '' ?>>Desenvolvimento</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Proxies de confiança</label>
            <input type="text" name="trusted_proxies" value="<?= htmlspecialchars(Config::get('trusted_proxies', '')) ?>"
                   placeholder="10.0.0.1, 10.0.0.2"
                   class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="text-xs text-slate-400 mt-1">IPs de reverse proxies (separados por vírgula) para resolução correta de IPs de clientes.</p>
        </div>
    </div>

    <button type="submit" class="py-2.5 px-6 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition">
        Guardar configurações
    </button>
</form>
</main></body></html>
