<?php
// /var/www/smartprocure/public/rfqs/create.php
// CREATE: send an RFQ for an approved request to chosen vendors.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$requestId = requireIdParam('request_id');
$request = findRequest($requestId);

if (!$request) {
    abortPage(404, 'Request not found.');
}
if ($request['status'] !== REQ_APPROVED) {
    abortPage(403, 'RFQs can only be created for approved requests.');
}
if ($existing = activeRfqForRequest($requestId)) {
    flash('warning', 'This request already has an RFQ.');
    redirect('/rfqs/view.php', ['id' => $existing['id']]);
}

$minVendors = (int) config('rfq.min_vendors');
$items = requestItems($requestId);

// Active vendors, with how many active login accounts each has.
$stmt = $pdo->prepare(
    'SELECT v.id, v.vendor_name,
            (SELECT COUNT(*) FROM users u WHERE u.vendor_id = v.id AND u.role = :role AND u.is_active = 1) AS accounts
     FROM vendors v
     WHERE v.status = :active
     ORDER BY v.vendor_name'
);
$stmt->execute(['role' => ROLE_VENDOR, 'active' => VENDOR_ACTIVE]);
$vendors = $stmt->fetchAll();

$invitable = [];
foreach ($vendors as $vendor) {
    if ((int) $vendor['accounts'] > 0) {
        $invitable[] = (int) $vendor['id'];
    }
}

$errors = [];
$selected = [];
$deadline = date('Y-m-d\TH:i', strtotime('+' . (int) config('rfq.default_deadline_days') . ' days'));
$notes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $selected = array_values(array_unique(array_map('intval', (array) ($_POST['vendor_ids'] ?? []))));
    $deadline = trim($_POST['deadline'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if (count($selected) < $minVendors) {
        $errors[] = "Invite at least {$minVendors} vendors.";
    }
    if (array_diff($selected, $invitable)) {
        $errors[] = 'Only active vendors with a login account can be invited.';
    }
    $deadlineTime = DateTime::createFromFormat('Y-m-d\TH:i', $deadline);
    if (!$deadlineTime || $deadlineTime->getTimestamp() <= time()) {
        $errors[] = 'The deadline must be a date and time in the future.';
    }
    if (mb_strlen($notes) > 500) {
        $errors[] = 'Notes must be 500 characters or fewer.';
    }

    if (!$errors) {
        $pdo->beginTransaction();

        // Lock the request so two officers cannot create two RFQs.
        $stmt = $pdo->prepare('SELECT status FROM purchase_requests WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $requestId]);

        if ($stmt->fetchColumn() !== REQ_APPROVED || activeRfqForRequest($requestId)) {
            $pdo->rollBack();
            flash('error', 'This request already has an RFQ or is no longer approved.');
            redirect('/rfqs/index.php');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO rfqs (request_id, created_by, deadline, notes, status)
             VALUES (:request_id, :created_by, :deadline, :notes, :status)'
        );
        $stmt->execute([
            'request_id' => $requestId,
            'created_by' => currentUserId(),
            'deadline'   => $deadlineTime->format('Y-m-d H:i:00'),
            'notes'      => $notes !== '' ? $notes : null,
            'status'     => RFQ_OPEN,
        ]);
        $rfqId = (int) $pdo->lastInsertId();

        $inviteStmt = $pdo->prepare('INSERT INTO rfq_vendors (rfq_id, vendor_id) VALUES (:rfq, :vendor)');
        foreach ($selected as $vendorId) {
            $inviteStmt->execute(['rfq' => $rfqId, 'vendor' => $vendorId]);
            notifyUsers(vendorUserIds($vendorId), 'New RFQ-' . $rfqId . ': please quote before ' . $deadlineTime->format('d M Y, H:i') . '.', '/vendor/rfq.php?id=' . $rfqId);
        }

        auditLog(currentUserId(), 'rfq_created', 'rfq', $rfqId, [
            'request_id' => $requestId,
            'vendors'    => $selected,
            'deadline'   => $deadlineTime->format('Y-m-d H:i'),
        ]);

        $pdo->commit();

        flash('success', 'RFQ sent to ' . count($selected) . ' vendors.');
        redirect('/rfqs/view.php', ['id' => $rfqId]);
    }
}

$pageTitle   = 'Create RFQ for PR-' . $requestId;
$breadcrumbs = [['label' => 'RFQs', 'url' => url('/rfqs/index.php')], ['label' => 'Create RFQ']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (count($invitable) < $minVendors): ?>
    <div class="alert alert-warning">Fewer than <?= $minVendors ?> active vendors have a login account. Ask the administrator to create vendor accounts (Users → Add user → role Vendor).</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/rfqs/create.php', ['request_id' => $requestId])) ?>" class="form">
    <?= csrfField() ?>
    <div class="two-col two-col-wide">
        <section class="panel">
            <h2>What vendors will see</h2>
            <p class="hint">Vendors see the items, quantities and needed-by date below, and your note. They do not see estimated prices, the purpose or the budget.</p>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Item</th><th>Description</th><th class="num">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr><td><?= e($item['item_name']) ?></td><td><?= e($item['description']) ?></td><td class="num"><?= (int) $item['quantity'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p>Needed by: <strong><?= e(showDate($request['required_date'])) ?></strong></p>
            <div class="field">
                <label for="notes">Note to vendors (optional)</label>
                <textarea id="notes" name="notes" rows="3" maxlength="500" placeholder="Delivery address, brand requirements..."><?= e($notes) ?></textarea>
            </div>
        </section>

        <aside class="panel">
            <h2>Vendors and deadline</h2>
            <fieldset class="check-list">
                <legend>Invite at least <?= $minVendors ?> vendors</legend>
                <?php foreach ($vendors as $vendor): ?>
                    <?php $hasAccount = (int) $vendor['accounts'] > 0; ?>
                    <label class="choice<?= $hasAccount ? '' : ' disabled' ?>">
                        <input type="checkbox" name="vendor_ids[]" value="<?= (int) $vendor['id'] ?>"
                            <?= in_array((int) $vendor['id'], $selected, true) ? 'checked' : '' ?> <?= $hasAccount ? '' : 'disabled' ?>>
                        <span>
                            <?= e($vendor['vendor_name']) ?>
                            <small class="muted"><?= $hasAccount ? 'Reliability ' . e(reliabilityText(vendorReliability((int) $vendor['id']))) : 'No login account yet' ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="field">
                <label for="deadline">Quotation deadline</label>
                <input type="datetime-local" id="deadline" name="deadline" value="<?= e($deadline) ?>" required>
                <p class="hint">Bids stay sealed until this time. Vendors cannot quote or change their quotation after it.</p>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Send RFQ</button>
        </aside>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
