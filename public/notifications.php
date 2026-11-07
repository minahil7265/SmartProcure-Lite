<?php
// /var/www/smartprocure/public/notifications.php
// The logged-in user's own notifications (ID from the session).
require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :id AND is_read = 0');
    $stmt->execute(['id' => currentUserId()]);

    flash('success', 'All notifications marked as read.');
    redirect('/notifications.php');
}

$stmt = $pdo->prepare(
    'SELECT message, link, is_read, created_at
     FROM notifications
     WHERE user_id = :id
     ORDER BY id DESC
     LIMIT 100'
);
$stmt->execute(['id' => currentUserId()]);
$notifications = $stmt->fetchAll();
$unread = count(array_filter($notifications, fn ($n) => (int) $n['is_read'] === 0));

$pageTitle   = 'Notifications';
$breadcrumbs = [['label' => 'Notifications']];
if ($unread > 0) {
    $pageActions = '<form method="post" action="' . e(url('/notifications.php')) . '">' . csrfField()
        . '<button type="submit" class="btn">Mark all as read</button></form>';
}
require APP_ROOT . '/includes/header.php';
?>
<section class="panel">
<?php if (!$notifications): ?>
    <p class="empty">No notifications yet.</p>
<?php else: ?>
    <ul class="notification-list">
    <?php foreach ($notifications as $note): ?>
        <li class="<?= (int) $note['is_read'] === 0 ? 'unread' : '' ?>">
            <span class="note-time"><?= e(date('d M Y, H:i', strtotime($note['created_at']))) ?></span>
            <?php if ($note['link']): ?>
                <a href="<?= e(url($note['link'])) ?>"><?= e($note['message']) ?></a>
            <?php else: ?>
                <?= e($note['message']) ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
