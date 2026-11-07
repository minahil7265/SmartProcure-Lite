<?php
// /var/www/smartprocure/app/orders.php
//
// Purchase order rules (section 8.1 of the proposal), plus vendor
// acknowledgement: a new order is "Created" (sent to the vendor), and
// the vendor Accepts or Declines it. Goods can be received only after
// the vendor accepts. A decline releases the budget so the officer can
// choose another vendor from the same RFQ.

/**
 * Load one purchase order with vendor, request and department details.
 */
function findOrder(int $id): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT po.*, v.vendor_name, v.contact_person, v.email AS vendor_email, v.phone AS vendor_phone,
                r.purpose, r.department_id, r.request_date, d.department_name,
                u.full_name AS created_by_name
         FROM purchase_orders po
         JOIN vendors v ON v.id = po.vendor_id
         JOIN purchase_requests r ON r.id = po.request_id
         JOIN departments d ON d.id = r.department_id
         JOIN users u ON u.id = po.created_by
         WHERE po.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $order = $stmt->fetch();

    return $order ?: null;
}

/**
 * The active order of a request (not cancelled or declined; only one allowed).
 */
function activeOrderForRequest(int $requestId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id, status FROM purchase_orders
         WHERE request_id = :id AND status IN (:s1, :s2, :s3, :s4)
         LIMIT 1'
    );
    $stmt->execute(['id' => $requestId, 's1' => PO_ACTIVE[0], 's2' => PO_ACTIVE[1], 's3' => PO_ACTIVE[2], 's4' => PO_ACTIVE[3]]);
    $order = $stmt->fetch();

    return $order ?: null;
}

/**
 * Ordered, received and remaining quantity for each order item.
 */
function orderItemsWithProgress(int $orderId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT poi.id, poi.item_name, poi.quantity_ordered, poi.unit_price,
                COALESCE(SUM(di.quantity_received), 0) AS quantity_received
         FROM purchase_order_items poi
         LEFT JOIN delivery_items di ON di.purchase_order_item_id = poi.id
         WHERE poi.purchase_order_id = :id
         GROUP BY poi.id, poi.item_name, poi.quantity_ordered, poi.unit_price
         ORDER BY poi.id'
    );
    $stmt->execute(['id' => $orderId]);

    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['quantity_received'] = (int) $item['quantity_received'];
        $item['remaining'] = (int) $item['quantity_ordered'] - $item['quantity_received'];
    }
    unset($item);

    return $items;
}

/**
 * Overdue = expected date has passed and goods are still expected.
 */
function isOverdue(array $order): bool
{
    return in_array($order['status'], PO_PENDING_DELIVERY, true)
        && $order['expected_delivery_date'] < date('Y-m-d');
}

/**
 * Create a purchase order from the selected quotation of a CLOSED RFQ.
 * Everything runs in ONE transaction; any failed check rolls it all back.
 *
 * Returns ['ok' => bool, 'message' => string, 'order_id' => int|null].
 */
