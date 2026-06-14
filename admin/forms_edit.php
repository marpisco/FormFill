<?php
/**
 * Admin — Form Builder (Create/Edit)
 */

require 'index.php';

use FormFill\Lib\Csrf;
use FormFill\Lib\FormBuilder;

$formId = $_GET['id'] ?? null;
$isEdit = !empty($formId);
$form = $isEdit ? FormBuilder::get($formId) : null;

if ($isEdit && !$form) {
    echo "<div class='p-8 text-center text-red-500'>Formulário não encontrado.</div>";
    echo "</main></body></html>";
    exit();
}

// Handle access management (private forms)
$action = $_GET['action'] ?? '';
if ($action === 'add_access' && $isEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $searchQuery = trim($_POST['user_search'] ?? '');
    if (!empty($searchQuery)) {
        $users = FormBuilder::searchUsers($searchQuery);
        if (count($users) === 1) {
            FormBuilder::addAccess($formId, $users[0]['id']);
            acaoexecutada("Acesso concedido a {$users[0]['nome']}");
        }
    }
    $form = FormBuilder::get($formId); // Refresh
}
if ($action === 'remove_access' && $isEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_GET['user_id'] ?? '';
    if (!empty($userId)) {
        FormBuilder::removeAccess($formId, $userId);
        acaoexecutada("Acesso removido");
    }
    $form = FormBuilder::get($formId); // Refresh
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_form'])) {
    $data = [
        'nome'        => $_POST['nome'] ?? '',
        'descricao'   => $_POST['descricao'] ?? '',
        'instrucoes'  => $_POST['instrucoes'] ?? '',
        'ativado'     => isset($_POST['ativado']),
        'privacidade' => (int)($_POST['privacidade'] ?? 0),
        'campos'      => json_decode($_POST['campos_json'] ?? '[]', true) ?: [],
        'doc'        => [
            'criar' => isset($_POST['doc_criar']),
            'texto' => $_POST['doc_texto'] ?? '',
        ],
        'email'      => [
            'assuntoconfirmacao'  => $_POST['email_assunto_conf'] ?? '',
            'confirmacao'         => $_POST['email_conf'] ?? '',
            'assuntonotificacao'  => $_POST['email_assunto_notif'] ?? '',
            'notificacao'         => $_POST['email_notif'] ?? '',
        ],
    ];

    $validation = FormBuilder::validate($data);

    if ($validation['valid']) {
        if ($isEdit) {
            FormBuilder::update($formId, $data);
            acaoexecutada("Formulário atualizado");
            $form = FormBuilder::get($formId); // Refresh
        } else {
            $formId = FormBuilder::create($data);
            acaoexecutada("Formulário criado");
            header("Location: /admin/forms_edit.php?id=" . urlencode($formId));
            exit();
        }
    } else {
        echo '<div class="mb-4 px-4 py-3 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg">';
        foreach ($validation['errors'] as $err) {
            echo '<p class="text-red-700 dark:text-red-300 text-sm">' . htmlspecialchars($err) . '</p>';
        }
        echo '</div>';
        $form = $data; // Show what was submitted
    }
}

$campos = $form['campos'] ?? [];
$doc = $form['doc'] ?? ['criar' => true, 'texto' => ''];
$email = $form['email'] ?? [];
?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2">
    <?= $isEdit ? 'Editar formulário' : 'Novo formulário' ?>
</h1>
<p class="text-slate-500 dark:text-slate-400 mb-6">Construtor visual de formulários.</p>

