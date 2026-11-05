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
$wbColCount = max(1, count($wbColumns));
$wbNumKeys = [
    'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
    'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
    'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
];
$wbZcostKeys = [
    'costing_quotation_no', 'costing_item_no', 'maktx',
    'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
    'conv', 'buom', 'actual_cost', 'variance_pct',
];
$wbPoColCount = 0;
$wbZcostColCount = 0;
foreach ($wbColumns as $col) {
    $id = (string) ($col['id'] ?? '');
    if (in_array($id, $wbZcostKeys, true)) {
        $wbZcostColCount++;
    } else {
        $wbPoColCount++;
    }
}
$wbLoginUser = trim((string) (
    $_SESSION['username']
    ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User'))
));
?>
<section class="pcw" id="poCostingWorkbench" data-po="<?= e($wbPo) ?>" data-so="<?= e($wbSo) ?>" data-mat="<?= e($wbMat) ?>">
    <!-- Row 1: Common status overview -->
    <div class="pcw-status-kpis" id="pcwStatusKpis" aria-label="Validation Status Overview">
        <article class="pcb-kpi is-total" data-card-key="status_total" title="Click to view details & calculation">
            <span class="pcb-kpi-icon material-icons-round">rule</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiTotal">—</b>
                <small>Total</small>
            </div>
        </article>
        <article class="pcb-kpi is-pass" data-card-key="status_pass" title="Click to view details & calculation">
            <span class="pcb-kpi-icon material-icons-round">check_circle</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiPassed">—</b>
                <small>Passed</small>
            </div>
        </article>
        <article class="pcb-kpi is-fail" data-card-key="status_fail" title="Click to view not matched / not validated lines">
            <span class="pcb-kpi-icon material-icons-round">error</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiExceptions">—</b>
                <small>Not Matched</small>
            </div>
        </article>
        <article class="pcb-kpi is-review" data-card-key="status_review" title="Click to view details & calculation">
            <span class="pcb-kpi-icon material-icons-round">rate_review</span>
            <div class="pcb-kpi-body">
                <b id="pcwKpiReview">—</b>
                <small>Needs Review</small>
            </div>
        </article>
    </div>

    <!-- Row 2: PO Data vs zCOST Data comparison -->
    <div class="pcw-compare" id="pcwKpis" aria-label="PO Data vs zCOST Data comparison">
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
                        <small>Total SO Qty</small>
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

        <section class="pcw-compare-panel is-zcost" aria-label="zCOST Data totals">
            <header class="pcw-compare-head">
                <span class="material-icons-round">grid_view</span>
                <strong>zCOST Data</strong>
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
                        <small>Total SO Qty</small>
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

            <button type="button" class="rpt-tb-btn" id="pcwHistoryBtn" title="View validation history for this PO">
                <span class="material-icons-round">history</span>
                <span>View History</span>
            </button>

            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwOpenValidateBtn" title="Assign next user, set Validate / Not Validate, save remarks">
                <span class="material-icons-round">fact_check</span>
                <span>Assign &amp; Validate</span>
            </button>
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
    </div>

    <div class="pcw-match-pop" id="pcwMatchPop" hidden role="dialog" aria-modal="true" aria-labelledby="pcwMatchTitle">
        <button type="button" class="pcw-match-close" id="pcwMatchClose" aria-label="Close">&times;</button>
        <div class="pcw-match-head">
            <strong id="pcwMatchTitle">Match zCOST line</strong>
            <p id="pcwMatchDesc">Select a costing line to link this unmatched PO item.</p>
        </div>
        <div class="pcw-match-search">
            <span class="material-icons-round">search</span>
            <input type="search" id="pcwMatchSearch" placeholder="Search ITEM, MAKTX, quotation…" autocomplete="off">
        </div>
        <div class="pcw-match-list" id="pcwMatchList" role="listbox" aria-label="zCOST candidates"></div>
        <div class="pcw-match-foot">
            <label class="pcw-match-multi-check" id="pcwMatchMultiCheckWrap" title="Match other PO rows sharing the same material or group">
                <input type="checkbox" id="pcwMatchApplyAllSame" checked>
                <span id="pcwMatchMultiCheckLabel">Match all rows with same Material / Group</span>
            </label>
            <div style="display: flex; gap: 8px; margin-left: auto;">
                <button type="button" class="rpt-tb-btn" id="pcwMatchCancel">Cancel</button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwMatchApply" disabled>
                    <span class="material-icons-round">check</span>
                    Apply Match
                </button>
            </div>
        </div>
    </div>

    <?php
        $pvLoginUser = $wbLoginUser;
        $pvKicker = 'PO Costing Workbench';
        require base_path('app/views/reports/partials/po_validation_offcanvas.php');
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
                            zCOST Data
                        </span>
                    </th>
                </tr>
                <tr class="rpt-header-row">
                    <?php foreach ($wbColumns as $i => $col): ?>
                        <?php
                        $key = (string) ($col['id'] ?? '');
                        $isNum = in_array($key, $wbNumKeys, true);
                        $group = in_array($key, $wbZcostKeys, true) ? 'zcost' : 'po';
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
                        $group = in_array($key, $wbZcostKeys, true) ? 'zcost' : 'po';
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
        </table>
    </div>

</section>