function createPurchaseOrder(int $rfqId, int $quotationId, int $userId): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // 1. Lock the RFQ and the request so two officers cannot order at once.
        $stmt = $pdo->prepare('SELECT id, request_id, status, deadline FROM rfqs WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $rfqId]);
        $rfq = $stmt->fetch();

        if (!$rfq || $rfq['status'] !== RFQ_OPEN) {
            throw new RuntimeException('This RFQ is not open for selection.');
        }
        if (!rfqDeadlinePassed($rfq)) {
            throw new RuntimeException('Bids are sealed until the deadline. You can select a vendor after ' . date('d M Y, H:i', strtotime($rfq['deadline'])) . '.');
        }

        $requestId = (int) $rfq['request_id'];
        $stmt = $pdo->prepare(
            'SELECT id, status, department_id, request_date, required_date
             FROM purchase_requests WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();

        if (!$request || $request['status'] !== REQ_APPROVED) {
            throw new RuntimeException('Only approved requests can be ordered.');
        }

        // 2. One active order per request.
        if (activeOrderForRequest($requestId)) {
            throw new RuntimeException('This request already has an active purchase order.');
        }

        // 3. The quotation must belong to this RFQ and still be eligible.
        $comparison = compareQuotations($request, $rfqId);
        $chosen = null;
        foreach ($comparison['ranked'] as $quote) {
            if ((int) $quote['id'] === $quotationId) {
                $chosen = $quote;
            }
        }
        if ($chosen === null) {
            throw new RuntimeException('The selected quotation is not eligible for this RFQ.');
        }

        $total = $chosen['total'];

        // 4. Budget check with the row locked.
        $period = budgetPeriodFor($request['request_date']);
        $budget = findBudget((int) $request['department_id'], $period, true);

        if (!$budget) {
            throw new RuntimeException("No budget is set for this department for period {$period}.");
        }
        if (availableBudget($budget) < $total) {
            throw new RuntimeException('Not enough budget. Available: ' . money(availableBudget($budget)) . ', order total: ' . money($total) . '.');
        }

        // 5. Insert the order (status Created = sent to the vendor).
        $orderDate = date('Y-m-d');
        $expected  = date('Y-m-d', strtotime("+{$chosen['delivery_days']} days"));

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_orders
                (request_id, vendor_id, quotation_id, created_by, order_date, expected_delivery_date, status, total_amount)
             VALUES
                (:request_id, :vendor_id, :quotation_id, :created_by, :order_date, :expected, :status, :total)'
        );
        $stmt->execute([
            'request_id'   => $requestId,
            'vendor_id'    => $chosen['vendor_id'],
            'quotation_id' => $quotationId,
            'created_by'   => $userId,
            'order_date'   => $orderDate,
            'expected'     => $expected,
            'status'       => PO_CREATED,
            'total'        => $total,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // 6. Copy the items from the quotation.
        $names = [];
        foreach (requestItems($requestId) as $item) {
            $names[$item['id']] = $item['item_name'];
        }

        $itemStmt = $pdo->prepare(
            'INSERT INTO purchase_order_items
                (purchase_order_id, request_item_id, item_name, quantity_ordered, unit_price)
             VALUES
                (:order_id, :request_item_id, :item_name, :quantity, :price)'
        );
        foreach ($chosen['items'] as $requestItemId => $item) {
            $itemStmt->execute([
                'order_id'        => $orderId,
                'request_item_id' => $requestItemId,
                'item_name'       => $names[$requestItemId],
                'quantity'        => $item['quoted_quantity'],
                'price'           => $item['unit_price'],
            ]);
        }

        // 7. Commit the budget.
        changeCommittedAmount((int) $budget['id'], $total);

        // 8. Mark the selected quotation and the RFQ. The other quotations
        //    stay "Received" so another vendor can be chosen if this one declines.
        $stmt = $pdo->prepare('UPDATE quotations SET status = :selected WHERE id = :id');
        $stmt->execute(['selected' => QUOTE_SELECTED, 'id' => $quotationId]);

        $stmt = $pdo->prepare('UPDATE rfqs SET status = :awarded WHERE id = :id');
        $stmt->execute(['awarded' => RFQ_AWARDED, 'id' => $rfqId]);

        // 9. Audit: the ranking, the weights and the formula version used.
        $ranking = [];
        foreach ($comparison['ranked'] as $quote) {
            $ranking[] = [
                'rank'        => $quote['rank'],
                'quotation'   => (int) $quote['id'],
                'vendor'      => $quote['vendor_name'],
                'total'       => $quote['total'],
                'final_score' => $quote['final_score'],
            ];
        }
        auditLog($userId, 'vendor_selected', 'quotation', $quotationId, [
            'rfq_id'          => $rfqId,
            'request_id'      => $requestId,
            'vendor'          => $chosen['vendor_name'],
            'rank'            => $chosen['rank'],
            'formula_version' => config('scoring.formula_version'),
            'weights'         => $comparison['weights'],
            'baseline'        => $comparison['baseline'],
            'ranking'         => $ranking,
        ]);
        auditLog($userId, 'order_created', 'purchase_order', $orderId, [
            'request_id' => $requestId,
            'total'      => $total,
            'budget'     => $period,
        ]);

        notifyUsers(vendorUserIds((int) $chosen['vendor_id']), 'New purchase order PO-' . $orderId . ' is waiting for your acceptance.', '/vendor/order.php?id=' . $orderId);

        $pdo->commit();

        return ['ok' => true, 'message' => 'Purchase order created and sent to the vendor. Budget committed.', 'order_id' => $orderId];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $exception->getMessage(), 'order_id' => null];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Order creation failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'The order could not be saved. Nothing was changed.', 'order_id' => null];
    }
}

