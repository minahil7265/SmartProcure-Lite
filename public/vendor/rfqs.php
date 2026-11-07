<?php
// /var/www/smartprocure/public/vendor/rfqs.php
// Vendor: the RFQs this vendor company was invited to (and nothing else).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireVendor();

// The vendor ID comes from the session, never from the URL.
$stmt = $pdo->prepare(
    'SELECT f.id, f.deadline, f.status, f.created_at,
            q.id AS quotation_id, q.submitted_at
     FROM rfq_vendors rv
     JOIN rfqs f ON f.id = rv.rfq_id
     LEFT JOIN quotations q ON q.rfq_id = f.id AND q.vendor_id = rv.vendor_id
     WHERE rv.vendor_id = :vendor
     ORDER BY f.deadline DESC'
);
$stmt->execute(['vendor' => currentVendorId()]);
$rfqs = $stmt->fetchAll();

$pageTitle   = 'My RFQs';
$breadcrumbs = [['label' => 'My RFQs']];
require APP_ROOT . '/includes/header.php';
?>
<section class="panel">
<?php if (!$rfqs): ?>
    <p class="empty">You have not been invited to any RFQ yet.</p>
<?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>RFQ</th><th>Received</th><th>Deadline</th><th>Status</th><th>My quotation</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rfqs as $rfq): ?>
            <?php $open = rfqAcceptsQuotations($rfq); ?>
            <tr>
                <td>RFQ-<?= (int) $rfq['id'] ?></td>
                <td><?= e(showDate($rfq['created_at'])) ?></td>
                <td class="nowrap"><?= e(date('d M Y, H:i', strtotime($rfq['deadline']))) ?></td>
                <td><?= badge(rfqDisplayStatus($rfq)) ?></td>
                <td><?= $rfq['quotation_id'] ? badge('Submitted') : '<span class="muted">Not submitted</span>' ?></td>
                <td>
                    <a class="btn btn-sm <?= $open && !$rfq['quotation_id'] ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(url('/vendor/rfq.php', ['id' => $rfq['id']])) ?>">
                        <?= $open ? ($rfq['quotation_id'] ? 'View / edit' : 'Submit quotation') : 'View' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/includes/footer.php'; ?>
