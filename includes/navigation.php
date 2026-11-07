<?php
// /var/www/smartprocure/includes/navigation.php
//
// Sidebar links for each role.
// Hiding a link is only for convenience - each page checks the role again.

function navigationLinks(): array
{
    global $pdo;

    $links = [
        ['label' => 'Dashboard', 'path' => '/dashboard.php', 'icon' => '◧'],
    ];

    // ---- Vendor: only its own RFQs and orders ----------------------
    if (hasRole(ROLE_VENDOR)) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rfq_vendors rv JOIN rfqs f ON f.id = rv.rfq_id
             WHERE rv.vendor_id = :vendor AND f.status = :open AND f.deadline > NOW()
               AND NOT EXISTS (SELECT 1 FROM quotations q WHERE q.rfq_id = f.id AND q.vendor_id = rv.vendor_id)'
        );
        $stmt->execute(['vendor' => currentVendorId(), 'open' => RFQ_OPEN]);
        $links[] = ['label' => 'My RFQs', 'path' => '/vendor/rfqs.php', 'icon' => '⇄', 'count' => (int) $stmt->fetchColumn()];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE vendor_id = :vendor AND status = :created');
        $stmt->execute(['vendor' => currentVendorId(), 'created' => PO_CREATED]);
        $links[] = ['label' => 'My orders', 'path' => '/vendor/orders.php', 'icon' => '▦', 'count' => (int) $stmt->fetchColumn()];
    }

    if (hasRole(...REQUESTER_ROLES)) {
        $links[] = ['label' => 'My requests', 'path' => '/requests/index.php', 'icon' => '▤'];
        $links[] = ['label' => 'New request', 'path' => '/requests/create.php', 'icon' => '+'];
    }

    if (hasRole(ROLE_MANAGER)) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM purchase_requests
             WHERE status = :status AND department_id = :dept AND requester_id <> :me'
        );
        $stmt->execute(['status' => REQ_SUBMITTED, 'dept' => currentDepartmentId(), 'me' => currentUserId()]);
        $links[] = ['label' => 'Approvals', 'path' => '/approvals/index.php', 'icon' => '✓', 'count' => (int) $stmt->fetchColumn()];
    }

    if (hasRole(ROLE_ADMIN)) {
        $links[] = ['label' => 'All requests', 'path' => '/requests/index.php', 'icon' => '▤'];
        $links[] = ['label' => 'Escalated approvals', 'path' => '/approvals/index.php', 'icon' => '✓', 'count' => count(escalatedRequests())];
    }

    if (hasRole(ROLE_PROCUREMENT)) {
        $links[] = ['label' => 'RFQs', 'path' => '/rfqs/index.php', 'icon' => '⇄'];
    }

    if (hasRole(ROLE_PROCUREMENT, ROLE_ADMIN)) {
        $links[] = ['label' => 'Purchase orders', 'path' => '/orders/index.php', 'icon' => '▦'];
        $links[] = ['label' => 'Vendors', 'path' => '/vendors/index.php', 'icon' => '◎'];
    }

    if (hasRole(ROLE_ADMIN)) {
        $pending = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0 AND approved_at IS NULL')->fetchColumn();
        $links[] = ['section' => 'Administration'];
        $links[] = ['label' => 'Users', 'path' => '/admin/users.php', 'icon' => '◉', 'count' => $pending];
        $links[] = ['label' => 'Departments', 'path' => '/admin/departments.php', 'icon' => '▣'];
        $links[] = ['label' => 'Budgets', 'path' => '/admin/budgets.php', 'icon' => '◔'];
        $links[] = ['label' => 'Scoring settings', 'path' => '/admin/settings.php', 'icon' => '⚖'];
        $links[] = ['label' => 'Audit log', 'path' => '/admin/audit_logs.php', 'icon' => '☰'];
    }

    $links[] = ['section' => 'Account'];
    $links[] = ['label' => 'Notifications', 'path' => '/notifications.php', 'icon' => '◆', 'count' => unreadNotificationCount(currentUserId())];
    $links[] = ['label' => 'My profile', 'path' => '/profile.php', 'icon' => '◌'];

    return $links;
}
