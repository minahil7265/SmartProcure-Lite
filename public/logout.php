<?php
// /var/www/smartprocure/public/logout.php
//
// Logout is a POST form (with CSRF token) in the top bar, so another
// website cannot log the user out with a simple link or image.
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(isLoggedIn() ? '/dashboard.php' : '/login.php');
}

verifyCsrf();

if (isLoggedIn()) {
    auditLog(currentUserId(), 'logout', 'user', currentUserId());
}

// 1. empty $_SESSION  2. expire the session cookie  3. destroy server-side data
destroySession();

redirect('/login.php', ['logged_out' => 1]);
