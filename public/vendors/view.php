<?php
// /var/www/smartprocure/public/vendors/view.php
// READ: vendor details and simple vendor analytics.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT, ROLE_ADMIN);

$id = requireIdParam();
$vendor = findVendor($id);

if (!$vendor) {
    abortPage(404, 'Vendor not found.');
}

// Analytics
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS quotations,
            AVG(delivery_days) AS avg_days,
            SUM(status = :selected) AS selected
     FROM quotations WHERE vendor_id = :id'
);
$stmt->execute(['selected' => QUOTE_SELECTED, 'id' => $id]);
$quoteStats = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT po.id, po.order_date, po.expected_delivery_date, po.status, po.total_amount, po.request_id
     FROM purchase_orders po
     WHERE po.vendor_id = :id
     ORDER BY po.id DESC'
);
$stmt->execute(['id' => $id]);
$orders = $stmt->fetchAll();

$totalValue = 0;
foreach ($orders as $order) {
    if (in_array($order['status'], PO_ACTIVE, true)) {
        $totalValue += (float) $order['total_amount'];
    }
}

// Same calculation the comparison uses (app/reliability.php).
$reliability = vendorReliability($id);

$stmt = $pdo->prepare('SELECT full_name, email, is_active FROM users WHERE vendor_id = :id AND role = :role ORDER BY full_name');
$stmt->execute(['id' => $id, 'role' => ROLE_VENDOR]);
$accounts = $stmt->fetchAll();

$pageTitle   = $vendor['vendor_name'];
$breadcrumbs = [['label' => 'Vendors', 'url' => url('/vendors/index.php')], ['label' => $vendor['vendor_name']]];
$pageActions = hasRole(ROLE_PROCUREMENT)
    ? '<a class="btn" href="' . e(url('/vendors/edit.php', ['id' => $id])) . '">Edit vendor</a>'
    : '';
require APP_ROOT . '/includes/header.php';
?>
<section class="stat-strip">
    <div class="stat"><span class="stat-value"><?= (int) $quoteStats['quotations'] ?></span><span class="stat-label">Quotations</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $quoteStats['selected'] ?></span><span class="stat-label">Times selected</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $reliability['rating'] ?> / <?= RATING_MAX ?></span><span class="stat-label">Delivery reliability<?= $reliability['is_default'] ? ' (new vendor, default)' : '' ?></span></div>
    <div class="stat"><span class="stat-value"><?= e(money($totalValue)) ?></span><span class="stat-label">Order value</span></div>
    <div class="stat"><span class="stat-value"><?= $reliability['on_time_pct'] !== null ? e($reliability['on_time_pct']) . '%' : '-' ?></span><span class="stat-label">Completed on time (<?= (int) $reliability['completed'] ?> orders)</span></div>
</section>

<div class="two-col two-col-wide">
    <section class="panel">
        <h2>Orders</h2>
        <?php if (!$orders): ?>
            <p class="empty">No orders with this vendor yet.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Order</th><th>Request</th><th>Ordered</th><th>Expected</th><th class="num">Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr class="<?= isOverdue($order) ? 'row-overdue' : '' ?>">
                        <td><a href="<?= e(url('/orders/view.php', ['id' => $order['id']])) ?>">PO-<?= (int) $order['id'] ?></a></td>
                        <td><a href="<?= e(url('/requests/view.php', ['id' => $order['request_id']])) ?>">PR-<?= (int) $order['request_id'] ?></a></td>
                        <td><?= e(showDate($order['order_date'])) ?></td>
                        <td><?= e(showDate($order['expected_delivery_date'])) ?></td>
                        <td class="num"><?= e(money($order['total_amount'])) ?></td>
                        <td><?= badge($order['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <aside class="panel">
        <div class="panel-head"><h2>Contact</h2><?= badge($vendor['status']) ?></div>
        <dl class="details">
            <dt>Contact person</dt><dd><?= e($vendor['contact_person'] ?: '-') ?></dd>
            <dt>Email</dt><dd><?= e($vendor['email'] ?: '-') ?></dd>
            <dt>Phone</dt><dd><?= e($vendor['phone'] ?: '-') ?></dd>
            <dt>Address</dt><dd><?= e($vendor['address'] ?: '-') ?></dd>
            <dt>Average delivery</dt><dd><?= $quoteStats['avg_days'] !== null ? e(number_format((float) $quoteStats['avg_days'], 1)) . ' days' : '-' ?></dd>
        </dl>
        <h2>Login accounts</h2>
        <?php if (!$accounts): ?>
            <p class="muted">None. This vendor cannot be invited to RFQs until the administrator creates a vendor login.</p>
        <?php else: ?>
            <ul class="plain-list">
            <?php foreach ($accounts as $account): ?>
                <li><?= e($account['full_name']) ?> <small class="muted"><?= e($account['email']) ?><?= (int) $account['is_active'] === 1 ? '' : ' (inactive)' ?></small></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
