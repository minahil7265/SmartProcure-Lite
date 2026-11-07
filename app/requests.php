<?php
// /var/www/smartprocure/app/requests.php
//
// Purchase request helpers: loading, access checks, validation and saving.

/**
 * Load one request with requester and department names.
 */
function findRequest(int $id, bool $forUpdate = false): ?array
{
    global $pdo;

    $sql = 'SELECT r.*, u.full_name AS requester_name, d.department_name
            FROM purchase_requests r
            JOIN users u ON u.id = r.requester_id
            JOIN departments d ON d.id = r.department_id
            WHERE r.id = :id';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $request = $stmt->fetch();

    return $request ?: null;
}

/**
 * Items of a request.
 */
function requestItems(int $requestId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id, item_name, description, quantity, estimated_unit_price
         FROM purchase_request_items
         WHERE request_id = :id
         ORDER BY id'
    );
    $stmt->execute(['id' => $requestId]);
    return $stmt->fetchAll();
}

/**
 * Approval / rejection history of a request.
 */
function approvalHistory(int $requestId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT a.decision, a.comments, a.decision_date, u.full_name AS approver_name
         FROM approvals a
         JOIN users u ON u.id = a.approver_id
         WHERE a.request_id = :id
         ORDER BY a.decision_date DESC, a.id DESC'
    );
    $stmt->execute(['id' => $requestId]);
    return $stmt->fetchAll();
}

/**
 * Who may open a request?
 *  - admin: every request
 *  - the requester: their own requests
 *  - manager: submitted/decided requests of their own department
 *  - procurement officer: approved requests (to collect quotations)
 */
function canViewRequest(array $request): bool
{
    if (hasRole(ROLE_ADMIN)) {
        return true;
    }
    if ((int) $request['requester_id'] === currentUserId()) {
        return true;
    }
    if (hasRole(ROLE_MANAGER)
        && (int) $request['department_id'] === currentDepartmentId()
        && $request['status'] !== REQ_DRAFT) {
        return true;
    }
    if (hasRole(ROLE_PROCUREMENT) && $request['status'] === REQ_APPROVED) {
        return true;
    }
    return false;
}

/**
 * Only the requester may edit/cancel, and only before a decision.
 */
function canEditRequest(array $request): bool
{
    return (int) $request['requester_id'] === currentUserId()
        && in_array($request['status'], REQUEST_EDITABLE, true);
}

/**
 * Validate the request form. Returns [$data, $items, $errors].
 * Items arrive as parallel arrays: item_name[], description[], quantity[], unit_price[].
 */
function validateRequestForm(array $post): array
{
    $errors = [];

    $data = [
        'purpose'       => trim($post['purpose'] ?? ''),
        'required_date' => trim($post['required_date'] ?? ''),
    ];

    if ($data['purpose'] === '') {
        $errors[] = 'Purpose is required.';
    } elseif (mb_strlen($data['purpose']) > 500) {
        $errors[] = 'Purpose must be 500 characters or fewer.';
    }

    if ($data['required_date'] !== '') {
        if (!isValidDate($data['required_date'])) {
            $errors[] = 'Required date is not a valid date.';
        } elseif ($data['required_date'] < date('Y-m-d')) {
            $errors[] = 'Required date cannot be in the past.';
        }
    }

    $names        = (array) ($post['item_name'] ?? []);
    $descriptions = (array) ($post['description'] ?? []);
    $quantities   = (array) ($post['quantity'] ?? []);
    $prices       = (array) ($post['unit_price'] ?? []);

    $items = [];

    foreach ($names as $i => $name) {
        $name        = trim((string) $name);
        $description = trim((string) ($descriptions[$i] ?? ''));
        $quantity    = trim((string) ($quantities[$i] ?? ''));
        $price       = trim((string) ($prices[$i] ?? ''));

        // Skip rows that were left completely empty.
        if ($name === '' && $description === '' && $quantity === '' && $price === '') {
            continue;
        }

        $row = $i + 1;

        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = "Item {$row}: name is required (max 150 characters).";
        }
        if (filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1) {
            $errors[] = "Item {$row}: quantity must be a whole number of at least 1.";
        }
        if (!is_numeric($price) || (float) $price < 0) {
            $errors[] = "Item {$row}: estimated unit price must be 0 or more.";
        }

        $items[] = [
            'item_name'            => $name,
            'description'          => mb_substr($description, 0, 255),
            'quantity'             => (int) $quantity,
            'estimated_unit_price' => round((float) $price, 2),
        ];
    }

    if (!$items) {
        $errors[] = 'Add at least one item.';
    }

    return [$data, $items, $errors];
}

/**
 * Estimated total = sum of quantity x estimated unit price.
 */
function calculateEstimatedTotal(array $items): float
{
    $total = 0.0;
    foreach ($items as $item) {
        $total += $item['quantity'] * $item['estimated_unit_price'];
    }
    return round($total, 2);
}

/**
 * Insert the items of a request (used by create and edit).
 */
function insertRequestItems(int $requestId, array $items): void
{
    global $pdo;

    $stmt = $pdo->prepare(
        'INSERT INTO purchase_request_items
            (request_id, item_name, description, quantity, estimated_unit_price)
         VALUES
            (:request_id, :item_name, :description, :quantity, :price)'
    );

    foreach ($items as $item) {
        $stmt->execute([
            'request_id'  => $requestId,
            'item_name'   => $item['item_name'],
            'description' => $item['description'] !== '' ? $item['description'] : null,
            'quantity'    => $item['quantity'],
            'price'       => $item['estimated_unit_price'],
        ]);
    }
}

/**
 * After a request is submitted: tell the department's other managers.
 * (If there is none, the request is escalated to the admin after
 * 'escalation_days' - see app/approvals.php.)
 */
function notifyRequestSubmitted(int $requestId, int $departmentId, int $requesterId): void
{
    $managers = array_diff(activeUserIds(ROLE_MANAGER, $departmentId), [$requesterId]);
    notifyUsers($managers, 'Request PR-' . $requestId . ' is waiting for your approval.', '/approvals/review.php?id=' . $requestId);
}
