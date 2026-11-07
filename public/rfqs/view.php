<?php
// /var/www/smartprocure/public/rfqs/view.php
// One RFQ. Before the deadline: who has submitted (bids sealed, no prices).
// After the deadline: the scored comparison, rating override and vendor selection.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_PROCUREMENT);

$id = requireIdParam();
$rfq = findRfq($id);

if (!$rfq) {
    abortPage(404, 'RFQ not found.');
}

$request     = findRequest((int) $rfq['request_id']);
$items       = requestItems((int) $rfq['request_id']);
$invitations = rfqInvitations($id);
$sealed      = !rfqDeadlinePassed($rfq);
$activeOrder = activeOrderForRequest((int) $rfq['request_id']);
$submitted   = count(array_filter($invitations, fn ($row) => $row['quotation_id'] !== null));

// Prices are loaded ONLY after the deadline.
$comparison = $sealed ? null : compareQuotations($request, $id);
$canSelect  = !$sealed && $rfq['status'] === RFQ_OPEN && !$activeOrder;
$canCancel  = $rfq['status'] === RFQ_OPEN && !$activeOrder;

$pageTitle   = 'RFQ-' . $id . ' for PR-' . (int) $rfq['request_id'];
$breadcrumbs = [['label' => 'RFQs', 'url' => url('/rfqs/index.php')], ['label' => 'RFQ-' . $id]];
require APP_ROOT . '/includes/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <h2>RFQ details</h2>
        <?= badge(rfqDisplayStatus($rfq)) ?>
    </div>
    <dl class="details details-grid">
        <dt>Request</dt><dd><a href="<?= e(url('/requests/view.php', ['id' => $rfq['request_id']])) ?>">PR-<?= (int) $rfq['request_id'] ?></a>, <?= e($request['department_name']) ?></dd>
        <dt>Deadline</dt><dd><?= e(date('d M Y, H:i', strtotime($rfq['deadline']))) ?></dd>
        <dt>Quotations</dt><dd><?= $submitted ?> of <?= count($invitations) ?> vendors</dd>
        <dt>Needed by</dt><dd><?= e(showDate($request['required_date'])) ?></dd>
        <dt>Created by</dt><dd><?= e($rfq['created_by_name']) ?></dd>
        <dt>Estimate</dt><dd class="num-strong"><?= e(money($comparison['baseline'] ?? requestBaseline($items))) ?></dd>
    </dl>
    <p class="purpose"><?= e($request['purpose']) ?></p>
</section>

<?php if ($activeOrder): ?>
    <div class="alert alert-info">
        Purchase order <a href="<?= e(url('/orders/view.php', ['id' => $activeOrder['id']])) ?>">PO-<?= (int) $activeOrder['id'] ?></a> (<?= e($activeOrder['status']) ?>) was created from this RFQ.
    </div>
<?php endif; ?>

<section class="panel">
    <h2>Invited vendors</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Vendor</th><th>Invited</th><th>Quotation</th></tr></thead>
        <tbody>
        <?php foreach ($invitations as $row): ?>
            <tr>
                <td><?= e($row['vendor_name']) ?></td>
                <td><?= e(showDate($row['invited_at'])) ?></td>
                <td><?= $row['quotation_id'] ? badge('Submitted') . ' <small class="muted">' . e(date('d M, H:i', strtotime($row['submitted_at']))) . '</small>' : '<span class="muted">Not yet</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($sealed): ?>
    <section class="panel panel-quiet">
        <h2>Bids are sealed</h2>
        <p class="muted">Prices are hidden from everyone until the deadline (<?= e(date('d M Y, H:i', strtotime($rfq['deadline']))) ?>). After it, this page shows the scored comparison.</p>
    </section>
