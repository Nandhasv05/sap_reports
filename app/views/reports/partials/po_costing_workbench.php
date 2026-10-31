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
    'sno', 'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
    'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
    'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
];
$wbZcostKeys = [
    'cost_item', 'maktx',
    'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
    'conv', 'buom', 'actual_cost', 'cost_matrix', 'variance_pct', 'validation_result',
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
?>
<section class="pcw" id="poCostingWorkbench" data-po="<?= e($wbPo) ?>" data-so="<?= e($wbSo) ?>" data-mat="<?= e($wbMat) ?>">
    <div class="pcw-compare" id="pcwKpis" aria-label="PO Data vs zCOST Data comparison">
        <section class="pcw-compare-panel is-po" aria-label="PO Data totals">
            <header class="pcw-compare-head">
                <span class="material-icons-round">receipt_long</span>
                <strong>PO Data</strong>
            </header>
            <div class="pcw-compare-meta">
                <div class="pcw-compare-chip">
                    <span class="material-icons-round">format_list_numbered</span>
                    <div><b id="pcwKpiItems">—</b><small>Items</small></div>
                </div>
                <div class="pcw-compare-chip">
                    <span class="material-icons-round">category</span>
                    <div><b id="pcwKpiMaterials">—</b><small>Materials</small></div>
                </div>
                <div class="pcw-compare-chip">
                    <span class="material-icons-round">receipt_long</span>
                    <div><b id="pcwKpiSalesOrders">—</b><small>Sales Orders</small></div>
                </div>
            </div>
            <div class="pcw-compare-totals">
                <div class="pcw-compare-total is-qty">
                    <span class="material-icons-round">inventory_2</span>
                    <div>
                        <small>PO Order Qty</small>
                        <div class="pcw-compare-total-val">
                            <b id="pcwKpiOrderQty">—</b>
                            <em id="pcwKpiOrderQtyUnit"></em>
                        </div>
                    </div>
                </div>
                <div class="pcw-compare-total is-value">
                    <span class="material-icons-round">payments</span>
                    <div>
                        <small>PO Net Value</small>
                        <div class="pcw-compare-total-val">
                            <b id="pcwKpiValue">—</b>
                            <em id="pcwKpiValueUnit"></em>
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
            <div class="pcw-compare-meta">
                <div class="pcw-compare-chip is-pass">
                    <span class="material-icons-round">check_circle</span>
                    <div><b id="pcwKpiPassed">—</b><small>Passed</small></div>
                </div>
                <div class="pcw-compare-chip is-fail">
                    <span class="material-icons-round">error</span>
                    <div><b id="pcwKpiExceptions">—</b><small>Exceptions</small></div>
                </div>
                <div class="pcw-compare-chip is-review">
                    <span class="material-icons-round">rate_review</span>
                    <div><b id="pcwKpiReview">—</b><small>Needs Review</small></div>
                </div>
            </div>
            <div class="pcw-compare-totals">
                <div class="pcw-compare-total is-expected">
                    <span class="material-icons-round">account_balance_wallet</span>
                    <div>
                        <small>Budget Total</small>
                        <div class="pcw-compare-total-val">
                            <b id="pcwKpiExpected">—</b>
                            <em id="pcwKpiExpectedUnit"></em>
                        </div>
                    </div>
                </div>
                <div class="pcw-compare-total is-actual">
                    <span class="material-icons-round">calculate</span>
                    <div>
                        <small>Actual Cost Total</small>
                        <div class="pcw-compare-total-val">
                            <b id="pcwKpiActual">—</b>
                            <em id="pcwKpiActualUnit"></em>
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

            <button type="button" class="rpt-tb-btn is-active" id="rptToggleColFilters" title="Toggle column filter inputs">
                <i class="fas fa-filter"></i>
                <span>Column Filters</span>
                <span class="rpt-tb-badge" id="rptActiveFilterBadge" style="display: none;">0</span>
            </button>

            <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="rptClearAllFilters" title="Clear all search and filters" style="display: none;">
                <i class="fas fa-rotate-left"></i>
                <span>Clear All</span>
            </button>

            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pcwRevalidateBtn" title="Revalidate">
                <span class="material-icons-round">refresh</span>
                <span>Revalidate</span>
            </button>
        </div>
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
                FORMULA
            </div>
            <code id="pcwFormulaExprText"></code>
        </div>
        <div class="pcw-formula-steps" id="pcwFormulaSteps" hidden>
            <div class="pcw-formula-steps-label">CALCULATION STEPS</div>
            <ul id="pcwFormulaStepsList"></ul>
            <div class="pcw-formula-result" id="pcwFormulaResult"></div>
        </div>
        <div class="pcw-formula-example" id="pcwFormulaExample" hidden>
            <span>EXAMPLE</span>
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
                        <th class="is-sortable<?= $isNum ? ' num' : '' ?><?= $key === 'sno' ? ' sno' : '' ?> pcw-col-<?= e($group) ?>"
                            data-col="<?= (int) $i ?>"
                            data-col-id="<?= e($key) ?>"
                            data-col-group="<?= e($group) ?>"
                            data-type="<?= $isNum ? 'num' : 'text' ?>"
                            title="Click to sort · drag to reorder">
                            <div class="th-content<?= $isNum ? ' num' : '' ?>"><span><?= e($col['label'] ?? $key) ?></span></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
                <tr class="rpt-filter-row" id="rptFilterRow">
                    <?php foreach ($wbColumns as $i => $col): ?>
                        <?php
                        $key = (string) ($col['id'] ?? '');
                        $isNum = in_array($key, $wbNumKeys, true);
                        $group = in_array($key, $wbZcostKeys, true) ? 'zcost' : 'po';
                        ?>
                        <?php if ($key === 'sno'): ?>
                            <th class="num sno" data-col-id="sno" data-col-group="po">
                                <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                    <i class="fas fa-eraser"></i>
                                </button>
                            </th>
                        <?php elseif (in_array($key, ['status'], true)): ?>
                            <th data-col-id="<?= e($key) ?>" data-col-group="<?= e($group) ?>"></th>
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
