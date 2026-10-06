<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : Purchase Order report view (SAP ZI_PurchaseOrderAPI_HUB)
 *               - List mode: condition-wise PO list (date range, plant, supplier, search)
 *               - Detail mode: single PO report (Hero, KPI cards, charts, items table)
 */
$mode = (string) ($mode ?? 'list');
$isList = $mode === 'list';
$purchaseOrder = (string) ($purchaseOrder ?? '');
$poInput = (string) ($poInput ?? '');
$poError = $poError ?? null;
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$catalogHome = url('/');
$pageUrl = url('purchase-order');
$plant = (string) ($plant ?? '');
$plants = is_array($plants ?? null) ? $plants : [];
$range = is_array($range ?? null) ? $range : ['preset' => 'this_month', 'from' => '', 'to' => '', 'label' => 'This Month'];
$rangePreset = (string) ($rangePreset ?? 'this_month');
$rangeError = $rangeError ?? null;
$presets = is_array($presets ?? null) ? $presets : [];
$presetDates = is_array($presetDates ?? null) ? $presetDates : [];
$supplier = (string) ($supplier ?? '');
$salesOrder = (string) ($salesOrder ?? '');
$columns = is_array($columns ?? null) ? $columns : [];
$colCount = count($columns);

$csvUrl = $pageUrl . '?' . http_build_query(array_filter([
    'po'       => $purchaseOrder,
    'range'    => $rangePreset,
    'from'     => $range['from'] ?? '',
    'to'       => $range['to'] ?? '',
    'plant'    => $plant,
    'supplier' => $supplier,
    'so'       => $salesOrder,
    'export'   => 'csv',
], static fn ($v): bool => $v !== ''));

$detailCards = [
    ['id' => 'items',     'label' => 'Items',           'icon' => 'format_list_numbered', 'tone' => 'indigo'],
    ['id' => 'materials', 'label' => 'Materials',       'icon' => 'category',             'tone' => 'teal'],
    ['id' => 'qty',       'label' => 'Total Order Qty', 'icon' => 'straighten',           'tone' => 'sky'],
    ['id' => 'value',     'label' => 'Total Net Value', 'icon' => 'payments',             'tone' => 'mint'],
    ['id' => 'so',        'label' => 'Sales Orders',    'icon' => 'receipt_long',         'tone' => 'amber'],
];

