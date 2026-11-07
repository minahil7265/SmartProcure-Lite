<?php
// /var/www/smartprocure/public/admin/department_edit.php
// Admin: UPDATE a department.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$id = requireIdParam();

$stmt = $pdo->prepare('SELECT id, department_name, description FROM departments WHERE id = :id');
$stmt->execute(['id' => $id]);
$department = $stmt->fetch();

if (!$department) {
    abortPage(404, 'Department not found.');
}

$errors = [];
$name = $department['department_name'];
$description = (string) $department['description'];

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
            $stmt = $pdo->prepare('UPDATE departments SET department_name = :name, description = :description WHERE id = :id');
            $stmt->execute(['name' => $name, 'description' => $description !== '' ? $description : null, 'id' => $id]);

            auditLog(currentUserId(), 'department_updated', 'department', $id, ['name' => $name]);

            flash('success', 'Department updated.');
            redirect('/admin/departments.php');
        } catch (PDOException $exception) {
            $errors[] = 'A department with this name already exists.';
        }
    }
}

$pageTitle   = 'Edit department';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Departments', 'url' => url('/admin/departments.php')], ['label' => $department['department_name']]];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/department_edit.php', ['id' => $id])) ?>" class="form panel form-narrow">
    <?= csrfField() ?>
    <div class="field">
        <label for="department_name">Name</label>
        <input type="text" id="department_name" name="department_name" value="<?= e($name) ?>" maxlength="100" required>
    </div>
    <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="255"><?= e($description) ?></textarea>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save department</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/departments.php')) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
