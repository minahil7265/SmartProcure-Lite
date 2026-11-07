<?php
// /var/www/smartprocure/public/vendors/create.php
// CREATE: add a vendor (procurement officer only).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$errors = [];
$vendor = ['vendor_name' => '', 'contact_person' => '', 'email' => '', 'phone' => '', 'address' => '', 'status' => VENDOR_ACTIVE];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    [$vendor, $errors] = validateVendorForm($_POST, false);

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO vendors (vendor_name, contact_person, email, phone, address, status)
             VALUES (:vendor_name, :contact_person, :email, :phone, :address, :status)'
        );
        $stmt->execute(vendorParams($vendor));
        $vendorId = (int) $pdo->lastInsertId();

        auditLog(currentUserId(), 'vendor_created', 'vendor', $vendorId, ['name' => $vendor['vendor_name']]);

        flash('success', 'Vendor added.');
        redirect('/vendors/view.php', ['id' => $vendorId]);
    }
}

$pageTitle   = 'Add vendor';
$breadcrumbs = [['label' => 'Vendors', 'url' => url('/vendors/index.php')], ['label' => 'Add vendor']];
require APP_ROOT . '/includes/header.php';

if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif;

$formAction = url('/vendors/create.php');
$showStatus = false;
$cancelUrl  = url('/vendors/index.php');
require APP_ROOT . '/includes/partials/vendor_form.php';

require APP_ROOT . '/includes/footer.php';
