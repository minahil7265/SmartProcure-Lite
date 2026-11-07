<?php
// /var/www/smartprocure/public/orders/cancel.php
// Cancel an order with no deliveries (POST + CSRF). Budget is released.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abortPage(400, 'Orders can only be cancelled from the order page.');
}
verifyCsrf();

$id = requireIdParam();
$result = cancelPurchaseOrder($id, currentUserId());

flash($result['ok'] ? 'success' : 'error', $result['message']);
redirect('/orders/view.php', ['id' => $id]);
