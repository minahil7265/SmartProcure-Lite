<?php
// /var/www/smartprocure/public/login.php
require_once __DIR__ . '/../includes/bootstrap.php';

if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $lockMinutes = $email !== '' ? loginLockMinutes($email) : 0;

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif ($lockMinutes > 0) {
        // Rate limit: the password is not even checked while locked.
        $error = "Too many failed attempts. Please try again in {$lockMinutes} minute(s).";
        auditLog(null, 'login_rate_limited', 'user', null, ['email' => $email, 'ip' => clientIp()]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.password_hash, u.role, u.department_id, u.vendor_id,
                    u.is_active, u.approved_at, v.status AS vendor_status
             FROM users u
             LEFT JOIN vendors v ON v.id = u.vendor_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {

            $vendorBlocked = $user['role'] === ROLE_VENDOR
                && ($user['vendor_id'] === null || $user['vendor_status'] !== VENDOR_ACTIVE);

            if ($vendorBlocked) {
                $error = 'This vendor account is not active. Please contact the buyer.';
                auditLog((int) $user['id'], 'login_blocked', 'user', (int) $user['id'], 'vendor inactive');
            } elseif ((int) $user['is_active'] !== 1) {
                // Correct password, but the admin has not approved (or has deactivated) the account.
                $error = $user['approved_at'] === null
                    ? 'Your account is waiting for administrator approval. You can log in once it is approved.'
                    : 'Your account has been deactivated. Please contact the administrator.';

                auditLog((int) $user['id'], 'login_blocked', 'user', (int) $user['id'], $user['approved_at'] === null ? 'pending approval' : 'deactivated');
            } else {
                // Prevent session fixation: new session ID after login.
                session_regenerate_id(true);
                regenerateCsrfToken();
                clearLoginAttempts($email);

                $_SESSION['user_id']       = (int) $user['id'];
                $_SESSION['name']          = $user['full_name'];
                $_SESSION['email']         = $user['email'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['department_id'] = $user['department_id'] !== null ? (int) $user['department_id'] : null;
                $_SESSION['vendor_id']     = $user['vendor_id'] !== null ? (int) $user['vendor_id'] : null;
                $_SESSION['last_activity'] = time();

                auditLog((int) $user['id'], 'login', 'user', (int) $user['id']);

                flash('success', 'Welcome back, ' . $user['full_name'] . '.');
                redirect('/dashboard.php');
            }
        } else {
            // Generic message: do not reveal whether the email exists.
            $error = 'Invalid email or password.';
            recordFailedLogin($email);
            auditLog(null, 'login_failed', 'user', null, ['email' => $email, 'ip' => clientIp()]);
        }
    }
}

$pageTitle  = 'Log in';
$authLayout = true;
require APP_ROOT . '/includes/header.php';
?>
<section class="auth-card">
    <h1>Log in</h1>
    <p class="muted">Use the email and password you registered with.</p>

    <?php if (isset($_GET['logged_out']) && $error === ''): ?>
        <div class="alert alert-success" role="status">You have been logged out.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login.php')) ?>" class="form" novalidate>
        <?= csrfField() ?>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" maxlength="150" required autofocus>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>

    <p class="auth-switch">New here? <a href="<?= e(url('/signup.php')) ?>">Create an account</a></p>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
