<?php
// /var/www/smartprocure/includes/bootstrap.php
//
// Every page in public/ starts with ONE line:
//     require_once __DIR__ . '/../includes/bootstrap.php';          (public/*.php)
//     require_once dirname(__DIR__, 2) . '/includes/bootstrap.php'; (public/folder/*.php)
// This file loads the configuration, database, session and helpers
// in the right order.

define('APP_ROOT', dirname(__DIR__));

// ---- 1. Configuration --------------------------------------------
$configFile = APP_ROOT . '/config/config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config/config.php. Copy config/config.example.php to config/config.php and edit it.');
}

$config = require $configFile;

date_default_timezone_set($config['app']['timezone']);

// Show errors only while developing.
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
error_reporting(E_ALL);

// ---- 2. Shared code ----------------------------------------------
require_once APP_ROOT . '/includes/constants.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/config/database.php';   // creates $pdo
require_once APP_ROOT . '/includes/session.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/navigation.php';

// ---- 3. Business logic -------------------------------------------
require_once APP_ROOT . '/app/audit.php';
require_once APP_ROOT . '/app/settings.php';
require_once APP_ROOT . '/app/ratelimit.php';
require_once APP_ROOT . '/app/notifications.php';
require_once APP_ROOT . '/app/budget.php';
require_once APP_ROOT . '/app/vendors.php';
require_once APP_ROOT . '/app/requests.php';
require_once APP_ROOT . '/app/approvals.php';
require_once APP_ROOT . '/app/reliability.php';
require_once APP_ROOT . '/app/rfqs.php';
require_once APP_ROOT . '/app/scoring.php';
require_once APP_ROOT . '/app/orders.php';
require_once APP_ROOT . '/app/deliveries.php';

// ---- 4. Safety net for unexpected errors -------------------------
// Any error a page did not handle itself: undo an open transaction,
// write the details to the Apache error log and show a friendly page.
set_exception_handler(function (Throwable $exception) {
    global $pdo;

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Unhandled error: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

    if (config('app.debug')) {
        http_response_code(500);
        echo '<pre>' . e((string) $exception) . '</pre>';
        return;
    }
    abortPage(500, 'Something went wrong and nothing was saved. Please try again; if it keeps happening, tell the administrator.');
});

// ---- 5. Security headers -----------------------------------------
// Pages contain private data, so the browser must not cache them.
// This also stops the Back button from showing a page after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
