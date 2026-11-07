<?php
// /var/www/smartprocure/public/requests/create.php
// CREATE: a new purchase request with several items.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(...REQUESTER_ROLES);

if (!currentDepartmentId()) {
    abortPage(403, 'You are not linked to a department. Ask the administrator to set your department first.');
}

$errors = [];
$data = ['purpose' => '', 'required_date' => ''];
$items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    [$data, $items, $errors] = validateRequestForm($_POST);
    $status = ($_POST['action'] ?? '') === 'submit' ? REQ_SUBMITTED : REQ_DRAFT;

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_requests
                    (requester_id, department_id, request_date, required_date, status, purpose, estimated_total, submitted_at)
                 VALUES
                    (:requester_id, :department_id, :request_date, :required_date, :status, :purpose, :total,
                     IF(:status2 = :submitted, NOW(), NULL))'
            );
            $stmt->execute([
                'requester_id'  => currentUserId(),
                'department_id' => currentDepartmentId(),
                'request_date'  => date('Y-m-d'),
                'required_date' => $data['required_date'] !== '' ? $data['required_date'] : null,
                'status'        => $status,
                'purpose'       => $data['purpose'],
                'total'         => calculateEstimatedTotal($items),
                'status2'       => $status,
                'submitted'     => REQ_SUBMITTED,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            insertRequestItems($requestId, $items);

            auditLog(currentUserId(), $status === REQ_SUBMITTED ? 'request_submitted' : 'request_created', 'purchase_request', $requestId, [
                'items' => count($items),
                'total' => calculateEstimatedTotal($items),
            ]);

            if ($status === REQ_SUBMITTED) {
                notifyRequestSubmitted($requestId, (int) currentDepartmentId(), currentUserId());
            }

            $pdo->commit();

            flash('success', $status === REQ_SUBMITTED ? 'Request submitted for approval.' : 'Request saved as draft.');
            redirect('/requests/view.php', ['id' => $requestId]);
        } catch (PDOException $exception) {
            $pdo->rollBack();
            error_log('Request create failed: ' . $exception->getMessage());
            $errors[] = 'The request could not be saved. Please try again.';
        }
    }
}

$pageTitle   = 'New purchase request';
$breadcrumbs = [['label' => 'Requests', 'url' => url('/requests/index.php')], ['label' => 'New request']];
require APP_ROOT . '/includes/header.php';

if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif;

$formAction      = url('/requests/create.php');
$showDraftButton = true;
$submitLabel     = 'Submit for approval';
$cancelUrl       = url('/requests/index.php');
require APP_ROOT . '/includes/partials/request_form.php';

require APP_ROOT . '/includes/footer.php';
