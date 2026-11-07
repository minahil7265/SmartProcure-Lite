<?php
// /var/www/smartprocure/public/admin/user_create.php
// Admin: create an account that is active straight away.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$departmentIds = array_map('intval', array_column($departments, 'id'));
$vendorList = $pdo->query('SELECT id, vendor_name, status FROM vendors ORDER BY vendor_name')->fetchAll();
$vendorIds = array_map('intval', array_column($vendorList, 'id'));

$minLength = (int) config('password_min_length');

$errors = [];
$form = ['full_name' => '', 'email' => '', 'role' => DEFAULT_ROLE, 'department_id' => '', 'vendor_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $password = $_POST['password'] ?? '';

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
        $errors[] = 'Full name is required (max 100 characters).';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
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
    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least {$minLength} characters.";
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $form['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, role, department_id, vendor_id, is_active, approved_at)
             VALUES (:full_name, :email, :hash, :role, :department_id, :vendor_id, 1, NOW())'
        );
        $stmt->execute([
            'full_name'     => $form['full_name'],
            'email'         => $form['email'],
            'hash'          => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $form['role'],
            'department_id' => $form['department_id'] !== '' ? (int) $form['department_id'] : null,
            'vendor_id'     => $form['vendor_id'] !== '' ? (int) $form['vendor_id'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();

        auditLog(currentUserId(), 'user_created', 'user', $newId, ['email' => $form['email'], 'role' => $form['role']]);

        flash('success', 'User ' . $form['full_name'] . ' created.');
        redirect('/admin/users.php');
    }
}

$pageTitle   = 'Add user';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Users', 'url' => url('/admin/users.php')], ['label' => 'Add user']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/user_create.php')) ?>" class="form panel form-narrow">
    <?= csrfField() ?>
    <div class="field">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="100" required>
    </div>
    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="150" required>
    </div>
    <div class="field-row">
        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role">
                <?php foreach (ROLE_LABELS as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $form['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
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
    <div class="field">
        <label for="password">Temporary password</label>
        <input type="password" id="password" name="password" minlength="<?= $minLength ?>" autocomplete="new-password" required>
        <p class="hint">Share it privately; the user can change it on their profile page.</p>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create user</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/users.php')) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
