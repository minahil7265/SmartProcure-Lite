<?php
// /var/www/smartprocure/public/vendor/order.php
// Vendor: one purchase order of THIS vendor; accept or decline it.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireVendor();

$id = requireIdParam();
$vendorId = currentVendorId();

$stmt = $pdo->prepare(
    'SELECT id, vendor_id, order_date, expected_delivery_date, status, total_amount,
            vendor_response_at, vendor_response_note
     FROM purchase_orders WHERE id = :id'
);
$stmt->execute(['id' => $id]);
$order = $stmt->fetch();

// OWNERSHIP CHECK: another vendor's order looks exactly like a missing one.
if (!$order || (int) $order['vendor_id'] !== $vendorId) {
    abortPage(404, 'Order not found.');
}

$errors = [];
$note = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $answer = $_POST['answer'] ?? '';
    $note   = trim($_POST['note'] ?? '');

    if (!in_array($answer, ['accept', 'decline'], true)) {
        $errors[] = 'Choose Accept or Decline.';
    } elseif (mb_strlen($note) > 500) {
        $errors[] = 'The note must be 500 characters or fewer.';
    } else {
        $result = vendorRespondToOrder($id, $vendorId, currentUserId(), $answer === 'accept', $note);

        if ($result['ok']) {
            flash('success', $result['message']);
            redirect('/vendor/order.php', ['id' => $id]);
        }
        $errors[] = $result['message'];
    }
}

$items = orderItemsWithProgress($id);

$pageTitle   = 'Purchase order PO-' . $id;
$breadcrumbs = [['label' => 'My orders', 'url' => url('/vendor/orders.php')], ['label' => 'PO-' . $id]];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><h2>Order details</h2><?= badge($order['status']) ?></div>
    <dl class="details details-grid">
        <dt>Order date</dt><dd><?= e(showDate($order['order_date'])) ?></dd>
        <dt>Deliver by</dt><dd><strong><?= e(showDate($order['expected_delivery_date'])) ?></strong></dd>
        <dt>Total</dt><dd class="num-strong"><?= e(money($order['total_amount'])) ?></dd>
        <?php if ($order['vendor_response_at']): ?>
            <dt>Your response</dt><dd><?= e(date('d M Y, H:i', strtotime($order['vendor_response_at']))) ?></dd>
            <?php if ($order['vendor_response_note']): ?><dt>Your note</dt><dd><?= e($order['vendor_response_note']) ?></dd><?php endif; ?>
        <?php endif; ?>
    </dl>
</section>

<section class="panel">
    <h2>Items</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Item</th><th class="num">Unit price</th><th class="num">Ordered</th><th class="num">Received by buyer</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['item_name']) ?></td>
                <td class="num"><?= e(money($item['unit_price'])) ?></td>
                <td class="num"><?= (int) $item['quantity_ordered'] ?></td>
                <td class="num"><?= (int) $item['quantity_received'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($order['status'] === PO_CREATED): ?>
<section class="panel">
    <h2>Accept or decline</h2>
    <form method="post" action="<?= e(url('/vendor/order.php', ['id' => $id])) ?>" class="form form-narrow">
        <?= csrfField() ?>
        <div class="field">
            <label for="note">Note (required if you decline)</label>
            <textarea id="note" name="note" rows="3" maxlength="500"><?= e($note) ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" name="answer" value="accept" class="btn btn-primary">Accept order</button>
            <button type="submit" name="answer" value="decline" class="btn btn-danger" data-confirm-button="Decline this order?">Decline</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
