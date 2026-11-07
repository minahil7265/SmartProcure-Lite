<?php
// /var/www/smartprocure/public/dashboard.php
//
// One dashboard, different content for each role.
// Every query is filtered by the logged-in user's role/department.
require_once __DIR__ . '/../includes/bootstrap.php';

requireLogin();

$userId = currentUserId();
$deptId = currentDepartmentId();
$period = budgetPeriodFor(date('Y-m-d'));

/**
 * Print a <canvas> that app.js turns into a Chart.js chart.
 */
function chartCanvas(string $type, array $labels, array $datasets, string $label): string
{
    $configJson = json_encode(['type' => $type, 'labels' => $labels, 'datasets' => $datasets]);
    return '<canvas class="chart" role="img" aria-label="' . e($label) . '" data-chart="' . e($configJson) . '"></canvas>';
}

/**
 * Turn rows of [label, total] into two arrays for a chart.
 */
function chartColumns(array $rows, string $labelKey, string $valueKey): array
{
    return [array_column($rows, $labelKey), array_map('floatval', array_column($rows, $valueKey))];
}

// Placeholders for "order is active" (not cancelled or declined).
$active = ['a1' => PO_ACTIVE[0], 'a2' => PO_ACTIVE[1], 'a3' => PO_ACTIVE[2], 'a4' => PO_ACTIVE[3]];
$activeSql = 'IN (:a1, :a2, :a3, :a4)';
$pending = ['p1' => PO_PENDING_DELIVERY[0], 'p2' => PO_PENDING_DELIVERY[1], 'p3' => PO_PENDING_DELIVERY[2]];
$pendingSql = 'IN (:p1, :p2, :p3)';

$stats = [];   // small number tiles
$charts = [];  // ['title' => ..., 'html' => ...]
$tables = [];  // extra lists shown below

// ------------------------------------------------------------------
// Vendor: only its own RFQs and orders (vendor ID from the session)
// ------------------------------------------------------------------
if (hasRole(ROLE_VENDOR)) {
    $vendorId = currentVendorId();

    $stmt = $pdo->prepare(
        'SELECT f.id, f.deadline, f.status, q.id AS quotation_id
         FROM rfq_vendors rv
         JOIN rfqs f ON f.id = rv.rfq_id
         LEFT JOIN quotations q ON q.rfq_id = f.id AND q.vendor_id = rv.vendor_id
         WHERE rv.vendor_id = :vendor AND f.status = :open AND f.deadline > NOW()
         ORDER BY f.deadline'
    );
    $stmt->execute(['vendor' => $vendorId, 'open' => RFQ_OPEN]);
    $openRfqs = $stmt->fetchAll();
    $toQuote = count(array_filter($openRfqs, fn ($r) => $r['quotation_id'] === null));

    $stats[] = ['label' => 'RFQs waiting for your quotation', 'value' => $toQuote, 'alert' => $toQuote > 0, 'link' => url('/vendor/rfqs.php')];

    $stmt = $pdo->prepare('SELECT status, COUNT(*) FROM purchase_orders WHERE vendor_id = :vendor GROUP BY status');
    $stmt->execute(['vendor' => $vendorId]);
    $myOrders = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats[] = ['label' => 'Orders waiting for your response', 'value' => $myOrders[PO_CREATED] ?? 0, 'alert' => ($myOrders[PO_CREATED] ?? 0) > 0, 'link' => url('/vendor/orders.php')];
    $stats[] = ['label' => 'Orders to deliver', 'value' => ($myOrders[PO_ACCEPTED] ?? 0) + ($myOrders[PO_PARTIAL] ?? 0), 'link' => url('/vendor/orders.php')];

    $reliability = vendorReliability($vendorId);
    $stats[] = ['label' => 'Your delivery reliability', 'value' => $reliability['rating'] . ' / ' . RATING_MAX];

    $tables['Open RFQs'] = ['type' => 'vendor_rfqs', 'rows' => $openRfqs];
}

