<?php
// /var/www/smartprocure/app/notifications.php
//
// In-app notifications. Each message is stored for one user and shown
// on the Notifications page. (Email can be added here later.)

/**
 * Send the same message to several users.
 */
function notifyUsers(array $userIds, string $message, ?string $link = null): void
{
    global $pdo;

    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (:user, :message, :link)');

    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if ($userId > 0) {
            $stmt->execute(['user' => $userId, 'message' => mb_substr($message, 0, 255), 'link' => $link]);
        }
    }
}

/**
 * Active users with a role (optionally only in one department).
 */
function activeUserIds(string $role, ?int $departmentId = null): array
{
    global $pdo;

    $sql = 'SELECT id FROM users WHERE role = :role AND is_active = 1';
    $params = ['role' => $role];

    if ($departmentId !== null) {
        $sql .= ' AND department_id = :dept';
        $params['dept'] = $departmentId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Active login accounts of one vendor company.
 */
function vendorUserIds(int $vendorId): array
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = :role AND vendor_id = :vendor AND is_active = 1');
    $stmt->execute(['role' => ROLE_VENDOR, 'vendor' => $vendorId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function unreadNotificationCount(int $userId): int
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0');
    $stmt->execute(['id' => $userId]);
    return (int) $stmt->fetchColumn();
}
