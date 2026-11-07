<?php
// /var/www/smartprocure/public/signup.php
//
// Public registration. The visitor can NOT choose a role:
// every new account gets the database default role (employee) and
// stays inactive until an administrator approves it.
require_once __DIR__ . '/../includes/bootstrap.php';

if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$minLength = (int) config('password_min_length');

$errors = [];
$fullName = '';
$email = '';
$departmentId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName     = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $departmentId = $_POST['department_id'] ?? '';
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || mb_strlen($fullName) > 100) {
        $errors[] = 'Full name is required (max 100 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!in_array((int) $departmentId, array_map('intval', array_column($departments, 'id')), true)) {
        $errors[] = 'Please choose your department.';
    }
    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least {$minLength} characters.";
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        // Role and is_active are NOT sent: the database defaults
        // (employee, inactive) are used.
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, department_id)
             VALUES (:full_name, :email, :password_hash, :department_id)'
        );
        $stmt->execute([
            'full_name'     => $fullName,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'department_id' => (int) $departmentId,
        ]);

        $newId = (int) $pdo->lastInsertId();
        auditLog($newId, 'signup', 'user', $newId, ['email' => $email]);

        flash('success', 'Account created. An administrator must approve it before you can log in.');
        redirect('/login.php');
    }
}

$pageTitle  = 'Create account';
$authLayout = true;
require APP_ROOT . '/includes/header.php';
?>
<section class="auth-card">
    <h1>Create an account</h1>
    <p class="muted">You can log in once your account has been approved.</p>

    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/signup.php')) ?>" class="form">
        <?= csrfField() ?>

        <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>" maxlength="100" autocomplete="name" required>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($email) ?>" maxlength="150" autocomplete="email" required>
        </div>

        <div class="field">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id" required>
                <option value="">Choose your department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int) $dept['id'] ?>" <?= (string) $dept['id'] === (string) $departmentId ? 'selected' : '' ?>><?= e($dept['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="<?= $minLength ?>" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="<?= $minLength ?>" autocomplete="new-password" required>
            </div>
        </div>
        <p class="hint">At least <?= $minLength ?> characters.</p>

        <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>

    <p class="auth-switch">Already registered? <a href="<?= e(url('/login.php')) ?>">Log in</a></p>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