<?php else: ?>
    <?php $weights = $comparison['weights']; ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Comparison</h2>
            <p class="muted small">Price <?= (int) $weights['price'] ?>% &middot; Delivery <?= (int) $weights['delivery'] ?>% &middot; Delivery reliability <?= (int) $weights['reliability'] ?>%</p>
        </div>

        <?php if ($comparison['used_fallback']): ?>
            <div class="alert alert-warning">This request has no "needed by" date, so the delivery score assumes <?= (int) $comparison['fallback_days'] ?> days are available.</div>
        <?php endif; ?>

        <?php if (!$comparison['ranked']): ?>
            <p class="empty">No eligible quotations. <?= $canCancel ? 'You can cancel this RFQ and send a new one.' : '' ?></p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="ranking">
                <thead>
                    <tr>
                        <th>Rank</th><th>Vendor</th><th class="num">Total price</th><th class="num">Days</th><th class="num">Warranty</th>
                        <th class="num">Reliability</th><th class="num">Price score</th><th class="num">Delivery score</th>
                        <th class="num">Reliability score</th><th class="num">Final score</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comparison['ranked'] as $quote): ?>
                    <tr class="<?= $quote['rank'] === 1 ? 'row-best' : '' ?>">
                        <td><span class="rank">#<?= (int) $quote['rank'] ?></span></td>
                        <td><?= e($quote['vendor_name']) ?> <?= $quote['status'] !== QUOTE_RECEIVED ? badge($quote['status']) : '' ?></td>
                        <td class="num"><?= e(money($quote['total'])) ?></td>
                        <td class="num"><?= (int) $quote['delivery_days'] ?> <small class="muted">of <?= (int) $quote['window_days'] ?></small></td>
                        <td class="num"><?= (int) $quote['warranty_months'] ?> mo</td>
                        <td class="num">
                            <?= (int) $quote['quality_rating'] ?> / <?= RATING_MAX ?>
                            <?php if ($quote['rating_override_reason'] !== null): ?>
                                <br><small class="muted" title="<?= e($quote['rating_override_reason']) ?>">overridden (was <?= (int) $quote['rating_calculated'] ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e(number_format($quote['price_score'], 2)) ?></td>
                        <td class="num"><?= e(number_format($quote['delivery_score'], 2)) ?></td>
                        <td class="num"><?= e(number_format($quote['reliability_score'], 2)) ?></td>
                        <td class="num"><strong><?= e(number_format($quote['final_score'], 2)) ?></strong></td>
                        <td class="actions">
                            <?php if ($canSelect): ?>
                                <a class="btn btn-sm btn-primary" href="<?= e(url('/orders/create.php', ['quotation_id' => $quote['id']])) ?>">Select vendor</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="hint">
                Each quotation is scored on its own: price against the estimate (<?= e(money($comparison['baseline'])) ?>), delivery days against the days available until the needed-by date,
                and delivery reliability from the vendor's order history. Warranty is shown but not scored. The ranking is a recommendation; the final choice is yours.
            </p>
        <?php endif; ?>
    </section>

    <?php if ($comparison['ineligible']): ?>
    <section class="panel">
        <h2>Not eligible</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Vendor</th><th class="num">Total</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($comparison['ineligible'] as $quote): ?>
                <tr><td><?= e($quote['vendor_name']) ?></td><td class="num"><?= e(money($quote['total'])) ?></td><td><?= e($quote['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canSelect && $comparison['ranked']): ?>
    <section class="panel panel-quiet">
        <h2>Override a reliability rating</h2>
        <p class="muted">The rating comes from delivery history. If you know something the history does not show, you can change it, but you must give a reason. It is saved in the audit log.</p>
        <form method="post" action="<?= e(url('/rfqs/override.php', ['id' => $id])) ?>" class="form inline-row">
            <?= csrfField() ?>
            <div class="field">
                <label for="quotation_id">Quotation</label>
                <select id="quotation_id" name="quotation_id" required>
                    <?php foreach ($comparison['ranked'] as $quote): ?>
                        <option value="<?= (int) $quote['id'] ?>"><?= e($quote['vendor_name']) ?> (now <?= (int) $quote['quality_rating'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field-xs">
                <label for="rating">New rating</label>
                <input type="number" id="rating" name="rating" min="<?= RATING_MIN ?>" max="<?= RATING_MAX ?>" step="1" required>
            </div>
            <div class="field field-grow">
                <label for="reason">Reason (required)</label>
                <input type="text" id="reason" name="reason" maxlength="255" required>
            </div>
            <button type="submit" class="btn">Save override</button>
        </form>
    </section>
    <?php endif; ?>

    <?php $allQuotes = array_merge($comparison['ranked'], $comparison['ineligible']); ?>
    <?php if ($allQuotes): ?>
    <section class="panel">
        <h2>Unit prices by item</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Item</th><th class="num">Qty</th>
                    <?php foreach ($allQuotes as $quote): ?><th class="num"><?= e($quote['vendor_name']) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['item_name']) ?></td>
                    <td class="num"><?= (int) $item['quantity'] ?></td>
                    <?php foreach ($allQuotes as $quote): ?>
                        <?php $line = $quote['items'][$item['id']] ?? null; ?>
                        <td class="num">
                            <?php if ($line): ?>
                                <?= e(money($line['unit_price'])) ?>
                                <?php if ((int) $line['quoted_quantity'] !== (int) $item['quantity']): ?><br><small class="muted">qty <?= (int) $line['quoted_quantity'] ?></small><?php endif; ?>
                            <?php else: ?>
                                <span class="muted">not quoted</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>

<?php if ($canCancel): ?>
<section class="panel panel-quiet">
    <h2>Cancel RFQ</h2>
    <p class="muted">Use this if no quotation is suitable. Vendors are told, and you can send a new RFQ.</p>
    <form method="post" action="<?= e(url('/rfqs/cancel.php', ['id' => $id])) ?>" data-confirm="Cancel RFQ-<?= (int) $id ?>?">
        <?= csrfField() ?>
        <button type="submit" class="btn btn-danger">Cancel RFQ</button>
    </form>
</section>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
