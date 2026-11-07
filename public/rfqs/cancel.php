<?php
// /var/www/smartprocure/public/rfqs/cancel.php
// Cancel an RFQ that has no active purchase order (POST + CSRF).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abortPage(400, 'RFQs can only be cancelled from the RFQ page.');
}
verifyCsrf();

$id = requireIdParam();

$pdo->beginTransaction();

$stmt = $pdo->prepare('SELECT id, request_id, status FROM rfqs WHERE id = :id FOR UPDATE');
$stmt->execute(['id' => $id]);
$rfq = $stmt->fetch();

if (!$rfq) {
    $pdo->rollBack();
    abortPage(404, 'RFQ not found.');
}
if ($rfq['status'] !== RFQ_OPEN || activeOrderForRequest((int) $rfq['request_id'])) {
    $pdo->rollBack();
    flash('error', 'Only open RFQs without a purchase order can be cancelled.');
    redirect('/rfqs/view.php', ['id' => $id]);
}

$stmt = $pdo->prepare('UPDATE rfqs SET status = :cancelled WHERE id = :id');
$stmt->execute(['cancelled' => RFQ_CANCELLED, 'id' => $id]);

foreach (rfqInvitations($id) as $row) {
    notifyUsers(vendorUserIds((int) $row['id']), 'RFQ-' . $id . ' was cancelled by the buyer.', '/vendor/rfqs.php');
}
auditLog(currentUserId(), 'rfq_cancelled', 'rfq', $id, ['request_id' => (int) $rfq['request_id']]);

$pdo->commit();

flash('success', 'RFQ cancelled. You can create a new RFQ for this request.');
redirect('/rfqs/index.php');
