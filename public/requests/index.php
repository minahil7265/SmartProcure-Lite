<?php
// /var/www/smartprocure/public/requests/index.php
// READ: list purchase requests with search and status filter.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_EMPLOYEE, ROLE_MANAGER, ROLE_PROCUREMENT, ROLE_ADMIN);

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

// Which requests may this user see?
if (hasRole(ROLE_ADMIN)) {
    // all requests
} elseif (hasRole(ROLE_MANAGER)) {
    $where[] = '(r.requester_id = :me OR (r.department_id = :dept AND r.status <> :draft))';
    $params['me'] = currentUserId();
    $params['dept'] = currentDepartmentId();
    $params['draft'] = REQ_DRAFT;
} else {
    $where[] = 'r.requester_id = :me';
    $params['me'] = currentUserId();
}

if ($search !== '') {
    $where[] = '(r.purpose LIKE :search OR u.full_name LIKE :search2 OR r.id = :search_id)';
    $params['search'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search_id'] = (int) preg_replace('/\D/', '', $search);
}

if (in_array($status, REQUEST_STATUSES, true)) {
    $where[] = 'r.status = :status';
    $params['status'] = $status;
}

$sql = 'SELECT r.id, r.purpose, r.request_date, r.required_date, r.status, r.estimated_total,
               u.full_name AS requester_name, d.department_name
        FROM purchase_requests r
        JOIN users u ON u.id = r.requester_id
        JOIN departments d ON d.id = r.department_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY r.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle   = hasRole(ROLE_ADMIN) ? 'All purchase requests' : (hasRole(ROLE_MANAGER) ? 'Requests' : 'My purchase requests');
$breadcrumbs = [['label' => 'Requests']];
$pageActions = hasRole(...REQUESTER_ROLES)
    ? '<a class="btn btn-primary" href="' . e(url('/requests/create.php')) . '">New request</a>'
    : '';
require APP_ROOT . '/includes/header.php';
?>
<form method="get" action="<?= e(url('/requests/index.php')) ?>" class="filter-bar" role="search">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Purpose, requester or PR number">
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (REQUEST_STATUSES as $option): ?>
                <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('/requests/index.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<section class="panel">
<?php if (!$requests): ?>
    <p class="empty">No requests match. <?php if (hasRole(...REQUESTER_ROLES)): ?><a href="<?= e(url('/requests/create.php')) ?>">Create a new request</a>.<?php endif; ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>Purpose</th><th>Requester</th><th>Department</th>
                <th>Requested</th><th>Needed by</th><th class="num">Estimate</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $row): ?>
            <tr>
                <td><a href="<?= e(url('/requests/view.php', ['id' => $row['id']])) ?>">PR-<?= (int) $row['id'] ?></a></td>
                <td><?= e($row['purpose']) ?></td>
                <td><?= e($row['requester_name']) ?></td>
                <td><?= e($row['department_name']) ?></td>
                <td><?= e(showDate($row['request_date'])) ?></td>
                <td><?= e(showDate($row['required_date'])) ?></td>
                <td class="num"><?= e(money($row['estimated_total'])) ?></td>
                <td><?= badge($row['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="table-note"><?= count($requests) ?> request(s)</p>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
