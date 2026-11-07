<?php
// /var/www/smartprocure/app/scoring.php
//
// Vendor evaluation, version "v2-independent".
//
// Each quotation is scored ON ITS OWN, against fixed values of the
// request, so adding or removing another quotation never changes it:
//
//   price score       = estimated total / quoted total x 100   (max 100)
//                       estimated total = sum of (quantity x estimated unit price)
//                       of the request items, recalculated here every time
//   delivery score    = days available / delivery days x 100    (max 100)
//                       days available = required date - quotation date
//                       (fallback from config when there is no required date)
//   reliability score = reliability rating / 5 x 100
//   final score       = weighted average, weights in percent (sum 100)
//
// Only ELIGIBLE quotations are scored:
//   request Approved, vendor Active, still valid, not declined/rejected,
//   and EVERY requested item priced for the FULL quantity.
//
// Scores are calculated when the comparison is opened; they are never
// stored. The weights and formula version used are saved in the audit
// log when a vendor is selected.

/**
 * Quotation price = sum of quoted quantity x unit price.
 */
function quotationTotal(array $quoteItems): float
{
    $total = 0.0;
    foreach ($quoteItems as $item) {
        $total += (int) $item['quoted_quantity'] * (float) $item['unit_price'];
    }
    return round($total, 2);
}

/**
 * The price baseline: estimated total worked out from the request items
 * (not read from the stored column, so it can never be out of sync).
 */
function requestBaseline(array $requestItems): float
{
    $total = 0.0;
    foreach ($requestItems as $item) {
        $total += (int) $item['quantity'] * (float) $item['estimated_unit_price'];
    }
    return round($total, 2);
}

/**
 * Days the vendor has to deliver. Returns [days, usedFallback].
 */
function deliveryWindow(?string $requiredDate, string $quotationDate, int $fallbackDays): array
{
    if ($requiredDate === null || $requiredDate === '') {
        return [$fallbackDays, true];
    }
    $days = (int) floor((strtotime($requiredDate) - strtotime($quotationDate)) / 86400);
    return [max(1, $days), false];
}

/**
 * Score ONE quotation. Pure function: it only uses this quotation and
 * fixed values of the request.
 *
 * $quote needs: total, delivery_days, quality_rating (reliability 1-5)
 * $weights in percent: ['price' => 50, 'delivery' => 30, 'reliability' => 20]
 */
function scoreQuotation(array $quote, float $baseline, int $windowDays, array $weights): array
{
    $priceScore = $quote['total'] > 0 ? min(100, $baseline / $quote['total'] * 100) : 0;
    $deliveryScore = min(100, $windowDays / max(1, (int) $quote['delivery_days']) * 100);
    $reliabilityScore = (int) $quote['quality_rating'] / RATING_MAX * 100;

    $final = ($priceScore * $weights['price']
            + $deliveryScore * $weights['delivery']
            + $reliabilityScore * $weights['reliability']) / 100;

    return [
        'price_score'       => round($priceScore, 2),
        'delivery_score'    => round($deliveryScore, 2),
        'reliability_score' => round($reliabilityScore, 2),
        'final_score'       => round($final, 2),
    ];
}

/**
 * Check one quotation. Returns [true, ''] or [false, 'reason'].
 */
function checkEligibility(array $quote, array $requestItems, string $requestStatus, string $today): array
{
    if ($requestStatus !== REQ_APPROVED) {
        return [false, 'Request is not approved'];
    }
    if ($quote['status'] === QUOTE_REJECTED) {
        return [false, 'Vendor declined the order for this quotation'];
    }
    if ($quote['vendor_status'] !== VENDOR_ACTIVE) {
        return [false, 'Vendor is inactive'];
    }
    if ($quote['validity_date'] < $today) {
        return [false, 'Quotation expired on ' . $quote['validity_date']];
    }
    if ((int) $quote['delivery_days'] <= 0) {
        return [false, 'Delivery days must be positive'];
    }

    foreach ($requestItems as $requestItem) {
        $quoted = $quote['items'][$requestItem['id']] ?? null;

        if ($quoted === null) {
            return [false, 'No price for "' . $requestItem['item_name'] . '"'];
        }
        if ((int) $quoted['quoted_quantity'] !== (int) $requestItem['quantity']) {
            return [false, 'Only ' . $quoted['quoted_quantity'] . ' of ' . $requestItem['quantity'] . ' "' . $requestItem['item_name'] . '" quoted'];
        }
    }

    if (quotationTotal($quote['items']) <= 0) {
        return [false, 'Total price must be above zero'];
    }

    return [true, ''];
}

/**
 * Score every quotation independently, then sort for display.
 * Sorting only puts them in order; it never changes a score.
 * Each quote needs: total, delivery_days, quality_rating, quotation_date.
 */
function rankQuotations(array $quotes, float $baseline, ?string $requiredDate, array $weights, int $fallbackDays): array
{
    foreach ($quotes as &$quote) {
        [$window, $usedFallback] = deliveryWindow($requiredDate, $quote['quotation_date'], $fallbackDays);
        $quote = array_merge($quote, scoreQuotation($quote, $baseline, $window, $weights));
        $quote['window_days'] = $window;
        $quote['used_fallback'] = $usedFallback;
    }
    unset($quote);

    // Highest score first; on a tie the cheaper quotation is listed first.
    usort($quotes, function ($a, $b) {
        return [$b['final_score'], $a['total']] <=> [$a['final_score'], $b['total']];
    });

    foreach ($quotes as $index => &$quote) {
        $quote['rank'] = $index + 1;
    }
    unset($quote);

    return $quotes;
}

/**
 * Load every quotation of an RFQ with its items and vendor details.
 */
function loadQuotations(int $rfqId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT q.*, v.vendor_name, v.status AS vendor_status
         FROM quotations q
         JOIN vendors v ON v.id = q.vendor_id
         WHERE q.rfq_id = :id
         ORDER BY q.id'
    );
    $stmt->execute(['id' => $rfqId]);
    $quotes = $stmt->fetchAll();

    $itemStmt = $pdo->prepare(
        'SELECT request_item_id, quoted_quantity, unit_price
         FROM quotation_items
         WHERE quotation_id = :id'
    );

    foreach ($quotes as &$quote) {
        $itemStmt->execute(['id' => $quote['id']]);
        $quote['items'] = [];
        foreach ($itemStmt->fetchAll() as $item) {
            $quote['items'][$item['request_item_id']] = $item;
        }
        $quote['total'] = quotationTotal($quote['items']);
    }
    unset($quote);

    return $quotes;
}

/**
 * Build the comparison for a request's RFQ.
 * $request needs: id, status, required_date.
 */
function compareQuotations(array $request, int $rfqId): array
{
    $requestItems = requestItems((int) $request['id']);
    $baseline = requestBaseline($requestItems);
    $weights = scoringWeights();
    $fallback = (int) config('scoring.fallback_delivery_days');
    $today = date('Y-m-d');

    $eligible = [];
    $ineligible = [];

    foreach (loadQuotations($rfqId) as $quote) {
        [$ok, $reason] = checkEligibility($quote, $requestItems, $request['status'], $today);

        if ($ok) {
            $eligible[] = $quote;
        } else {
            $quote['reason'] = $reason;
            $ineligible[] = $quote;
        }
    }

    return [
        'ranked'        => rankQuotations($eligible, $baseline, $request['required_date'], $weights, $fallback),
        'ineligible'    => $ineligible,
        'baseline'      => $baseline,
        'weights'       => $weights,
        'used_fallback' => $request['required_date'] === null || $request['required_date'] === '',
        'fallback_days' => $fallback,
    ];
}
