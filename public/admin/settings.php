<?php
// /var/www/smartprocure/public/admin/settings.php
// Admin: scoring weights (must add up to 100). Every change is audited.
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN);

$weights = scoringWeights();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $input = [
        'price'       => trim($_POST['price'] ?? ''),
        'delivery'    => trim($_POST['delivery'] ?? ''),
        'reliability' => trim($_POST['reliability'] ?? ''),
    ];
    $errors = validateWeights($input);

    if (!$errors) {
        $old = $weights;
        foreach ($input as $name => $value) {
            saveSetting('weight_' . $name, (string) (int) $value, currentUserId());
        }
        auditLog(currentUserId(), 'scoring_weights_changed', 'settings', null, ['from' => $old, 'to' => array_map('intval', $input)]);

        flash('success', 'Scoring weights saved. They apply to every comparison from now on.');
        redirect('/admin/settings.php');
    }
    $weights = $input;
}

$reliability = config('reliability');

$pageTitle   = 'Scoring settings';
$breadcrumbs = [['label' => 'Administration'], ['label' => 'Scoring settings']];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="two-col">
    <form method="post" action="<?= e(url('/admin/settings.php')) ?>" class="form panel" data-weights-form>
        <?= csrfField() ?>
        <h2>Weights (%)</h2>
        <div class="field">
            <label for="price">Price</label>
            <input type="number" id="price" name="price" value="<?= e($weights['price']) ?>" min="0" max="100" step="1" required data-weight>
        </div>
        <div class="field">
            <label for="delivery">Delivery time</label>
            <input type="number" id="delivery" name="delivery" value="<?= e($weights['delivery']) ?>" min="0" max="100" step="1" required data-weight>
        </div>
        <div class="field">
            <label for="reliability">Delivery reliability</label>
            <input type="number" id="reliability" name="reliability" value="<?= e($weights['reliability']) ?>" min="0" max="100" step="1" required data-weight>
        </div>
        <p class="weight-total">Total: <strong data-weight-total>-</strong> (must be 100)</p>
        <button type="submit" class="btn btn-primary">Save weights</button>
    </form>

    <section class="panel">
        <h2>How a quotation is scored</h2>
        <p>Each quotation is scored on its own, so adding another quotation never changes it.</p>
        <dl class="details">
            <dt>Price</dt><dd>estimated total ÷ quoted total × 100 (at most 100)</dd>
            <dt>Delivery time</dt><dd>days until the needed-by date ÷ delivery days × 100 (at most 100). Without a needed-by date, <?= (int) config('scoring.fallback_delivery_days') ?> days are assumed.</dd>
            <dt>Delivery reliability</dt><dd>rating ÷ <?= RATING_MAX ?> × 100. The rating comes from the share of the vendor's orders that were completed on time.</dd>
        </dl>
        <h2>Reliability rating</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Rating</th><th>On-time orders</th></tr></thead>
            <tbody>
            <?php $thresholds = $reliability['thresholds']; krsort($thresholds); ?>
            <?php foreach ($thresholds as $rating => $percent): ?>
                <tr><td><?= (int) $rating ?></td><td><?= (int) $percent ?>% or more</td></tr>
            <?php endforeach; ?>
            <tr><td><?= RATING_MIN ?></td><td>less than <?= (int) min($thresholds) ?>%</td></tr>
            </tbody>
        </table>
        </div>
        <p class="hint">Vendors with fewer than <?= (int) $reliability['min_orders'] ?> completed orders get rating <?= (int) $reliability['default_rating'] ?>. These rules are set in the configuration file.</p>
    </section>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
