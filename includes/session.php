<?php
// /var/www/smartprocure/includes/session.php
//
// Starts the session with safe cookie settings.
// Must run before any HTML is sent to the browser.

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionConfig = $config['session'];

    // Reject session IDs that PHP did not create itself.
    ini_set('session.use_strict_mode', '1');
    // Only ever keep the session ID in a cookie, never in the URL.
    ini_set('session.use_only_cookies', '1');

    session_name($sessionConfig['name']);

    session_set_cookie_params([
        'lifetime' => 0,                          // cookie ends when the browser closes
        'path'     => '/',
        'secure'   => $sessionConfig['secure'],   // true only on HTTPS
        'httponly' => true,                       // JavaScript cannot read the cookie
        'samesite' => $sessionConfig['samesite'], // limits cross-site sending
    ]);

    session_start();
}

/**
 * Log the user out when they have been idle for too long.
 */
if (isset($_SESSION['user_id'])) {
    $idleLimit = (int) $config['session']['idle_timeout'];
    $lastSeen  = $_SESSION['last_activity'] ?? time();

    if (time() - $lastSeen > $idleLimit) {
        destroySession();
        session_start();
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Your session expired. Please log in again.'];
    } else {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Completely end the current session:
 * empty $_SESSION, expire the session cookie, destroy server data.
 */
function destroySession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    session_destroy();
}
