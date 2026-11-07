<?php
// /var/www/smartprocure/app/audit.php
//
// Writes one row to audit_logs. The database user can only INSERT and
// SELECT on this table, so entries cannot be edited or deleted.

/**
 * @param int|null     $userId   null for failed logins
 * @param string       $action   short name, e.g. 'login', 'request_approved'
 * @param string|null  $entityType  e.g. 'purchase_request'
 * @param int|null     $entityId
 * @param array|string $details  extra information (arrays are saved as JSON)
 */
function auditLog(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, $details = null): void
{
    global $pdo;

    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details)'
    );

    $stmt->execute([
        'user_id'     => $userId,
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'details'     => $details,
    ]);
}