// ------------------------------------------------------------------
// Requesters (employee, manager, procurement): their own requests
// ------------------------------------------------------------------
if (hasRole(...REQUESTER_ROLES)) {
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS total
         FROM purchase_requests WHERE requester_id = :id
         GROUP BY status'
    );
    $stmt->execute(['id' => $userId]);
    $myStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats[] = ['label' => 'My open requests', 'value' => ($myStatus[REQ_DRAFT] ?? 0) + ($myStatus[REQ_SUBMITTED] ?? 0), 'link' => url('/requests/index.php')];
    $stats[] = ['label' => 'My approved requests', 'value' => $myStatus[REQ_APPROVED] ?? 0];

    if ($myStatus) {
        $charts[] = [
            'title' => 'My requests by status',
            'html'  => chartCanvas('doughnut', array_keys($myStatus), [['data' => array_map('intval', array_values($myStatus))]], 'My requests by status'),
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT id, purpose, status, estimated_total, request_date
         FROM purchase_requests WHERE requester_id = :id
         ORDER BY id DESC LIMIT 5'
    );
    $stmt->execute(['id' => $userId]);
    $tables['My recent requests'] = ['type' => 'requests', 'rows' => $stmt->fetchAll()];
}

// ------------------------------------------------------------------
// Manager: department approvals and budget
// ------------------------------------------------------------------
if (hasRole(ROLE_MANAGER) && $deptId) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM purchase_requests
         WHERE department_id = :dept AND status = :status AND requester_id <> :me'
    );
    $stmt->execute(['dept' => $deptId, 'status' => REQ_SUBMITTED, 'me' => $userId]);
    $stats[] = ['label' => 'Waiting for my approval', 'value' => (int) $stmt->fetchColumn(), 'link' => url('/approvals/index.php')];

    $budget = findBudget($deptId, $period);
    if ($budget) {
        $stats[] = ['label' => "Budget available ({$period})", 'value' => money(availableBudget($budget))];
        $charts[] = [
            'title' => "Department budget {$period}",
            'html'  => chartCanvas('bar', ['Allocated', 'Committed', 'Available'], [[
                'label' => 'Amount',
                'data'  => [(float) $budget['allocated_amount'], (float) $budget['committed_amount'], availableBudget($budget)],
            ]], 'Department budget'),
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT a.decision, COUNT(*) AS total
         FROM approvals a WHERE a.approver_id = :me
         GROUP BY a.decision'
    );
    $stmt->execute(['me' => $userId]);
    $decisions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($decisions) {
        $charts[] = [
            'title' => 'My approval decisions',
            'html'  => chartCanvas('doughnut', array_keys($decisions), [['data' => array_map('intval', array_values($decisions))]], 'Approval decisions'),
        ];
    }
}

