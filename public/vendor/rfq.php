<?php
// /var/www/smartprocure/public/vendor/rfq.php
// Vendor: one RFQ and this vendor's OWN quotation (submit or edit before the deadline).
//
// Security:
//  - the vendor ID comes from the session (currentVendorId()), never the URL
//  - an RFQ the vendor was not invited to gives 404 (we do not even say it exists)
//  - the page never shows other vendors, their prices, estimates, budgets or the purpose
//  - the deadline is checked again on the server when saving (app/rfqs.php)
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireVendor();

$id = requireIdParam();
$vendorId = currentVendorId();
$rfq = findRfq($id);

// OWNERSHIP CHECK
if (!$rfq || !vendorIsInvited($id, $vendorId)) {
    abortPage(404, 'RFQ not found.');
}

$items = requestItems((int) $rfq['request_id']);
$myQuote = vendorQuotation($id, $vendorId);
$open = rfqAcceptsQuotations($rfq);

$stmt = $pdo->prepare('SELECT required_date FROM purchase_requests WHERE id = :id');
$stmt->execute(['id' => $rfq['request_id']]);
$requiredDate = $stmt->fetchColumn();

// Was this vendor's quotation turned into an order?
$stmt = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE quotation_id = :q ORDER BY id DESC LIMIT 1');
$stmt->execute(['q' => $myQuote['id'] ?? 0]);
$myOrder = $stmt->fetch();

$errors = [];
$form = [
    'delivery_days'   => (string) ($myQuote['delivery_days'] ?? ''),
    'validity_date'   => (string) ($myQuote['validity_date'] ?? date('Y-m-d', strtotime('+30 days'))),
    'warranty_months' => (string) ($myQuote['warranty_months'] ?? '0'),
];
$quoted = [];
foreach ($items as $item) {
    $line = $myQuote['items'][$item['id']] ?? null;
    $quoted[$item['id']] = [
        'qty'   => $line ? (string) $line['quoted_quantity'] : (string) $item['quantity'],
        'price' => $line ? (string) $line['unit_price'] : '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if (filter_var($form['delivery_days'], FILTER_VALIDATE_INT) === false || (int) $form['delivery_days'] < 1) {
        $errors[] = 'Delivery days must be a whole number of at least 1.';
    }
    if (!isValidDate($form['validity_date']) || $form['validity_date'] < date('Y-m-d')) {
        $errors[] = 'Valid-until must be a valid date, today or later.';
    }
    $warranty = filter_var($form['warranty_months'], FILTER_VALIDATE_INT);
    if ($warranty === false || $warranty < 0 || $warranty > 120) {
        $errors[] = 'Warranty must be between 0 and 120 months.';
    }

    $rows = [];
    $postedQty   = (array) ($_POST['quoted_quantity'] ?? []);
    $postedPrice = (array) ($_POST['unit_price'] ?? []);

    foreach ($items as $item) {
        $qty   = trim((string) ($postedQty[$item['id']] ?? ''));
        $price = trim((string) ($postedPrice[$item['id']] ?? ''));
        $quoted[$item['id']] = ['qty' => $qty, 'price' => $price];

        if ($price === '') {
            continue; // item not offered
        }
        if (!is_numeric($price) || (float) $price < 0) {
            $errors[] = "{$item['item_name']}: unit price must be 0 or more.";
            continue;
        }
        $qtyInt = filter_var($qty, FILTER_VALIDATE_INT);
        if ($qtyInt === false || $qtyInt < 1 || $qtyInt > (int) $item['quantity']) {
            $errors[] = "{$item['item_name']}: quantity must be between 1 and {$item['quantity']}.";
            continue;
        }
        $rows[] = ['request_item_id' => (int) $item['id'], 'qty' => $qtyInt, 'price' => round((float) $price, 2)];
    }

    if (!$rows && !$errors) {
        $errors[] = 'Enter a unit price for at least one item.';
    }

    if (!$errors) {
        $result = saveVendorQuotation($id, $vendorId, currentUserId(), [
            'delivery_days'   => (int) $form['delivery_days'],
            'validity_date'   => $form['validity_date'],
            'warranty_months' => $warranty,
        ], $rows);

        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('/vendor/rfq.php', ['id' => $id]);
    }
}

