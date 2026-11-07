<?php
// /var/www/smartprocure/public/deliveries/create.php
// CREATE: record a full or partial delivery against an open order.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$orderId = requireIdParam('order_id');
$order = findOrder($orderId);

if (!$order) {
    abortPage(404, 'Purchase order not found.');
}
if (!in_array($order['status'], PO_OPEN, true)) {
    flash('warning', $order['status'] === PO_CREATED
        ? 'The vendor has not accepted this order yet, so no delivery can be recorded.'
        : 'This order is ' . $order['status'] . ' and cannot receive deliveries.');
    redirect('/orders/view.php', ['id' => $orderId]);
}

$items = orderItemsWithProgress($orderId);

$errors = [];
$receivedDate = date('Y-m-d');
$notes = '';
$quantities = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $receivedDate = trim($_POST['received_date'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $quantities   = (array) ($_POST['quantity'] ?? []);

    if (!isValidDate($receivedDate) || $receivedDate > date('Y-m-d') || $receivedDate < $order['order_date']) {
        $errors[] = 'Received date must be between the order date and today.';
    }
    if (mb_strlen($notes) > 500) {
        $errors[] = 'Notes must be 500 characters or fewer.';
    }

    if (!$errors) {
        $result = recordDelivery($orderId, $receivedDate, $notes, $quantities, currentUserId());

        if ($result['ok']) {
            flash('success', $result['message']);
            redirect('/orders/view.php', ['id' => $orderId]);
        }
        $errors = $result['errors'] ?: [$result['message']];
    }
}

$pageTitle   = 'Record delivery for PO-' . $orderId;
$breadcrumbs = [
    ['label' => 'Purchase orders', 'url' => url('/orders/index.php')],
    ['label' => 'PO-' . $orderId, 'url' => url('/orders/view.php', ['id' => $orderId])],
    ['label' => 'Record delivery'],
];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<p class="lead"><?= e($order['vendor_name']) ?>, expected <?= e(showDate($order['expected_delivery_date'])) ?></p>

<form method="post" action="<?= e(url('/deliveries/create.php', ['order_id' => $orderId])) ?>" class="form">
    <?= csrfField() ?>

    <section class="panel">
        <h2>Quantities received</h2>
        <div class="table-wrap">
        <table class="items-table">
            <thead><tr><th>Item</th><th class="num">Ordered</th><th class="num">Already received</th><th class="num">Remaining</th><th class="num">Received now</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['item_name']) ?></td>
                    <td class="num"><?= (int) $item['quantity_ordered'] ?></td>
                    <td class="num"><?= (int) $item['quantity_received'] ?></td>
                    <td class="num"><?= (int) $item['remaining'] ?></td>
                    <td class="num">
                        <?php if ($item['remaining'] > 0): ?>
                            <input type="number" name="quantity[<?= (int) $item['id'] ?>]" value="<?= e($quantities[$item['id']] ?? '') ?>" min="0" max="<?= (int) $item['remaining'] ?>" step="1" placeholder="0" aria-label="Received now: <?= e($item['item_name']) ?>">
                        <?php else: ?>
                            <span class="muted">Complete</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="hint">You cannot receive more than the remaining quantity. Leave an item at 0 if it did not arrive.</p>
    </section>

    <section class="panel">
        <div class="field field-narrow">
            <label for="received_date">Received date</label>
            <input type="date" id="received_date" name="received_date" value="<?= e($receivedDate) ?>" min="<?= e($order['order_date']) ?>" max="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="field">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" rows="3" maxlength="500" placeholder="Condition of goods, delivery note number..."><?= e($notes) ?></textarea>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save delivery</button>
        <a class="btn btn-ghost" href="<?= e(url('/orders/view.php', ['id' => $orderId])) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
