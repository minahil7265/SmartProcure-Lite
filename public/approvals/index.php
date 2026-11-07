<?php
// /var/www/smartprocure/public/approvals/index.php
// Manager: submitted requests of their own department waiting for a decision.
// Admin:   ESCALATED requests only (no other manager in the department
//          and waiting for 'escalation_days' days).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_MANAGER, ROLE_ADMIN);

if (hasRole(ROLE_ADMIN)) {
    $escalated = escalatedRequests();
    $days = (int) config('escalation_days');

    $pageTitle   = 'Escalated approvals';
    $breadcrumbs = [['label' => 'Escalated approvals']];
    require APP_ROOT . '/includes/header.php';
    ?>
    <p class="lead">Requests appear here when no other manager in the department can decide (for example, the only manager raised them) and they have waited <?= $days ?> day(s).</p>
    <section class="panel">
        <?php if (!$escalated): ?>
            <p class="empty">No escalated requests right now.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Purpose</th><th>Requester</th><th>Department</th><th>Submitted</th><th class="num">Estimate</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($escalated as $row): ?>
                    <tr class="row-pending">
                        <td>PR-<?= (int) $row['id'] ?></td>
                        <td><?= e($row['purpose']) ?></td>
                        <td><?= e($row['requester_name']) ?></td>
                        <td><?= e($row['department_name']) ?></td>
                        <td><?= e(showDate($row['submitted_at'])) ?></td>
                        <td class="num"><?= e(money($row['estimated_total'])) ?></td>
                        <td><a class="btn btn-sm btn-primary" href="<?= e(url('/approvals/review.php', ['id' => $row['id']])) ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$deptId = currentDepartmentId();
if (!$deptId) {
    abortPage(403, 'You are not linked to a department. Ask the administrator to set your department.');
}

$stmt = $pdo->prepare(
    'SELECT r.id, r.purpose, r.request_date, r.required_date, r.estimated_total, u.full_name AS requester_name
     FROM purchase_requests r
     JOIN users u ON u.id = r.requester_id
     WHERE r.department_id = :dept AND r.status = :status AND r.requester_id <> :me
     ORDER BY r.request_date, r.id'
);
$stmt->execute(['dept' => $deptId, 'status' => REQ_SUBMITTED, 'me' => currentUserId()]);
$pending = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT a.request_id, a.decision, a.comments, a.decision_date, r.purpose
     FROM approvals a
     JOIN purchase_requests r ON r.id = a.request_id
     WHERE a.approver_id = :me
     ORDER BY a.decision_date DESC
     LIMIT 10'
);
$stmt->execute(['me' => currentUserId()]);
$recent = $stmt->fetchAll();

$period = budgetPeriodFor(date('Y-m-d'));
$budget = findBudget($deptId, $period);

$pageTitle   = 'Approvals';
$breadcrumbs = [['label' => 'Approvals']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($budget): ?>
    <p class="lead">Budget available for <?= e($_SESSION['department_name']) ?> (<?= e($period) ?>): <strong><?= e(money(availableBudget($budget))) ?></strong> of <?= e(money($budget['allocated_amount'])) ?>.</p>
<?php else: ?>
    <div class="alert alert-warning">No budget is set for your department for <?= e($period) ?>. Ask the administrator to add one.</div>
<?php endif; ?>

<section class="panel">
    <h2>Waiting for your decision</h2>
    <?php if (!$pending): ?>
        <p class="empty">No requests are waiting. You will see new submissions from your department here.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Purpose</th><th>Requester</th><th>Requested</th><th>Needed by</th><th class="num">Estimate</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $row): ?>
                <tr>
                    <td>PR-<?= (int) $row['id'] ?></td>
                    <td><?= e($row['purpose']) ?></td>
                    <td><?= e($row['requester_name']) ?></td>
                    <td><?= e(showDate($row['request_date'])) ?></td>
                    <td><?= e(showDate($row['required_date'])) ?></td>
                    <td class="num"><?= e(money($row['estimated_total'])) ?></td>
                    <td><a class="btn btn-sm btn-primary" href="<?= e(url('/approvals/review.php', ['id' => $row['id']])) ?>">Review</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Your recent decisions</h2>
    <?php if (!$recent): ?>
        <p class="empty">You have not decided on any request yet.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Purpose</th><th>Decision</th><th>Comments</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><a href="<?= e(url('/requests/view.php', ['id' => $row['request_id']])) ?>">PR-<?= (int) $row['request_id'] ?></a></td>
                    <td><?= e($row['purpose']) ?></td>
                    <td><?= badge($row['decision']) ?></td>
                    <td><?= e($row['comments']) ?></td>
                    <td><?= e(showDate($row['decision_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
