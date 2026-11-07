<?php
// /var/www/smartprocure/app/approvals.php
//
// Who may decide on a submitted request?
//  - a manager of the same department who did not raise it, or
//  - the admin, but only when the request is ESCALATED: no other active
//    manager exists in that department (e.g. the only manager raised it)
//    and it has waited at least 'escalation_days' days.

/**
 * Number of active managers in the department other than the requester.
 */
function otherManagerCount(int $departmentId, int $requesterId): int
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM users
         WHERE role = :role AND is_active = 1 AND department_id = :dept AND id <> :requester'
    );
    $stmt->execute(['role' => ROLE_MANAGER, 'dept' => $departmentId, 'requester' => $requesterId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Is this request waiting for the admin?
 * Pure rule, given the facts, so it can be unit-tested.
 */
function isEscalationDue(string $status, ?string $submittedAt, int $otherManagers, int $escalationDays, int $now): bool
{
    if ($status !== REQ_SUBMITTED || $submittedAt === null || $otherManagers > 0) {
        return false;
    }
    return strtotime($submittedAt) <= $now - $escalationDays * 86400;
}

function requestIsEscalated(array $request): bool
{
    return isEscalationDue(
        $request['status'],
        $request['submitted_at'],
        otherManagerCount((int) $request['department_id'], (int) $request['requester_id']),
        (int) config('escalation_days'),
        time()
    );
}

/**
 * Can the current user approve/reject this request?
 * Returns [true, ''] or [false, 'reason'].
 */
function canDecideRequest(array $request): array
{
    if ((int) $request['requester_id'] === currentUserId()) {
        return [false, 'You cannot approve or reject your own request.'];
    }
    if (hasRole(ROLE_MANAGER)) {
        if ((int) $request['department_id'] !== currentDepartmentId()) {
            return [false, 'You can only review requests of your own department.'];
        }
        return [true, ''];
    }
    if (hasRole(ROLE_ADMIN)) {
        if (!requestIsEscalated($request)) {
            return [false, 'The admin can decide only on escalated requests (no other manager in the department after ' . (int) config('escalation_days') . ' days).'];
        }
        return [true, ''];
    }
    return [false, 'You do not have permission to decide on requests.'];
}

/**
 * Submitted requests that are escalated to the admin right now.
 */
function escalatedRequests(): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT r.id, r.purpose, r.request_date, r.submitted_at, r.estimated_total,
                u.full_name AS requester_name, d.department_name
         FROM purchase_requests r
         JOIN users u ON u.id = r.requester_id
         JOIN departments d ON d.id = r.department_id
         WHERE r.status = :status
           AND r.submitted_at <= DATE_SUB(NOW(), INTERVAL :days DAY)
           AND NOT EXISTS (
               SELECT 1 FROM users m
               WHERE m.role = :manager AND m.is_active = 1
                 AND m.department_id = r.department_id AND m.id <> r.requester_id
           )
         ORDER BY r.submitted_at'
    );
    $stmt->execute(['status' => REQ_SUBMITTED, 'days' => (int) config('escalation_days'), 'manager' => ROLE_MANAGER]);
    return $stmt->fetchAll();
}
