<?php
// /var/www/smartprocure/includes/csrf.php
//
// CSRF protection: every form that changes data carries a secret token
// stored in the session. Another website cannot know this token, so it
// cannot make the user's browser submit our forms.

/**
 * Get (or create) the token for this session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden input to place inside every POST form.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * Check the token on every POST request. Stops with 403 Forbidden if it is wrong.
 */
function verifyCsrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';

    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        abortPage(403, 'This form has expired or was not sent from SmartProcure. Go back, reload the page and try again.', 'Form expired');
    }
}

/**
 * Create a brand new token (used right after login).
 */
function regenerateCsrfToken(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
