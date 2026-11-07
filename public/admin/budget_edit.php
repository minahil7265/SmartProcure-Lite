<?php
// /var/www/smartprocure/public/admin/budget_edit.php
// Admin: UPDATE the allocated amount. The committed amount is changed
// only by purchase orders, never by hand.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$id = requireIdParam();

$stmt = $pdo->prepare(
    'SELECT b.*, d.department_name FROM budgets b JOIN departments d ON d.id = b.department_id WHERE b.id = :id'
);
$stmt->execute(['id' => $id]);
$budget = $stmt->fetch();

if (!$budget) {
    abortPage(404, 'Budget not found.');
}

$errors = [];
$allocated = (string) $budget['allocated_amount'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $allocated = trim($_POST['allocated_amount'] ?? '');

    if (!is_numeric($allocated) || (float) $allocated < 0) {
        $errors[] = 'Allocated amount must be 0 or more.';
    } else {
        $pdo->beginTransaction();

        // Lock the row: an order may be committing money at the same moment.
        $locked = findBudget((int) $budget['department_id'], $budget['budget_period'], true);

        if ((float) $allocated < (float) $locked['committed_amount']) {
            $pdo->rollBack();
            $errors[] = 'Allocated amount cannot be lower than the committed amount (' . money($locked['committed_amount']) . ').';
        } else {
            $stmt = $pdo->prepare('UPDATE budgets SET allocated_amount = :allocated WHERE id = :id');
            $stmt->execute(['allocated' => round((float) $allocated, 2), 'id' => $id]);

            auditLog(currentUserId(), 'budget_updated', 'budget', $id, [
                'from' => (float) $budget['allocated_amount'],
                'to'   => (float) $allocated,
            ]);

            $pdo->commit();

            flash('success', 'Budget updated.');
            redirect('/admin/budgets.php', ['period' => $budget['budget_period']]);
        }
    }
}

$pageTitle   = 'Edit budget';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Budgets', 'url' => url('/admin/budgets.php')], ['label' => $budget['department_name'] . ' ' . $budget['budget_period']]];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/budget_edit.php', ['id' => $id])) ?>" class="form panel form-narrow">
    <?= csrfField() ?>
    <dl class="details">
        <dt>Department</dt><dd><?= e($budget['department_name']) ?></dd>
        <dt>Period</dt><dd><?= e($budget['budget_period']) ?></dd>
        <dt>Committed</dt><dd class="num-strong"><?= e(money($budget['committed_amount'])) ?></dd>
    </dl>
    <div class="field">
        <label for="allocated_amount">Allocated amount (<?= e(config('app.currency')) ?>)</label>
        <input type="number" id="allocated_amount" name="allocated_amount" value="<?= e($allocated) ?>" min="<?= e($budget['committed_amount']) ?>" step="0.01" required>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save budget</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/budgets.php')) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
