<?php
// /var/www/smartprocure/includes/helpers.php
//
// Small helper functions used across all pages.

/**
 * Escape text before printing it in HTML (prevents XSS).
 */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Read a setting from config/config.php, e.g. config('app.name').
 */
function config(string $key)
{
    global $config;

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * Build a link inside the application, e.g. url('/requests/index.php').
 * The base URL comes from the config file, so moving the site to
 * another folder only needs one change.
 */
function url(string $path = '/', array $query = []): string
{
    $link = rtrim((string) config('app.base_url'), '/') . '/' . ltrim($path, '/');

    if ($query) {
        $link .= '?' . http_build_query($query);
    }
    return $link;
}

/**
 * Redirect and stop the script.
 */
function redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query));
    exit;
}

/**
 * Store a one-time message to show on the next page.
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Read (and remove) the one-time message.
 */
function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Format an amount of money with the configured currency.
 */
function money($amount): string
{
    return config('app.currency') . ' ' . number_format((float) $amount, 2);
}

/**
 * Format a database date for display.
 */
function showDate(?string $date): string
{
    if (!$date) {
        return '-';
    }
    return date('d M Y', strtotime($date));
}

/**
 * Read a positive integer ID from the query string, or stop with 400.
 */
function requireIdParam(string $name = 'id'): int
{
    $id = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);

    if (!$id || $id < 1) {
        abortPage(400, 'Invalid or missing ID in the address.');
    }
    return $id;
}

/**
 * Check that a string is a real date in Y-m-d format.
 */
function isValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/**
 * CSS class for a status badge.
 */
function statusClass(string $status): string
{
    return 'badge badge-' . strtolower(str_replace(' ', '-', $status));
}

/**
 * Print a status badge.
 */
function badge(string $status): string
{
    return '<span class="' . e(statusClass($status)) . '">' . e($status) . '</span>';
}

/**
 * Show an error page (400, 403, 404, 500) inside the normal layout and stop.
 * Only standard HTTP codes are used, because Apache turns unknown ones into 500.
 */
function abortPage(int $code, string $message, ?string $title = null): void
{
    global $pdo, $config;

    http_response_code($code);

    $titles = [400 => 'Bad request', 403 => 'Access denied', 404 => 'Not found', 500 => 'Server error'];
    $pageTitle = $title ?? ($titles[$code] ?? 'Error');
    $breadcrumbs = [['label' => $pageTitle]];

    require __DIR__ . '/header.php';
    echo '<section class="panel error-panel">';
    echo '<p class="error-code">' . $code . '</p>';
    echo '<h2>' . e($pageTitle) . '</h2>';
    echo '<p>' . e($message) . '</p>';
    echo '<p><a class="btn" href="' . e(url(isLoggedIn() ? '/dashboard.php' : '/login.php')) . '">Go to ' . (isLoggedIn() ? 'dashboard' : 'login') . '</a></p>';
    echo '</section>';
    require __DIR__ . '/footer.php';
    exit;
}
