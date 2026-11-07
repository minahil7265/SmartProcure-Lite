<?php
// /var/www/smartprocure/public/orders/index.php
// READ: purchase orders with search, status filter and overdue highlight.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT, ROLE_ADMIN);

$search      = trim($_GET['q'] ?? '');
$status      = $_GET['status'] ?? '';
$overdueOnly = isset($_GET['overdue']) && $_GET['overdue'] === '1';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(v.vendor_name LIKE :search OR r.purpose LIKE :search2 OR po.id = :search_id)';
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search_id'] = (int) preg_replace('/\D/', '', $search);
}
if (in_array($status, PO_STATUSES, true)) {
    $where[] = 'po.status = :status';
    $params['status'] = $status;
}
if ($overdueOnly) {
    $where[] = 'po.status IN (:open1, :open2, :open3) AND po.expected_delivery_date < CURDATE()';
    [$params['open1'], $params['open2'], $params['open3']] = PO_PENDING_DELIVERY;
}

$stmt = $pdo->prepare(
    'SELECT po.id, po.request_id, po.order_date, po.expected_delivery_date, po.status, po.total_amount,
            v.vendor_name, d.department_name
     FROM purchase_orders po
     JOIN vendors v ON v.id = po.vendor_id
     JOIN purchase_requests r ON r.id = po.request_id
     JOIN departments d ON d.id = r.department_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
    ' ORDER BY po.id DESC'
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageTitle   = 'Purchase orders';
$breadcrumbs = [['label' => 'Purchase orders']];
require APP_ROOT . '/includes/header.php';
?>
<form method="get" action="<?= e(url('/orders/index.php')) ?>" class="filter-bar" role="search">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Vendor, purpose or PO number">
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (PO_STATUSES as $option): ?>
                <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <label class="choice inline-check"><input type="checkbox" name="overdue" value="1" <?= $overdueOnly ? 'checked' : '' ?>> Overdue only</label>
    <button type="submit" class="btn">Filter</button>
    <?php if ($search !== '' || $status !== '' || $overdueOnly): ?>
        <a class="btn btn-ghost" href="<?= e(url('/orders/index.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<section class="panel">
<?php if (!$orders): ?>
    <p class="empty">No purchase orders match.<?php if (hasRole(ROLE_PROCUREMENT)): ?> Orders are created from an <a href="<?= e(url('/rfqs/index.php')) ?>">RFQ comparison</a>.<?php endif; ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Order</th><th>Request</th><th>Vendor</th><th>Department</th><th>Ordered</th><th>Expected</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <?php $late = isOverdue($order); ?>
            <tr class="<?= $late ? 'row-overdue' : '' ?>">
                <td><a href="<?= e(url('/orders/view.php', ['id' => $order['id']])) ?>">PO-<?= (int) $order['id'] ?></a></td>
                <td><a href="<?= e(url('/requests/view.php', ['id' => $order['request_id']])) ?>">PR-<?= (int) $order['request_id'] ?></a></td>
                <td><?= e($order['vendor_name']) ?></td>
                <td><?= e($order['department_name']) ?></td>
                <td><?= e(showDate($order['order_date'])) ?></td>
                <td><?= e(showDate($order['expected_delivery_date'])) ?><?= $late ? ' <span class="badge badge-overdue">Overdue</span>' : '' ?></td>
                <td class="num"><?= e(money($order['total_amount'])) ?></td>
                <td><?= badge($order['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
