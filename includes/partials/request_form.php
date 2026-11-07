<?php
// /var/www/smartprocure/includes/partials/request_form.php
//
// Shared form for creating and editing a purchase request.
// Expects: $formAction, $data (purpose, required_date), $items (list of rows),
//          $showDraftButton (bool), $submitLabel
if (!$items) {
    $items = [['item_name' => '', 'description' => '', 'quantity' => '', 'estimated_unit_price' => '']];
}
?>
<form method="post" action="<?= e($formAction) ?>" class="form" data-request-form>
    <?= csrfField() ?>

    <section class="panel">
        <h2>Request details</h2>
        <div class="field">
            <label for="purpose">Purpose</label>
            <textarea id="purpose" name="purpose" rows="3" maxlength="500" required placeholder="Why are these items needed?"><?= e($data['purpose']) ?></textarea>
        </div>
        <div class="field field-narrow">
            <label for="required_date">Needed by (optional)</label>
            <input type="date" id="required_date" name="required_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($data['required_date']) ?>">
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Items</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-add-item>Add item</button>
        </div>

        <div class="table-wrap">
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th><th>Description</th><th class="num">Qty</th>
                    <th class="num">Est. unit price</th><th class="num">Line total</th><th><span class="sr-only">Remove</span></th>
                </tr>
            </thead>
            <tbody data-items>
            <?php foreach ($items as $item): ?>
                <tr data-item-row>
                    <td><input type="text" name="item_name[]" value="<?= e($item['item_name']) ?>" maxlength="150" aria-label="Item name" required></td>
                    <td><input type="text" name="description[]" value="<?= e($item['description'] ?? '') ?>" maxlength="255" aria-label="Description"></td>
                    <td class="num"><input type="number" name="quantity[]" value="<?= e($item['quantity']) ?>" min="1" step="1" aria-label="Quantity" required data-qty></td>
                    <td class="num"><input type="number" name="unit_price[]" value="<?= e($item['estimated_unit_price']) ?>" min="0" step="0.01" aria-label="Estimated unit price" required data-price></td>
                    <td class="num" data-line-total>-</td>
                    <td><button type="button" class="btn-icon" data-remove-item aria-label="Remove item">&times;</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="num">Estimated total</th>
                    <th class="num" data-grand-total>-</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
        </div>
    </section>

    <div class="form-actions">
        <?php if ($showDraftButton): ?>
            <button type="submit" name="action" value="draft" class="btn">Save as draft</button>
        <?php endif; ?>
        <button type="submit" name="action" value="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
        <a class="btn btn-ghost" href="<?= e($cancelUrl) ?>">Cancel</a>
    </div>
</form>
