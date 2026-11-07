<?php
// /var/www/smartprocure/config/config.example.php
//
// Copy this file to config.php and put your own values in it:
//     cp config/config.example.php config/config.php
//
// config.php is listed in .gitignore, so your real password is never
// pushed to GitHub. Every setting the application needs lives here,
// nothing is hard-coded in the pages.

return [

    // ---------------------------------------------------------------
    // Database (must match database/create_app_user.sql)
    // ---------------------------------------------------------------
    'db' => [
        'host'     => 'localhost',
        'name'     => 'smartprocure_db',
        'user'     => 'procure_user',
        'password' => 'CHANGE_THIS_PASSWORD',
        'charset'  => 'utf8mb4',
    ],

    // ---------------------------------------------------------------
    // Application
    // ---------------------------------------------------------------
    'app' => [
        'name'     => 'SmartProcure Lite',
        'tagline'  => 'Purchasing & Vendor Evaluation',
        // Leave empty when the site runs at the root of a virtual host
        // (http://smartprocure.local). Use '/smartprocure' if you open it
        // as http://localhost/smartprocure instead.
        'base_url' => '',
        'timezone' => 'Asia/Karachi',
        'currency' => 'Rs.',
        // Show detailed PHP errors. Keep false outside your own machine.
        'debug'    => false,
    ],

    // ---------------------------------------------------------------
    // Session and cookie settings
    // ---------------------------------------------------------------
    'session' => [
        'name'         => 'SPLSESSID',
        // Log the user out after this many seconds without activity.
        'idle_timeout' => 1800,
        // Set to true when the site is served over HTTPS.
        'secure'       => false,
        'samesite'     => 'Lax',
    ],

    // ---------------------------------------------------------------
    // Budget periods
    // A budget period is the request date formatted with this PHP date
    // format: 'Y' = yearly (2026), 'Y-m' = monthly (2026-11).
    // ---------------------------------------------------------------
    'budget_period_format' => 'Y',

    // ---------------------------------------------------------------
    // Vendor evaluation (scoring)
    // Each quotation is scored ON ITS OWN, so adding or removing another
    // quotation never changes it:
    //   price       = estimated total / quoted total x 100 (max 100)
    //   delivery    = days available  / delivery days x 100 (max 100)
    //                 days available = required date - quotation date
    //   reliability = reliability rating / 5 x 100
    // These weights are only the DEFAULTS. The admin changes them on the
    // Settings page (they must add up to 100).
    // ---------------------------------------------------------------
    'scoring' => [
        'weight_price'       => 50,
        'weight_delivery'    => 30,
        'weight_reliability' => 20,
        // Used when a request has no "needed by" date.
        'fallback_delivery_days' => 14,
        // Saved in the audit log with every vendor selection.
        'formula_version'    => 'v2-independent',
    ],

    // ---------------------------------------------------------------
    // Delivery reliability rating (1 to 5) from order history.
    // On time = the delivery that completed the order arrived on or
    // before the expected delivery date.
    // The database checks ratings are 1 to 5, so keep that range.
    // ---------------------------------------------------------------
    'reliability' => [
        'min_orders'     => 3,   // fewer completed orders than this ...
        'default_rating' => 3,   // ... gives this rating
        // Minimum on-time percentage for each rating (anything lower = 1).
        'thresholds'     => [5 => 95, 4 => 85, 3 => 70, 2 => 50],
    ],

    // ---------------------------------------------------------------
    // RFQs (Request for Quotation)
    // ---------------------------------------------------------------
    'rfq' => [
        'min_vendors'           => 2,  // vendors to invite at least
        'default_deadline_days' => 3,  // suggested deadline on the form
    ],

    // ---------------------------------------------------------------
    // Approval escalation
    // If a submitted request has no other active manager in its
    // department (e.g. the only manager raised it), the admin may decide
    // after this many days.
    // ---------------------------------------------------------------
    'escalation_days' => 3,

    // ---------------------------------------------------------------
    // Login rate limit
    // Within the window, logins are refused after too many failures:
    //  - per email: protects one account from password guessing
    //  - per IP:    stops one computer trying many accounts. Higher,
    //               because a whole lab often shares one IP address.
    // ---------------------------------------------------------------
    'rate_limit' => [
        'max_per_email'  => 5,
        'max_per_ip'     => 20,
        'window_minutes' => 15,
    ],

    // Minimum password length for signup and password change.
    'password_min_length' => 8,
];
