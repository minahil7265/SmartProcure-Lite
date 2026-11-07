<?php
// /var/www/smartprocure/app/rfqs.php
//
// RFQs (Request for Quotation): the procurement officer invites chosen
// vendors to quote for an approved request before a deadline.
//
// Rules enforced here on the server:
//  - only invited vendors can see an RFQ or quote for it
//  - quotations can be added or changed only before the deadline
//  - bids are SEALED: until the deadline nobody (not even the officer)
//    sees another vendor's prices; vendors never see other vendors at all

function findRfq(int $id): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT f.*, u.full_name AS created_by_name
         FROM rfqs f JOIN users u ON u.id = f.created_by
         WHERE f.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $rfq = $stmt->fetch();

    return $rfq ?: null;
}

/**
 * The RFQ of a request that is not cancelled (there can be only one).
 */
function activeRfqForRequest(int $requestId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT * FROM rfqs WHERE request_id = :id AND status <> :cancelled ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $requestId, 'cancelled' => RFQ_CANCELLED]);
    $rfq = $stmt->fetch();

    return $rfq ?: null;
}

/**
 * Has the deadline passed? (Compared with the server clock.)
 */
function rfqDeadlinePassed(array $rfq): bool
{
    return strtotime($rfq['deadline']) <= time();
}

/**
 * Open for quotations right now?
 */
function rfqAcceptsQuotations(array $rfq): bool
{
    return $rfq['status'] === RFQ_OPEN && !rfqDeadlinePassed($rfq);
}

/**
 * Status shown on screen: Open, Closed (deadline passed), Awarded, Cancelled.
 */
function rfqDisplayStatus(array $rfq): string
{
    if ($rfq['status'] === RFQ_OPEN && rfqDeadlinePassed($rfq)) {
        return 'Closed';
    }
    return $rfq['status'];
}

/**
 * OWNERSHIP CHECK: was this vendor invited to this RFQ?
 */
function vendorIsInvited(int $rfqId, int $vendorId): bool
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rfq_vendors WHERE rfq_id = :rfq AND vendor_id = :vendor');
    $stmt->execute(['rfq' => $rfqId, 'vendor' => $vendorId]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Invited vendors of an RFQ, with whether each has submitted.
 * Prices are NOT included (safe to show before the deadline).
 */
function rfqInvitations(int $rfqId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT v.id, v.vendor_name, v.status, rv.invited_at,
                q.id AS quotation_id, q.submitted_at
         FROM rfq_vendors rv
         JOIN vendors v ON v.id = rv.vendor_id
         LEFT JOIN quotations q ON q.rfq_id = rv.rfq_id AND q.vendor_id = rv.vendor_id
         WHERE rv.rfq_id = :id
         ORDER BY v.vendor_name'
    );
    $stmt->execute(['id' => $rfqId]);
    return $stmt->fetchAll();
}

/**
 * This vendor's own quotation for an RFQ (or null), with its items.
 */
function vendorQuotation(int $rfqId, int $vendorId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM quotations WHERE rfq_id = :rfq AND vendor_id = :vendor');
    $stmt->execute(['rfq' => $rfqId, 'vendor' => $vendorId]);
    $quote = $stmt->fetch();

    if (!$quote) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT request_item_id, quoted_quantity, unit_price FROM quotation_items WHERE quotation_id = :id');
    $stmt->execute(['id' => $quote['id']]);
    $quote['items'] = [];
    foreach ($stmt->fetchAll() as $item) {
        $quote['items'][$item['request_item_id']] = $item;
    }
    $quote['total'] = quotationTotal($quote['items']);

    return $quote;
}

/**
 * Save (insert or replace) a vendor's quotation in one transaction.
 * The RFQ row is locked and the deadline checked again on the server,
 * so a form opened before the deadline cannot be saved after it.
 *
 * $data: delivery_days, validity_date, warranty_months
 * $rows: [['request_item_id' => .., 'qty' => .., 'price' => ..], ...]
 */
function saveVendorQuotation(int $rfqId, int $vendorId, int $userId, array $data, array $rows): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, request_id, status, deadline FROM rfqs WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $rfqId]);
        $rfq = $stmt->fetch();

        if (!$rfq || !vendorIsInvited($rfqId, $vendorId)) {
            throw new RuntimeException('You were not invited to this RFQ.');
        }
        if (!rfqAcceptsQuotations($rfq)) {
            throw new RuntimeException('The deadline has passed. Quotations can no longer be added or changed.');
        }

        // Snapshot of the vendor's reliability rating at submission time.
        $reliability = vendorReliability($vendorId);
        $existing = vendorQuotation($rfqId, $vendorId);

        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE quotations
                 SET submitted_by = :user, quotation_date = CURDATE(), submitted_at = NOW(),
                     delivery_days = :days, validity_date = :validity, warranty_months = :warranty,
                     quality_rating = :rating, rating_calculated = :rating2, rating_override_reason = NULL
                 WHERE id = :id'
            );
            $stmt->execute([
                'user' => $userId, 'days' => $data['delivery_days'], 'validity' => $data['validity_date'],
                'warranty' => $data['warranty_months'], 'rating' => $reliability['rating'],
                'rating2' => $reliability['rating'], 'id' => $existing['id'],
            ]);
            $quotationId = (int) $existing['id'];

            $stmt = $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = :id');
            $stmt->execute(['id' => $quotationId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO quotations
                    (request_id, rfq_id, vendor_id, submitted_by, quotation_date, delivery_days,
                     validity_date, warranty_months, quality_rating, rating_calculated, status)
                 VALUES
                    (:request_id, :rfq_id, :vendor_id, :user, CURDATE(), :days,
                     :validity, :warranty, :rating, :rating2, :status)'
            );
            $stmt->execute([
                'request_id' => $rfq['request_id'], 'rfq_id' => $rfqId, 'vendor_id' => $vendorId,
                'user' => $userId, 'days' => $data['delivery_days'], 'validity' => $data['validity_date'],
                'warranty' => $data['warranty_months'], 'rating' => $reliability['rating'],
                'rating2' => $reliability['rating'], 'status' => QUOTE_RECEIVED,
            ]);
            $quotationId = (int) $pdo->lastInsertId();
        }

        $itemStmt = $pdo->prepare(
            'INSERT INTO quotation_items (quotation_id, request_item_id, quoted_quantity, unit_price)
             VALUES (:quotation_id, :request_item_id, :qty, :price)'
        );
        foreach ($rows as $row) {
            $itemStmt->execute([
                'quotation_id' => $quotationId, 'request_item_id' => $row['request_item_id'],
                'qty' => $row['qty'], 'price' => $row['price'],
            ]);
        }

        // The audit log records that a quotation was submitted, but not
        // its prices, so the bid stays sealed until the deadline.
        auditLog($userId, $existing ? 'quotation_updated_by_vendor' : 'quotation_submitted_by_vendor', 'quotation', $quotationId, [
            'rfq_id' => $rfqId,
            'vendor_id' => $vendorId,
            'reliability_snapshot' => $reliability['rating'],
        ]);

        $pdo->commit();
        return ['ok' => true, 'message' => $existing ? 'Your quotation was updated.' : 'Your quotation was submitted.'];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $exception->getMessage()];
    }
}
