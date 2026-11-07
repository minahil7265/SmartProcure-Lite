<?php
// /var/www/smartprocure/app/settings.php
//
// Settings the admin can change in the app (stored in the settings
// table). If a value was never saved, the default from config.php is used.

/**
 * Read one setting, or $default if it was never saved.
 */
function getSetting(string $key, ?string $default = null): ?string
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : $value;
}

/**
 * Save one setting (insert or update).
 */
function saveSetting(string $key, string $value, int $userId): void
{
    global $pdo;

    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_by)
         VALUES (:key, :value, :user)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute(['key' => $key, 'value' => $value, 'user' => $userId]);
}

/**
 * Current scoring weights in percent, e.g. ['price' => 50, 'delivery' => 30, 'reliability' => 20].
 */
function scoringWeights(): array
{
    $defaults = config('scoring');

    return [
        'price'       => (int) getSetting('weight_price', (string) $defaults['weight_price']),
        'delivery'    => (int) getSetting('weight_delivery', (string) $defaults['weight_delivery']),
        'reliability' => (int) getSetting('weight_reliability', (string) $defaults['weight_reliability']),
    ];
}

/**
 * Check weights typed by the admin. Returns a list of errors.
 * Pure function (no database) so it can be unit-tested.
 */
function validateWeights(array $input): array
{
    $errors = [];
    $sum = 0;

    foreach (['price', 'delivery', 'reliability'] as $name) {
        $value = filter_var($input[$name] ?? '', FILTER_VALIDATE_INT);

        if ($value === false || $value < 0 || $value > 100) {
            $errors[] = ucfirst($name) . ' weight must be a whole number from 0 to 100.';
            continue;
        }
        $sum += $value;
    }

    if (!$errors && $sum !== 100) {
        $errors[] = "The three weights must add up to 100 (they add up to {$sum}).";
    }

    return $errors;
}
