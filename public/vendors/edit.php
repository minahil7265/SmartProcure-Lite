<?php
// /var/www/smartprocure/public/vendors/edit.php
// UPDATE: edit vendor details or activate/deactivate (never delete).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$id = requireIdParam();
$vendor = findVendor($id);

if (!$vendor) {
    abortPage(404, 'Vendor not found.');
}

$errors = [];
$oldStatus = $vendor['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    [$vendor, $errors] = validateVendorForm($_POST, true);

    if (!$errors) {
        $params = vendorParams($vendor);
        $params['id'] = $id;

        $stmt = $pdo->prepare(
            'UPDATE vendors
             SET vendor_name = :vendor_name, contact_person = :contact_person, email = :email,
                 phone = :phone, address = :address, status = :status
             WHERE id = :id'
        );
        $stmt->execute($params);

        auditLog(currentUserId(), 'vendor_updated', 'vendor', $id, [
            'name'   => $vendor['vendor_name'],
            'status' => $oldStatus !== $vendor['status'] ? "{$oldStatus} -> {$vendor['status']}" : $vendor['status'],
        ]);

        flash('success', 'Vendor updated.');
        redirect('/vendors/view.php', ['id' => $id]);
    }
}

$pageTitle   = 'Edit vendor';
$breadcrumbs = [
    ['label' => 'Vendors', 'url' => url('/vendors/index.php')],
    ['label' => $vendor['vendor_name'], 'url' => url('/vendors/view.php', ['id' => $id])],
    ['label' => 'Edit'],
];
require APP_ROOT . '/includes/header.php';

if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif;

$formAction = url('/vendors/edit.php', ['id' => $id]);
$showStatus = true;
$cancelUrl  = url('/vendors/view.php', ['id' => $id]);
require APP_ROOT . '/includes/partials/vendor_form.php';

require APP_ROOT . '/includes/footer.php';
