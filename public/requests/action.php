<?php
// /var/www/smartprocure/public/requests/action.php
// Submit a draft, or cancel a draft/submitted request (POST only).
// Requests are never deleted - cancelling keeps them in history.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(...REQUESTER_ROLES);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abortPage(400, 'This action must be sent from the request page.');
}
verifyCsrf();

$id = requireIdParam();
$action = $_POST['action'] ?? '';

$allowed = [
    'submit' => ['from' => [REQ_DRAFT], 'to' => REQ_SUBMITTED, 'message' => 'Request submitted for approval.'],
    'cancel' => ['from' => REQUEST_EDITABLE, 'to' => REQ_CANCELLED, 'message' => 'Request cancelled.'],
];

if (!isset($allowed[$action])) {
    abortPage(400, 'Unknown action.');
}
$rule = $allowed[$action];

$pdo->beginTransaction();

$stmt = $pdo->prepare('SELECT id, requester_id, department_id, status FROM purchase_requests WHERE id = :id FOR UPDATE');
$stmt->execute(['id' => $id]);
$request = $stmt->fetch();

if (!$request || (int) $request['requester_id'] !== currentUserId()) {
    $pdo->rollBack();
    abortPage(403, 'Only the requester can change this request.');
}

if (!in_array($request['status'], $rule['from'], true)) {
    $pdo->rollBack();
    flash('error', 'This request is ' . $request['status'] . ' and can no longer be changed.');
    redirect('/requests/view.php', ['id' => $id]);
}

// Submitting starts the escalation clock (submitted_at).
$stmt = $pdo->prepare(
    'UPDATE purchase_requests
     SET status = :status, submitted_at = IF(:submitting = 1, NOW(), submitted_at)
     WHERE id = :id'
);
$stmt->execute(['status' => $rule['to'], 'submitting' => $action === 'submit' ? 1 : 0, 'id' => $id]);

if ($action === 'submit') {
    notifyRequestSubmitted($id, (int) $request['department_id'], currentUserId());
}

auditLog(currentUserId(), 'request_' . strtolower($rule['to']), 'purchase_request', $id, ['from' => $request['status']]);

$pdo->commit();

flash('success', $rule['message']);
redirect('/requests/view.php', ['id' => $id]);
