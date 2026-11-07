<?php
// /var/www/smartprocure/public/vendors/index.php
// READ: vendor directory with search and status filter.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT, ROLE_ADMIN);

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(v.vendor_name LIKE :s1 OR v.contact_person LIKE :s2 OR v.email LIKE :s3)';
    $params['s1'] = $params['s2'] = $params['s3'] = '%' . $search . '%';
}
if (in_array($status, VENDOR_STATUSES, true)) {
    $where[] = 'v.status = :status';
    $params['status'] = $status;
}

$sql = 'SELECT v.*,
               (SELECT COUNT(*) FROM quotations q WHERE q.vendor_id = v.id) AS quotation_count,
               (SELECT COUNT(*) FROM purchase_orders po WHERE po.vendor_id = v.id AND po.status IN (:a1, :a2, :a3, :a4)) AS order_count,
               (SELECT COUNT(*) FROM users u WHERE u.vendor_id = v.id AND u.is_active = 1) AS account_count
        FROM vendors v'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY v.status, v.vendor_name';
[$params['a1'], $params['a2'], $params['a3'], $params['a4']] = PO_ACTIVE;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vendors = $stmt->fetchAll();

$pageTitle   = 'Vendors';
$breadcrumbs = [['label' => 'Vendors']];
$pageActions = hasRole(ROLE_PROCUREMENT)
    ? '<a class="btn btn-primary" href="' . e(url('/vendors/create.php')) . '">Add vendor</a>'
    : '';
require APP_ROOT . '/includes/header.php';
?>
<form method="get" action="<?= e(url('/vendors/index.php')) ?>" class="filter-bar" role="search">
    <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, contact or email">
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All</option>
            <?php foreach (VENDOR_STATUSES as $option): ?>
                <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('/vendors/index.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<section class="panel">
<?php if (!$vendors): ?>
    <p class="empty">No vendors found.<?php if (hasRole(ROLE_PROCUREMENT)): ?> <a href="<?= e(url('/vendors/create.php')) ?>">Add the first vendor</a>.<?php endif; ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Vendor</th><th>Contact</th><th>Email</th><th>Phone</th><th class="num">Quotations</th><th class="num">Orders</th><th class="num">Logins</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($vendors as $vendor): ?>
            <tr>
                <td><a href="<?= e(url('/vendors/view.php', ['id' => $vendor['id']])) ?>"><?= e($vendor['vendor_name']) ?></a></td>
                <td><?= e($vendor['contact_person']) ?></td>
                <td><?= e($vendor['email']) ?></td>
                <td><?= e($vendor['phone']) ?></td>
                <td class="num"><?= (int) $vendor['quotation_count'] ?></td>
                <td class="num"><?= (int) $vendor['order_count'] ?></td>
                <td class="num"><?= (int) $vendor['account_count'] ?></td>
                <td><?= badge($vendor['status']) ?></td>
                <td>
                    <?php if (hasRole(ROLE_PROCUREMENT)): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e(url('/vendors/edit.php', ['id' => $vendor['id']])) ?>">Edit</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
