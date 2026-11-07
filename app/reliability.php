<?php
// /var/www/smartprocure/app/reliability.php
//
// Delivery reliability (NOT product quality): how often a vendor's
// orders arrive on time, turned into a 1-5 rating.
//
//  - Only orders with status Delivered count.
//  - The date that counts is the delivery that COMPLETED the order
//    (partial deliveries before it are ignored).
//  - On time = that date <= the order's expected delivery date.
//  - Fewer than 'min_orders' completed orders -> 'default_rating'.
// Settings come from config/config.php ('reliability').

/**
 * Turn history numbers into a rating. Pure function (unit-tested).
 * Returns ['rating' => int, 'on_time_pct' => ?float, 'completed' => int, 'is_default' => bool].
 */
function reliabilityFromHistory(int $completed, int $onTime, array $settings): array
{
    if ($completed < (int) $settings['min_orders']) {
        return ['rating' => (int) $settings['default_rating'], 'on_time_pct' => null, 'completed' => $completed, 'is_default' => true];
    }

    $percent = round($onTime / $completed * 100, 1);
    $rating = RATING_MIN;

    // Thresholds are checked from the highest rating down.
    $thresholds = $settings['thresholds'];
    krsort($thresholds);
    foreach ($thresholds as $value => $minimumPercent) {
        if ($percent >= $minimumPercent) {
            $rating = (int) $value;
            break;
        }
    }

    return ['rating' => $rating, 'on_time_pct' => $percent, 'completed' => $completed, 'is_default' => false];
}

/**
 * Reliability of one vendor from its delivered orders.
 */
function vendorReliability(int $vendorId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS completed,
                COALESCE(SUM(d.received_date <= po.expected_delivery_date), 0) AS on_time
         FROM purchase_orders po
         JOIN deliveries d ON d.purchase_order_id = po.id AND d.delivery_status = :complete
         WHERE po.vendor_id = :vendor AND po.status = :delivered'
    );
    $stmt->execute(['complete' => DELIVERY_COMPLETE, 'vendor' => $vendorId, 'delivered' => PO_DELIVERED]);
    $row = $stmt->fetch();

    return reliabilityFromHistory((int) $row['completed'], (int) $row['on_time'], config('reliability'));
}

/**
 * Short text for screens, e.g. "4 / 5 (88% on time, 9 orders)".
 */
function reliabilityText(array $reliability): string
{
    if ($reliability['is_default']) {
        return $reliability['rating'] . ' / ' . RATING_MAX . ' (new vendor: ' . $reliability['completed'] . ' completed order(s), default rating)';
    }
    return $reliability['rating'] . ' / ' . RATING_MAX . ' (' . $reliability['on_time_pct'] . '% on time, ' . $reliability['completed'] . ' orders)';
}
