<?php
// /var/www/smartprocure/scripts/db_check.php
//
// Checks the database connection two ways, PDO (used by the app)
// and MySQLi (shown in the course guides), using config/config.php.
// It is a terminal script and is NOT inside public/, so nobody can
// open it from a browser.
//
// Run:  php scripts/db_check.php

if (PHP_SAPI !== 'cli') {
    exit('Run this script from the terminal.');
}

$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/constants.php';
$db = $config['db'];

echo "Checking database '{$db['name']}' as '{$db['user']}'@'{$db['host']}'" . PHP_EOL . PHP_EOL;

// ---- 1. PDO ----------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo '[OK]   PDO connected. Tables found: ' . count($tables) . ' (expected 19)' . PHP_EOL;
} catch (PDOException $exception) {
    echo '[FAIL] PDO: ' . $exception->getMessage() . PHP_EOL;
}

// ---- 2. MySQLi -------------------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($db['host'], $db['user'], $db['password'], $db['name']);
    $conn->set_charset($db['charset']);

    $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
    $role = ROLE_ADMIN;
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $stmt->bind_result($admins);
    $stmt->fetch();
    $stmt->close();

    echo "[OK]   MySQLi connected. Administrators: {$admins}" . PHP_EOL;
    $conn->close();
} catch (mysqli_sql_exception $exception) {
    echo '[FAIL] MySQLi: ' . $exception->getMessage() . PHP_EOL;
}

// ---- 3. Audit log protection -----------------------------------------
if (isset($pdo)) {
    try {
        $pdo->exec('DELETE FROM audit_logs WHERE 1 = 0');
        echo '[WARN] The app user CAN delete audit logs. Run database/create_app_user.sql again.' . PHP_EOL;
    } catch (PDOException $exception) {
        echo '[OK]   The app user cannot delete audit logs (as required).' . PHP_EOL;
    }
}
