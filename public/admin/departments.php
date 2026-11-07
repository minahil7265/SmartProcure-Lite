<?php
// /var/www/smartprocure/public/admin/departments.php
// Admin: list departments (READ) and add a new one (CREATE).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$errors = [];
$name = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name        = trim($_POST['department_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Department name is required (max 100 characters).';
    }
    if (mb_strlen($description) > 255) {
        $errors[] = 'Description must be 255 characters or fewer.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('INSERT INTO departments (department_name, description) VALUES (:name, :description)');
            $stmt->execute(['name' => $name, 'description' => $description !== '' ? $description : null]);
            $newId = (int) $pdo->lastInsertId();

            auditLog(currentUserId(), 'department_created', 'department', $newId, ['name' => $name]);

            flash('success', 'Department added.');
            redirect('/admin/departments.php');
        } catch (PDOException $exception) {
            $errors[] = 'A department with this name already exists.';
        }
    }
}

$departments = $pdo->query(
    'SELECT d.id, d.department_name, d.description,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS user_count,
            (SELECT COUNT(*) FROM purchase_requests r WHERE r.department_id = d.id) AS request_count,
            (SELECT COUNT(*) FROM budgets b WHERE b.department_id = d.id) AS budget_count
     FROM departments d
     ORDER BY d.department_name'
)->fetchAll();

$pageTitle   = 'Departments';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Departments']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="two-col two-col-wide">
    <section class="panel">
        <h2>All departments</h2>
        <?php if (!$departments): ?>
            <p class="empty">No departments yet. Add the first one with the form.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Department</th><th>Description</th><th class="num">Users</th><th class="num">Requests</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><?= e($dept['department_name']) ?></td>
                        <td><?= e($dept['description']) ?></td>
                        <td class="num"><?= (int) $dept['user_count'] ?></td>
                        <td class="num"><?= (int) $dept['request_count'] ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/department_edit.php', ['id' => $dept['id']])) ?>">Edit</a>
                            <?php if ((int) $dept['user_count'] === 0 && (int) $dept['request_count'] === 0 && (int) $dept['budget_count'] === 0): ?>
                                <form method="post" action="<?= e(url('/admin/department_delete.php', ['id' => $dept['id']])) ?>" data-confirm="Delete <?= e($dept['department_name']) ?>?">
                                    <?= csrfField() ?>
                                    <button type="submit" class="btn btn-sm btn-ghost">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="hint">A department can be deleted only while it has no users, requests or budgets.</p>
        <?php endif; ?>
    </section>

    <aside class="panel">
        <h2>Add department</h2>
        <form method="post" action="<?= e(url('/admin/departments.php')) ?>" class="form">
            <?= csrfField() ?>
            <div class="field">
                <label for="department_name">Name</label>
                <input type="text" id="department_name" name="department_name" value="<?= e($name) ?>" maxlength="100" required>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="255"><?= e($description) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Add department</button>
        </form>
    </aside>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