$listCards = [
    ['id' => 'pos',       'label' => 'Purchase Orders', 'icon' => 'receipt_long',         'tone' => 'indigo'],
    ['id' => 'items',     'label' => 'Total Items',     'icon' => 'format_list_numbered', 'tone' => 'teal'],
    ['id' => 'qty',       'label' => 'Total Order Qty', 'icon' => 'straighten',           'tone' => 'sky'],
    ['id' => 'value',     'label' => 'Total Net Value', 'icon' => 'payments',             'tone' => 'mint'],
    ['id' => 'suppliers', 'label' => 'Suppliers',       'icon' => 'storefront',           'tone' => 'amber'],
];
?>
<div class="rpt-app is-purchase-order <?= $isList ? 'is-po-list' : 'is-po-detail' ?>">
    <?php
        $loaderIcon = 'receipt';
        $loaderSteps = $isList
            ? ['Connecting to SAP…', 'Fetching purchase orders…', 'Reading items & suppliers…', 'Preparing report…']
            : ['Connecting to SAP…', 'Fetching purchase order ' . ($purchaseOrder ?: '…'), 'Reading items & sales orders…', 'Preparing report…'];
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
            <div class="rpt-name"><?= $isList ? 'Purchase Order Report' : ('PO ' . e($purchaseOrder)) ?></div>
        </div>

        <?php if ($isList): ?>
            <!-- Condition-wise filter form for PO List -->
            <form class="rpt-search prod-filters po-filters" method="get" action="<?= e($pageUrl) ?>" id="poFilterForm" novalidate>
                <!-- Date Range Preset -->
                <label class="rpt-field rpt-field-range" title="PO creation date range">
                    <span class="material-icons-round">date_range</span>
                    <select name="range" data-range-select aria-label="PO date range">
                        <?php foreach ($presets as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $rangePreset ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- Custom Date Inputs (shown only when Custom Date is picked - up to 6 months) -->
                <div class="date-custom" data-date-custom<?= $rangePreset === 'custom' ? '' : ' hidden' ?> style="display: <?= $rangePreset === 'custom' ? 'flex' : 'none' ?>; gap: 6px;">
                    <label class="rpt-field rpt-field-date" title="From date (within last 6 months)">
                        <span class="date-cap">From</span>
                        <input type="date" name="from" min="<?= e(date('Y-m-d', strtotime('-186 days'))) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= e($range['from'] ?? '') ?>" aria-label="From date">
                    </label>
                    <label class="rpt-field rpt-field-date" title="To date (within last 6 months)">
                        <span class="date-cap">To</span>
                        <input type="date" name="to" min="<?= e(date('Y-m-d', strtotime('-186 days'))) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= e($range['to'] ?? '') ?>" aria-label="To date">
                    </label>
                </div>
                <!-- Direct PO Search -->
                <label class="rpt-field" title="Search particular PO Number to view its details" style="max-width: 140px;">
                    <span class="material-icons-round">search</span>
                    <input id="po" name="po" value="" placeholder="Search PO #" inputmode="numeric" maxlength="10" autocomplete="off">
                </label>

                <button class="rpt-btn" type="submit" title="Load report">
                    <span class="material-icons-round">sync</span>
                </button>

                <a class="rpt-btn rpt-btn-ghost" href="<?= e($csvUrl) ?>" title="Download CSV of current list">
                    <span class="material-icons-round">file_download</span>
                </a>
            </form>
        <?php else: ?>
            <!-- Detail view header with Back to List & PO Search -->
            <div style="display: flex; align-items: center; gap: 10px;">
                <a href="<?= e($pageUrl) ?>" class="po-back-nav" title="Back to Purchase Orders List">
                    <span class="material-icons-round">arrow_back</span>
                    <span>PO List</span>
                </a>
                <form class="rpt-search" method="get" action="<?= e($pageUrl) ?>" id="poFilterForm" novalidate>
                    <label class="rpt-field">
                        <span class="material-icons-round">receipt</span>
                        <input id="po" name="po" value="<?= e($purchaseOrder) ?>" placeholder="Purchase order" inputmode="numeric" maxlength="10" autocomplete="off" required>
                    </label>
                    <button class="rpt-btn" type="submit" title="Load report">
                        <span class="material-icons-round">sync</span>
                    </button>
                    <a class="rpt-btn rpt-btn-ghost" href="<?= e($csvUrl) ?>" title="Download CSV">
                        <span class="material-icons-round">file_download</span>
                    </a>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <main class="rpt-main">
        <div class="rpt-alert" id="poAlert" hidden></div>

        <?php if ($isList): ?>
            <!-- ===================== PO LIST VIEW ===================== -->
            <!-- 5 KPI Summary Stat Cards for PO List -->
            <div class="rpt-stats po-stat-row">
                <?php foreach ($listCards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap">
                                <b id="poStat-<?= e($card['id']) ?>">—</b>
                                <em class="po-stat-unit" id="poStatUnit-<?= e($card['id']) ?>"></em>
                            </div>
                            <small><?= e($card['label']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- ===================== PO DETAIL VIEW ===================== -->
            <section class="po-hero" id="poHero">
                <div class="po-hero-main">
                    <span class="po-hero-icon material-icons-round" aria-hidden="true">receipt</span>
                    <div class="po-hero-title">
                        <span class="po-hero-kicker">Purchase Order</span>
                        <h1><?= e($purchaseOrder) ?></h1>
                        <p id="poHeroSub">Loading from SAP…</p>
                    </div>
                </div>
                <dl class="po-hero-meta" id="poHeroMeta">
                    <?php foreach (['PO Category', 'PO Type', 'PO Type Name', 'PO Date', 'Supplier', 'Supplier Name', 'Company Code', 'Purchasing Org.', 'Purchasing Group', 'Created By', 'Plant'] as $label): ?>
                        <div><dt><?= e($label) ?></dt><dd>—</dd></div>
                    <?php endforeach; ?>
                </dl>
                <div class="po-hero-sos">
                    <span>Linked Sales Orders</span>
                    <div id="poHeroSos"><span class="po-muted">—</span></div>
                </div>
            </section>

            <div class="rpt-stats po-stat-row">
                <?php foreach ($detailCards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap">
                                <b id="poStat-<?= e($card['id']) ?>">—</b>
                                <em class="po-stat-unit" id="poStatUnit-<?= e($card['id']) ?>"></em>
                            </div>
                            <small><?= e($card['label']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="rpt-charts po-charts">
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">bar_chart</span>
                        <div>
                            <h2>Order Qty &amp; Net Value By Item</h2>
                            <p>Bars show order quantity, the line shows net value</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body"><canvas id="poItemChart"></canvas></div>
                </section>
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">donut_large</span>
                        <div>
                            <h2>Net Value Share By Material</h2>
                            <p>Share of the purchase order value</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut"><canvas id="poShareChart"></canvas></div>
                </section>
            </div>
        <?php endif; ?>

        <!-- Table Toolbar (shared for both list & detail with appropriate columns) -->
        <div class="rpt-table-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="rptTableSearch" class="rpt-tb-input"
                       placeholder="<?= $isList ? 'Quick search PO #, Supplier, Plant, Sales Orders, Created By...' : 'Quick search Material, Description, Sales order...' ?>"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="rptTableCount">Loading…</span>

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
                        data-excel-title="<?= e($isList ? 'Purchase Orders List' : ('Purchase Order ' . $purchaseOrder)) ?>"
                        data-excel-file="<?= e($isList ? 'purchase-orders-list' : ('purchase-order-' . $purchaseOrder)) ?>"
                        data-excel-sheet="<?= e($isList ? 'PO List' : ('PO ' . $purchaseOrder)) ?>"
                        title="Download the table as an Excel file (visible columns and filtered rows)">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>

                <button type="button" class="rpt-tb-btn is-active" id="rptToggleColFilters" title="Toggle column filter inputs">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                    <span class="rpt-tb-badge" id="rptActiveFilterBadge" style="display: none;">0</span>
                </button>

                <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="rptClearAllFilters" title="Clear all search, filters and sorting" style="display: none;">
                    <i class="fas fa-rotate-left"></i>
                    <span>Clear All</span>
                </button>
            </div>
        </div>

        <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
            <table class="rpt-table prod-table po-page-table text-nowrap" id="rptDataTable">
                <thead>
                    <tr class="rpt-header-row">
                        <?php foreach ($columns as $i => $col): ?>
                            <?php $isNum = ($col['type'] ?? '') === 'num'; ?>
                            <th class="is-sortable<?= $isNum ? ' num' : '' ?><?= ($col['key'] ?? '') === 'sno' ? ' sno' : '' ?>"
                                data-col="<?= (int) $i ?>" data-type="<?= e($col['type'] ?? 'text') ?>" title="Click to sort by <?= e($col['label'] ?? '') ?>">
                                <div class="th-content<?= $isNum ? ' num' : '' ?>"><span><?= e($col['label'] ?? '') ?></span></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="rpt-filter-row" id="rptFilterRow">
                        <?php foreach ($columns as $i => $col): ?>
                            <?php if (($col['key'] ?? '') === 'sno'): ?>
                                <th class="num sno">
                                    <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </th>
                            <?php elseif (($col['key'] ?? '') === 'action'): ?>
                                <th></th>
                            <?php else: ?>
                                <th<?= ($col['type'] ?? '') === 'num' ? ' class="num"' : '' ?>>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="<?= (int) $i ?>"<?= ($col['type'] ?? '') === 'num' ? ' data-numeric' : '' ?>
                                               placeholder="<?= e(($col['type'] ?? '') === 'num' ? '>0, <10…' : 'Filter…') ?>" title="Filter <?= e($col['label'] ?? '') ?>">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="rptTableBody">
                    <tr class="rpt-table-loading">
                        <td colspan="<?= (int) $colCount ?>" class="rpt-table-skeleton">
                            <div class="rpt-skel-rows" aria-label="Loading purchase orders from SAP"><i></i><i></i><i></i><i></i><i></i></div>
                        </td>
                    </tr>
                    <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                        <td colspan="<?= (int) $colCount ?>" class="rpt-table-empty">
                            <div class="rpt-no-match-card">
                                <span class="material-icons-round">filter_alt_off</span>
                                <p><?= $isList ? 'No purchase orders match the applied search or filter criteria.' : 'No items match the applied search or filter criteria.' ?></p>
                                <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr id="rptTableFoot">
                        <?php foreach ($columns as $i => $col): ?>
                            <?php if (($col['key'] ?? '') === 'sno'): ?>
                                <th id="footTotalLabel">Total</th>
                            <?php else: ?>
                                <th<?= ($col['type'] ?? '') === 'num' ? ' class="num"' : '' ?> data-foot-key="<?= e($col['key'] ?? '') ?>"></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </main>
</div>

<script type="application/json" id="poConfig"><?= json_encode([
    'mode'        => $mode,
    'po'          => $purchaseOrder,
    'range'       => $range,
    'plant'       => $plant,
    'supplier'    => $supplier,
    'salesOrder'  => $salesOrder,
    'presetDates' => $presetDates,
    'dataUrl'     => url('purchase-order/data'),
    'pageUrl'     => $pageUrl,
    'columns'     => $columns,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<script src="<?= e(asset('js/po-report.js')) ?>"></script>
<?php $prefTableKey = $isList ? 'purchase_order_list_table' : 'purchase_order_table'; require base_path('app/views/partials/table_preferences.php'); ?>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/purchase-order.js')) ?>"></script>
