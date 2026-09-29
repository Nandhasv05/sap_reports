<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Procurement report view (SAP ProcurementDashboardSet) - rows are loaded live by procurement.js
 */
$salesOrder = (string) ($salesOrder ?? '');
$columns = is_array($columns ?? null) ? $columns : [];
$isLookup = $salesOrder === '';
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$catalogHome = url('/');
$colCount = count($columns);
$stageNames = ['req' => 'Requirement', 'pr' => 'Purchase Requisition', 'po' => 'Purchase Order', 'bal' => 'Balance', 'stock' => 'Stock', 'lines' => 'PR Lines'];
$csvUrl = url('procurement') . '?' . http_build_query(['so' => $salesOrder, 'export' => 'csv']);

$cards = [
    ['id' => 'components',     'label' => 'Components',     'icon' => 'category',          'tone' => 'teal'],
    ['id' => 'pr_count',       'label' => 'PR Lines',       'icon' => 'list_alt',          'tone' => 'sky'],
    ['id' => 'total_pr_qty',   'label' => 'Total PR Qty',   'icon' => 'assignment',        'tone' => 'indigo'],
    ['id' => 'total_po_qty',   'label' => 'Total PO Qty',   'icon' => 'shopping_cart',     'tone' => 'mint'],
    ['id' => 'balance_pr_qty', 'label' => 'Balance PR Qty', 'icon' => 'pending_actions',   'tone' => 'rose'],
    ['id' => 'stock_qty',      'label' => 'Stock Qty',      'icon' => 'inventory_2',       'tone' => 'amber'],
    ['id' => 'po_coverage',    'label' => 'PO vs PR',       'icon' => 'donut_small',       'tone' => 'violet'],
];
?>
<div class="rpt-app is-procurement <?= $isLookup ? 'is-lookup' : 'is-report' ?>">
    <?php
        $loaderIcon = 'shopping_cart';
        $loaderSteps = ['Connecting to SAP…', 'Fetching component requirements…', 'Fetching PR & PO lines…', 'Calculating balances & stock…', 'Preparing report…'];
        require base_path('app/views/partials/loader.php');
    ?>

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
            <div class="rpt-name"><?= e($item['title']) ?></div>
        </div>

        <?php if (!$isLookup): ?>
            <form class="rpt-search" method="get" action="<?= e(url('procurement')) ?>" id="rptFilterForm">
                <label class="rpt-field">
                    <span class="material-icons-round">receipt_long</span>
                    <input id="so" name="so" value="<?= e($salesOrder) ?>" placeholder="Sales order" inputmode="numeric" required>
                </label>
                <button class="rpt-btn" type="submit" title="Load report">
                    <span class="material-icons-round">sync</span>
                </button>
                <a class="rpt-btn rpt-btn-ghost" href="<?= e($csvUrl) ?>" title="Download CSV (one line per PR line)">
                    <span class="material-icons-round">file_download</span>
                </a>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($isLookup): ?>
        <main class="lookup">
            <div class="lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
            <div class="lookup-card">
                <p class="lookup-kicker">Procurement report</p>
                <h1>Find a sales order</h1>
                <p class="lookup-lede">Pull component requirement, PR, PO and stock quantities from SAP, with PR line details.</p>
                <form method="get" action="<?= e(url('procurement')) ?>" id="rptFilterForm" class="lookup-form">
                    <label class="lookup-so">
                        <span class="material-icons-round">receipt_long</span>
                        <input id="so" name="so" value="" placeholder="Sales order number" inputmode="numeric" autofocus required>
                    </label>
                    <button class="rpt-btn lookup-go" type="submit" title="Load report">
                        <span class="material-icons-round">sync</span>
                    </button>
                </form>
                <p class="lookup-hint">Example: <b>4203</b></p>
            </div>
        </main>
    <?php else: ?>
        <main class="rpt-main">
            <div class="prod-info" id="procInfo" hidden>
                <div class="prod-info-item"><small>Sales Order</small><b id="procInfoSo"><?= e($salesOrder) ?></b></div>
                <div class="prod-info-item"><small>Style</small><b id="procInfoStyle">—</b></div>
                <div class="prod-info-item is-wide"><small>Style Description</small><b id="procInfoStyleDesc">—</b></div>
                <div class="prod-info-item"><small>Material Types</small><b id="procInfoTypes">—</b></div>
            </div>

            <div class="rpt-stats">
                <?php foreach ($cards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap"><b id="procStat-<?= e($card['id']) ?>">—</b></div>
                            <small><?= e($card['label']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="rpt-charts">
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">bar_chart</span>
                        <div>
                            <h2>PR vs PO By Material Group</h2>
                            <p>Total PR, total PO and balance PR quantity</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body">
                        <canvas id="rptTotalsChart"></canvas>
                    </div>
                </section>
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">donut_large</span>
                        <div>
                            <h2>Components By Material Group</h2>
                            <p>Number of components</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut">
                        <canvas id="rptMixChart"></canvas>
                    </div>
                </section>
            </div>

            <div class="rpt-table-toolbar">
                <div class="rpt-tb-search">
                    <span class="material-icons-round rpt-search-ico">search</span>
                    <input type="search" id="rptTableSearch" class="rpt-tb-input" placeholder="Quick search (Component, Description, PR number...)" autocomplete="off" spellcheck="false">
                    <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="rpt-tb-actions">
                    <span class="rpt-tb-count" id="rptTableCount">Loading…</span>

                    <button type="button" class="rpt-tb-btn" id="rptToggleAllPr" title="Show or hide the PR lines of every visible component">
                        <i class="fas fa-list-ul"></i>
                        <span id="rptToggleAllPrLabel">Expand PR Lines</span>
                    </button>

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

                    <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="rptDataTable"
                            data-excel-title="<?= e('Procurement Report — Sales Order ' . $salesOrder) ?>"
                            data-excel-file="<?= e('procurement-SO' . $salesOrder) ?>"
                            data-excel-sheet="<?= e('Procurement ' . $salesOrder) ?>"
                            title="Download the table as an Excel file (visible columns and filtered rows)">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                    </button>

                    <button type="button" class="rpt-tb-btn is-active" id="rptToggleColFilters" title="Toggle column filter inputs">
                        <i class="fas fa-filter"></i>
                        <span>Column Filters</span>
                        <span class="rpt-tb-badge" id="rptActiveFilterBadge" style="display: none;">0</span>
                    </button>

                    <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="rptClearAllFilters" title="Clear all search and filters" style="display: none;">
                        <i class="fas fa-rotate-left"></i>
                        <span>Clear All</span>
                    </button>
                </div>
            </div>

            <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
                <table class="rpt-table prod-table proc-table" id="rptDataTable">
                    <thead>
                        <tr class="rpt-header-row">
                            <?php foreach ($columns as $i => $col): ?>
                                <?php $stageTitle = $stageNames[$col['stage']] ?? ''; ?>
                                <th class="is-sortable<?= $col['type'] === 'num' ? ' num' : '' ?><?= $col['key'] === 'sno' ? ' sno' : '' ?><?= $col['stage'] !== '' ? ' stage-' . e($col['stage']) : '' ?>"
                                    data-col="<?= (int) $i ?>" data-type="<?= e($col['type']) ?>"
                                    title="<?= e(($stageTitle !== '' ? $stageTitle . ': ' : '') . 'Click to sort by ' . $col['label']) ?>">
                                    <div class="th-content<?= $col['type'] === 'num' ? ' num' : '' ?>"><span><?= e($col['label']) ?></span></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="rpt-filter-row" id="rptFilterRow">
                            <?php foreach ($columns as $i => $col): ?>
                                <?php if ($col['key'] === 'sno'): ?>
                                    <th class="num sno">
                                        <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                            <i class="fas fa-eraser"></i>
                                        </button>
                                    </th>
                                <?php else: ?>
                                    <th<?= $col['type'] === 'num' ? ' class="num"' : '' ?>>
                                        <div class="rpt-col-input-wrap">
                                            <input type="text" class="rpt-col-input" data-col="<?= (int) $i ?>"<?= $col['type'] === 'num' ? ' data-numeric' : '' ?>
                                                   placeholder="<?= e($col['type'] === 'num' ? '>0, <10…' : 'Filter…') ?>" title="Filter <?= e($col['label']) ?>">
                                            <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                        </div>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="rptTableBody">
                        <tr class="rpt-table-loading">
                            <td colspan="<?= (int) $colCount ?>" class="rpt-table-skeleton"><div class="rpt-skel-rows" aria-label="Loading procurement data for sales order <?= e($salesOrder) ?>"><i></i><i></i><i></i><i></i><i></i><i></i></div></td>
                        </tr>
                        <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                            <td colspan="<?= (int) $colCount ?>" class="rpt-table-empty">
                                <div class="rpt-no-match-card">
                                    <span class="material-icons-round">filter_alt_off</span>
                                    <p>No records match the applied search or filter criteria.</p>
                                    <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr id="rptTableFoot">
                            <?php foreach ($columns as $i => $col): ?>
                                <?php if ($col['key'] === 'sno'): ?>
                                    <th id="footTotalLabel">Total</th>
                                <?php else: ?>
                                    <th<?= $col['type'] === 'num' ? ' class="num" data-foot-col="' . (int) $i . '"' : '' ?>></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </main>
    <?php endif; ?>
</div>
<script type="application/json" id="rptPageConfig"><?= json_encode([
    'report'        => 'procurement',
    'so'            => $salesOrder,
    'live'          => !$isLookup,
    'dataUrl'       => url('procurement/data'),
    'pageUrl'       => url('procurement'),
    'columns'       => $columns,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<?php if (!$isLookup): ?>
<?php $prefTableKey = 'procurement_table'; require base_path('app/views/partials/table_preferences.php'); ?>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/procurement.js')) ?>"></script>
<?php endif; ?>
