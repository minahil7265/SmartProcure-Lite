<?php
// /var/www/smartprocure/config/database.php
//
// Creates the PDO connection ($pdo) used by every page.
// The connection details come from config/config.php - nothing is
// hard-coded here.

$dbConfig = $config['db'];

$dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions on SQL errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,      // rows come back as arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                 // real prepared statements
];

try {
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $options);

    // Make MySQL's NOW() and CURDATE() use the same timezone as PHP
    // (set from config 'app.timezone'), e.g. '+05:00' for Asia/Karachi.
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
} catch (PDOException $exception) {
    // Write the real reason to the Apache error log, but never show
    // database details (or the password) to the visitor.
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please contact the administrator.');
}

unset($dbConfig);
