<?php
// /var/www/smartprocure/app/vendors.php
//
// Vendor helpers. Vendors are never deleted: they are set to Inactive
// so old quotations and orders keep pointing to a real vendor.

function findVendor(int $id): ?array
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM vendors WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $vendor = $stmt->fetch();

    return $vendor ?: null;
}

/**
 * Validate the vendor form. Returns [$vendor, $errors].
 */
function validateVendorForm(array $post, bool $withStatus): array
{
    $vendor = [
        'vendor_name'    => trim($post['vendor_name'] ?? ''),
        'contact_person' => trim($post['contact_person'] ?? ''),
        'email'          => trim($post['email'] ?? ''),
        'phone'          => trim($post['phone'] ?? ''),
        'address'        => trim($post['address'] ?? ''),
        'status'         => $withStatus ? ($post['status'] ?? '') : VENDOR_ACTIVE,
    ];

    $errors = [];

    if ($vendor['vendor_name'] === '' || mb_strlen($vendor['vendor_name']) > 150) {
        $errors[] = 'Vendor name is required (max 150 characters).';
    }
    if ($vendor['email'] !== '' && !filter_var($vendor['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vendor email is not valid.';
    }
    if ($vendor['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{5,30}$/', $vendor['phone'])) {
        $errors[] = 'Phone may contain only digits, spaces, +, - and brackets.';
    }
    if (mb_strlen($vendor['contact_person']) > 100 || mb_strlen($vendor['address']) > 255) {
        $errors[] = 'Contact person or address is too long.';
    }
    if (!in_array($vendor['status'], VENDOR_STATUSES, true)) {
        $errors[] = 'Choose a valid status.';
    }

    return [$vendor, $errors];
}

/**
 * Empty strings become NULL in the database.
 */
function vendorParams(array $vendor): array
{
    return [
        'vendor_name'    => $vendor['vendor_name'],
        'contact_person' => $vendor['contact_person'] !== '' ? $vendor['contact_person'] : null,
        'email'          => $vendor['email'] !== '' ? $vendor['email'] : null,
        'phone'          => $vendor['phone'] !== '' ? $vendor['phone'] : null,
        'address'        => $vendor['address'] !== '' ? $vendor['address'] : null,
        'status'         => $vendor['status'],
    ];
}
