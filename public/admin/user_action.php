<?php
// /var/www/smartprocure/public/admin/user_action.php
// Approve, activate or deactivate an account (POST + CSRF).
// Users are never deleted, so their requests and approvals stay valid.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abortPage(400, 'This action must be sent from the users page.');
}
verifyCsrf();

$id = requireIdParam();
$action = $_POST['action'] ?? '';

if ($id === currentUserId()) {
    flash('error', 'You cannot change the status of your own account.');
    redirect('/admin/users.php');
}

$stmt = $pdo->prepare('SELECT id, full_name, is_active, approved_at FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    abortPage(404, 'User not found.');
}

if ($action === 'approve' || $action === 'activate') {
    // approved_at is set the first time only.
    $stmt = $pdo->prepare('UPDATE users SET is_active = 1, approved_at = COALESCE(approved_at, NOW()) WHERE id = :id');
    $stmt->execute(['id' => $id]);
    auditLog(currentUserId(), $action === 'approve' ? 'user_approved' : 'user_activated', 'user', $id);
    flash('success', $user['full_name'] . ($action === 'approve' ? ' approved. They can now log in.' : ' activated.'));
} elseif ($action === 'deactivate') {
    $stmt = $pdo->prepare('UPDATE users SET is_active = 0, approved_at = COALESCE(approved_at, NOW()) WHERE id = :id');
    $stmt->execute(['id' => $id]);
    auditLog(currentUserId(), 'user_deactivated', 'user', $id);
    flash('success', $user['full_name'] . ' deactivated. They are logged out on their next click.');
} else {
    abortPage(400, 'Unknown action.');
}

redirect('/admin/users.php');
