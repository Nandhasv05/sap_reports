<?php
/*
 * PO Costing Validation Workbench — single rpt-table with search, column hide/reorder, filters, Excel
*/
$wbPo = (string) ($purchaseOrder ?? '');
$wbSo = (string) ($salesOrder ?? '');
$wbMat = (string) ($matCat ?? 'all');
if (!in_array($wbMat, ['all', 'fabric', 'trims'], true)) {
    $wbMat = 'all';
}

require_once base_path('app/services/TableColumnRegistry.php');
$wbTableKey = 'purchase_order_costing_workbench_table';
$wbTableDef = TableColumnRegistry::get($wbTableKey) ?? ['columns' => [], 'defaultHidden' => []];
$wbColumns = $wbTableDef['columns'] ?? [];
// Actions always last column
$wbActionsCol = null;
$wbColumns = array_values(array_filter($wbColumns, static function ($col) use (&$wbActionsCol) {
    if (($col['id'] ?? '') === 'actions') {
        $wbActionsCol = $col;
        return false;
    }
    return true;
}));
if ($wbActionsCol) {
    $wbColumns[] = $wbActionsCol;
} else {
    $wbColumns[] = ['id' => 'actions', 'label' => 'Actions', 'locked' => false];
}
$wbColCount = max(1, count($wbColumns));
$wbNumKeys = [
    'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
    'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
    'budget_conv_rt', 'budget_cost_per_gmt', 'budget_cost_per_piece', 'conv', 'actual_cost', 'variance_pct',
];
$wbZcostKeys = [
    'costing_quotation_no', 'costing_item_no', 'zsection', 'maktx',
    'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'conv',
];
$wbResultKeys = [
    'budget_cost_per_gmt', 'budget_cost_per_piece', 'buom', 'actual_cost', 'variance_pct', 'actions',
];
$wbPoColCount = 0;
$wbZcostColCount = 0;
$wbResultColCount = 0;
foreach ($wbColumns as $col) {
    $id = (string) ($col['id'] ?? '');
    if (in_array($id, $wbResultKeys, true)) {
        $wbResultColCount++;
    } elseif (in_array($id, $wbZcostKeys, true)) {
        $wbZcostColCount++;
    } else {
        $wbPoColCount++;
    }
}
$wbColGroupOf = static function (string $id) use ($wbZcostKeys, $wbResultKeys): string {
    if (in_array($id, $wbResultKeys, true)) {
        return 'result';
    }
    if (in_array($id, $wbZcostKeys, true)) {
        return 'zcost';
    }
    return 'po';
};
$wbLoginUser = trim((string) (
    $_SESSION['username']
    ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User'))
));
?>
<section class="pcw" id="poCostingWorkbench" data-po="<?= e($wbPo) ?>" data-so="<?= e($wbSo) ?>" data-mat="<?= e($wbMat) ?>">
    <!-- Row 1: Status overview (classic portal cards) -->
    <div class="pcw-status-kpis" id="pcwStatusKpis" aria-label="Validation Status Overview">
        <article class="pcb-kpi is-total" data-card-key="status_total" title="Click to view details & calculation">
            <span class="pcb-kpi-icon material-icons-round">description</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiTotal">—</b>
                <small>Total Items</small>
            </div>
        </article>
        <article class="pcb-kpi is-pass" data-card-key="status_pass" title="Passed — actual ≤ budget">
            <span class="pcb-kpi-icon material-icons-round">check_circle</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiPassed">—</b>
                <small>Passed</small>
            </div>
        </article>
        <article class="pcb-kpi is-pending" data-card-key="status_mapping" title="Mapping Required — select zCOST line">
            <span class="pcb-kpi-icon material-icons-round">warning</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiMapping">—</b>
                <small>Mapping Required</small>
            </div>
        </article>
        <article class="pcb-kpi is-fail" data-card-key="status_mismatch" title="Cost Mismatch — actual &gt; budget">
            <span class="pcb-kpi-icon material-icons-round">error</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiMismatch">—</b>
                <small>Cost Mismatch</small>
            </div>
        </article>
        <article class="pcb-kpi is-ambiguous" data-card-key="status_ambiguous" title="Need to Review — uncertain mapping">
            <span class="pcb-kpi-icon material-icons-round">rate_review</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiAmbiguous">—</b>
                <small>Need to Review</small>
            </div>
        </article>
        <article class="pcb-kpi is-value" data-card-key="status_po_value" title="Total PO Value">
            <span class="pcb-kpi-icon material-icons-round">payments</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiStatusPoValue">—</b>
                <small>Total PO Value</small>
            </div>
        </article>
    </div>

    <!-- Row 2: PO Data vs Costing Data comparison -->
    <div class="pcw-compare" id="pcwKpis" aria-label="PO Data vs Costing Data comparison">
        <section class="pcw-compare-panel is-po" aria-label="PO Data totals">
            <header class="pcw-compare-head">
                <span class="material-icons-round">receipt_long</span>
                <strong>PO Data</strong>
            </header>
            <div class="pcw-compare-grid">
                <div class="pcw-compare-card" data-card-key="po_items" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-items">format_list_numbered</span>
                    <div class="pcw-card-content">
                        <small>Total Items</small>
                        <div class="pcw-card-val"><b id="pcwKpiPoItems">—</b></div>
                    </div>
                </div>
                <div class="pcw-compare-card" data-card-key="po_materials" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-materials">category</span>
                    <div class="pcw-card-content">
                        <small>Total Material</small>
                        <div class="pcw-card-val"><b id="pcwKpiPoMaterials">—</b></div>
                    </div>
                </div>
                <div class="pcw-compare-card is-qty" data-card-key="po_so_qty" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-qty">inventory_2</span>
                    <div class="pcw-card-content">
                        <small>Total PO Qty</small>
                        <div class="pcw-card-val">
                            <b id="pcwKpiPoOrderQty">—</b>
                            <em id="pcwKpiPoOrderQtyUnit"></em>
                        </div>
                    </div>
                </div>
                <div class="pcw-compare-card is-value" data-card-key="po_value" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-value">payments</span>
                    <div class="pcw-card-content">
                        <small>Total Net Value</small>
                        <div class="pcw-card-val">
                            <b id="pcwKpiPoValue">—</b>
                            <em id="pcwKpiPoValueUnit"></em>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="pcw-compare-vs" aria-hidden="true"><span>VS</span></div>

        <section class="pcw-compare-panel is-zcost" aria-label="Costing Data totals">
            <header class="pcw-compare-head">
                <span class="material-icons-round">grid_view</span>
                <strong>Costing Data</strong>
            </header>
            <div class="pcw-compare-grid">
                <div class="pcw-compare-card" data-card-key="zcost_items" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-items">format_list_numbered</span>
                    <div class="pcw-card-content">
                        <small>Total Items</small>
                        <div class="pcw-card-val"><b id="pcwKpiZcostItems">—</b></div>
                    </div>
                </div>
                <div class="pcw-compare-card" data-card-key="zcost_materials" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-materials">category</span>
                    <div class="pcw-card-content">
                        <small>Total Material</small>
                        <div class="pcw-card-val"><b id="pcwKpiZcostMaterials">—</b></div>
                    </div>
                </div>
                <div class="pcw-compare-card is-qty" data-card-key="zcost_so_qty" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-qty">inventory_2</span>
                    <div class="pcw-card-content">
                        <small>Total PO Qty</small>
                        <div class="pcw-card-val">
                            <b id="pcwKpiZcostOrderQty">—</b>
                            <em id="pcwKpiZcostOrderQtyUnit"></em>
                        </div>
                    </div>
                </div>
                <div class="pcw-compare-card is-value" data-card-key="zcost_value" title="Click to view details & calculation">
                    <span class="material-icons-round pcw-card-ico is-value">payments</span>
                    <div class="pcw-card-content">
                        <small>Total Net Value</small>
                        <div class="pcw-card-val">
                            <b id="pcwKpiZcostValue">—</b>
                            <em id="pcwKpiZcostValueUnit"></em>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="rpt-table-toolbar pcw-rpt-toolbar">
        <div class="rpt-tb-search">
            <span class="material-icons-round rpt-search-ico">search</span>
            <input type="search" id="rptTableSearch" class="rpt-tb-input"
                   placeholder="Quick search PONO, SALORDNO, STYLE, MATERIAL…"
                   autocomplete="off" spellcheck="false">
            <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="rpt-tb-actions">
            <span class="rpt-tb-count" id="rptTableCount">—</span>

            <button type="button" class="rpt-tb-btn is-active" id="rptToggleStickyScroll" title="Keep table header fixed and scroll body only">
                <i class="fas fa-arrows-up-down"></i>
                <span id="rptStickyScrollLabel">Scroll Body Only</span>
            </button>

            <div class="rpt-cat-dropdown" id="rptColumnToggleDropdown">
                <button type="button" class="rpt-tb-btn" id="rptColumnToggleBtn" title="Manage columns: reorder, show or hide (saved to your account)">
                    <i class="fas fa-columns"></i>
                    <span>Columns</span>
                    <span class="material-icons-round rpt-cat-dd-arrow" style="font-size: 14px;">expand_more</span>
                </button>
                <div class="rpt-cat-dd-menu" id="rptColumnToggleMenu" role="menu" style="right:0; left:auto; max-height:400px; overflow-y:auto;"></div>
            </div>

            <button type="button" class="rpt-tb-btn" id="rptToggleColFilters" title="Show column filter inputs">
                <i class="fas fa-filter"></i>
                <span>Column Filters</span>
                <span class="rpt-tb-badge" id="rptActiveFilterBadge" style="display: none;">0</span>
            </button>

            <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="rptClearAllFilters" title="Clear all search and filters" style="display: none;">
                <i class="fas fa-rotate-left"></i>
                <span>Clear All</span>
            </button>

            <button type="button" class="rpt-tb-btn pcw-pdf-btn pcw-pdf-btn-open" id="pcwOpenPdfBtn" title="Open PO PDF in a new tab">
                <span class="material-icons-round">open_in_new</span>
                <span>Open PDF</span>
            </button>

            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwOpenValidateBtn" title="Status &amp; Remarks">
                <span class="material-icons-round">forum</span>
                <span>Status &amp; Remarks</span>
            </button>

            <?php
                $canEsignBtn = true;
                if (isset($canEsign)) {
                    $canEsignBtn = (bool) $canEsign;
                } elseif (function_exists('sap_reports_user_can')) {
                    $canEsignBtn = sap_reports_user_can('po-costing', 'esign');
                }
            ?>
            <?php if ($canEsignBtn): ?>
            <button type="button" class="rpt-tb-btn pcw-esign-toolbar-btn" id="pcwOpenEsignBtn" title="Open PO E-Signature">
                <span class="material-icons-round">draw</span>
                <span>eSign</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="pcw-pdf-backdrop" id="pcwPdfBackdrop" hidden></div>
    <div class="pcw-pdf-modal" id="pcwPdfModal" hidden role="dialog" aria-modal="true" aria-labelledby="pcwPdfTitle">
        <div class="pcw-pdf-head">
            <div class="pcw-pdf-head-left">
                <span class="material-icons-round">picture_as_pdf</span>
                <div>
                    <strong id="pcwPdfTitle">PO PDF</strong>
                    <p id="pcwPdfMeta">—</p>
                </div>
            </div>
            <div class="pcw-pdf-head-actions">
                <a class="rpt-tb-btn" id="pcwPdfOpenTab" href="#" target="_blank" rel="noopener" title="Open in new tab">
                    <span class="material-icons-round">open_in_new</span>
                    <span>Open PDF</span>
                </a>
                <button type="button" class="pcw-pdf-close" id="pcwPdfClose" aria-label="Close PDF">&times;</button>
            </div>
        </div>
        <div class="pcw-pdf-body">
            <div class="pcw-pdf-loading" id="pcwPdfLoading">Loading PDF…</div>
            <iframe id="pcwPdfFrame" class="pcw-pdf-frame" title="Purchase order PDF" hidden></iframe>
        </div>
        <div class="pcw-pdf-foot" id="pcwPdfPassFoot" hidden>
            <p>Review the PO PDF, then continue to Pass &amp; e-sign.</p>
            <button type="button" class="rpt-tb-btn" id="pcwPdfPassCancel">Cancel</button>
            <button type="button" class="pcw-cb-btn is-pass" id="pcwPdfContinuePass">Continue to Pass</button>
        </div>
    </div>

    <div class="pcw-match-offcanvas" id="pcwMatchPop" hidden aria-hidden="true">
        <div class="pcw-match-backdrop" id="pcwMatchBackdrop"></div>
        <aside class="pcw-match-panel" id="pcwMatchPanel" role="dialog" aria-modal="true" aria-labelledby="pcwMatchTitle">
            <div class="pcw-match-head">
                <div class="pcw-match-head-left">
                    <span class="pcw-match-icon material-icons-round">link</span>
                    <div>
                        <span class="pcw-match-kicker">PO Costing Validation</span>
                        <strong id="pcwMatchTitle">Manual Costing Mapping</strong>
                        <p id="pcwMatchDesc">Select a matching costing row from the quotation.</p>
                    </div>
                </div>
                <button type="button" class="pcw-match-close" id="pcwMatchClose" aria-label="Close">&times;</button>
            </div>
            <div class="pcw-match-layout">
                <!-- Full-width 2-row PO values -->
                <div class="pcw-match-meta" id="pcwMatchMeta"></div>
                <div class="pcw-match-body">
                    <section class="pcw-match-main">
                        <div class="pcw-match-table-wrap">
                            <div class="pcw-match-table-head">
                                <strong id="pcwMatchTableTitle">Select Matching Costing Row from Quotation</strong>
                                <div class="pcw-match-search">
                                    <span class="material-icons-round">search</span>
                                    <input type="search" id="pcwMatchSearch" placeholder="Search in costing description…" autocomplete="off">
                                </div>
                            </div>
                            <div class="pcw-match-list" id="pcwMatchList" role="listbox" aria-label="zCOST candidates"></div>
                        </div>
                        <div class="pcw-match-foot">
                            <div class="pcw-match-foot-actions">
                                <button type="button" class="rpt-tb-btn" id="pcwMatchCancel">Cancel</button>
                                <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwMatchApply" disabled>
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

    <?php
        $pvLoginUser = $wbLoginUser;
        $pvKicker = 'PO Costing Workbench';
        require base_path('app/views/reports/partials/po_validation_offcanvas.php');
        $esignUser = $wbLoginUser;
        require base_path('app/views/reports/partials/po_esign_modal.php');
    ?>

    <div class="pcw-same-val-backdrop" id="pcwSameValBackdrop" hidden></div>
    <div class="pcw-same-val-modal" id="pcwSameValModal" hidden role="dialog" aria-modal="true" aria-labelledby="pcwSameValTitle">
        <div class="pcw-svm-head">
            <div class="pcw-svm-icon"><span class="material-icons-round">auto_awesome</span></div>
            <div>
                <h4 class="pcw-svm-title" id="pcwSameValTitle">Match Multiple Same-Value Items</h4>
                <p class="pcw-svm-desc" id="pcwSameValDesc">Found other PO items with matching values.</p>
            </div>
        </div>
        <div class="pcw-svm-matched-box" id="pcwSameValMatchedBox"></div>
        <div class="pcw-svm-options" id="pcwSameValOptions"></div>
        <div class="pcw-svm-actions">
            <button type="button" class="rpt-tb-btn" id="pcwSameValCancel">Only This Item</button>
            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwSameValApply">
                <span class="material-icons-round">done_all</span>
                <span id="pcwSameValApplyLabel">Match All Items</span>
            </button>
        </div>
    </div>

    <!-- Card detail 50% offcanvas -->
    <div class="pcw-card-offcanvas" id="pcwCardDetailModal" hidden aria-hidden="true">
        <div class="pcw-card-detail-backdrop" id="pcwCardDetailBackdrop"></div>
        <aside class="pcw-card-offcanvas-panel" id="pcwCardOffcanvasPanel" role="dialog" aria-modal="true" aria-labelledby="pcwCardModalTitle">
            <div class="pcw-cdm-head">
                <div class="pcw-cdm-head-left">
                    <span class="pcw-cdm-icon material-icons-round" id="pcwCardModalIcon">analytics</span>
                    <div>
                        <span class="pcw-cdm-subtitle" id="pcwCardModalSubtitle">Card Details &amp; Calculation</span>
                        <h3 class="pcw-cdm-title" id="pcwCardModalTitle">—</h3>
                    </div>
                </div>
                <div class="pcw-cdm-head-right">
                    <div class="pcw-cdm-val-badge">
                        <span id="pcwCardModalVal">—</span>
                        <small id="pcwCardModalUnit"></small>
                    </div>
                    <button type="button" class="pcw-cdm-close" id="pcwCardModalClose" aria-label="Close details">&times;</button>
                </div>
            </div>
            <div class="pcw-cdm-body">
                <div class="pcw-cdm-calc-box">
                    <div class="pcw-cdm-calc-head">
                        <span class="material-icons-round">calculate</span>
                        <strong>Calculation Method &amp; Formula</strong>
                    </div>
                    <div class="pcw-cdm-formula" id="pcwCardModalFormula">—</div>
                    <div class="pcw-cdm-steps" id="pcwCardModalSteps"></div>
                </div>
                <div class="pcw-cdm-breakdown">
                    <div class="pcw-cdm-breakdown-head">
                        <strong id="pcwCardModalTableTitle">Contributing Items Breakdown</strong>
                        <span class="pcw-cdm-breakdown-count" id="pcwCardModalTableCount">—</span>
                    </div>
                    <div class="pcw-cdm-table-wrap">
                        <table class="pcw-cdm-table" id="pcwCardModalTable">
                            <thead>
                                <tr id="pcwCardModalTableHead">
                                    <th># Item</th>
                                    <th>Material / Description</th>
                                    <th class="num">Quantity</th>
                                    <th class="num">Rate</th>
                                    <th class="num">Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="pcwCardModalTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="pcw-cdm-foot">
                <button type="button" class="rpt-tb-btn" id="pcwCardModalFilterBtn" style="display: none;">
                    <span class="material-icons-round">filter_alt</span>
                    <span id="pcwCardModalFilterLabel">Filter Table</span>
                </button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwCardModalDone">Close</button>
            </div>
        </aside>
    </div>

    <div class="pcw-formula-pop" id="pcwFormulaPop" hidden role="dialog" aria-modal="false" aria-labelledby="pcwFormulaTitle">
        <button type="button" class="pcw-formula-close" id="pcwFormulaClose" aria-label="Close">&times;</button>
        <div class="pcw-formula-head">
            <strong id="pcwFormulaTitle">—</strong>
            <p id="pcwFormulaDesc"></p>
        </div>
        <div class="pcw-formula-formula" id="pcwFormulaExpr" hidden>
            <div class="pcw-formula-formula-label">
                <span class="pcw-formula-sigma" aria-hidden="true">Σ</span>
                SAP API COLUMN
            </div>
            <code id="pcwFormulaExprText"></code>
        </div>
        <div class="pcw-formula-steps" id="pcwFormulaSteps" hidden>
            <div class="pcw-formula-steps-label">VALUE DETAIL</div>
            <ul id="pcwFormulaStepsList"></ul>
            <div class="pcw-formula-result" id="pcwFormulaResult"></div>
        </div>
        <div class="pcw-formula-example" id="pcwFormulaExample" hidden>
            <span>SOURCE</span>
            <p id="pcwFormulaExampleText"></p>
        </div>
    </div>

    <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
        <table class="rpt-table prod-table po-page-table text-nowrap pcw-unified-table" id="rptDataTable">
            <thead>
                <tr class="pcw-group-row" id="pcwGroupRow">
                    <th class="pcw-group-th is-po" data-group="po" colspan="<?= (int) max(1, $wbPoColCount) ?>">
                        <span class="pcw-group-label">
                            <span class="material-icons-round">receipt_long</span>
                            PO Data
                        </span>
                    </th>
                    <th class="pcw-group-th is-zcost" data-group="zcost" colspan="<?= (int) max(1, $wbZcostColCount) ?>">
                        <span class="pcw-group-label">
                            <span class="material-icons-round">calculate</span>
                            Costing Data
                        </span>
                    </th>
                    <th class="pcw-group-th is-result" data-group="result" colspan="<?= (int) max(1, $wbResultColCount) ?>">
                        <span class="pcw-group-label">
                            <span class="material-icons-round">fact_check</span>
                            Result
                        </span>
                    </th>
                </tr>
                <tr class="rpt-header-row">
                    <?php foreach ($wbColumns as $i => $col): ?>
                        <?php
                        $key = (string) ($col['id'] ?? '');
                        $isNum = in_array($key, $wbNumKeys, true);
                        $group = $wbColGroupOf($key);
                        ?>
                        <th class="is-sortable<?= $isNum ? ' num' : '' ?> pcw-col-<?= e($group) ?>"
                            data-col="<?= (int) $i ?>"
                            data-col-id="<?= e($key) ?>"
                            data-col-group="<?= e($group) ?>"
                            data-type="<?= $isNum ? 'num' : 'text' ?>"
                            title="Click to sort · drag to reorder">
                            <div class="th-content<?= $isNum ? ' num' : '' ?>"><span><?= e($col['label'] ?? $key) ?></span></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
                <tr class="rpt-filter-row" id="rptFilterRow" style="display: none;">
                    <?php foreach ($wbColumns as $i => $col): ?>
                        <?php
                        $key = (string) ($col['id'] ?? '');
                        $isNum = in_array($key, $wbNumKeys, true);
                        $group = $wbColGroupOf($key);
                        ?>
                        <?php if ($key === 'status'): ?>
                            <th data-col-id="status" data-col-group="po">
                                <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                    <i class="fas fa-eraser"></i>
                                </button>
                            </th>
                        <?php else: ?>
                            <th<?= $isNum ? ' class="num"' : '' ?> data-col-id="<?= e($key) ?>" data-col-group="<?= e($group) ?>">
                                <div class="rpt-col-input-wrap">
                                    <input type="text" class="rpt-col-input" data-col="<?= (int) $i ?>" data-col-key="<?= e($key) ?>"<?= $isNum ? ' data-numeric' : '' ?>
                                           placeholder="<?= $isNum ? '>0, <10…' : 'Filter…' ?>" title="Filter <?= e($col['label'] ?? $key) ?>">
                                    <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                </div>
                            </th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="rptTableBody">
                <tr class="rpt-table-loading">
                    <td colspan="<?= (int) $wbColCount ?>" class="rpt-table-skeleton">
                        <div class="rpt-skel-rows" aria-label="Loading PO costing comparison"><i></i><i></i><i></i><i></i><i></i></div>
                    </td>
                </tr>
                <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                    <td colspan="<?= (int) $wbColCount ?>" class="rpt-table-empty">
                        <div class="rpt-no-match-card">
                            <span class="material-icons-round">filter_alt_off</span>
                            <p>No rows match the applied search or filter criteria.</p>
                            <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                        </div>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr id="pcwTableFoot" class="pcw-table-foot">
                    <?php foreach ($wbColumns as $i => $col): ?>
                        <?php
                        $key = (string) ($col['id'] ?? '');
                        $isNum = in_array($key, $wbNumKeys, true);
                        $group = $wbColGroupOf($key);
                        $isLabel = ($i === 0);
                        ?>
                        <th class="<?= $isNum ? 'num ' : '' ?>pcw-col-<?= e($group) ?>"
                            data-col-id="<?= e($key) ?>"
                            data-foot-key="<?= e($key) ?>"
                            <?= $isLabel ? ' id="pcwFootTotalLabel"' : '' ?>>
                            <?= $isLabel ? 'TOTAL' : '' ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- SAP pricing conditions — after table -->
    <aside class="pcw-po-value" id="pcwChargesPanel" aria-label="PO charges and total value" hidden>
        <div class="pcw-po-value-bar">
            <div class="pcw-po-value-title">
                <span class="material-icons-round">payments</span>
                <div>
                    <strong>PO Value Breakdown</strong>
                    <small>SAP conditions · CondOTHR · CondFRB · CondZB00 · CondZSCI · CondZBCR</small>
                </div>
            </div>
            <div class="pcw-po-value-hero">
                <span>Total P.O Value</span>
                <strong><em id="pcwChargeGrandCurr"></em> <b id="pcwChargeGrandTotal">—</b></strong>
            </div>
        </div>

        <div class="pcw-po-value-body">
            <div class="pcw-po-value-sub">
                <span>Sub Total <small>(Excl. Tax)</small></span>
                <b id="pcwChargeSubTotal">—</b>
            </div>

            <div class="pcw-po-value-tiles" role="list">
                <article class="pcw-po-tile" data-charge="discount" role="listitem" title="Discount">
                    <span class="pcw-po-tile-k">Discount</span>
                    <b id="pcwChargeDiscount">0.00</b>
                </article>
                <article class="pcw-po-tile" data-charge="freight" role="listitem" title="CondFRB1 + CondFRB2">
                    <span class="pcw-po-tile-k">Freight</span>
                    <span class="pcw-po-tile-sap">FRB1/2</span>
                    <b id="pcwChargeFreight">0.00</b>
                </article>
                <article class="pcw-po-tile" data-charge="moq" role="listitem" title="CondZB00">
                    <span class="pcw-po-tile-k">MOQ</span>
                    <span class="pcw-po-tile-sap">ZB00</span>
                    <b id="pcwChargeMoq">0.00</b>
                </article>
                <article class="pcw-po-tile" data-charge="other" role="listitem" title="CondOTHR">
                    <span class="pcw-po-tile-k">Other</span>
                    <span class="pcw-po-tile-sap">OTHR</span>
                    <b id="pcwChargeOther">0.00</b>
                </article>
                <article class="pcw-po-tile" data-charge="screen" role="listitem" title="CondZSCI">
                    <span class="pcw-po-tile-k">Screen</span>
                    <span class="pcw-po-tile-sap">ZSCI</span>
                    <b id="pcwChargeScreen">0.00</b>
                </article>
                <article class="pcw-po-tile" data-charge="bank" role="listitem" title="CondZBCR">
                    <span class="pcw-po-tile-k">Bank</span>
                    <span class="pcw-po-tile-sap">ZBCR</span>
                    <b id="pcwChargeBank">0.00</b>
                </article>
            </div>

            <div class="pcw-po-value-tax">
                <div class="pcw-po-tax-item"><span>SGST</span><b id="pcwChargeSgst">0.00</b></div>
                <div class="pcw-po-tax-item"><span>CGST</span><b id="pcwChargeCgst">0.00</b></div>
                <div class="pcw-po-tax-item"><span>IGST</span><b id="pcwChargeIgst">0.00</b></div>
            </div>
        </div>
    </aside>

</section>
