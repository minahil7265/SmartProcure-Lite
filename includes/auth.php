<?php
// /var/www/smartprocure/includes/auth.php
//
// Authentication (who are you?) and authorization (what may you do?)
// helpers, following the RBAC lesson.

/**
 * Return true if a user is logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Require a logged-in, active user.
 *
 * The account is re-read from the database on every request, so if the
 * admin deactivates a user or changes their role, it takes effect on the
 * user's very next click (not only after they log in again).
 */
function requireLogin(): void
{
    global $pdo;

    if (!isLoggedIn()) {
        // Keep a message that is already waiting (e.g. "session expired").
        if (empty($_SESSION['flash'])) {
            flash('warning', 'Please log in to continue.');
        }
        redirect('/login.php');
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.full_name, u.email, u.role, u.department_id, u.vendor_id, u.is_active,
                d.department_name, v.vendor_name, v.status AS vendor_status
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN vendors v ON v.id = u.vendor_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // A vendor login also stops working when its vendor company is
    // inactive or the account is not linked to a company.
    $vendorBlocked = $user && $user['role'] === ROLE_VENDOR
        && ($user['vendor_id'] === null || $user['vendor_status'] !== VENDOR_ACTIVE);

    if (!$user || (int) $user['is_active'] !== 1 || $vendorBlocked) {
        destroySession();
        session_start();
        flash('error', 'Your account is no longer active.');
        redirect('/login.php');
    }

    // Keep the session in sync with the database.
    $_SESSION['name']            = $user['full_name'];
    $_SESSION['email']           = $user['email'];
    $_SESSION['role']            = $user['role'];
    $_SESSION['department_id']   = $user['department_id'] !== null ? (int) $user['department_id'] : null;
    $_SESSION['department_name'] = $user['department_name'];
    $_SESSION['vendor_id']       = $user['vendor_id'] !== null ? (int) $user['vendor_id'] : null;
    $_SESSION['vendor_name']     = $user['vendor_name'];
}

/**
 * Require one of the given roles, e.g. requireRole(ROLE_ADMIN)
 * or requireRole(ROLE_MANAGER, ROLE_ADMIN).
 * A logged-in user with a different role gets 403 Forbidden.
 */
function requireRole(string ...$allowedRoles): void
{
    requireLogin();

    if (!in_array(currentRole(), $allowedRoles, true)) {
        abortPage(403, 'You do not have permission to open this page.');
    }
}

/**
 * Does the current user have one of these roles?
 */
function hasRole(string ...$roles): bool
{
    return in_array(currentRole(), $roles, true);
}

function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function currentRole(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

function currentDepartmentId(): ?int
{
    return $_SESSION['department_id'] ?? null;
}

/**
 * The vendor company of the logged-in vendor user.
 * Always taken from the session (filled from the database by
 * requireLogin), never from the URL or a form.
 */
function currentVendorId(): int
{
    return (int) ($_SESSION['vendor_id'] ?? 0);
}

/**
 * Require a logged-in vendor user linked to an active vendor company.
 */
function requireVendor(): void
{
    requireRole(ROLE_VENDOR);

    if (currentVendorId() < 1) {
        abortPage(403, 'Your account is not linked to a vendor company.');
    }
}

function roleLabel(string $role): string
{
    return ROLE_LABELS[$role] ?? ucfirst($role);
}
