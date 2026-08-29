<?php
declare(strict_types=1);

$pageTitle = 'Audit Logs';
t8_require_role(['admin']);
$action = trim((string) ($_GET['action'] ?? ''));
$module = trim((string) ($_GET['module'] ?? ''));
$pageSize = 10;
$params = [];
$where = [];
if ($action !== '') { $where[] = 'a.action = :action'; $params['action'] = $action; }
if ($module !== '') { $where[] = 'a.entity_type = :module'; $params['module'] = $module; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a $whereSql");
$countStmt->execute($params);
$totalLogs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / $pageSize));
$currentPage = min(max(1, (int) ($_GET['audit_page'] ?? 1)), $totalPages);
$offset = ($currentPage - 1) * $pageSize;
$stmt = $pdo->prepare("SELECT a.*, u.full_name FROM audit_logs a JOIN users u ON u.id = a.user_id $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT $pageSize OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$modules = $pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
?>
<h1>Audit Logs</h1>
<p class="t8-help-text">Read-only record of system activity. Logs cannot be edited or deleted here.</p>
<form class="t8-audit-filters" method="get" action="<?= e(base_url('index.php')) ?>">
    <input type="hidden" name="page" value="audit">
    <select class="t8-select" name="action"><option value="">All actions</option><?php foreach ($actions as $item): ?><option value="<?= e($item) ?>" <?= $item === $action ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $item))) ?></option><?php endforeach; ?></select>
    <select class="t8-select" name="module"><option value="">All modules</option><?php foreach ($modules as $item): ?><option value="<?= e($item) ?>" <?= $item === $module ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $item))) ?></option><?php endforeach; ?></select>
    <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">Filter</button>
</form>
<div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>Result / details</th><th>Date &amp; time</th></tr></thead><tbody>
<?php if ($logs === []): ?><tr class="t8-table-empty-row"><td colspan="6">No matching audit events.</td></tr><?php else: foreach ($logs as $log): ?><tr><td><?= e($log['full_name']) ?></td><td><?= e(ucwords(str_replace('_', ' ', $log['action']))) ?></td><td><?= e(ucwords(str_replace('_', ' ', $log['entity_type']))) ?></td><td>#<?= e((string) $log['entity_id']) ?></td><td><?= e((string) ($log['new_value'] ?? 'Recorded')) ?></td><td><?= e(format_date($log['created_at'], 'M d, Y g:i A')) ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div>
<?php if ($totalPages > 1): ?>
    <nav class="t8-pagination" aria-label="Audit log pages">
        <?php if ($currentPage > 1): ?>
            <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(base_url('index.php?page=audit&action=' . rawurlencode($action) . '&module=' . rawurlencode($module) . '&audit_page=' . ($currentPage - 1))) ?>">Previous</a>
        <?php endif; ?>
        <span class="t8-help-text">Page <?= e((string) $currentPage) ?> of <?= e((string) $totalPages) ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(base_url('index.php?page=audit&action=' . rawurlencode($action) . '&module=' . rawurlencode($module) . '&audit_page=' . ($currentPage + 1))) ?>">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
