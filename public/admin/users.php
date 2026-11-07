<?php
// /var/www/smartprocure/public/admin/users.php
// Admin: all user accounts, with pending approvals first.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$role   = $_GET['role'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.full_name LIKE :s1 OR u.email LIKE :s2)';
    $params['s1'] = $params['s2'] = '%' . $search . '%';
}
if ($filter === 'pending') {
    $where[] = 'u.is_active = 0 AND u.approved_at IS NULL';
} elseif ($filter === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($filter === 'inactive') {
    $where[] = 'u.is_active = 0 AND u.approved_at IS NOT NULL';
}
if (array_key_exists($role, ROLE_LABELS)) {
    $where[] = 'u.role = :role';
    $params['role'] = $role;
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.approved_at,
            COALESCE(d.department_name, v.vendor_name) AS belongs_to
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN vendors v ON v.id = u.vendor_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
    ' ORDER BY (u.is_active = 0 AND u.approved_at IS NULL) DESC, u.full_name'
);
$stmt->execute($params);
$users = $stmt->fetchAll();

/**
 * Account state shown in the table.
 */
function accountState(array $user): string
{
    if ((int) $user['is_active'] === 1) {
        return 'Active';
    }
    return $user['approved_at'] === null ? 'Pending' : 'Inactive';
}

$pageTitle   = 'Users';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Users']];
$pageActions = '<a class="btn btn-primary" href="' . e(url('/admin/user_create.php')) . '">Add user</a>';
require APP_ROOT . '/includes/header.php';
?>
<form method="get" action="<?= e(url('/admin/users.php')) ?>" class="filter-bar" role="search">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Name or email">
    </div>
    <div class="field">
        <label for="filter">Account</label>
        <select id="filter" name="filter">
            <option value="">All accounts</option>
            <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Waiting for approval</option>
            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
        </select>
    </div>
    <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="">All roles</option>
            <?php foreach (ROLE_LABELS as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $role === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if ($search !== '' || $filter !== '' || $role !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('/admin/users.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<section class="panel">
<?php if (!$users): ?>
    <p class="empty">No users match these filters.</p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department / company</th><th>Account</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $state = accountState($user); $isMe = (int) $user['id'] === currentUserId(); ?>
            <tr class="<?= $state === 'Pending' ? 'row-pending' : '' ?>">
                <td><?= e($user['full_name']) ?><?= $isMe ? ' <span class="muted">(you)</span>' : '' ?></td>
                <td><?= e($user['email']) ?></td>
                <td><?= e(roleLabel($user['role'])) ?></td>
                <td><?= e($user['belongs_to'] ?? '-') ?></td>
                <td><?= badge($state) ?></td>
                <td class="actions">
                    <?php if (!$isMe): ?>
                        <form method="post" action="<?= e(url('/admin/user_action.php', ['id' => $user['id']])) ?>">
                            <?= csrfField() ?>
                            <?php if ($state === 'Pending'): ?>
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                            <?php elseif ($state === 'Active'): ?>
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" class="btn btn-sm btn-ghost">Deactivate</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="activate">
                                <button type="submit" class="btn btn-sm">Activate</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/user_edit.php', ['id' => $user['id']])) ?>">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
