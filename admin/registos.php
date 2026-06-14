<?php
/**
 * Admin — Audit Logs (Registos)
 */

require 'index.php';

global $db;

$showIps = ($_GET['show_ips'] ?? '') === '1';
$limit = 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Fetch logs with user names
$stmt = $db->prepare(
    "SELECT l.id, l.loginfo, l.timestamp, l.ip_address, l.user_id, c.nome AS user_nome
     FROM logs l LEFT JOIN cache c ON l.user_id = c.id
     ORDER BY l.timestamp DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

// Check if there are more logs
$countStmt = $db->query("SELECT COUNT(*) as total FROM logs");
$total = $countStmt->fetch_assoc()['total'];
$hasMore = ($offset + $limit) < $total;
?>

<h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 mb-2">Registos</h1>
<p class="text-slate-500 dark:text-slate-400 mb-2">Registo de auditoria de todas as ações no sistema.</p>
<p class="text-xs text-slate-400 dark:text-slate-500 mb-6">
    <?= $total ?> registos no total — a mostrar <?= min($offset + $limit, $total) ?>
</p>

<div class="mb-4 flex items-center gap-3">
    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 cursor-pointer select-none">
        <input type="checkbox" <?= $showIps ? 'checked' : '' ?> onchange="window.location='?show_ips=' + (this.checked ? '1' : '0')"
               class="rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500">
        Mostrar endereços IP
    </label>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 w-40">Data</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300">Ação</th>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 hidden sm:table-cell w-32">Utilizador</th>
                <?php if ($showIps): ?>
                <th class="text-left px-4 py-3 font-medium text-slate-600 dark:text-slate-300 w-36">IP</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <?php if ($logs && $logs->num_rows > 0): ?>
            <?php while ($log = $logs->fetch_assoc()): 
                $logId = htmlspecialchars($log['id']);
                $timestamp = date('d/m/Y H:i:s', strtotime($log['timestamp'] ?? 'now'));
                $actionFull = $log['loginfo'] ?? '';
                // Truncate action for collapsed view (first line or first 120 chars)
                $actionFirstLine = strtok($actionFull, "\n");
                $actionShort = mb_strlen($actionFirstLine) > 120 
                    ? mb_substr($actionFirstLine, 0, 120) . '…' 
                    : $actionFirstLine;
                $isLong = mb_strlen($actionFull) > mb_strlen($actionShort) + 5;
                $userName = htmlspecialchars($log['user_nome'] ?? '—');
                $ip = htmlspecialchars($log['ip_address'] ?? '—');
            ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 align-top">
                <td class="px-4 py-2.5 text-xs text-slate-500 dark:text-slate-400 font-mono whitespace-nowrap">
                    <?= $timestamp ?>
                </td>
                <td class="px-4 py-2.5">
                    <span class="action-collapsed text-slate-700 dark:text-slate-200" id="short-<?= $logId ?>">
                        <?= htmlspecialchars($actionShort) ?>
                    </span>
                    <?php if ($isLong): ?>
                    <span class="action-expanded text-slate-700 dark:text-slate-200 hidden" id="full-<?= $logId ?>">
                        <?= nl2br(htmlspecialchars($actionFull)) ?>
                    </span>
                    <button onclick="toggleLog('<?= $logId ?>')" 
                            class="text-brand-600 dark:text-brand-400 hover:underline text-xs ml-1 inline-flex items-center gap-0.5"
                            id="btn-<?= $logId ?>">
                        <span class="expand-label">+ expandir</span>
                        <span class="collapse-label hidden">− recolher</span>
                    </button>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-2.5 text-xs text-slate-500 dark:text-slate-400 hidden sm:table-cell">
                    <?= $userName ?>
                </td>
                <?php if ($showIps): ?>
                <td class="px-4 py-2.5 text-xs font-mono text-slate-400 dark:text-slate-500">
                    <?= $ip ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="<?= $showIps ? 4 : 3 ?>" class="px-4 py-12 text-center text-slate-400">Nenhum registo encontrado.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($hasMore): ?>
<div class="text-center mt-4">
    <a href="?offset=<?= $offset + $limit ?><?= $showIps ? '&show_ips=1' : '' ?>"
       class="inline-flex items-center gap-2 py-2 px-5 text-sm text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-900/20 rounded-lg transition">
        Carregar mais
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </a>
</div>
<?php endif; ?>

<script>
function toggleLog(id) {
    const short = document.getElementById('short-' + id);
    const full = document.getElementById('full-' + id);
    const btn = document.getElementById('btn-' + id);
    const expand = btn.querySelector('.expand-label');
    const collapse = btn.querySelector('.collapse-label');

    if (full.classList.contains('hidden')) {
        short.classList.add('hidden');
        full.classList.remove('hidden');
        expand.classList.add('hidden');
        collapse.classList.remove('hidden');
    } else {
        short.classList.remove('hidden');
        full.classList.add('hidden');
        expand.classList.remove('hidden');
        collapse.classList.add('hidden');
    }
}
</script>
</main></body></html>
