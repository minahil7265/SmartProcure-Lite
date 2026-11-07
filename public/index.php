<?php
// /var/www/smartprocure/public/index.php
// Home page: send visitors to the right place.
require_once __DIR__ . '/../includes/bootstrap.php';

redirect(isLoggedIn() ? '/dashboard.php' : '/login.php');