$pageTitle   = 'RFQ-' . $id;
$breadcrumbs = [['label' => 'My RFQs', 'url' => url('/vendor/rfqs.php')], ['label' => 'RFQ-' . $id]];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><h2>Request for quotation</h2><?= badge(rfqDisplayStatus($rfq)) ?></div>
    <dl class="details details-grid">
        <dt>Deadline</dt><dd><strong><?= e(date('d M Y, H:i', strtotime($rfq['deadline']))) ?></strong></dd>
        <dt>Needed by</dt><dd><?= e(showDate($requiredDate ?: null)) ?></dd>
        <dt>My quotation</dt><dd><?= $myQuote ? 'Submitted ' . e(date('d M Y, H:i', strtotime($myQuote['submitted_at']))) : 'Not submitted' ?></dd>
    </dl>
    <?php if ($rfq['notes']): ?><p class="purpose"><?= nl2br(e($rfq['notes'])) ?></p><?php endif; ?>
</section>

<?php if ($myOrder): ?>
    <div class="alert alert-success">Your quotation was selected. <a href="<?= e(url('/vendor/order.php', ['id' => $myOrder['id']])) ?>">Open purchase order PO-<?= (int) $myOrder['id'] ?></a> (<?= e($myOrder['status']) ?>).</div>
<?php elseif (!$open): ?>
    <div class="alert alert-info">This RFQ is closed. Quotations can no longer be added or changed.</div>
<?php endif; ?>

<?php if ($open): ?>
<form method="post" action="<?= e(url('/vendor/rfq.php', ['id' => $id])) ?>" class="form">
    <?= csrfField() ?>
    <section class="panel">
        <h2><?= $myQuote ? 'Edit your quotation' : 'Your quotation' ?></h2>
        <p class="hint">Your prices are sealed: nobody sees them before the deadline, and other vendors never see them. You can change your quotation until the deadline.</p>
        <div class="table-wrap">
        <table class="items-table" data-quote-form>
            <thead><tr><th>Item</th><th class="num">Requested</th><th class="num">You can supply</th><th class="num">Unit price</th><th class="num">Line total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr data-item-row>
                    <td><?= e($item['item_name']) ?><?php if ($item['description']): ?><br><small class="muted"><?= e($item['description']) ?></small><?php endif; ?></td>
                    <td class="num"><?= (int) $item['quantity'] ?></td>
                    <td class="num"><input type="number" name="quoted_quantity[<?= (int) $item['id'] ?>]" value="<?= e($quoted[$item['id']]['qty']) ?>" min="1" max="<?= (int) $item['quantity'] ?>" step="1" aria-label="Quantity for <?= e($item['item_name']) ?>" data-qty></td>
                    <td class="num"><input type="number" name="unit_price[<?= (int) $item['id'] ?>]" value="<?= e($quoted[$item['id']]['price']) ?>" min="0" step="0.01" aria-label="Unit price for <?= e($item['item_name']) ?>" data-price></td>
                    <td class="num" data-line-total>-</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="4" class="num">Total</th><th class="num" data-grand-total>-</th></tr></tfoot>
        </table>
        </div>
        <p class="hint">Leave a price empty for items you cannot supply. Only quotations for every item in full can be selected.</p>
        <div class="field-row field-row-3">
            <div class="field">
                <label for="delivery_days">Delivery time (days after order)</label>
                <input type="number" id="delivery_days" name="delivery_days" value="<?= e($form['delivery_days']) ?>" min="1" step="1" required>
            </div>
            <div class="field">
                <label for="validity_date">Prices valid until</label>
                <input type="date" id="validity_date" name="validity_date" value="<?= e($form['validity_date']) ?>" min="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="warranty_months">Warranty (months)</label>
                <input type="number" id="warranty_months" name="warranty_months" value="<?= e($form['warranty_months']) ?>" min="0" max="120" step="1" required>
            </div>
        </div>
    </section>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $myQuote ? 'Update quotation' : 'Submit quotation' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/vendor/rfqs.php')) ?>">Back</a>
    </div>
</form>
<?php else: ?>
<section class="panel">
    <h2>Items</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Item</th><th class="num">Requested</th><th class="num">My qty</th><th class="num">My unit price</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $line = $myQuote['items'][$item['id']] ?? null; ?>
            <tr>
                <td><?= e($item['item_name']) ?></td>
                <td class="num"><?= (int) $item['quantity'] ?></td>
                <td class="num"><?= $line ? (int) $line['quoted_quantity'] : '-' ?></td>
                <td class="num"><?= $line ? e(money($line['unit_price'])) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
