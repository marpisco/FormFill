<?php
/**
 * Admin — Response Management
 */

require 'index.php';

use FormFill\Lib\Csrf;
use FormFill\Lib\Mailer;

global $db;

$action = $_GET['action'] ?? 'list';
$brand = Config::brandName();

// ─── Respond to a submission ─────────────────────────────────────────────────
if ($action === 'respond' && !empty($_POST['resposta_id']) && !empty($_POST['resposta_texto'])) {
    $respostaId = $_POST['resposta_id'];
    $texto = trim($_POST['resposta_texto']);

    // Mark as responded
    $stmt = $db->prepare("UPDATE respostas SET respondido = TRUE, resposta = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $texto, $respostaId);
        $stmt->execute();
        $stmt->close();
    }

    // Get user email and send notification
    $stmt = $db->prepare(
        "SELECT r.pdf_path, r.form_id, c.email, c.nome 
         FROM respostas r JOIN cache c ON r.enviador_id = c.id 
         WHERE r.id = ?"
    );
    if ($stmt) {
        $stmt->bind_param("s", $respostaId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['email'])) {
            Mailer::sendResponseNotification($row['email'], $row['nome'], $texto, $row['pdf_path'] ?? null);
        }
    }

    acaoexecutada("Resposta enviada ao utilizador");
}

// ─── Delete response ─────────────────────────────────────────────────────────
if ($action === 'delete' && !empty($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("DELETE FROM respostas WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $_GET['id']);
        $stmt->execute();
        $stmt->close();
    }
    acaoexecutada("Resposta eliminada");
}

// ─── List responses ──────────────────────────────────────────────────────────
$search = $_GET['q'] ?? '';
$where = '';
$params = [];
$types = '';

if (!empty($search)) {
    $where = "WHERE (f.nome LIKE ? OR c.nome LIKE ?)";
    $searchParam = "%{$search}%";
    $params = [$searchParam, $searchParam];
    $types = 'ss';
}

$sql = "SELECT r.id, r.pdf_path, r.respondido, r.criado_em, 
               f.nome AS form_nome, c.nome AS user_nome, c.email AS user_email
        FROM respostas r
        JOIN forms f ON r.form_id = f.id
        JOIN cache c ON r.enviador_id = c.id
        {$where}
        ORDER BY r.criado_em DESC
        LIMIT 100";

$stmt = $db->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $respostas = $stmt->get_result();
    $stmt->close();
} else {
    $respostas = $db->query($sql);
}
?>

<h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2">Respostas</h1>
<p class="text-slate-500 dark:text-slate-400 mb-6">Gira as respostas aos formulários.</p>

<!-- Search -->
<form method="GET" class="mb-4">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Pesquisar por formulário ou utilizador..."
           class="w-full sm:w-80 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
</form>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Formulário</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden sm:table-cell">Utilizador</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden md:table-cell">Data</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Estado</th>
                <th class="text-right px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Ações</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <?php if ($respostas && $respostas->num_rows > 0): ?>
            <?php while ($r = $respostas->fetch_assoc()): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($r['form_nome']) ?></td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300 hidden sm:table-cell"><?= htmlspecialchars($r['user_nome']) ?></td>
                <td class="px-4 py-3 text-slate-500 hidden md:table-cell"><?= date('d/m/Y H:i', strtotime($r['criado_em'] ?? '')) ?></td>
                <td class="px-4 py-3">
                    <?php if ($r['respondido']): ?>
                    <span class="text-xs text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-full">Respondido</span>
                    <?php else: ?>
                    <span class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 px-2 py-0.5 rounded-full">Pendente</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <?php if ($r['pdf_path']): ?>
                        <a href="/<?= htmlspecialchars(str_replace(__DIR__ . '/../', '', $r['pdf_path'])) ?>" target="_blank"
                           class="px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded transition">PDF</a>
                        <?php endif; ?>
                        <?php if (!$r['respondido']): ?>
                        <button onclick="showRespondForm('<?= htmlspecialchars($r['id']) ?>', '<?= htmlspecialchars($r['user_nome']) ?>')"
                                class="px-2 py-1 text-xs text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-900/20 rounded transition">Responder</button>
                        <?php endif; ?>
                        <form method="POST" action="?action=delete&id=<?= urlencode($r['id']) ?>" class="inline" onsubmit="return confirm('Eliminar esta resposta?')">
                            <?= Csrf::field() ?>
                            <button type="submit" class="px-2 py-1 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">Eliminar</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Nenhuma resposta encontrada.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Response Modal -->
<div id="respondModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-lg p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-2">Responder a <span id="respondUserName"></span></h3>
        <form method="POST" action="?action=respond">
            <?= Csrf::field() ?>
            <input type="hidden" name="resposta_id" id="respondId">
            <textarea name="resposta_texto" rows="4" required
                      class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transition mb-4"
                      placeholder="Escreva a resposta..."></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('respondModal').classList.add('hidden')"
                        class="px-4 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition">Cancelar</button>
                <button type="submit" class="px-4 py-2 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg transition">Enviar resposta</button>
            </div>
        </form>
    </div>
</div>
<script>
function showRespondForm(id, nome) {
    document.getElementById('respondId').value = id;
    document.getElementById('respondUserName').textContent = nome;
    document.getElementById('respondModal').classList.remove('hidden');
}
</script>
</main></body></html>
