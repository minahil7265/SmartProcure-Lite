<?php
// /var/www/smartprocure/public/approvals/review.php
// Approve or reject one submitted request (comment required on rejection).
// Decided by a manager of the department, or by the admin when the
// request is escalated (see app/approvals.php).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_MANAGER, ROLE_ADMIN);

$id = requireIdParam();
$request = findRequest($id);

if (!$request) {
    abortPage(404, 'Request not found.');
}
// Server-side rules - the button being hidden is not enough.
$isEscalation = hasRole(ROLE_ADMIN);

if ($isEscalation) {
    // Admin: only escalated requests.
    [$allowed, $reason] = canDecideRequest($request);
    if (!$allowed) {
        abortPage(403, $reason);
    }
} else {
    // Manager: own department, never their own request.
    // (Already-decided requests can still be viewed, read-only.)
    if ((int) $request['department_id'] !== currentDepartmentId()) {
        abortPage(403, 'You can only review requests of your own department.');
    }
    if ((int) $request['requester_id'] === currentUserId()) {
        abortPage(403, 'You cannot approve or reject your own request.');
    }
}

$items  = requestItems($id);
$period = budgetPeriodFor($request['request_date']);
$budget = findBudget((int) $request['department_id'], $period);
$available = $budget ? availableBudget($budget) : null;
$overBudget = $budget && (float) $request['estimated_total'] > $available;

$errors = [];
$decision = '';
$comments = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $decision = $_POST['decision'] ?? '';
    $comments = trim($_POST['comments'] ?? '');

    if (!in_array($decision, [DECISION_APPROVED, DECISION_REJECTED], true)) {
        $errors[] = 'Choose Approve or Reject.';
    }
    if ($decision === DECISION_REJECTED && $comments === '') {
        $errors[] = 'A comment is required when rejecting a request.';
    }
    if (mb_strlen($comments) > 500) {
        $errors[] = 'Comments must be 500 characters or fewer.';
    }

    if (!$errors) {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT status FROM purchase_requests WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $lockedStatus = $stmt->fetchColumn();

        // Check the rules again with the row locked.
        $request['status'] = $lockedStatus;
        [$allowed, $reason] = canDecideRequest($request);

        if ($lockedStatus !== REQ_SUBMITTED || !$allowed) {
            $pdo->rollBack();
            flash('error', 'This request is no longer waiting for a decision.');
            redirect('/requests/view.php', ['id' => $id]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO approvals (request_id, approver_id, decision, comments, is_escalated)
             VALUES (:request_id, :approver_id, :decision, :comments, :escalated)'
        );
        $stmt->execute([
            'request_id'  => $id,
            'approver_id' => currentUserId(),
            'decision'    => $decision,
            'comments'    => $comments !== '' ? $comments : null,
            'escalated'   => $isEscalation ? 1 : 0,
        ]);

        $newStatus = $decision === DECISION_APPROVED ? REQ_APPROVED : REQ_REJECTED;
        $stmt = $pdo->prepare('UPDATE purchase_requests SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $id]);

        auditLog(currentUserId(), 'request_' . strtolower($newStatus), 'purchase_request', $id, [
            'comments'         => $comments,
            'estimated_total'  => (float) $request['estimated_total'],
            'budget_available' => $available,
            'escalated'        => $isEscalation,
        ]);

        notifyUsers([(int) $request['requester_id']], 'Your request PR-' . $id . ' was ' . strtolower($newStatus) . '.', '/requests/view.php?id=' . $id);

        $pdo->commit();

        flash('success', 'Request PR-' . $id . ' ' . strtolower($newStatus) . '.');
        redirect('/approvals/index.php');
    }
}

$pageTitle   = 'Review PR-' . $id;
$breadcrumbs = [['label' => $isEscalation ? 'Escalated approvals' : 'Approvals', 'url' => url('/approvals/index.php')], ['label' => 'Review PR-' . $id]];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($isEscalation): ?>
    <div class="alert alert-warning">Escalated: no other manager in <?= e($request['department_name']) ?> could decide on this request.</div>
<?php endif; ?>

<?php if ($request['status'] !== REQ_SUBMITTED): ?>
    <div class="alert alert-info">This request is <?= e($request['status']) ?> and is not waiting for a decision.</div>
<?php endif; ?>

<div class="two-col two-col-wide">
    <section class="panel">
        <h2>Request</h2>
        <dl class="details">
            <dt>Requester</dt><dd><?= e($request['requester_name']) ?></dd>
            <dt>Request date</dt><dd><?= e(showDate($request['request_date'])) ?></dd>
            <dt>Needed by</dt><dd><?= e(showDate($request['required_date'])) ?></dd>
        </dl>
        <p class="purpose"><?= nl2br(e($request['purpose'])) ?></p>

        <div class="table-wrap">
        <table>
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Est. unit</th><th class="num">Line total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['item_name']) ?><?php if ($item['description']): ?><br><small class="muted"><?= e($item['description']) ?></small><?php endif; ?></td>
                    <td class="num"><?= (int) $item['quantity'] ?></td>
                    <td class="num"><?= e(money($item['estimated_unit_price'])) ?></td>
                    <td class="num"><?= e(money($item['quantity'] * $item['estimated_unit_price'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="3" class="num">Estimated total</th><th class="num"><?= e(money($request['estimated_total'])) ?></th></tr></tfoot>
        </table>
        </div>
    </section>

    <aside class="panel">
        <h2>Budget <?= e($period) ?></h2>
        <?php if (!$budget): ?>
            <div class="alert alert-warning">No budget is set for this department and period.</div>
        <?php else: ?>
            <dl class="details">
                <dt>Allocated</dt><dd class="num-strong"><?= e(money($budget['allocated_amount'])) ?></dd>
                <dt>Committed</dt><dd class="num-strong"><?= e(money($budget['committed_amount'])) ?></dd>
                <dt>Available</dt><dd class="num-strong"><?= e(money($available)) ?></dd>
            </dl>
            <?php if ($overBudget): ?>
                <div class="alert alert-warning">The estimate (<?= e(money($request['estimated_total'])) ?>) is higher than the available budget.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($request['status'] === REQ_SUBMITTED): ?>
        <form method="post" action="<?= e(url('/approvals/review.php', ['id' => $id])) ?>" class="form">
            <?= csrfField() ?>
            <fieldset class="choice-group">
                <legend>Decision</legend>
                <label class="choice"><input type="radio" name="decision" value="<?= e(DECISION_APPROVED) ?>" <?= $decision === DECISION_APPROVED ? 'checked' : '' ?> required> Approve</label>
                <label class="choice"><input type="radio" name="decision" value="<?= e(DECISION_REJECTED) ?>" <?= $decision === DECISION_REJECTED ? 'checked' : '' ?>> Reject</label>
            </fieldset>
            <div class="field">
                <label for="comments">Comments <span class="muted">(required to reject)</span></label>
                <textarea id="comments" name="comments" rows="4" maxlength="500"><?= e($comments) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Save decision</button>
        </form>
        <?php endif; ?>
    </aside>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
