<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/11/2026
 * DESCRIPTION : PO Costing Validation — Fabric-Sale-style full-page lookup
 *               PO number → Workbench · Date range → Bulk Validation
 */
$presets = is_array($presets ?? null) ? $presets : [];
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$poBaseUrl = (string) ($poBaseUrl ?? url('purchase-order'));
$todayMax = date('Y-m-d');
?>
<div class="rpt-app is-po-costing is-lookup" id="poCostingPage"
     data-po-base="<?= e($poBaseUrl) ?>">

    <header class="rpt-bar">
        <div class="rpt-bar-left">
            <a href="<?= e($catalogHome) ?>" class="rpt-brand" aria-label="EVOLV" title="SAP Reports">
                <img src="<?= e($logoUrl) ?>" alt="evolv">
            </a>
            <a href="<?= e($catalogHome) ?>" class="rpt-home" aria-label="All Reports" title="Reports Catalog">
                <span class="material-icons-round">apps</span>
            </a>
            <a href="<?= e($home) ?>" class="rpt-home" aria-label="Portal home" title="Portal Dashboard">
                <span class="material-icons-round">home</span>
            </a>
            <div class="rpt-name">PO Costing Validation</div>
        </div>
        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <main class="lookup" id="poCostingLanding">
        <div class="lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="lookup-card">
            <p class="lookup-kicker">PO Costing Validation</p>
            <h1>Find PO costing</h1>
            <p class="lookup-lede">Search by purchase order <b>or</b> date range (one at a time). PO opens Workbench; date opens Bulk Validation.</p>

            <form id="poCostingLaunchForm" class="lookup-filters" autocomplete="off" novalidate>
                <input type="hidden" name="view" value="costing">
                <input type="hidden" id="poCostingFrom" name="from" value="">
                <input type="hidden" id="poCostingTo" name="to" value="">

                <div class="lookup-form">
                    <label class="lookup-so" title="Purchase order number">
                        <span class="material-icons-round">receipt_long</span>
                        <input type="text" id="poCostingPoSearch" name="po" value=""
                               placeholder="Purchase order number" inputmode="numeric" maxlength="10" autocomplete="off"
                               aria-label="Purchase order number">
                    </label>
                </div>

                <p class="lookup-label">Date range <span class="lookup-label-note">or purchase order — one only *</span></p>
                <div class="lookup-range-wrap">
                    <label class="lookup-so lookup-range" title="Date Range">
                        <span class="material-icons-round">date_range</span>
                        <select id="poCostingRange" name="range" aria-label="Date Range">
                            <option value="" selected>Select Date Range…</option>
                            <?php foreach ($presets as $key => $label): ?>
                                <option value="<?= e((string) $key) ?>"><?= e((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="poc-lookup-custom" id="poCostingCustomDates" hidden>
                    <label class="lookup-so" title="From date">
                        <span class="material-icons-round">event</span>
                        <input type="date" id="poCostingFromInput" max="<?= e($todayMax) ?>" aria-label="From date">
                    </label>
                    <label class="lookup-so" title="To date">
                        <span class="material-icons-round">event</span>
                        <input type="date" id="poCostingToInput" max="<?= e($todayMax) ?>" aria-label="To date">
                    </label>
                </div>

                <p class="date-error" role="alert" id="poCostingFormError" hidden></p>

                <button class="rpt-btn lookup-submit" type="submit" id="poCostingLaunchBtn">
                    <span class="material-icons-round">sync</span>
                    Show report
                </button>
            </form>

            <p class="lookup-hint">Use <b>either</b> a purchase order <b>or</b> a date range — not both. Choosing one clears the other.</p>
        </div>
    </main>

    <?php require base_path('app/views/partials/footer.php'); ?>
</div>

<script>
(function () {
    var page = document.getElementById('poCostingPage');
    var form = document.getElementById('poCostingLaunchForm');
    var poInput = document.getElementById('poCostingPoSearch');
    var rangeSel = document.getElementById('poCostingRange');
    var customDates = document.getElementById('poCostingCustomDates');
    var fromHidden = document.getElementById('poCostingFrom');
    var toHidden = document.getElementById('poCostingTo');
    var fromInput = document.getElementById('poCostingFromInput');
    var toInput = document.getElementById('poCostingToInput');
    var formError = document.getElementById('poCostingFormError');
    var poBaseUrl = (page && page.getAttribute('data-po-base')) || '';

    function showError(msg) {
        if (!formError) return;
        formError.textContent = msg || '';
        formError.hidden = !msg;
    }

    function syncCustomDates() {
        if (!customDates || !rangeSel) return;
        customDates.hidden = rangeSel.value !== 'custom';
    }

    function clearPo() {
        if (poInput) poInput.value = '';
    }

    function clearRange() {
        if (rangeSel) rangeSel.value = '';
        if (fromInput) fromInput.value = '';
        if (toInput) toInput.value = '';
        if (fromHidden) fromHidden.value = '';
        if (toHidden) toHidden.value = '';
        syncCustomDates();
    }

    if (poInput) {
        poInput.addEventListener('input', function () {
            showError('');
            if (String(poInput.value || '').trim() !== '') clearRange();
        });
        setTimeout(function () { poInput.focus(); }, 40);
    }
    if (rangeSel) {
        rangeSel.addEventListener('change', function () {
            showError('');
            if (rangeSel.value) clearPo();
            syncCustomDates();
        });
        syncCustomDates();
    }
    if (fromInput) {
        fromInput.addEventListener('change', function () {
            if (fromInput.value) clearPo();
        });
    }
    if (toInput) {
        toInput.addEventListener('change', function () {
            if (toInput.value) clearPo();
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            showError('');
            var po = String(poInput && poInput.value || '').replace(/\D/g, '');
            if (poInput) poInput.value = po;
            var range = String(rangeSel && rangeSel.value || '').trim();

            if (po && range) {
                showError('Use either a purchase order or a date range — not both.');
                return;
            }
            if (!po && !range) {
                showError('Enter a purchase order number or select a date range.');
                return;
            }

            var url = new URL(poBaseUrl || form.action, window.location.origin);
            url.searchParams.set('view', 'costing');

            if (po) {
                if (!/^\d{1,10}$/.test(po)) {
                    showError('Purchase order must be a number of up to 10 digits.');
                    if (poInput) poInput.focus();
                    return;
                }
                url.searchParams.set('po', po);
                window.location.href = url.toString();
                return;
            }

            if (range === 'custom') {
                var from = String(fromInput && fromInput.value || '').trim();
                var to = String(toInput && toInput.value || '').trim();
                if (!from || !to) {
                    showError('Select both From and To dates for Custom Date.');
                    return;
                }
                if (from > to) {
                    showError('From date cannot be after To date.');
                    return;
                }
                if (fromHidden) fromHidden.value = from;
                if (toHidden) toHidden.value = to;
                url.searchParams.set('from', from);
                url.searchParams.set('to', to);
            }
            url.searchParams.set('range', range);
            window.location.href = url.toString();
        });
    }
})();
</script>