// ------------------------------------------------------------------
// Procurement officer and admin: orders, deliveries, vendors
// ------------------------------------------------------------------
if (hasRole(ROLE_PROCUREMENT, ROLE_ADMIN)) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM purchase_requests r
         WHERE r.status = :approved
           AND NOT EXISTS (SELECT 1 FROM purchase_orders po WHERE po.request_id = r.id AND po.status ' . $activeSql . ')'
    );
    $stmt->execute(['approved' => REQ_APPROVED] + $active);
    $stats[] = ['label' => 'Approved, not yet ordered', 'value' => (int) $stmt->fetchColumn(), 'link' => hasRole(ROLE_PROCUREMENT) ? url('/rfqs/index.php') : null];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM rfqs f
         WHERE f.status = :open AND f.deadline <= NOW()
           AND NOT EXISTS (SELECT 1 FROM purchase_orders po WHERE po.request_id = f.request_id AND po.status ' . $activeSql . ')'
    );
    $stmt->execute(['open' => RFQ_OPEN] + $active);
    $readyToCompare = (int) $stmt->fetchColumn();
    $stats[] = ['label' => 'RFQs ready to compare', 'value' => $readyToCompare, 'alert' => $readyToCompare > 0, 'link' => hasRole(ROLE_PROCUREMENT) ? url('/rfqs/index.php') : null];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE status = :created');
    $stmt->execute(['created' => PO_CREATED]);
    $stats[] = ['label' => 'Orders waiting for vendor', 'value' => (int) $stmt->fetchColumn(), 'link' => url('/orders/index.php', ['status' => PO_CREATED])];

    $stmt = $pdo->prepare(
        'SELECT po.id, po.expected_delivery_date, po.status, po.total_amount, v.vendor_name
         FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
         WHERE po.status ' . $pendingSql . ' AND po.expected_delivery_date < CURDATE()
         ORDER BY po.expected_delivery_date'
    );
    $stmt->execute($pending);
    $overdue = $stmt->fetchAll();
    $stats[] = ['label' => 'Overdue deliveries', 'value' => count($overdue), 'alert' => count($overdue) > 0, 'link' => url('/orders/index.php', ['overdue' => 1])];
    if ($overdue) {
        $tables['Overdue orders'] = ['type' => 'orders', 'rows' => $overdue];
    }

    $stmt = $pdo->prepare(
        'SELECT v.vendor_name, SUM(po.total_amount) AS total
         FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
         WHERE po.status ' . $activeSql . '
         GROUP BY v.id, v.vendor_name
         ORDER BY total DESC LIMIT 8'
    );
    $stmt->execute($active);
    [$labels, $values] = chartColumns($stmt->fetchAll(), 'vendor_name', 'total');
    if ($labels) {
        $charts[] = ['title' => 'Order value by vendor', 'html' => chartCanvas('bar', $labels, [['label' => 'Order value', 'data' => $values]], 'Order value by vendor')];
    }

    $stmt = $pdo->query('SELECT status, COUNT(*) FROM purchase_orders GROUP BY status');
    $orderStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($orderStatus) {
        $charts[] = ['title' => 'Orders by status', 'html' => chartCanvas('doughnut', array_keys($orderStatus), [['data' => array_map('intval', array_values($orderStatus))]], 'Orders by status')];
    }
}

// ------------------------------------------------------------------
// Admin: users, all requests, budgets, monthly spending
// ------------------------------------------------------------------
if (hasRole(ROLE_ADMIN)) {
    $pendingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0 AND approved_at IS NULL')->fetchColumn();
    $escalatedCount = count(escalatedRequests());
    array_unshift($stats,
        ['label' => 'Accounts waiting for approval', 'value' => $pendingUsers, 'alert' => $pendingUsers > 0, 'link' => url('/admin/users.php', ['filter' => 'pending'])],
        ['label' => 'Escalated approvals', 'value' => $escalatedCount, 'alert' => $escalatedCount > 0, 'link' => url('/approvals/index.php')]
    );
    $stats[] = ['label' => 'Active users', 'value' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn()];

    $requestStatus = $pdo->query('SELECT status, COUNT(*) FROM purchase_requests GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($requestStatus) {
        $charts[] = ['title' => 'All requests by status', 'html' => chartCanvas('doughnut', array_keys($requestStatus), [['data' => array_map('intval', array_values($requestStatus))]], 'Requests by status')];
    }

    $stmt = $pdo->prepare(
        'SELECT d.department_name, b.allocated_amount, b.committed_amount
         FROM budgets b JOIN departments d ON d.id = b.department_id
         WHERE b.budget_period = :period
         ORDER BY d.department_name'
    );
    $stmt->execute(['period' => $period]);
    $budgetRows = $stmt->fetchAll();
    if ($budgetRows) {
        $charts[] = ['title' => "Budget use by department ({$period})", 'html' => chartCanvas('bar', array_column($budgetRows, 'department_name'), [
            ['label' => 'Allocated', 'data' => array_map('floatval', array_column($budgetRows, 'allocated_amount'))],
            ['label' => 'Committed', 'data' => array_map('floatval', array_column($budgetRows, 'committed_amount'))],
        ], 'Budget use by department')];
    }

    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) AS total
         FROM purchase_orders
         WHERE status {$activeSql} AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY month ORDER BY month"
    );
    $stmt->execute($active);
    [$labels, $values] = chartColumns($stmt->fetchAll(), 'month', 'total');
    if ($labels) {
        $charts[] = ['title' => 'Monthly purchasing (last 6 months)', 'html' => chartCanvas('line', $labels, [['label' => 'Order value', 'data' => $values]], 'Monthly purchasing')];
    }
}

