<?php
// /var/www/smartprocure/public/admin/user_edit.php
// Admin: change a user's name, role and department. Role changes are audited.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$id = requireIdParam();

$stmt = $pdo->prepare('SELECT id, full_name, email, role, department_id, vendor_id, is_active, approved_at FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    abortPage(404, 'User not found.');
}

$isMe = $id === currentUserId();
$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$departmentIds = array_map('intval', array_column($departments, 'id'));
$vendorList = $pdo->query('SELECT id, vendor_name, status FROM vendors ORDER BY vendor_name')->fetchAll();
$vendorIds = array_map('intval', array_column($vendorList, 'id'));


$errors = [];
$form = [
    'full_name'     => $user['full_name'],
    'role'          => $user['role'],
    'department_id' => (string) $user['department_id'],
    'vendor_id'     => (string) $user['vendor_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $form['full_name']     = trim($_POST['full_name'] ?? '');
    $form['role']          = $isMe ? $user['role'] : ($_POST['role'] ?? '');
    $form['department_id'] = trim($_POST['department_id'] ?? '');
    $form['vendor_id']     = trim($_POST['vendor_id'] ?? '');

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
        $errors[] = 'Full name is required (max 100 characters).';
    }
    if (!array_key_exists($form['role'], ROLE_LABELS)) {
        $errors[] = 'Choose a valid role.';
    }
    if ($form['department_id'] !== '' && !in_array((int) $form['department_id'], $departmentIds, true)) {
        $errors[] = 'Choose a valid department.';
    }
    // Vendor accounts belong to one vendor company and no department.
    if ($form['role'] === ROLE_VENDOR) {
        if (!in_array((int) $form['vendor_id'], $vendorIds, true)) {
            $errors[] = 'A vendor account must be linked to a vendor company.';
        }
        $form['department_id'] = '';
    } else {
        $form['vendor_id'] = '';
    }
    if (in_array($form['role'], REQUESTER_ROLES, true) && $form['department_id'] === '') {
        $errors[] = 'Employees, managers and procurement officers need a department.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE users SET full_name = :full_name, role = :role, department_id = :department_id, vendor_id = :vendor_id WHERE id = :id'
        );
        $stmt->execute([
            'full_name'     => $form['full_name'],
            'role'          => $form['role'],
            'department_id' => $form['department_id'] !== '' ? (int) $form['department_id'] : null,
            'vendor_id'     => $form['vendor_id'] !== '' ? (int) $form['vendor_id'] : null,
            'id'            => $id,
        ]);

        if ($form['role'] !== $user['role']) {
            auditLog(currentUserId(), 'role_changed', 'user', $id, ['from' => $user['role'], 'to' => $form['role']]);
        }
        if ($form['vendor_id'] !== (string) $user['vendor_id']) {
            auditLog(currentUserId(), 'vendor_link_changed', 'user', $id, ['from' => $user['vendor_id'], 'to' => $form['vendor_id']]);
        }
        if ($form['department_id'] !== (string) $user['department_id']) {
            auditLog(currentUserId(), 'department_changed', 'user', $id, ['from' => $user['department_id'], 'to' => $form['department_id']]);
        }

        flash('success', 'User updated. Changes apply on their next page load.');
        redirect('/admin/users.php');
    }
}

$pageTitle   = 'Edit user';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Users', 'url' => url('/admin/users.php')], ['label' => $user['full_name']]];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/user_edit.php', ['id' => $id])) ?>" class="form panel form-narrow">
    <?= csrfField() ?>
    <p class="muted"><?= e($user['email']) ?></p>
    <div class="field">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="100" required>
    </div>
    <div class="field-row">
        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" <?= $isMe ? 'disabled' : '' ?>>
                <?php foreach (ROLE_LABELS as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $form['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($isMe): ?><p class="hint">You cannot change your own role.</p><?php endif; ?>
        </div>
        <div class="field">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">None (admin and vendor)</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int) $dept['id'] ?>" <?= (string) $dept['id'] === $form['department_id'] ? 'selected' : '' ?>><?= e($dept['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field" data-vendor-field="<?= e(ROLE_VENDOR) ?>">
        <label for="vendor_id">Vendor company <span class="muted">(vendor accounts only)</span></label>
        <select id="vendor_id" name="vendor_id">
            <option value="">None</option>
            <?php foreach ($vendorList as $vendor): ?>
                <option value="<?= (int) $vendor['id'] ?>" <?= (string) $vendor['id'] === (string) $form['vendor_id'] ? 'selected' : '' ?>><?= e($vendor['vendor_name']) ?><?= $vendor['status'] !== VENDOR_ACTIVE ? ' (inactive)' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save user</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/users.php')) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
