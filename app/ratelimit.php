<?php
// /var/www/smartprocure/app/ratelimit.php
//
// Login rate limit: after too many failed logins for one email, or from
// one IP address, within the time window, the login form refuses to
// check passwords until the window has passed. Limits come from config.php
// (the IP limit is higher because a whole lab may share one IP).

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Minutes left before this email/IP may try again (0 = allowed now).
 */
function loginLockMinutes(string $email): int
{
    global $pdo;

    $window = (int) config('rate_limit.window_minutes');
    $limits = [
        'email'      => [mb_strtolower($email), (int) config('rate_limit.max_per_email')],
        'ip_address' => [clientIp(), (int) config('rate_limit.max_per_ip')],
    ];

    $minutes = 0;
    foreach ($limits as $column => [$value, $max]) {
        // $column is one of the two fixed names above, never user input.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest
             FROM login_attempts
             WHERE {$column} = :value AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)"
        );
        $stmt->execute(['value' => $value, 'window' => $window]);
        $row = $stmt->fetch();

        if ((int) $row['failures'] >= $max) {
            // Locked until the oldest counted failure leaves the window.
            $unlockAt = strtotime($row['oldest']) + $window * 60;
            $minutes = max($minutes, (int) ceil(($unlockAt - time()) / 60), 1);
        }
    }

    return $minutes;
}

function recordFailedLogin(string $email): void
{
    global $pdo;

    $stmt = $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)');
    $stmt->execute(['email' => mb_strtolower($email), 'ip' => clientIp()]);
}

/**
 * After a successful login, forget this email's failures and
 * clean up rows that are older than the window.
 */
function clearLoginAttempts(string $email): void
{
    global $pdo;

    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
    $stmt->execute(['email' => mb_strtolower($email)]);

    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :window MINUTE)');
    $stmt->execute(['window' => (int) config('rate_limit.window_minutes')]);
}
