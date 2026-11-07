<?php
// /var/www/smartprocure/scripts/create_admin.php
//
// Creates the FIRST administrator from the terminal, so no admin
// password is ever written into the SQL files or the code.
//
// Run:  php scripts/create_admin.php

if (PHP_SAPI !== 'cli') {
    exit('Run this script from the terminal.');
}

$config = require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);
require dirname(__DIR__) . '/includes/constants.php';
require dirname(__DIR__) . '/config/database.php';

function ask(string $question): string
{
    echo $question;
    return trim((string) fgets(STDIN));
}

function askHidden(string $question): string
{
    echo $question;
    // Hide typing on Linux terminals.
    system('stty -echo 2>/dev/null');
    $value = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo PHP_EOL;
    return $value;
}

echo "Create a SmartProcure Lite administrator" . PHP_EOL . PHP_EOL;

$name  = ask('Full name: ');
$email = ask('Email: ');
$password = askHidden('Password: ');
$confirm  = askHidden('Confirm password: ');

$minLength = (int) $config['password_min_length'];

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit('A name and a valid email are required.' . PHP_EOL);
}
if (strlen($password) < $minLength) {
    exit("Password must be at least {$minLength} characters." . PHP_EOL);
}
if ($password !== $confirm) {
    exit('Passwords do not match.' . PHP_EOL);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    exit('That email is already registered.' . PHP_EOL);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, email, password_hash, role, department_id, is_active, approved_at)
     VALUES (:name, :email, :hash, :role, NULL, 1, NOW())'
);
$stmt->execute([
    'name'  => $name,
    'email' => $email,
    'hash'  => password_hash($password, PASSWORD_DEFAULT),
    'role'  => ROLE_ADMIN,
]);
$id = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare(
    'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
     VALUES (:id, :action, :type, :id2, :details)'
);
$stmt->execute(['id' => $id, 'action' => 'admin_created_cli', 'type' => 'user', 'id2' => $id, 'details' => 'Created with scripts/create_admin.php']);

echo "Administrator {$email} created. You can now log in." . PHP_EOL;
