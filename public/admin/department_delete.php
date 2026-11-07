<?php
// /var/www/smartprocure/public/admin/department_delete.php
// Admin: DELETE an unused department (POST + CSRF).
// Foreign keys stop the delete if users, requests or budgets still use it.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abortPage(400, 'Departments can only be deleted from the departments page.');
}
verifyCsrf();

$id = requireIdParam();

$stmt = $pdo->prepare('SELECT department_name FROM departments WHERE id = :id');
$stmt->execute(['id' => $id]);
$name = $stmt->fetchColumn();

if ($name === false) {
    abortPage(404, 'Department not found.');
}

try {
    $stmt = $pdo->prepare('DELETE FROM departments WHERE id = :id');
    $stmt->execute(['id' => $id]);

    auditLog(currentUserId(), 'department_deleted', 'department', $id, ['name' => $name]);
    flash('success', 'Department ' . $name . ' deleted.');
} catch (PDOException $exception) {
    flash('error', $name . ' is still used by users, requests or budgets, so it was not deleted.');
}

redirect('/admin/departments.php');