/**
 * Release an order's budget and reopen its RFQ (used by cancel and decline).
 * Call inside a transaction. $quotationStatus: Received (can be chosen
 * again) or Rejected (vendor declined it).
 */
function releaseOrder(array $order, string $quotationStatus): float
{
    global $pdo;

    $period = budgetPeriodFor($order['request_date']);
    $budget = findBudget((int) $order['department_id'], $period, true);
    if ($budget) {
        changeCommittedAmount((int) $budget['id'], -(float) $order['total_amount']);
    }

    $stmt = $pdo->prepare('UPDATE quotations SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $quotationStatus, 'id' => $order['quotation_id']]);

    $stmt = $pdo->prepare('UPDATE rfqs SET status = :open WHERE id = (SELECT rfq_id FROM quotations WHERE id = :quotation)');
    $stmt->execute(['open' => RFQ_OPEN, 'quotation' => $order['quotation_id']]);

    return (float) $order['total_amount'];
}

/**
 * Lock an order row together with what releaseOrder() needs.
 */
function lockOrder(int $orderId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT po.id, po.status, po.total_amount, po.request_id, po.vendor_id, po.quotation_id,
                po.created_by, r.department_id, r.request_date
         FROM purchase_orders po
         JOIN purchase_requests r ON r.id = po.request_id
         WHERE po.id = :id
         FOR UPDATE'
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    return $order ?: null;
}

/**
 * The vendor accepts or declines an order sent to it.
 * OWNERSHIP: the order must belong to $vendorId (from the session).
 */
function vendorRespondToOrder(int $orderId, int $vendorId, int $userId, bool $accept, string $note): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        $order = lockOrder($orderId);

        if (!$order || (int) $order['vendor_id'] !== $vendorId) {
            throw new RuntimeException('Order not found.');
        }
        if ($order['status'] !== PO_CREATED) {
            throw new RuntimeException('This order was already answered (status: ' . $order['status'] . ').');
        }
        if (!$accept && $note === '') {
            throw new RuntimeException('Please give a reason for declining.');
        }

        $newStatus = $accept ? PO_ACCEPTED : PO_DECLINED;

        $stmt = $pdo->prepare(
            'UPDATE purchase_orders
             SET status = :status, vendor_response_at = NOW(), vendor_response_note = :note
             WHERE id = :id'
        );
        $stmt->execute(['status' => $newStatus, 'note' => $note !== '' ? $note : null, 'id' => $orderId]);

        $released = $accept ? 0 : releaseOrder($order, QUOTE_REJECTED);

        auditLog($userId, $accept ? 'order_accepted_by_vendor' : 'order_declined_by_vendor', 'purchase_order', $orderId, [
            'vendor_id' => $vendorId,
            'note'      => $note,
            'released'  => $released,
        ]);

        notifyUsers([(int) $order['created_by']], 'PO-' . $orderId . ' was ' . strtolower($newStatus) . ' by the vendor.' . ($accept ? '' : ' Budget released; choose another vendor.'), '/orders/view.php?id=' . $orderId);

        $pdo->commit();
        return ['ok' => true, 'message' => $accept ? 'Order accepted. Please deliver by the expected date.' : 'Order declined.'];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $exception->getMessage()];
    }
}

/**
 * Cancel an order that has no deliveries yet and release its budget.
 * Allowed while it waits for the vendor or after the vendor accepted.
 */
function cancelPurchaseOrder(int $orderId, int $userId): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        $order = lockOrder($orderId);

        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        if (!in_array($order['status'], [PO_CREATED, PO_ACCEPTED], true)) {
            throw new RuntimeException('Only orders that are waiting for the vendor or accepted can be cancelled.');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE purchase_order_id = :id');
        $stmt->execute(['id' => $orderId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('This order already has a delivery and cannot be cancelled.');
        }

        $stmt = $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
        $stmt->execute(['status' => PO_CANCELLED, 'id' => $orderId]);

        // Budget back; the quotation can be selected again.
        $released = releaseOrder($order, QUOTE_RECEIVED);

        auditLog($userId, 'order_cancelled', 'purchase_order', $orderId, ['released' => $released]);
        notifyUsers(vendorUserIds((int) $order['vendor_id']), 'PO-' . $orderId . ' was cancelled by the buyer.', '/vendor/order.php?id=' . $orderId);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Order cancelled and budget released.'];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $exception->getMessage()];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Order cancel failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'The order could not be cancelled. Nothing was changed.'];
    }
}