$pageTitle   = 'Dashboard';
$breadcrumbs = [['label' => 'Dashboard']];
$useCharts   = (bool) $charts;
$pageActions = hasRole(...REQUESTER_ROLES)
    ? '<a class="btn btn-primary" href="' . e(url('/requests/create.php')) . '">New request</a>'
    : '';

require APP_ROOT . '/includes/header.php';
?>
<?php if (hasRole(...REQUESTER_ROLES) && !$deptId): ?>
    <div class="alert alert-warning">You are not linked to a department yet, so you cannot raise requests. Ask the administrator to set your department.</div>
<?php endif; ?>

<section class="stat-strip" aria-label="Summary">
    <?php foreach ($stats as $stat): ?>
        <div class="stat<?= !empty($stat['alert']) ? ' stat-alert' : '' ?>">
            <span class="stat-value"><?= e($stat['value']) ?></span>
            <span class="stat-label">
                <?php if (!empty($stat['link'])): ?>
                    <a href="<?= e($stat['link']) ?>"><?= e($stat['label']) ?></a>
                <?php else: ?>
                    <?= e($stat['label']) ?>
                <?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
</section>

<?php if ($charts): ?>
<section class="chart-grid">
    <?php foreach ($charts as $chart): ?>
        <figure class="panel chart-panel">
            <figcaption><?= e($chart['title']) ?></figcaption>
            <div class="chart-box"><?= $chart['html'] ?></div>
        </figure>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php foreach ($tables as $title => $table): ?>
<section class="panel">
    <h2><?= e($title) ?></h2>
    <?php if (!$table['rows']): ?>
        <?php if ($table['type'] === 'requests'): ?>
            <p class="empty">Nothing here yet. <a href="<?= e(url('/requests/create.php')) ?>">Raise your first request</a>.</p>
        <?php else: ?>
            <p class="empty">Nothing open right now. New invitations will appear here.</p>
        <?php endif; ?>
    <?php elseif ($table['type'] === 'vendor_rfqs'): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>RFQ</th><th>Deadline</th><th>My quotation</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($table['rows'] as $row): ?>
                <tr>
                    <td>RFQ-<?= (int) $row['id'] ?></td>
                    <td class="nowrap"><?= e(date('d M Y, H:i', strtotime($row['deadline']))) ?></td>
                    <td><?= $row['quotation_id'] ? badge('Submitted') : '<span class="muted">Not submitted</span>' ?></td>
                    <td><a class="btn btn-sm btn-primary" href="<?= e(url('/vendor/rfq.php', ['id' => $row['id']])) ?>"><?= $row['quotation_id'] ? 'View / edit' : 'Submit quotation' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php elseif ($table['type'] === 'requests'): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Purpose</th><th>Date</th><th class="num">Estimate</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($table['rows'] as $row): ?>
                <tr>
                    <td><a href="<?= e(url('/requests/view.php', ['id' => $row['id']])) ?>">PR-<?= (int) $row['id'] ?></a></td>
                    <td><?= e($row['purpose']) ?></td>
                    <td><?= e(showDate($row['request_date'])) ?></td>
                    <td class="num"><?= e(money($row['estimated_total'])) ?></td>
                    <td><?= badge($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Order</th><th>Vendor</th><th>Expected</th><th class="num">Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($table['rows'] as $row): ?>
                <tr class="row-overdue">
                    <td><a href="<?= e(url('/orders/view.php', ['id' => $row['id']])) ?>">PO-<?= (int) $row['id'] ?></a></td>
                    <td><?= e($row['vendor_name']) ?></td>
                    <td><?= e(showDate($row['expected_delivery_date'])) ?></td>
                    <td class="num"><?= e(money($row['total_amount'])) ?></td>
                    <td><?= badge($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
