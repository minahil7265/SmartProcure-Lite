<?php
// /var/www/smartprocure/tests/run_tests.php
//
// Structured PHP test script (section 11 of the proposal).
// Tests the business rules that do not need a database:
// scoring, eligibility, delivery quantities and request validation.
//
// Run:  php tests/run_tests.php

if (PHP_SAPI !== 'cli') {
    exit('Run this script from the terminal.');
}

$root = dirname(__DIR__);
$config = require (is_file("$root/config/config.php") ? "$root/config/config.php" : "$root/config/config.example.php");

require "$root/includes/constants.php";
require "$root/includes/helpers.php";
require "$root/app/requests.php";
require "$root/app/scoring.php";
require "$root/app/reliability.php";
require "$root/app/settings.php";
require "$root/app/approvals.php";
require "$root/app/deliveries.php";

$passed = 0;
$failed = 0;

function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . PHP_EOL;
    }
}

// ---------------------------------------------------------------------
echo PHP_EOL . 'Scoring is independent of other quotations' . PHP_EOL;
// ---------------------------------------------------------------------
$weights = ['price' => 50, 'delivery' => 30, 'reliability' => 20];
$baseline = 110.0;   // estimated total of the request
$window = 7;         // days until the needed-by date

$a = scoreQuotation(['total' => 100, 'delivery_days' => 8, 'quality_rating' => 4], $baseline, $window, $weights);
$b = scoreQuotation(['total' => 120, 'delivery_days' => 6, 'quality_rating' => 4], $baseline, $window, $weights);
$c = scoreQuotation(['total' => 40,  'delivery_days' => 30, 'quality_rating' => 1], $baseline, $window, $weights);

check('A: price capped at 100 (cheaper than estimate)', $a['price_score'] == 100.0);
check('A: delivery 7 / 8 days = 87.50', $a['delivery_score'] == 87.5);
check('A: reliability 4 / 5 = 80', $a['reliability_score'] == 80.0);
check('A: final 92.25', $a['final_score'] == 92.25);
check('B: price 110 / 120 = 91.67', $b['price_score'] == 91.67);
check('B: delivery capped at 100 (6 <= 7 days)', $b['delivery_score'] == 100.0);
check('B: final 91.83', $b['final_score'] == 91.83);

// The problem from the brief: adding C used to flip A and B.
$fallback = 14;
$quotes = [
    ['id' => 'A', 'total' => 100, 'delivery_days' => 8, 'quality_rating' => 4, 'quotation_date' => '2026-11-01'],
    ['id' => 'B', 'total' => 120, 'delivery_days' => 6, 'quality_rating' => 4, 'quotation_date' => '2026-11-01'],
];
$withoutC = rankQuotations($quotes, $baseline, '2026-11-08', $weights, $fallback);
$quotes[] = ['id' => 'C', 'total' => 40, 'delivery_days' => 30, 'quality_rating' => 1, 'quotation_date' => '2026-11-01'];
$withC = rankQuotations($quotes, $baseline, '2026-11-08', $weights, $fallback);
$score = fn ($list, $id) => array_values(array_filter($list, fn ($q) => $q['id'] === $id))[0]['final_score'];

check('Adding C does not change A\'s score', $score($withoutC, 'A') === $score($withC, 'A'));
check('Adding C does not change B\'s score', $score($withoutC, 'B') === $score($withC, 'B'));
check('A stays ahead of B after adding C', $score($withC, 'A') > $score($withC, 'B'));
check('Ranks are numbered 1, 2, 3', array_column($withC, 'rank') === [1, 2, 3]);

check('Window = needed-by date minus quotation date', deliveryWindow('2026-11-08', '2026-11-01', 14) === [7, false]);
check('No needed-by date uses the fallback (and says so)', deliveryWindow(null, '2026-11-01', 14) === [14, true]);
check('Needed-by date already passed counts as 1 day', deliveryWindow('2026-10-01', '2026-11-01', 14) === [1, false]);
check('Zero total gets price score 0 (no division by zero)', scoreQuotation(['total' => 0, 'delivery_days' => 5, 'quality_rating' => 3], 100, 7, $weights)['price_score'] == 0);

