<?php
// /var/www/smartprocure/public/admin/audit_logs.php
// Admin: read-only audit log with filters and pages.
// There is no edit or delete here, and the database user cannot do either.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

const AUDIT_PAGE_SIZE = 50;

$action = trim($_GET['action'] ?? '');
$userQ  = trim($_GET['user'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];

if ($action !== '') {
    $where[] = 'a.action = :action';
    $params['action'] = $action;
}
if ($userQ !== '') {
    $where[] = '(u.full_name LIKE :u1 OR u.email LIKE :u2)';
    $params['u1'] = $params['u2'] = '%' . $userQ . '%';
}
if ($from !== '' && isValidDate($from)) {
    $where[] = 'a.created_at >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '' && isValidDate($to)) {
    $where[] = 'a.created_at <= :to';
    $params['to'] = $to . ' 23:59:59';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id' . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / AUDIT_PAGE_SIZE));
$page  = min($page, $pages);

$stmt = $pdo->prepare(
    'SELECT a.*, u.full_name, u.email
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id'
    . $whereSql .
    ' ORDER BY a.id DESC LIMIT ' . AUDIT_PAGE_SIZE . ' OFFSET ' . (($page - 1) * AUDIT_PAGE_SIZE)
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$filters = array_filter(['action' => $action, 'user' => $userQ, 'from' => $from, 'to' => $to]);

$pageTitle   = 'Audit log';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Audit log']];
require APP_ROOT . '/includes/header.php';
?>
<form method="get" action="<?= e(url('/admin/audit_logs.php')) ?>" class="filter-bar" role="search">
    <div class="field">
        <label for="action">Action</label>
        <select id="action" name="action">
            <option value="">All actions</option>
            <?php foreach ($actions as $option): ?>
                <option value="<?= e($option) ?>" <?= $action === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="user">User</label>
        <input type="search" id="user" name="user" value="<?= e($userQ) ?>" placeholder="Name or email">
    </div>
    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if ($filters): ?>
        <a class="btn btn-ghost" href="<?= e(url('/admin/audit_logs.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<section class="panel">
<?php if (!$logs): ?>
    <p class="empty">No audit entries match these filters.</p>
<?php else: ?>
    <div class="table-wrap">
    <table class="audit-table">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td class="nowrap"><?= e(date('d M Y, H:i:s', strtotime($log['created_at']))) ?></td>
                <td><?= $log['full_name'] ? e($log['full_name']) : '<span class="muted">Unknown visitor</span>' ?></td>
                <td><code><?= e($log['action']) ?></code></td>
                <td><?= $log['entity_type'] ? e($log['entity_type']) . ($log['entity_id'] ? ' #' . (int) $log['entity_id'] : '') : '-' ?></td>
                <td class="details-cell"><?= e($log['details']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <nav class="pager" aria-label="Pages">
        <span><?= $total ?> entries, page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page > 1): ?>
            <a class="btn btn-sm" href="<?= e(url('/admin/audit_logs.php', $filters + ['page' => $page - 1])) ?>">Newer</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
            <a class="btn btn-sm" href="<?= e(url('/admin/audit_logs.php', $filters + ['page' => $page + 1])) ?>">Older</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
