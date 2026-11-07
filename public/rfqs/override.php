<?php
// /var/www/smartprocure/public/rfqs/override.php
// The officer changes a quotation's reliability rating, with a reason (POST + CSRF).
// Allowed only after the deadline and before a vendor is selected.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abortPage(400, 'Ratings can only be changed from the RFQ page.');
}
verifyCsrf();

$rfqId = requireIdParam();
$rfq = findRfq($rfqId);

if (!$rfq) {
    abortPage(404, 'RFQ not found.');
}

$quotationId = filter_var($_POST['quotation_id'] ?? '', FILTER_VALIDATE_INT);
$rating      = filter_var($_POST['rating'] ?? '', FILTER_VALIDATE_INT);
$reason      = trim($_POST['reason'] ?? '');


if (!rfqDeadlinePassed($rfq) || $rfq['status'] !== RFQ_OPEN || activeOrderForRequest((int) $rfq['request_id'])) {
    flash('error', 'Ratings can be changed only after the deadline and before a vendor is selected.');
    redirect('/rfqs/view.php', ['id' => $rfqId]);
}
if ($rating === false || $rating < RATING_MIN || $rating > RATING_MAX) {
    flash('error', 'The rating must be between ' . RATING_MIN . ' and ' . RATING_MAX . '.');
    redirect('/rfqs/view.php', ['id' => $rfqId]);
}
if ($reason === '' || mb_strlen($reason) > 255) {
    flash('error', 'A reason (up to 255 characters) is required to override a rating.');
    redirect('/rfqs/view.php', ['id' => $rfqId]);
}

// The quotation must belong to THIS RFQ.
$stmt = $pdo->prepare('SELECT id, quality_rating, rating_calculated FROM quotations WHERE id = :id AND rfq_id = :rfq');
$stmt->execute(['id' => $quotationId ?: 0, 'rfq' => $rfqId]);
$quote = $stmt->fetch();

if (!$quote) {
    abortPage(404, 'Quotation not found in this RFQ.');
}

$stmt = $pdo->prepare('UPDATE quotations SET quality_rating = :rating, rating_override_reason = :reason WHERE id = :id');
$stmt->execute(['rating' => $rating, 'reason' => $reason, 'id' => $quote['id']]);

auditLog(currentUserId(), 'rating_overridden', 'quotation', (int) $quote['id'], [
    'rfq_id'     => $rfqId,
    'from'       => (int) $quote['quality_rating'],
    'to'         => $rating,
    'calculated' => (int) $quote['rating_calculated'],
    'reason'     => $reason,
]);

flash('success', 'Reliability rating changed. The scores below use the new rating.');
redirect('/rfqs/view.php', ['id' => $rfqId]);