<form method="POST" id="formBuilderForm" class="flex flex-col lg:flex-row gap-6">
    <?= Csrf::field() ?>
    <input type="hidden" name="save_form" value="1">
    <input type="hidden" name="campos_json" id="camposJson" value="<?= htmlspecialchars(json_encode($campos, JSON_UNESCAPED_UNICODE)) ?>">

    <!-- Left Sidebar: Settings + Field Palette -->
    <div class="w-full lg:w-80 flex-shrink-0 space-y-4">
        <!-- Form Meta -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Configuração do formulário</h3>
            <input type="text" name="nome" value="<?= htmlspecialchars($form['nome'] ?? '') ?>" placeholder="Nome do formulário" required
                   class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
            <input type="text" name="descricao" value="<?= htmlspecialchars($form['descricao'] ?? '') ?>" placeholder="Descrição curta"
                   class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
            <input type="text" name="instrucoes" value="<?= htmlspecialchars($form['instrucoes'] ?? '') ?>" placeholder="Instruções para o utilizador"
                   class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="ativado" <?= ($form['ativado'] ?? true) ? 'checked' : '' ?> class="rounded text-brand-600">
                <span class="text-slate-600 dark:text-slate-300">Ativado</span>
            </label>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Privacidade</label>
                <select name="privacidade" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                    <option value="0" <?= (int)($form['privacidade'] ?? 0) === 0 ? 'selected' : '' ?>>🌐 Público — todos podem aceder</option>
                    <option value="1" <?= (int)($form['privacidade'] ?? 0) === 1 ? 'selected' : '' ?>>🏢 Interno — apenas utilizadores internos</option>
                    <option value="2" <?= (int)($form['privacidade'] ?? 0) === 2 ? 'selected' : '' ?>>🔒 Privado — apenas utilizadores autorizados</option>
                </select>
            </div>
        </div>

        <!-- Access List (only for private forms) -->
        <?php if ($isEdit && (int)($form['privacidade'] ?? 0) === 2): ?>
        <?php $accessList = FormBuilder::getAccessList($formId); ?>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4" id="accessListPanel">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Utilizadores com acesso</h3>
            <?php if (empty($accessList)): ?>
            <p class="text-xs text-slate-400 mb-2">Nenhum utilizador adicionado.</p>
            <?php else: ?>
            <div class="space-y-1 mb-3">
                <?php foreach ($accessList as $au): ?>
                <div class="flex items-center justify-between text-sm py-1 px-2 rounded hover:bg-slate-50 dark:hover:bg-slate-700/50">
                    <span class="text-slate-700 dark:text-slate-200"><?= htmlspecialchars($au['nome']) ?></span>
                    <form method="POST" action="?action=remove_access&id=<?= urlencode($formId) ?>&user_id=<?= urlencode($au['id']) ?>" class="inline">
                        <?= Csrf::field() ?>
                        <button class="text-xs text-red-500 hover:text-red-700">Remover</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST" action="?action=add_access&id=<?= urlencode($formId) ?>" class="flex gap-2">
                <?= Csrf::field() ?>
                <input type="text" name="user_search" placeholder="Procurar utilizador por nome ou email..."
                       class="flex-1 px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"
                       list="userSearchList" autocomplete="off">
                <button type="submit" class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg transition">Adicionar</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Field Palette -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Tipos de campo</h3>
            <p class="text-xs text-slate-400 mb-3">Arraste para o construtor</p>
            <div class="grid grid-cols-2 gap-1.5" id="fieldPalette">
                <?php
                $fieldTypes = [
                    ['type' => 'text', 'label' => 'Texto curto', 'icon' => 'T'],
                    ['type' => 'textarea', 'label' => 'Texto longo', 'icon' => '¶'],
                    ['type' => 'number', 'label' => 'Número', 'icon' => '#'],
                    ['type' => 'email', 'label' => 'Email', 'icon' => '@'],
                    ['type' => 'date', 'label' => 'Data', 'icon' => '📅'],
                    ['type' => 'time', 'label' => 'Hora', 'icon' => '🕐'],
                    ['type' => 'datetime-local', 'label' => 'Data e hora', 'icon' => '📆'],
                    ['type' => 'url', 'label' => 'URL', 'icon' => '🔗'],
                    ['type' => 'tel', 'label' => 'Telefone', 'icon' => '📞'],
                    ['type' => 'color', 'label' => 'Cor', 'icon' => '🎨'],
                    ['type' => 'select', 'label' => 'Dropdown', 'icon' => '▼'],
                    ['type' => 'checkbox', 'label' => 'Checkboxes', 'icon' => '☑'],
                    ['type' => 'radio', 'label' => 'Radio', 'icon' => '◉'],
                    ['type' => 'file', 'label' => 'Ficheiro', 'icon' => '📎'],
                    ['type' => 'range', 'label' => 'Slider', 'icon' => '↔'],
                    ['type' => 'hidden', 'label' => 'Escondido', 'icon' => '👁‍🗨'],
                ];
                foreach ($fieldTypes as $ft):
                ?>
                <button type="button" data-field-type="<?= $ft['type'] ?>"
                        class="field-palette-item flex items-center gap-1.5 px-2 py-1.5 text-xs text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-700/50 hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400 border border-slate-200 dark:border-slate-600 rounded-lg cursor-grab transition">
                    <span class="text-sm"><?= $ft['icon'] ?></span>
                    <?= $ft['label'] ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Placeholder Cheatsheet -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">Placeholders</h3>
            <div class="text-xs space-y-1 text-slate-500 dark:text-slate-400 font-mono">
                <p><code>§nomecompleto§</code> — Nome completo</p>
                <p><code>§nome§</code> — Primeiro nome</p>
                <p><code>§id§</code> — ID do utilizador</p>
                <p><code>§email§</code> — Email</p>
                <p><code>&amp;campo&amp;</code> — Valor do campo</p>
                <p><code>#data#</code> — Data atual</p>
                <p><code>§resposta§</code> — Resposta do admin</p>
            </div>
        </div>
    </div>

    <!-- Right Canvas: Builder + Preview -->
    <div class="flex-1 space-y-4 min-w-0">
        <!-- Field Builder Canvas -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Campos do formulário</h3>
            <div id="fieldCanvas" class="space-y-2 min-h-[100px] p-2 border-2 border-dashed border-slate-200 dark:border-slate-600 rounded-lg transition-colors">
                <!-- Fields rendered by JS -->
            </div>
            <p class="text-xs text-slate-400 mt-2 text-center" id="canvasEmpty">Arraste tipos de campo da esquerda para aqui, ou clique para adicionar.</p>
        </div>

        <!-- Document Template -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Documento PDF</h3>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="doc_criar" <?= ($doc['criar'] ?? true) ? 'checked' : '' ?> class="rounded text-brand-600">
                <span class="text-slate-600 dark:text-slate-300">Gerar PDF</span>
            </label>
            <textarea name="doc_texto" rows="4" placeholder="Eu, §nomecompleto§, declaro..."
                      class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm font-mono"><?= htmlspecialchars($doc['texto'] ?? '') ?></textarea>
        </div>

        <!-- Email Templates -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Emails de confirmação</h3>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-slate-500">Assunto (confirmação)</label>
                    <input type="text" name="email_assunto_conf" value="<?= htmlspecialchars($email['assuntoconfirmacao'] ?? '') ?>"
                           class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm mt-1">
                </div>
                <div>
                    <label class="text-xs text-slate-500">Corpo (confirmação)</label>
                    <textarea name="email_conf" rows="2"
                              class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm mt-1"><?= htmlspecialchars($email['confirmacao'] ?? '') ?></textarea>
                </div>
            </div>
            <h4 class="text-xs font-semibold text-slate-500 mt-2">Resposta do admin</h4>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-slate-500">Assunto (notificação)</label>
                    <input type="text" name="email_assunto_notif" value="<?= htmlspecialchars($email['assuntonotificacao'] ?? '') ?>"
                           class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm mt-1">
                </div>
                <div>
                    <label class="text-xs text-slate-500">Corpo (notificação)</label>
                    <textarea name="email_notif" rows="2"
                              class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm mt-1"><?= htmlspecialchars($email['notificacao'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Save -->
        <div class="flex gap-3">
            <button type="submit" class="py-2.5 px-6 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition">
                <?= $isEdit ? 'Guardar alterações' : 'Criar formulário' ?>
            </button>
            <a href="/admin/forms.php" class="py-2.5 px-6 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition">Cancelar</a>
        </div>
    </div>
</form>

<script src="/assets/js/form-builder.js"></script>
<script>
    // Initialize with existing fields
    FormBuilder.init(<?= json_encode($campos, JSON_UNESCAPED_UNICODE) ?>, '<?= $isEdit ? 'true' : 'false' ?>');
</script>
</main></body></html>