$items = [['quantity' => 20, 'estimated_unit_price' => 95000], ['quantity' => 2, 'estimated_unit_price' => 1500.50]];
check('Price baseline is recalculated from items (= estimated total)', requestBaseline($items) == calculateEstimatedTotal(array_map(fn ($i) => $i + ['item_name' => 'x'], $items)));

// ---------------------------------------------------------------------
echo PHP_EOL . 'Scoring weights (admin settings)' . PHP_EOL;
// ---------------------------------------------------------------------
check('50 / 30 / 20 is accepted', validateWeights(['price' => '50', 'delivery' => '30', 'reliability' => '20']) === []);
check('100 / 0 / 0 is accepted', validateWeights(['price' => '100', 'delivery' => '0', 'reliability' => '0']) === []);
check('Sum 110 is rejected', count(validateWeights(['price' => '50', 'delivery' => '30', 'reliability' => '30'])) === 1);
check('Negative weight is rejected', count(validateWeights(['price' => '-10', 'delivery' => '60', 'reliability' => '50'])) === 1);
check('Decimal weights are rejected', count(validateWeights(['price' => '50.5', 'delivery' => '29.5', 'reliability' => '20'])) >= 1);

// ---------------------------------------------------------------------
echo PHP_EOL . 'Delivery reliability rating' . PHP_EOL;
// ---------------------------------------------------------------------
$rel = ['min_orders' => 3, 'default_rating' => 3, 'thresholds' => [5 => 95, 4 => 85, 3 => 70, 2 => 50]];
check('New vendor (2 orders) gets default 3', reliabilityFromHistory(2, 0, $rel)['rating'] === 3 && reliabilityFromHistory(2, 0, $rel)['is_default']);
check('10 of 10 on time -> 5', reliabilityFromHistory(10, 10, $rel)['rating'] === 5);
check('9 of 10 (90%) -> 4', reliabilityFromHistory(10, 9, $rel)['rating'] === 4);
check('7 of 10 (70%) -> 3', reliabilityFromHistory(10, 7, $rel)['rating'] === 3);
check('5 of 10 (50%) -> 2', reliabilityFromHistory(10, 5, $rel)['rating'] === 2);
check('4 of 10 (40%) -> 1', reliabilityFromHistory(10, 4, $rel)['rating'] === 1);
check('Exactly 3 orders uses history, not default', reliabilityFromHistory(3, 3, $rel)['is_default'] === false);

// ---------------------------------------------------------------------
echo PHP_EOL . 'Quotation eligibility' . PHP_EOL;
// ---------------------------------------------------------------------
$requestItems = [
    ['id' => 10, 'item_name' => 'Computer', 'quantity' => 20],
    ['id' => 11, 'item_name' => 'Monitor',  'quantity' => 20],
];
$goodQuote = [
    'status'         => QUOTE_RECEIVED,
    'vendor_status'  => VENDOR_ACTIVE,
    'validity_date'  => '2099-12-31',
    'delivery_days'  => 5,
    'quality_rating' => 4,
    'items' => [
        10 => ['quoted_quantity' => 20, 'unit_price' => 4000],
        11 => ['quoted_quantity' => 20, 'unit_price' => 500],
    ],
];
$today = '2026-11-06';

check('Complete, valid quotation is eligible', checkEligibility($goodQuote, $requestItems, REQ_APPROVED, $today)[0] === true);
check('Request not approved is not eligible', checkEligibility($goodQuote, $requestItems, REQ_SUBMITTED, $today)[0] === false);
$q = $goodQuote; $q['status'] = QUOTE_REJECTED;
check('Quotation of a vendor who declined is not eligible', checkEligibility($q, $requestItems, REQ_APPROVED, $today)[0] === false);
$q = $goodQuote; $q['vendor_status'] = VENDOR_INACTIVE;
check('Inactive vendor is not eligible', checkEligibility($q, $requestItems, REQ_APPROVED, $today)[0] === false);
$q = $goodQuote; $q['validity_date'] = '2026-11-05';
check('Expired quotation is not eligible', checkEligibility($q, $requestItems, REQ_APPROVED, $today)[0] === false);
$q = $goodQuote; unset($q['items'][11]);
check('Missing item is not eligible', checkEligibility($q, $requestItems, REQ_APPROVED, $today)[0] === false);
$q = $goodQuote; $q['items'][10]['quoted_quantity'] = 15;
check('Partial quantity is not eligible', checkEligibility($q, $requestItems, REQ_APPROVED, $today)[0] === false);
check('Quotation total = sum of qty x unit price', quotationTotal($goodQuote['items']) == 90000.0);

