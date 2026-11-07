<?php
// /var/www/smartprocure/public/orders/create.php
// CREATE: confirm the selected vendor and create the purchase order.
// All checks and the budget commitment run in one transaction (app/orders.php).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$quotationId = requireIdParam('quotation_id');

$stmt = $pdo->prepare('SELECT rfq_id FROM quotations WHERE id = :id');
$stmt->execute(['id' => $quotationId]);
$rfqId = (int) $stmt->fetchColumn();

$rfq = $rfqId ? findRfq($rfqId) : null;
if (!$rfq) {
    abortPage(404, 'Quotation not found.');
}

$requestId = (int) $rfq['request_id'];
$request = findRequest($requestId);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $result = createPurchaseOrder($rfqId, $quotationId, currentUserId());

    if ($result['ok']) {
        flash('success', $result['message']);
        redirect('/orders/view.php', ['id' => $result['order_id']]);
    }
    $error = $result['message'];
}

// Preview: only after the deadline (bids are sealed before it).
$sealed = !rfqDeadlinePassed($rfq);
$chosen = null;
$comparison = null;
if (!$sealed) {
    $comparison = compareQuotations($request, $rfqId);
    foreach ($comparison['ranked'] as $quote) {
        if ((int) $quote['id'] === $quotationId) {
            $chosen = $quote;
        }
    }
}

$items  = requestItems($requestId);
$period = budgetPeriodFor($request['request_date']);
$budget = findBudget((int) $request['department_id'], $period);
$activeOrder = activeOrderForRequest($requestId);

$pageTitle   = 'Create purchase order';
$breadcrumbs = [
    ['label' => 'RFQs', 'url' => url('/rfqs/index.php')],
    ['label' => 'RFQ-' . $rfqId, 'url' => url('/rfqs/view.php', ['id' => $rfqId])],
    ['label' => 'Create order'],
];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($sealed): ?>
    <div class="alert alert-warning">Bids are sealed until <?= e(date('d M Y, H:i', strtotime($rfq['deadline']))) ?>. <a href="<?= e(url('/rfqs/view.php', ['id' => $rfqId])) ?>">Back to the RFQ</a>.</div>
<?php elseif ($activeOrder): ?>
    <div class="alert alert-warning">This request already has an active order: <a href="<?= e(url('/orders/view.php', ['id' => $activeOrder['id']])) ?>">PO-<?= (int) $activeOrder['id'] ?></a>.</div>
<?php elseif (!$chosen): ?>
    <div class="alert alert-error">This quotation is not eligible (expired, inactive vendor, declined, missing items or the request is no longer approved). <a href="<?= e(url('/rfqs/view.php', ['id' => $rfqId])) ?>">Back to the RFQ</a>.</div>
<?php else: ?>
    <div class="two-col two-col-wide">
        <section class="panel">
            <div class="panel-head">
                <h2><?= e($chosen['vendor_name']) ?></h2>
                <span class="rank">Rank #<?= (int) $chosen['rank'] ?> of <?= count($comparison['ranked']) ?>, score <?= e(number_format($chosen['final_score'], 2)) ?></span>
            </div>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Line total</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $line = $chosen['items'][$item['id']]; ?>
                    <tr>
                        <td><?= e($item['item_name']) ?></td>
                        <td class="num"><?= (int) $line['quoted_quantity'] ?></td>
                        <td class="num"><?= e(money($line['unit_price'])) ?></td>
                        <td class="num"><?= e(money($line['quoted_quantity'] * $line['unit_price'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="3" class="num">Order total</th><th class="num"><?= e(money($chosen['total'])) ?></th></tr></tfoot>
            </table>
            </div>
        </section>

        <aside class="panel">
            <h2>Check before ordering</h2>
            <dl class="details">
                <dt>Request</dt><dd>PR-<?= (int) $requestId ?>, <?= e($request['department_name']) ?></dd>
                <dt>Expected delivery</dt><dd><?= e(showDate(date('Y-m-d', strtotime("+{$chosen['delivery_days']} days")))) ?></dd>
                <dt>Warranty</dt><dd><?= (int) $chosen['warranty_months'] ?> months</dd>
                <dt>Budget <?= e($period) ?></dt>
                <dd><?= $budget ? e(money(availableBudget($budget))) . ' available' : 'Not set' ?></dd>
            </dl>
            <?php if (!$budget || availableBudget($budget) < $chosen['total']): ?>
                <div class="alert alert-warning">The department budget does not cover this order. Creating it will fail until the administrator raises the budget.</div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/orders/create.php', ['quotation_id' => $quotationId])) ?>">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-primary btn-block">Create and send purchase order</button>
            </form>
            <p class="hint">The vendor must accept the order before goods can be received. If the vendor declines, the budget is released and you can choose another vendor.</p>
        </aside>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
