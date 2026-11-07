<?php
// /var/www/smartprocure/public/requests/edit.php
// UPDATE: the requester edits a Draft or Submitted request (before approval).
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(...REQUESTER_ROLES);

$id = requireIdParam();
$request = findRequest($id);

if (!$request) {
    abortPage(404, 'Request not found.');
}
if (!canEditRequest($request)) {
    abortPage(403, 'Only the requester can edit a request, and only before the manager decides.');
}

$errors = [];
$data = ['purpose' => $request['purpose'], 'required_date' => (string) $request['required_date']];
$items = requestItems($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    [$data, $items, $errors] = validateRequestForm($_POST);

    // A draft can be submitted from here; a submitted request stays submitted.
    $newStatus = $request['status'] === REQ_DRAFT && ($_POST['action'] ?? '') === 'submit'
        ? REQ_SUBMITTED
        : $request['status'];

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock and re-check: the manager may have decided in the meantime.
            $stmt = $pdo->prepare('SELECT status FROM purchase_requests WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            if (!in_array($stmt->fetchColumn(), REQUEST_EDITABLE, true)) {
                throw new RuntimeException('This request was already decided and can no longer be edited.');
            }

            $stmt = $pdo->prepare(
                'UPDATE purchase_requests
                 SET purpose = :purpose, required_date = :required_date, status = :status, estimated_total = :total,
                     submitted_at = IF(:newly_submitted = 1, NOW(), submitted_at)
                 WHERE id = :id'
            );
            $stmt->execute([
                'purpose'       => $data['purpose'],
                'required_date' => $data['required_date'] !== '' ? $data['required_date'] : null,
                'status'        => $newStatus,
                'total'         => calculateEstimatedTotal($items),
                'newly_submitted' => $newStatus !== $request['status'] ? 1 : 0,
                'id'            => $id,
            ]);

            // Replace the items.
            $stmt = $pdo->prepare('DELETE FROM purchase_request_items WHERE request_id = :id');
            $stmt->execute(['id' => $id]);
            insertRequestItems($id, $items);

            auditLog(currentUserId(), $newStatus !== $request['status'] ? 'request_submitted' : 'request_updated', 'purchase_request', $id, [
                'items' => count($items),
                'total' => calculateEstimatedTotal($items),
            ]);

            if ($newStatus !== $request['status']) {
                notifyRequestSubmitted($id, (int) $request['department_id'], currentUserId());
            }

            $pdo->commit();

            flash('success', $newStatus !== $request['status'] ? 'Request submitted for approval.' : 'Request updated.');
            redirect('/requests/view.php', ['id' => $id]);
        } catch (RuntimeException $exception) {
            $pdo->rollBack();
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            $pdo->rollBack();
            error_log('Request update failed: ' . $exception->getMessage());
            $errors[] = 'The request could not be saved. Please try again.';
        }
    }
}

$pageTitle   = 'Edit request PR-' . $id;
$breadcrumbs = [
    ['label' => 'Requests', 'url' => url('/requests/index.php')],
    ['label' => 'PR-' . $id, 'url' => url('/requests/view.php', ['id' => $id])],
    ['label' => 'Edit'],
];
require APP_ROOT . '/includes/header.php';

if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif;

$formAction      = url('/requests/edit.php', ['id' => $id]);
$showDraftButton = $request['status'] === REQ_DRAFT;
$submitLabel     = $request['status'] === REQ_DRAFT ? 'Submit for approval' : 'Save changes';
$cancelUrl       = url('/requests/view.php', ['id' => $id]);
require APP_ROOT . '/includes/partials/request_form.php';

require APP_ROOT . '/includes/footer.php';
