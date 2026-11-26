<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Sale Costing — search PO number → validation detail + aside
 */
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$validateUrl = (string) ($validateUrl ?? url('purchase-order/validate'));
$passUrl = (string) ($passUrl ?? url('purchase-order/pass'));
$manualMapUrl = (string) ($manualMapUrl ?? url('purchase-order/manual-map'));
$chargesUrl = (string) ($chargesUrl ?? url('purchase-order/charges'));
$portalRevertUrl = (string) ($portalRevertUrl ?? url('purchase-order/portal-revert'));
$pdfUrl = (string) ($pdfUrl ?? url('purchase-order/pdf'));
$canPassPo = !empty($canPassPo);
$canValidate = !empty($canValidate);
$canSapRevert = !empty($canSapRevert);
$canPortalRevert = !empty($canPortalRevert);
$canEsign = !empty($canEsign);
$startPo = preg_replace('/\D/', '', (string) ($_GET['po'] ?? '')) ?? '';
$startMat = 'all';
$poCostingUrl = (string) ($poCostingUrl ?? url('po-costing'));
$purchaseOrderUrl = (string) ($purchaseOrderUrl ?? url('purchase-order'));
?>
<div class="rpt-app is-po-sale-costing is-psc-lookup" id="pscPage"
     data-validate-url="<?= e($validateUrl) ?>"
     data-pass-url="<?= e($passUrl) ?>"
     data-manual-map-url="<?= e($manualMapUrl) ?>"
     data-charges-url="<?= e($chargesUrl) ?>"
     data-portal-revert-url="<?= e($portalRevertUrl) ?>"
     data-pdf-url="<?= e($pdfUrl) ?>"
     data-can-pass-po="<?= $canPassPo ? '1' : '0' ?>"
     data-can-validate="<?= $canValidate ? '1' : '0' ?>"
     data-can-sap-revert="<?= $canSapRevert ? '1' : '0' ?>"
     data-can-portal-revert="<?= $canPortalRevert ? '1' : '0' ?>"
     data-can-esign="<?= $canEsign ? '1' : '0' ?>"
     data-start-po="<?= e($startPo) ?>"
     data-start-mat="all"
     data-po-costing-url="<?= e($poCostingUrl) ?>"
     data-purchase-order-url="<?= e($purchaseOrderUrl) ?>">

    <?php
        $loaderVisible = true;
        $loaderAutoHide = ($startPo === '');
        $loaderMessage = $startPo !== ''
            ? ('Validating PO ' . $startPo . '…')
            : 'Preparing PO Wise Costing Validation…';
        $loaderSteps = $startPo !== ''
            ? ['Connecting…', 'Loading PO…', 'Validating items…']
            : ['Connecting…', 'Preparing validation…'];
    ?>

    <header class="rpt-bar is-pcw-top psc-rpt-bar">
        <div class="rpt-bar-left">
            <a href="<?= e($home) ?>" class="rpt-brand" aria-label="EVOLV" title="EVOLV Portal Dashboard">
                <img src="<?= e($logoUrl) ?>" alt="evolv">
            </a>
            <a href="<?= e($catalogHome) ?>" class="rpt-home" aria-label="All Reports" title="Reports Catalog">
                <span class="material-icons-round">apps</span>
            </a>
            <a href="<?= e($home) ?>" class="rpt-home" aria-label="Portal home" title="Portal Dashboard">
                <span class="material-icons-round">home</span>
            </a>
            <div class="rpt-name" id="pscPageTitle"><strong>PO Wise Costing Validation</strong></div>
            <form class="pcw-control-bar" id="pscHeaderForm" autocomplete="off" novalidate hidden>
                <label class="pcw-cb-po">
                    <span>PURCH ORD NO</span>
                    <input type="text" id="pscHdrPo" name="po" value="<?= e($startPo) ?>"
                           inputmode="numeric" maxlength="10" autocomplete="off"
                           aria-label="Purchase order number">
                </label>
                <button type="submit" class="pcw-cb-btn" id="pscHdrSearchBtn" title="Search">
                    <i class="fas fa-search"></i> SEARCH
                </button>
                <?php if ($canPassPo): ?>
                <button type="button" class="pcw-cb-btn is-pass" id="pscPassBtn" hidden disabled
                        title="Pass PO">PASS PO</button>
                <?php endif; ?>
                <?php if ($canSapRevert): ?>
                <button type="button" class="pcw-cb-btn is-revert" id="pscSapRevertBtn" hidden disabled
                        title="SAP Revert - POApprovalSet Value = &quot;&quot;">SAP REVERT</button>
                <?php endif; ?>
                <?php if ($canPortalRevert): ?>
                <button type="button" class="pcw-cb-btn is-portal-revert" id="pscPortalRevertBtn" hidden disabled
                        title="Portal Revert - clear release log + delete PO PDF">PORTAL REVERT</button>
                <?php endif; ?>
                <div class="pcw-cb-status" id="pscPassStatus">STATUS : —</div>
            </form>
        </div>
        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <main class="psc-main" id="pscMain">
        <?php require base_path('app/views/poSaleCosting/partials/po_search_lookup.php'); ?>
        <?php require base_path('app/views/poSaleCosting/partials/po_validation_detail.php'); ?>
    </main>

    <div class="pcw-match-offcanvas" id="pscMatchPop" hidden aria-hidden="true">
        <div class="pcw-match-backdrop" id="pscMatchBackdrop"></div>
        <aside class="pcw-match-panel" id="pscMatchPanel" role="dialog" aria-modal="true" aria-labelledby="pscMatchTitle">
            <div class="pcw-match-head">
                <div class="pcw-match-head-left">
                    <span class="pcw-match-icon material-icons-round">link</span>
                    <div>
                        <span class="pcw-match-kicker">PO Costing Validation</span>
                        <strong id="pscMatchTitle">Manual Costing Mapping</strong>
                        <p id="pscMatchDesc">Select a matching costing row from the quotation.</p>
                    </div>
                </div>
                <button type="button" class="pcw-match-close" id="pscMatchClose" aria-label="Close">&times;</button>
            </div>
            <div class="pcw-match-layout">
                <div class="pcw-match-meta" id="pscMatchMeta"></div>
                <div class="pcw-match-body">
                    <section class="pcw-match-main">
                        <div class="pcw-match-table-wrap">
                            <div class="pcw-match-table-head">
                                <strong id="pscMatchTableTitle">Select Matching Costing Row from Quotation</strong>
                                <div class="pcw-match-search">
                                    <span class="material-icons-round">search</span>
                                    <input type="search" id="pscMatchSearch" placeholder="Search in costing description…" autocomplete="off">
                                </div>
                            </div>
                            <div class="pcw-match-list" id="pscMatchList" role="listbox" aria-label="zCOST candidates"></div>
                        </div>
                        <div class="pcw-match-foot">
                            <div class="pcw-match-foot-actions">
                                <button type="button" class="rpt-tb-btn" id="pscMatchCancel">Cancel</button>
                                <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pscMatchApply" disabled>
                                    <span class="material-icons-round">check</span>
                                    Map &amp; Validate
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </aside>
    </div>

    <div class="pcw-formula-pop" id="pscFormulaPop" hidden role="dialog" aria-modal="false" aria-labelledby="pscFormulaTitle">
        <button type="button" class="pcw-formula-close" id="pscFormulaClose" aria-label="Close">&times;</button>
        <div class="pcw-formula-head">
            <strong id="pscFormulaTitle">—</strong>
            <p id="pscFormulaDesc"></p>
        </div>
        <div class="pcw-formula-formula" id="pscFormulaExpr" hidden>
            <div class="pcw-formula-formula-label">
                <span class="pcw-formula-sigma" aria-hidden="true">Σ</span>
                SAP API COLUMN
            </div>
            <code id="pscFormulaExprText"></code>
        </div>
        <div class="pcw-formula-steps" id="pscFormulaSteps" hidden>
            <div class="pcw-formula-steps-label">VALUE DETAIL</div>
            <ul id="pscFormulaStepsList"></ul>
            <div class="pcw-formula-result" id="pscFormulaResult"></div>
        </div>
        <div class="pcw-formula-example" id="pscFormulaExample" hidden>
            <span>SOURCE</span>
            <p id="pscFormulaExampleText"></p>
        </div>
    </div>

    <div class="pwv-toast" id="pscToast" hidden role="status" aria-live="polite"></div>
</div>

<script src="<?= e(asset('js/po-sale-costing.js')) ?>?v=<?= filemtime(sap_reports_public_file('js/po-sale-costing.js')) ?: time() ?>"></script>
