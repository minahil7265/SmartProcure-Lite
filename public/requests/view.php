<?php
// /var/www/smartprocure/public/requests/view.php
// READ: one request with its items, decision history and order.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireLogin();

$id = requireIdParam();
$request = findRequest($id);

if (!$request) {
    abortPage(404, 'Request not found.');
}
if (!canViewRequest($request)) {
    abortPage(403, 'You are not allowed to view this request.');
}

$items   = requestItems($id);
$history = approvalHistory($id);

$stmt = $pdo->prepare(
    'SELECT po.id, po.status, po.total_amount, po.expected_delivery_date, v.vendor_name
     FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
     WHERE po.request_id = :id ORDER BY po.id DESC'
);
$stmt->execute(['id' => $id]);
$orders = $stmt->fetchAll();

$canEdit   = canEditRequest($request);
$canReview = $request['status'] === REQ_SUBMITTED && canDecideRequest($request)[0];
$canQuote  = hasRole(ROLE_PROCUREMENT) && $request['status'] === REQ_APPROVED;
$rfq       = $canQuote ? activeRfqForRequest($id) : null;

$pageTitle   = 'Request PR-' . $id;
$breadcrumbs = [['label' => 'Requests', 'url' => url('/requests/index.php')], ['label' => 'PR-' . $id]];

$actions = [];
if ($canEdit) {
    $actions[] = '<a class="btn" href="' . e(url('/requests/edit.php', ['id' => $id])) . '">Edit</a>';
}
if ($canReview) {
    $actions[] = '<a class="btn btn-primary" href="' . e(url('/approvals/review.php', ['id' => $id])) . '">Review and decide</a>';
}
if ($canQuote) {
    $actions[] = $rfq
        ? '<a class="btn btn-primary" href="' . e(url('/rfqs/view.php', ['id' => $rfq['id']])) . '">View RFQ</a>'
        : '<a class="btn btn-primary" href="' . e(url('/rfqs/create.php', ['request_id' => $id])) . '">Create RFQ</a>';
}
$pageActions = implode(' ', $actions);

require APP_ROOT . '/includes/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <h2>Details</h2>
        <?= badge($request['status']) ?>
    </div>
    <dl class="details details-grid">
        <dt>Requester</dt><dd><?= e($request['requester_name']) ?></dd>
        <dt>Department</dt><dd><?= e($request['department_name']) ?></dd>
        <dt>Request date</dt><dd><?= e(showDate($request['request_date'])) ?></dd>
        <dt>Needed by</dt><dd><?= e(showDate($request['required_date'])) ?></dd>
        <dt>Estimated total</dt><dd class="num-strong"><?= e(money($request['estimated_total'])) ?></dd>
    </dl>
    <p class="purpose"><?= nl2br(e($request['purpose'])) ?></p>
</section>

<section class="panel">
    <h2>Items</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Item</th><th>Description</th><th class="num">Qty</th><th class="num">Est. unit price</th><th class="num">Line total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['item_name']) ?></td>
                <td><?= e($item['description']) ?></td>
                <td class="num"><?= (int) $item['quantity'] ?></td>
                <td class="num"><?= e(money($item['estimated_unit_price'])) ?></td>
                <td class="num"><?= e(money($item['quantity'] * $item['estimated_unit_price'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="panel">
    <h2>Decision history</h2>
    <?php if (!$history): ?>
        <p class="empty">No decision yet.</p>
    <?php else: ?>
        <ul class="timeline">
        <?php foreach ($history as $entry): ?>
            <li>
                <p><?= badge($entry['decision']) ?> by <strong><?= e($entry['approver_name']) ?></strong> on <?= e(date('d M Y, H:i', strtotime($entry['decision_date']))) ?></p>
                <?php if ($entry['comments']): ?><blockquote><?= nl2br(e($entry['comments'])) ?></blockquote><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($orders): ?>
<section class="panel">
    <h2>Purchase orders</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Order</th><th>Vendor</th><th>Expected</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td>
                    <?php if (hasRole(ROLE_PROCUREMENT, ROLE_ADMIN)): ?>
                        <a href="<?= e(url('/orders/view.php', ['id' => $order['id']])) ?>">PO-<?= (int) $order['id'] ?></a>
                    <?php else: ?>
                        PO-<?= (int) $order['id'] ?>
                    <?php endif; ?>
                </td>
                <td><?= e($order['vendor_name']) ?></td>
                <td><?= e(showDate($order['expected_delivery_date'])) ?></td>
                <td class="num"><?= e(money($order['total_amount'])) ?></td>
                <td><?= badge($order['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<?php if ($canEdit): ?>
<section class="panel panel-quiet">
    <h2>Request actions</h2>
    <div class="inline-forms">
        <?php if ($request['status'] === REQ_DRAFT): ?>
        <form method="post" action="<?= e(url('/requests/action.php', ['id' => $id])) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit">
            <button type="submit" class="btn btn-primary">Submit for approval</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/requests/action.php', ['id' => $id])) ?>" data-confirm="Cancel this request? This cannot be undone.">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-danger">Cancel request</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
