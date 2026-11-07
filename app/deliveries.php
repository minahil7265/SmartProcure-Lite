<?php
// /var/www/smartprocure/app/deliveries.php
//
// Delivery rules (section 8.2 of the proposal):
//  - one row per item received
//  - never receive more than the remaining quantity
//  - order becomes Partially Delivered or Delivered
//  - delivery is Complete when it brings every item to its ordered quantity

/**
 * Check entered quantities against what is still outstanding.
 * Pure function (no database) so it can be unit-tested.
 *
 * @param array $items      order items with 'id', 'item_name', 'remaining'
 * @param array $quantities [order_item_id => quantity typed by the user]
 * @return array ['errors' => [], 'received' => [id => qty], 'delivery_status' => ..., 'order_status' => ...]
 */
function evaluateDelivery(array $items, array $quantities): array
{
    $errors = [];
    $received = [];
    $allComplete = true;

    foreach ($items as $item) {
        $raw = trim((string) ($quantities[$item['id']] ?? '0'));
        $qty = $raw === '' ? 0 : filter_var($raw, FILTER_VALIDATE_INT);

        if ($qty === false || $qty < 0) {
            $errors[] = "{$item['item_name']}: quantity must be a whole number, 0 or more.";
            continue;
        }
        if ($qty > $item['remaining']) {
            $errors[] = "{$item['item_name']}: only {$item['remaining']} remaining, you entered {$qty}.";
            continue;
        }
        if ($qty > 0) {
            $received[$item['id']] = $qty;
        }
        if ($item['remaining'] - $qty > 0) {
            $allComplete = false;
        }
    }

    if (!$errors && !$received) {
        $errors[] = 'Enter the quantity received for at least one item.';
    }

    return [
        'errors'          => $errors,
        'received'        => $received,
        'delivery_status' => $allComplete ? DELIVERY_COMPLETE : DELIVERY_PARTIAL,
        'order_status'    => $allComplete ? PO_DELIVERED : PO_PARTIAL,
    ];
}

/**
 * Save a delivery inside one transaction (order row locked).
 * Returns ['ok' => bool, 'message' => string, 'errors' => []].
 */
function recordDelivery(int $orderId, string $receivedDate, string $notes, array $quantities, int $userId): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order || !in_array($order['status'], PO_OPEN, true)) {
            throw new RuntimeException('Deliveries can only be recorded for open orders.');
        }

        // Re-read remaining quantities now that the order is locked.
        $result = evaluateDelivery(orderItemsWithProgress($orderId), $quantities);

        if ($result['errors']) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Please fix the quantities.', 'errors' => $result['errors']];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO deliveries (purchase_order_id, received_date, delivery_status, notes, recorded_by)
             VALUES (:order_id, :received_date, :status, :notes, :user_id)'
        );
        $stmt->execute([
            'order_id'      => $orderId,
            'received_date' => $receivedDate,
            'status'        => $result['delivery_status'],
            'notes'         => $notes !== '' ? $notes : null,
            'user_id'       => $userId,
        ]);
        $deliveryId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO delivery_items (delivery_id, purchase_order_item_id, quantity_received)
             VALUES (:delivery_id, :item_id, :qty)'
        );
        foreach ($result['received'] as $itemId => $qty) {
            $itemStmt->execute(['delivery_id' => $deliveryId, 'item_id' => $itemId, 'qty' => $qty]);
        }

        $stmt = $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $result['order_status'], 'id' => $orderId]);

        auditLog($userId, 'delivery_recorded', 'purchase_order', $orderId, [
            'delivery_id'  => $deliveryId,
            'received'     => $result['received'],
            'order_status' => $result['order_status'],
        ]);

        $pdo->commit();

        return ['ok' => true, 'message' => 'Delivery recorded. Order is now ' . $result['order_status'] . '.', 'errors' => []];
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $exception->getMessage(), 'errors' => []];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Delivery failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'The delivery could not be saved. Nothing was changed.', 'errors' => []];
    }
}

/**
 * Delivery history of an order with received items.
 */
function orderDeliveries(int $orderId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT d.id, d.received_date, d.delivery_status, d.notes, u.full_name AS recorded_by_name
         FROM deliveries d
         JOIN users u ON u.id = d.recorded_by
         WHERE d.purchase_order_id = :id
         ORDER BY d.received_date, d.id'
    );
    $stmt->execute(['id' => $orderId]);
    $deliveries = $stmt->fetchAll();

    $itemStmt = $pdo->prepare(
        'SELECT poi.item_name, di.quantity_received
         FROM delivery_items di
         JOIN purchase_order_items poi ON poi.id = di.purchase_order_item_id
         WHERE di.delivery_id = :id'
    );
    foreach ($deliveries as &$delivery) {
        $itemStmt->execute(['id' => $delivery['id']]);
        $delivery['items'] = $itemStmt->fetchAll();
    }
    unset($delivery);

    return $deliveries;
}
