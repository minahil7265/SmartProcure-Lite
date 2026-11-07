<?php
// /var/www/smartprocure/public/orders/view.php
// READ: one purchase order with delivery progress and history.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT, ROLE_ADMIN);

$id = requireIdParam();
$order = findOrder($id);

if (!$order) {
    abortPage(404, 'Purchase order not found.');
}

$items      = orderItemsWithProgress($id);
$deliveries = orderDeliveries($id);
$late       = isOverdue($order);

$canDeliver = hasRole(ROLE_PROCUREMENT) && in_array($order['status'], PO_OPEN, true);
$canCancel  = hasRole(ROLE_PROCUREMENT) && in_array($order['status'], [PO_CREATED, PO_ACCEPTED], true) && !$deliveries;

$pageTitle   = 'Purchase order PO-' . $id;
$breadcrumbs = [['label' => 'Purchase orders', 'url' => url('/orders/index.php')], ['label' => 'PO-' . $id]];
$pageActions = $canDeliver
    ? '<a class="btn btn-primary" href="' . e(url('/deliveries/create.php', ['order_id' => $id])) . '">Record delivery</a>'
    : '';
require APP_ROOT . '/includes/header.php';
?>
<?php if ($order['status'] === PO_CREATED): ?>
    <div class="alert alert-info">Sent to the vendor. Waiting for the vendor to accept or decline.</div>
<?php elseif ($order['status'] === PO_DECLINED): ?>
    <div class="alert alert-warning">
        The vendor declined this order<?= $order['vendor_response_note'] ? ': “' . e($order['vendor_response_note']) . '”' : '' ?>.
        The budget was released. <a href="<?= e(url('/rfqs/index.php')) ?>">Choose another vendor</a> from the RFQ.
    </div>
<?php endif; ?>

<?php if ($late): ?>
    <div class="alert alert-warning">This order is overdue: it was expected on <?= e(showDate($order['expected_delivery_date'])) ?>.</div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><h2>Order details</h2><?= badge($order['status']) ?></div>
    <dl class="details details-grid">
        <dt>Vendor</dt><dd><a href="<?= e(url('/vendors/view.php', ['id' => $order['vendor_id']])) ?>"><?= e($order['vendor_name']) ?></a></dd>
        <dt>Request</dt><dd><a href="<?= e(url('/requests/view.php', ['id' => $order['request_id']])) ?>">PR-<?= (int) $order['request_id'] ?></a>, <?= e($order['department_name']) ?></dd>
        <dt>Order date</dt><dd><?= e(showDate($order['order_date'])) ?></dd>
        <dt>Expected delivery</dt><dd><?= e(showDate($order['expected_delivery_date'])) ?></dd>
        <dt>Created by</dt><dd><?= e($order['created_by_name']) ?></dd>
        <dt>Vendor response</dt><dd><?= $order['vendor_response_at'] ? e(date('d M Y, H:i', strtotime($order['vendor_response_at']))) : '<span class="muted">Waiting</span>' ?></dd>
        <dt>Total</dt><dd class="num-strong"><?= e(money($order['total_amount'])) ?></dd>
    </dl>
    <p class="purpose"><?= e($order['purpose']) ?></p>
</section>

<section class="panel">
    <h2>Items and delivery progress</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Item</th><th class="num">Unit price</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Remaining</th><th>Progress</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $percent = (int) round($item['quantity_received'] / $item['quantity_ordered'] * 100); ?>
            <tr>
                <td><?= e($item['item_name']) ?></td>
                <td class="num"><?= e(money($item['unit_price'])) ?></td>
                <td class="num"><?= (int) $item['quantity_ordered'] ?></td>
                <td class="num"><?= (int) $item['quantity_received'] ?></td>
                <td class="num"><?= (int) $item['remaining'] ?></td>
                <td><div class="progress" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"><span style="width: <?= $percent ?>%"></span></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="panel">
    <h2>Deliveries</h2>
    <?php if (!$deliveries): ?>
        <p class="empty">Nothing received yet.<?php if ($canDeliver): ?> <a href="<?= e(url('/deliveries/create.php', ['order_id' => $id])) ?>">Record the first delivery</a>.<?php endif; ?></p>
    <?php else: ?>
        <ul class="timeline">
        <?php foreach ($deliveries as $delivery): ?>
            <li>
                <p><?= badge($delivery['delivery_status']) ?> delivery on <strong><?= e(showDate($delivery['received_date'])) ?></strong>, recorded by <?= e($delivery['recorded_by_name']) ?></p>
                <p class="muted">
                    <?php foreach ($delivery['items'] as $index => $line): ?>
                        <?= $index > 0 ? ', ' : '' ?><?= (int) $line['quantity_received'] ?> &times; <?= e($line['item_name']) ?>
                    <?php endforeach; ?>
                </p>
                <?php if ($delivery['notes']): ?><blockquote><?= nl2br(e($delivery['notes'])) ?></blockquote><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($canCancel): ?>
<section class="panel panel-quiet">
    <h2>Cancel order</h2>
    <p class="muted">Possible only while nothing has been delivered. The committed budget is released, the vendor is told, and you can select a vendor again from the RFQ.</p>
    <form method="post" action="<?= e(url('/orders/cancel.php', ['id' => $id])) ?>" data-confirm="Cancel PO-<?= (int) $id ?> and release its budget?">
        <?= csrfField() ?>
        <button type="submit" class="btn btn-danger">Cancel order</button>
    </form>
</section>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
