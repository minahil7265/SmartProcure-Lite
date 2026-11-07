<?php
// /var/www/smartprocure/includes/partials/vendor_form.php
// Shared vendor form. Expects $formAction, $vendor (array), $showStatus (bool), $cancelUrl.
?>
<form method="post" action="<?= e($formAction) ?>" class="form panel">
    <?= csrfField() ?>

    <div class="field">
        <label for="vendor_name">Vendor name</label>
        <input type="text" id="vendor_name" name="vendor_name" value="<?= e($vendor['vendor_name']) ?>" maxlength="150" required>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="contact_person">Contact person</label>
            <input type="text" id="contact_person" name="contact_person" value="<?= e($vendor['contact_person']) ?>" maxlength="100">
        </div>
        <div class="field">
            <label for="phone">Phone</label>
            <input type="tel" id="phone" name="phone" value="<?= e($vendor['phone']) ?>" maxlength="30">
        </div>
    </div>

    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($vendor['email']) ?>" maxlength="150">
    </div>

    <div class="field">
        <label for="address">Address</label>
        <input type="text" id="address" name="address" value="<?= e($vendor['address']) ?>" maxlength="255">
    </div>

    <?php if ($showStatus): ?>
    <div class="field field-narrow">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (VENDOR_STATUSES as $option): ?>
                <option value="<?= e($option) ?>" <?= $vendor['status'] === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="hint">Inactive vendors cannot be used for new quotations but stay in history.</p>
    </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save vendor</button>
        <a class="btn btn-ghost" href="<?= e($cancelUrl) ?>">Cancel</a>
    </div>
</form>
