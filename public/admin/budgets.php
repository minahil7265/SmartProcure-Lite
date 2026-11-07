<?php
// /var/www/smartprocure/public/admin/budgets.php
// Admin: department budgets per period (READ + CREATE).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$departmentIds = array_map('intval', array_column($departments, 'id'));
$currentPeriod = budgetPeriodFor(date('Y-m-d'));

$errors = [];
$form = ['department_id' => '', 'budget_period' => $currentPeriod, 'allocated_amount' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if (!in_array((int) $form['department_id'], $departmentIds, true)) {
        $errors[] = 'Choose a department.';
    }
    // The period must look like the configured format, e.g. 2026 or 2026-11.
    $sample = date((string) config('budget_period_format'));
    $parsed = DateTime::createFromFormat('!' . config('budget_period_format'), $form['budget_period']);
    if (!$parsed || $parsed->format((string) config('budget_period_format')) !== $form['budget_period']) {
        $errors[] = "Budget period must look like {$sample}.";
    }
    if (!is_numeric($form['allocated_amount']) || (float) $form['allocated_amount'] < 0) {
        $errors[] = 'Allocated amount must be 0 or more.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO budgets (department_id, budget_period, allocated_amount, committed_amount)
                 VALUES (:dept, :period, :allocated, 0)'
            );
            $stmt->execute([
                'dept'      => (int) $form['department_id'],
                'period'    => $form['budget_period'],
                'allocated' => round((float) $form['allocated_amount'], 2),
            ]);
            $newId = (int) $pdo->lastInsertId();

            auditLog(currentUserId(), 'budget_created', 'budget', $newId, [
                'department_id' => (int) $form['department_id'],
                'period'        => $form['budget_period'],
                'allocated'     => (float) $form['allocated_amount'],
            ]);

            flash('success', 'Budget added.');
            redirect('/admin/budgets.php', ['period' => $form['budget_period']]);
        } catch (PDOException $exception) {
            $errors[] = 'This department already has a budget for that period. Edit it instead.';
        }
    }
}

$period = trim($_GET['period'] ?? '');
$sql = 'SELECT b.*, d.department_name FROM budgets b JOIN departments d ON d.id = b.department_id';
$params = [];
if ($period !== '') {
    $sql .= ' WHERE b.budget_period = :period';
    $params['period'] = $period;
}
$sql .= ' ORDER BY b.budget_period DESC, d.department_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$budgets = $stmt->fetchAll();

$periods = $pdo->query('SELECT DISTINCT budget_period FROM budgets ORDER BY budget_period DESC')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle   = 'Budgets';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Budgets']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="two-col two-col-wide">
    <section class="panel">
        <div class="panel-head">
            <h2>Budgets</h2>
            <form method="get" action="<?= e(url('/admin/budgets.php')) ?>" class="inline-filter">
                <label for="period" class="sr-only">Period</label>
                <select id="period" name="period" data-auto-submit>
                    <option value="">All periods</option>
                    <?php foreach ($periods as $option): ?>
                        <option value="<?= e($option) ?>" <?= $period === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
            </form>
        </div>
        <?php if (!$budgets): ?>
            <p class="empty">No budgets yet. Add one with the form so managers can see what they can spend.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Department</th><th>Period</th><th class="num">Allocated</th><th class="num">Committed</th><th class="num">Available</th><th>Used</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($budgets as $budget): ?>
                    <?php
                    $used = (float) $budget['allocated_amount'] > 0
                        ? min(100, (int) round($budget['committed_amount'] / $budget['allocated_amount'] * 100))
                        : 0;
                    ?>
                    <tr>
                        <td><?= e($budget['department_name']) ?></td>
                        <td><?= e($budget['budget_period']) ?></td>
                        <td class="num"><?= e(money($budget['allocated_amount'])) ?></td>
                        <td class="num"><?= e(money($budget['committed_amount'])) ?></td>
                        <td class="num"><?= e(money(availableBudget($budget))) ?></td>
                        <td><div class="progress<?= $used >= 90 ? ' progress-warn' : '' ?>" role="progressbar" aria-valuenow="<?= $used ?>" aria-valuemin="0" aria-valuemax="100"><span style="width: <?= $used ?>%"></span></div></td>
                        <td><a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/budget_edit.php', ['id' => $budget['id']])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <aside class="panel">
        <h2>Add budget</h2>
        <form method="post" action="<?= e(url('/admin/budgets.php')) ?>" class="form">
            <?= csrfField() ?>
            <div class="field">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id" required>
                    <option value="">Choose</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= (int) $dept['id'] ?>" <?= (string) $dept['id'] === $form['department_id'] ? 'selected' : '' ?>><?= e($dept['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="budget_period">Period</label>
                <input type="text" id="budget_period" name="budget_period" value="<?= e($form['budget_period']) ?>" maxlength="20" required>
                <p class="hint">Format: <?= e($currentPeriod) ?></p>
            </div>
            <div class="field">
                <label for="allocated_amount">Allocated amount (<?= e(config('app.currency')) ?>)</label>
                <input type="number" id="allocated_amount" name="allocated_amount" value="<?= e($form['allocated_amount']) ?>" min="0" step="0.01" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Add budget</button>
        </form>
    </aside>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
