<?php
// /var/www/smartprocure/public/vendor/orders.php
// Vendor: purchase orders sent to THIS vendor company only.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireVendor();

$stmt = $pdo->prepare(
    'SELECT id, order_date, expected_delivery_date, status, total_amount
     FROM purchase_orders
     WHERE vendor_id = :vendor
     ORDER BY id DESC'
);
$stmt->execute(['vendor' => currentVendorId()]);
$orders = $stmt->fetchAll();

$pageTitle   = 'My orders';
$breadcrumbs = [['label' => 'My orders']];
require APP_ROOT . '/includes/header.php';
?>
<section class="panel">
<?php if (!$orders): ?>
    <p class="empty">No purchase orders yet. When one of your quotations is selected, the order appears here.</p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Order</th><th>Ordered</th><th>Deliver by</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr class="<?= $order['status'] === PO_CREATED ? 'row-pending' : '' ?>">
                <td>PO-<?= (int) $order['id'] ?></td>
                <td><?= e(showDate($order['order_date'])) ?></td>
                <td><?= e(showDate($order['expected_delivery_date'])) ?></td>
                <td class="num"><?= e(money($order['total_amount'])) ?></td>
                <td><?= badge($order['status'] === PO_CREATED ? 'Awaiting your response' : $order['status']) ?></td>
                <td><a class="btn btn-sm <?= $order['status'] === PO_CREATED ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('/vendor/order.php', ['id' => $order['id']])) ?>"><?= $order['status'] === PO_CREATED ? 'Respond' : 'View' ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
