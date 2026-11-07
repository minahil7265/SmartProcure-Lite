/* =====================================================================
   SmartProcure Lite - small browser helpers (plain JavaScript)
   1. Mobile menu toggle
   2. Confirmation before dangerous actions
   3. Add / remove item rows and live totals on request and quotation forms
   4. Auto-submit select boxes
   5. Chart.js charts on the dashboard
   All real checks still happen on the server - this file only makes
   the pages easier to use.
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {

    // ---- 1. Mobile menu ------------------------------------------------
    var toggle = document.querySelector('[data-toggle-sidebar]');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
    }

    // ---- 2. Confirm before submitting forms marked data-confirm --------
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Confirm on a single submit button (e.g. "Decline" next to "Accept").
    document.querySelectorAll('[data-confirm-button]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!window.confirm(button.getAttribute('data-confirm-button'))) {
                event.preventDefault();
            }
        });
    });

    // Scoring settings: show the running total of the three weights.
    var weightsForm = document.querySelector('[data-weights-form]');
    if (weightsForm) {
        var showTotal = function () {
            var sum = 0;
            weightsForm.querySelectorAll('[data-weight]').forEach(function (input) {
                sum += parseInt(input.value, 10) || 0;
            });
            var out = weightsForm.querySelector('[data-weight-total]');
            out.textContent = sum;
            out.parentNode.classList.toggle('bad', sum !== 100);
        };
        weightsForm.addEventListener('input', showTotal);
        showTotal();
    }

    // User forms: the vendor company field only matters for role "vendor".
    var roleSelect = document.getElementById('role');
    var vendorField = document.querySelector('[data-vendor-field]');
    if (roleSelect && vendorField) {
        var toggleVendor = function () {
            vendorField.hidden = roleSelect.value !== vendorField.getAttribute('data-vendor-field');
        };
        roleSelect.addEventListener('change', toggleVendor);
        toggleVendor();
    }

    // ---- 3. Item rows and totals ---------------------------------------
    var currency = document.body.getAttribute('data-currency') || '';
    var money = function (value) {
        return currency + ' ' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    function updateTotals(container) {
        var grand = 0;
        container.querySelectorAll('[data-item-row]').forEach(function (row) {
            var qty = parseFloat((row.querySelector('[data-qty]') || {}).value);
            var price = parseFloat((row.querySelector('[data-price]') || {}).value);
            var cell = row.querySelector('[data-line-total]');
            if (!isNaN(qty) && !isNaN(price)) {
                var line = qty * price;
                grand += line;
                if (cell) { cell.textContent = money(line); }
            } else if (cell) {
                cell.textContent = '-';
            }
        });
        var total = container.querySelector('[data-grand-total]');
        if (total) { total.textContent = money(grand); }
    }

    document.querySelectorAll('[data-request-form], [data-quote-form]').forEach(function (container) {
        container.addEventListener('input', function () { updateTotals(container); });
        updateTotals(container);

        var body = container.querySelector('[data-items]');
        var addButton = container.querySelector('[data-add-item]');

        if (body && addButton) {
            addButton.addEventListener('click', function () {
                var first = body.querySelector('[data-item-row]');
                var copy = first.cloneNode(true);
                copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                copy.querySelector('[data-line-total]').textContent = '-';
                body.appendChild(copy);
                copy.querySelector('input').focus();
            });

            body.addEventListener('click', function (event) {
                var button = event.target.closest('[data-remove-item]');
                if (!button) { return; }
                var rows = body.querySelectorAll('[data-item-row]');
                if (rows.length > 1) {
                    button.closest('[data-item-row]').remove();
                } else {
                    rows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });
                }
                updateTotals(container);
            });
        }
    });

    // ---- 4. Auto-submit ------------------------------------------------
    document.querySelectorAll('[data-auto-submit]').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });

    // ---- 5. Charts -----------------------------------------------------
    if (window.Chart) {
        var palette = ['#1d5a85', '#e9a23b', '#2e7d4f', '#b23a3a', '#6b5ca5', '#3c8fa6', '#8a8f98', '#c4703a'];

        Chart.defaults.font.family = '"IBM Plex Sans", "Segoe UI", sans-serif';
        Chart.defaults.color = '#5b6676';

        document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
            var config = JSON.parse(canvas.getAttribute('data-chart'));
            var round = config.type === 'doughnut';

            config.datasets.forEach(function (dataset, index) {
                if (round) {
                    dataset.backgroundColor = palette;
                    dataset.borderWidth = 2;
                } else {
                    dataset.backgroundColor = palette[index % palette.length];
                    dataset.borderColor = palette[index % palette.length];
                    dataset.borderRadius = 4;
                    dataset.tension = 0.3;
                }
            });

            new Chart(canvas, {
                type: config.type,
                data: { labels: config.labels, datasets: config.datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: round || config.datasets.length > 1, position: 'bottom' } },
                    scales: round ? {} : { y: { beginAtZero: true, grid: { color: '#eceff3' } }, x: { grid: { display: false } } }
                }
            });
        });
    }
});
