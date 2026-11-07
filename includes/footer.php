<?php
// /var/www/smartprocure/includes/footer.php
//
// Common page bottom. Set $useCharts = true before including the
// header if the page draws Chart.js charts.
$useCharts = $useCharts ?? false;
?>
<?php if ($authLayout ?? false): ?>
</main>
<?php else: ?>
        </main>
        <footer class="site-footer">
            <?= e(config('app.name')) ?> &copy; <?= date('Y') ?>
        </footer>
    </div>
</div>
<?php endif; ?>

<?php if ($useCharts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