// ---------------------------------------------------------------------
echo PHP_EOL . 'Approval escalation' . PHP_EOL;
// ---------------------------------------------------------------------
$now = strtotime('2026-11-10 12:00:00');
check('No other manager, waited 3 days -> escalated', isEscalationDue(REQ_SUBMITTED, '2026-11-07 11:00:00', 0, 3, $now));
check('No other manager, waited 2 days -> not yet', !isEscalationDue(REQ_SUBMITTED, '2026-11-08 12:00:00', 0, 3, $now));
check('Another manager exists -> never escalated', !isEscalationDue(REQ_SUBMITTED, '2026-11-01 12:00:00', 1, 3, $now));
check('Already approved -> not escalated', !isEscalationDue(REQ_APPROVED, '2026-11-01 12:00:00', 0, 3, $now));
check('Draft (never submitted) -> not escalated', !isEscalationDue(REQ_DRAFT, null, 0, 3, $now));

// ---------------------------------------------------------------------
echo PHP_EOL . 'Deliveries (20 computers: 15 first, then 5)' . PHP_EOL;
// ---------------------------------------------------------------------
$orderItems = [['id' => 1, 'item_name' => 'Computer', 'remaining' => 20]];

$first = evaluateDelivery($orderItems, [1 => '15']);
check('15 of 20 is accepted', !$first['errors']);
check('15 of 20 makes the delivery Partial', $first['delivery_status'] === DELIVERY_PARTIAL);
check('15 of 20 makes the order Partially Delivered', $first['order_status'] === PO_PARTIAL);

$orderItems[0]['remaining'] = 5;
$second = evaluateDelivery($orderItems, [1 => '5']);
check('Last 5 makes the delivery Complete', $second['delivery_status'] === DELIVERY_COMPLETE);
check('Last 5 makes the order Delivered', $second['order_status'] === PO_DELIVERED);

$tooMany = evaluateDelivery($orderItems, [1 => '6']);
check('More than the remaining quantity is rejected', count($tooMany['errors']) === 1);

$negative = evaluateDelivery($orderItems, [1 => '-2']);
check('Negative quantity is rejected', count($negative['errors']) === 1);

$nothing = evaluateDelivery($orderItems, [1 => '0']);
check('A delivery with nothing received is rejected', count($nothing['errors']) === 1);

// ---------------------------------------------------------------------
echo PHP_EOL . 'Request validation' . PHP_EOL;
// ---------------------------------------------------------------------
[$data, $items, $errors] = validateRequestForm([
    'purpose' => 'New lab computers',
    'required_date' => '',
    'item_name' => ['Computer', ''], 'description' => ['Core i5', ''],
    'quantity' => ['20', ''], 'unit_price' => ['95000', ''],
]);
check('Valid request has no errors', $errors === []);
check('Empty extra row is ignored', count($items) === 1);
check('Estimated total is calculated from items', calculateEstimatedTotal($items) == 1900000.0);

[, , $errors] = validateRequestForm(['purpose' => '', 'item_name' => [], 'quantity' => [], 'unit_price' => []]);
check('Missing purpose and items give errors', count($errors) === 2);

[, , $errors] = validateRequestForm([
    'purpose' => 'Test', 'item_name' => ['Pen'], 'description' => [''],
    'quantity' => ['0'], 'unit_price' => ['-5'],
]);
check('Zero quantity and negative price are rejected', count($errors) === 2);

[, , $errors] = validateRequestForm([
    'purpose' => 'Test', 'required_date' => '2000-01-01',
    'item_name' => ['Pen'], 'description' => [''], 'quantity' => ['1'], 'unit_price' => ['5'],
]);
check('Required date in the past is rejected', count($errors) === 1);

// ---------------------------------------------------------------------
echo PHP_EOL . "Result: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
