<?php
/**
 * Admin — User Management
 */

require_once __DIR__ . '/index.php';

use FormFill\Lib\Csrf;

global $db;

$action = $_GET['action'] ?? 'list';

// Toggle admin
if ($action === 'toggle_admin' && !empty($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = $_GET['id'];
    
    // Atomic check: use transaction with FOR UPDATE to prevent race
    $db->begin_transaction();
    try {
        $check = $db->prepare("SELECT admin FROM cache WHERE id = ? FOR UPDATE");
        $check->bind_param("s", $targetId);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        $check->close();

        if ($target && !empty($target['admin'])) {
            $countCheck = $db->query("SELECT COUNT(*) as c FROM cache WHERE admin = TRUE FOR UPDATE");
            $adminCount = (int)$countCheck->fetch_assoc()['c'];
            if ($adminCount <= 1) {
                $db->rollback();
                echo "<script>alert('Não é possível remover o último administrador.'); window.history.back();</script>";
                exit();
            }
        }

        $stmt = $db->prepare("UPDATE cache SET admin = NOT admin WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $targetId);
            $stmt->execute();
            $stmt->close();
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
    }
    acaoexecutada("Administrador ativado/desativado");
}

// Remove TOTP
if ($action === 'remove_totp' && !empty($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE cache SET totp_secret = NULL WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $_GET['id']);
        $stmt->execute();
        $stmt->close();
    }
    acaoexecutada("TOTP removido do utilizador");
}

// Delete user
if ($action === 'delete' && !empty($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteId = $_GET['id'];

    // Check all FK references before deletion
    $refs = [];
    $check = $db->prepare("SELECT COUNT(*) as c FROM respostas WHERE enviador_id = ?");
    $check->bind_param("s", $deleteId);
    $check->execute();
    $refs['respostas'] = (int)$check->get_result()->fetch_assoc()['c'];
    $check->close();

    $check = $db->prepare("SELECT COUNT(*) as c FROM forms WHERE criado_por = ?");
    $check->bind_param("s", $deleteId);
    $check->execute();
    $refs['forms'] = (int)$check->get_result()->fetch_assoc()['c'];
    $check->close();

    $check = $db->prepare("SELECT COUNT(*) as c FROM logs WHERE user_id = ?");
    $check->bind_param("s", $deleteId);
    $check->execute();
    $refs['logs'] = (int)$check->get_result()->fetch_assoc()['c'];
    $check->close();

    $check = $db->prepare("SELECT COUNT(*) as c FROM forms_access WHERE user_id = ?");
    $check->bind_param("s", $deleteId);
    $check->execute();
    $refs['access'] = (int)$check->get_result()->fetch_assoc()['c'];
    $check->close();

    $totalRefs = $refs['respostas'] + $refs['forms'] + $refs['access'];
    if ($totalRefs > 0) {
        $msg = "Não é possível eliminar: utilizador tem {$refs['respostas']} respostas, "
             . "{$refs['forms']} formulários, {$refs['access']} acessos a formulários.";
        echo "<script>alert(" . json_encode($msg) . "); window.history.back();</script>";
    } else {
        // Clean up access entries and set logs user_id to NULL (avoid FK violation)
        $cleanStmt = $db->prepare("DELETE FROM forms_access WHERE user_id = ?");
        if ($cleanStmt) {
            $cleanStmt->bind_param("s", $deleteId);
            $cleanStmt->execute();
            $cleanStmt->close();
        }
        $db->query("UPDATE logs SET user_id = NULL WHERE user_id = '" . $db->real_escape_string($deleteId) . "'");
        $stmt = $db->prepare("DELETE FROM cache WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $deleteId);
            $stmt->execute();
            $stmt->close();
        }
        acaoexecutada("Utilizador eliminado");
    }
}

// Pre-register user
if ($action === 'preregister' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $nome = trim($_POST['nome'] ?? '');
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($nome) >= 2) {
        // Check if a real (non-pre) user already exists with this email
        $existing = $db->prepare("SELECT id FROM cache WHERE email = ? AND id NOT LIKE 'pre_%'");
        $existing->bind_param("s", $email);
        $existing->execute();
        if ($existing->get_result()->num_rows > 0) {
            echo "<script>alert('Já existe um utilizador registado com este email.'); window.history.back();</script>";
            $existing->close();
            exit();
        }
        $existing->close();

        $id = 'pre_' . substr(hash('sha256', $email), 0, 32);
        $stmt = $db->prepare("INSERT INTO cache (id, nome, email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome)");
        if ($stmt) {
            $stmt->bind_param("sss", $id, $nome, $email);
            $stmt->execute();
            $stmt->close();
        }
        acaoexecutada("Utilizador pré-registado: {$email}");
    }
}

// List users
$search = $_GET['q'] ?? '';
$where = '';
$params = [];
$types = '';

if (!empty($search)) {
    $where = "WHERE nome LIKE ? OR email LIKE ?";
    $searchParam = "%{$search}%";
    $params = [$searchParam, $searchParam];
    $types = 'ss';
}

$sql = "SELECT id, nome, email, admin, totp_secret FROM cache {$where} ORDER BY nome LIMIT 100";
$stmt = $db->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $users = $stmt->get_result();
    $stmt->close();
} else {
    $users = $db->query($sql);
}
?>

