<?php
// /var/www/smartprocure/public/rfqs/index.php
// Procurement officer: approved requests and the state of their RFQs.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$search = trim($_GET['q'] ?? '');
$show   = $_GET['show'] ?? 'open';   // open = no active order yet, all = every approved request

$where = ['r.status = :approved'];
$params = ['approved' => REQ_APPROVED, 'cancelled' => RFQ_CANCELLED];

if ($show !== 'all') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM purchase_orders po WHERE po.request_id = r.id AND po.status IN (:a1, :a2, :a3, :a4))';
    [$params['a1'], $params['a2'], $params['a3'], $params['a4']] = PO_ACTIVE;
}
if ($search !== '') {
    $where[] = '(r.purpose LIKE :search OR d.department_name LIKE :search2 OR r.id = :search_id)';
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search_id'] = (int) preg_replace('/\D/', '', $search);
}

$stmt = $pdo->prepare(
    'SELECT r.id, r.purpose, r.required_date, r.estimated_total, d.department_name,
            f.id AS rfq_id, f.status AS rfq_status, f.deadline,
            (SELECT COUNT(*) FROM rfq_vendors rv WHERE rv.rfq_id = f.id) AS invited,
            (SELECT COUNT(*) FROM quotations q WHERE q.rfq_id = f.id) AS submitted
     FROM purchase_requests r
     JOIN departments d ON d.id = r.department_id
     LEFT JOIN rfqs f ON f.id = (SELECT MAX(f2.id) FROM rfqs f2 WHERE f2.request_id = r.id AND f2.status <> :cancelled)
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY r.required_date IS NULL, r.required_date, r.id'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle   = 'RFQs';
$breadcrumbs = [['label' => 'RFQs']];
require APP_ROOT . '/includes/header.php';
?>
<form method="get" action="<?= e(url('/rfqs/index.php')) ?>" class="filter-bar" role="search">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Purpose, department or PR number">
    </div>
    <div class="field">
        <label for="show">Show</label>
        <select id="show" name="show">
            <option value="open" <?= $show !== 'all' ? 'selected' : '' ?>>Not yet ordered</option>
            <option value="all" <?= $show === 'all' ? 'selected' : '' ?>>All approved requests</option>
        </select>
    </div>
    <button type="submit" class="btn">Filter</button>
</form>

<section class="panel">
<?php if (!$rows): ?>
    <p class="empty">No approved requests are waiting for an RFQ.</p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Request</th><th>Purpose</th><th>Department</th><th>Needed by</th><th>RFQ</th><th>Deadline</th><th class="num">Quotations</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a href="<?= e(url('/requests/view.php', ['id' => $row['id']])) ?>">PR-<?= (int) $row['id'] ?></a></td>
                <td><?= e($row['purpose']) ?></td>
                <td><?= e($row['department_name']) ?></td>
                <td><?= e(showDate($row['required_date'])) ?></td>
                <?php if ($row['rfq_id']): ?>
                    <td><?= badge(rfqDisplayStatus(['status' => $row['rfq_status'], 'deadline' => $row['deadline']])) ?></td>
                    <td class="nowrap"><?= e(date('d M Y, H:i', strtotime($row['deadline']))) ?></td>
                    <td class="num"><?= (int) $row['submitted'] ?> of <?= (int) $row['invited'] ?></td>
                    <td><a class="btn btn-sm btn-primary" href="<?= e(url('/rfqs/view.php', ['id' => $row['rfq_id']])) ?>">View RFQ</a></td>
                <?php else: ?>
                    <td><span class="muted">None</span></td><td>-</td><td class="num">-</td>
                    <td><a class="btn btn-sm btn-primary" href="<?= e(url('/rfqs/create.php', ['request_id' => $row['id']])) ?>">Create RFQ</a></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
