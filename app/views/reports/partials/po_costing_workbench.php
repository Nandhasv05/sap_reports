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
    'order_qty', 'net_value', 'net', 'waste', 'gross', 'rate', 'total_inr', 'total_fr', 'convr',
    'expected_unit_cost', 'po_unit_cost', 'variance_pct', 'eu_expected', 'eu_variance', 'po_item', 'sno',
];
?>
<section class="pcw" id="poCostingWorkbench" data-po="<?= e($wbPo) ?>" data-so="<?= e($wbSo) ?>" data-mat="<?= e($wbMat) ?>">
    <div class="pcw-kpis" id="pcwKpis">
        <article class="pcw-kpi">
            <span class="material-icons-round">format_list_numbered</span>
            <div><b id="pcwKpiItems">—</b><small>PO Items</small></div>
        </article>
        <article class="pcw-kpi is-pass">
            <span class="material-icons-round">check_circle</span>
            <div><b id="pcwKpiPassed">—</b><small>Passed</small></div>
        </article>
        <article class="pcw-kpi is-fail">
            <span class="material-icons-round">error</span>
            <div><b id="pcwKpiExceptions">—</b><small>Exceptions</small></div>
        </article>
        <article class="pcw-kpi is-review">
            <span class="material-icons-round">rate_review</span>
            <div><b id="pcwKpiReview">—</b><small>Needs Review</small></div>
        </article>
        <article class="pcw-kpi is-value">
            <span class="material-icons-round">payments</span>
            <div><b id="pcwKpiValue">—</b><small>Total PO Value</small></div>
        </article>
    </div>

    <div class="rpt-table-toolbar pcw-rpt-toolbar">
        <div class="rpt-tb-search">
            <span class="material-icons-round rpt-search-ico">search</span>
            <input type="search" id="rptTableSearch" class="rpt-tb-input"
                   placeholder="Quick search PO item, Material, MAKTX, Quotation…"
                   autocomplete="off" spellcheck="false">
            <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="pcw-field-seg pcw-inline-seg" title="Filter material type">
            <fieldset class="pcb-seg" role="radiogroup" aria-label="Material Type">
                <legend class="pcb-sr">Material Type</legend>
                <label class="pcb-seg-opt"><input type="radio" name="pcw_mat" value="fabric"<?= $wbMat === 'fabric' ? ' checked' : '' ?>><span>Fabric</span></label>
                <label class="pcb-seg-opt"><input type="radio" name="pcw_mat" value="trims"<?= $wbMat === 'trims' ? ' checked' : '' ?>><span>Trims</span></label>
                <label class="pcb-seg-opt"><input type="radio" name="pcw_mat" value="all"<?= !in_array($wbMat, ['fabric', 'trims'], true) ? ' checked' : '' ?>><span>All</span></label>
            </fieldset>
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

    <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
        <table class="rpt-table prod-table po-page-table text-nowrap pcw-unified-table" id="rptDataTable">
            <thead>
                <tr class="rpt-header-row">
                    <?php foreach ($wbColumns as $i => $col): ?>
                        <?php
                        $key = (string) ($col['id'] ?? '');
                        $isNum = in_array($key, $wbNumKeys, true);
                        ?>
                        <th class="is-sortable<?= $isNum ? ' num' : '' ?><?= $key === 'sno' ? ' sno' : '' ?>"
                            data-col="<?= (int) $i ?>"
                            data-col-id="<?= e($key) ?>"
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
                        ?>
                        <?php if ($key === 'sno'): ?>
                            <th class="num sno" data-col-id="sno">
                                <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                    <i class="fas fa-eraser"></i>
                                </button>
                            </th>
                        <?php elseif (in_array($key, ['customer', 'end_user', 'status'], true)): ?>
                            <th data-col-id="<?= e($key) ?>"></th>
                        <?php else: ?>
                            <th<?= $isNum ? ' class="num"' : '' ?> data-col-id="<?= e($key) ?>">
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
