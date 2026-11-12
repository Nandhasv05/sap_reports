<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/11/2026
 * DESCRIPTION : PO Costing Validation — landing lookup
 *               PO number → Workbench · Approved status + material → Bulk Validation
 */
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$poBaseUrl = (string) ($poBaseUrl ?? url('purchase-order'));
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

    <main class="poc-landing" id="poCostingLanding">
        <div class="poc-shell">
            <section class="poc-card" aria-label="Find PO costing">
                <header class="poc-card-head">
                    <div>
                        <p class="poc-card-kicker">PO Costing Validation</p>
                        <h1>Find PO costing</h1>
                    </div>
                    <span class="poc-card-badge material-icons-round" aria-hidden="true">fact_check</span>
                </header>

                <form id="poCostingLaunchForm" class="poc-form" autocomplete="off" novalidate>
                    <input type="hidden" name="view" value="costing">
                    <input type="hidden" name="range" value="this_month">

                    <div class="poc-field">
                        <label class="poc-field-label" for="poCostingPoSearch">Purchase order</label>
                        <div class="poc-control">
                            <span class="material-icons-round" aria-hidden="true">receipt_long</span>
                            <input type="text" id="poCostingPoSearch" name="po" value=""
                                   placeholder="Purchase order number" inputmode="numeric" maxlength="10" autocomplete="off"
                                   aria-label="Purchase order number">
                        </div>
                        <p class="poc-hint">Enter a PO to open the workbench.</p>
                    </div>

                    <div class="poc-or"><span>or search list</span></div>

                    <div class="poc-grid">
                        <div class="poc-field">
                            <label class="poc-field-label" for="poCostingApprovedStatus">APPROVEDSTATUS</label>
                            <div class="poc-control is-select">
                                <span class="material-icons-round" aria-hidden="true">verified</span>
                                <select id="poCostingApprovedStatus" name="approved_status" aria-label="APPROVEDSTATUS">
                                    <option value="Approved" selected>Approved</option>
                                    <option value="NotApproved">Unapproved</option>
                                </select>
                            </div>
                        </div>
                        <div class="poc-field">
                            <label class="poc-field-label" for="poCostingMatCat">Material type</label>
                            <div class="poc-control is-select">
                                <span class="material-icons-round" aria-hidden="true">category</span>
                                <select id="poCostingMatCat" name="mat_cat" aria-label="Material type">
                                    <option value="all" selected>All</option>
                                    <option value="fabric">Fabric</option>
                                    <option value="trims">Trims</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <p class="poc-form-error" role="alert" id="poCostingFormError" hidden></p>

                    <button class="poc-submit" type="submit" id="poCostingLaunchBtn">
                        <span class="material-icons-round">search</span>
                        Show report
                    </button>
                </form>
            </section>
        </div>
    </main>

    <?php require base_path('app/views/partials/footer.php'); ?>
</div>

<script>
(function () {
    var page = document.getElementById('poCostingPage');
    var form = document.getElementById('poCostingLaunchForm');
    var poInput = document.getElementById('poCostingPoSearch');
    var statusSel = document.getElementById('poCostingApprovedStatus');
    var matSel = document.getElementById('poCostingMatCat');
    var formError = document.getElementById('poCostingFormError');
    var poBaseUrl = (page && page.getAttribute('data-po-base')) || '';

    function showError(msg) {
        if (!formError) return;
        formError.textContent = msg || '';
        formError.hidden = !msg;
    }

    function clearPo() {
        if (poInput) poInput.value = '';
    }

    function clearFilters() {
        if (statusSel) statusSel.value = 'Approved';
        if (matSel) matSel.value = 'all';
    }

    if (poInput) {
        poInput.addEventListener('input', function () {
            showError('');
            if (String(poInput.value || '').trim() !== '') clearFilters();
        });
        setTimeout(function () { poInput.focus(); }, 40);
    }
    if (statusSel) {
        statusSel.addEventListener('change', function () {
            showError('');
            clearPo();
        });
    }
    if (matSel) {
        matSel.addEventListener('change', function () {
            showError('');
            if (matSel.value && matSel.value !== 'all') clearPo();
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            showError('');
            var po = String(poInput && poInput.value || '').replace(/\D/g, '');
            if (poInput) poInput.value = po;
            var status = String(statusSel && statusSel.value || 'Approved').trim();
            if (status !== 'Approved' && status !== 'NotApproved') status = 'Approved';
            var mat = String(matSel && matSel.value || 'all').trim() || 'all';

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

            url.searchParams.set('range', 'this_month');
            url.searchParams.set('approved_status', status);
            if (mat !== 'all') url.searchParams.set('mat_cat', mat);
            window.location.href = url.toString();
        });
    }
})();
</script>
