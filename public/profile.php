<?php
// /var/www/smartprocure/public/profile.php
//
// The user's own profile. The ID always comes from the session, never
// from the URL, so nobody can open another person's profile.
require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();

$userId = currentUserId();
$minLength = (int) config('password_min_length');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_name') {
        $fullName = trim($_POST['full_name'] ?? '');

        if ($fullName === '' || mb_strlen($fullName) > 100) {
            $errors[] = 'Full name is required (max 100 characters).';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name = :name WHERE id = :id');
            $stmt->execute(['name' => $fullName, 'id' => $userId]);

            flash('success', 'Your name was updated.');
            redirect('/profile.php');
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Your current password is not correct.';
        } elseif (strlen($new) < $minLength) {
            $errors[] = "New password must be at least {$minLength} characters.";
        } elseif ($new !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $stmt->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);

            // New session ID after a credential change.
            session_regenerate_id(true);
            auditLog($userId, 'password_changed', 'user', $userId);

            flash('success', 'Your password was changed.');
            redirect('/profile.php');
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT u.full_name, u.email, u.role, u.approved_at, d.department_name, v.vendor_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN vendors v ON v.id = u.vendor_id
     WHERE u.id = :id'
);
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

$pageTitle   = 'My profile';
$breadcrumbs = [['label' => 'My profile']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="two-col">
    <section class="panel">
        <h2>Account details</h2>
        <dl class="details">
            <dt>Email</dt><dd><?= e($user['email']) ?></dd>
            <dt>Role</dt><dd><?= e(roleLabel($user['role'])) ?></dd>
            <?php if ($user['role'] === ROLE_VENDOR): ?>
                <dt>Company</dt><dd><?= e($user['vendor_name'] ?? '-') ?></dd>
            <?php else: ?>
                <dt>Department</dt><dd><?= e($user['department_name'] ?? 'Not assigned') ?></dd>
            <?php endif; ?>
            <dt>Approved on</dt><dd><?= e(showDate($user['approved_at'])) ?></dd>
        </dl>

        <form method="post" action="<?= e(url('/profile.php')) ?>" class="form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_name">
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" maxlength="100" required>
            </div>
            <button type="submit" class="btn btn-primary">Save name</button>
        </form>
        <p class="hint">Only an administrator can change your role, department or company.</p>
    </section>

    <section class="panel">
        <h2>Change password</h2>
        <form method="post" action="<?= e(url('/profile.php')) ?>" class="form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="field">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" minlength="<?= $minLength ?>" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="<?= $minLength ?>" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Change password</button>
        </form>
    </section>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