<h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2">Utilizadores</h1>
<p class="text-slate-500 dark:text-slate-400 mb-6">Gira os utilizadores registados.</p>

<!-- Pre-register form -->
<details class="mb-6 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
    <summary class="text-sm font-medium text-slate-700 dark:text-slate-200 cursor-pointer">Pré-registar utilizador</summary>
    <form method="POST" action="?action=preregister" class="mt-4 flex flex-col sm:flex-row gap-3">
        <?= Csrf::field() ?>
        <input type="text" name="nome" placeholder="Nome" required minlength="2"
               class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
        <input type="email" name="email" placeholder="Email" required
               class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
        <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg transition">Pré-registar</button>
    </form>
</details>

<!-- Search -->
<form method="GET" class="mb-4">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Pesquisar..."
           class="w-full sm:w-80 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent">
</form>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Nome</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden sm:table-cell">Email</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Função</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden md:table-cell">2FA</th>
                <th class="text-right px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Ações</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <?php if ($users && $users->num_rows > 0): ?>
            <?php while ($u = $users->fetch_assoc()): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($u['nome']) ?></td>
                <td class="px-4 py-3 text-slate-500 hidden sm:table-cell"><?= htmlspecialchars($u['email']) ?></td>
                <td class="px-4 py-3">
                    <?php if ($u['admin']): ?>
                    <span class="text-xs text-brand-600 dark:text-brand-400 bg-brand-50 dark:bg-brand-900/20 px-2 py-0.5 rounded-full">Admin</span>
                    <?php else: ?>
                    <span class="text-xs text-slate-500 bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded-full">Utilizador</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <?= !empty($u['totp_secret']) ? '<span class="text-xs text-emerald-500">✅</span>' : '<span class="text-xs text-slate-400">—</span>' ?>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <form method="POST" action="?action=toggle_admin&id=<?= urlencode($u['id']) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <button class="px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded transition">
                                <?= $u['admin'] ? 'Remover admin' : 'Tornar admin' ?>
                            </button>
                        </form>
                        <?php if (!empty($u['totp_secret'])): ?>
                        <form method="POST" action="?action=remove_totp&id=<?= urlencode($u['id']) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <button class="px-2 py-1 text-xs text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded transition">Remover 2FA</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="?action=delete&id=<?= urlencode($u['id']) ?>" class="inline" onsubmit="return confirm('Eliminar este utilizador?')">
                            <?= Csrf::field() ?>
                            <button class="px-2 py-1 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">Eliminar</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Nenhum utilizador encontrado.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</main></body></html>
