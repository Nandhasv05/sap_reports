<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Fabric / Trims utilization view
*/
$isFabric = $report === 'fabric';
$mode = (string) ($_GET['mode'] ?? 'unit');
$showAllOptions = $mode === 'all';
$salesOrder = (string) ($salesOrder ?? '');
$isEmbedded = !empty($_GET['embedded']);
$awaitLive = !empty($liveClient) && $salesOrder !== '';
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$summary = is_array($summary ?? null) ? $summary : [];
$chart = is_array($chart ?? null) ? $chart : [];
$hasData = $records !== [] || (int) $total > 0 || $awaitLive;
$showNoData = $salesOrder !== '' && !$awaitLive && $records === [] && (int) $total === 0;
$rangeOrders = is_array($rangeOrders ?? null) ? $rangeOrders : null;
$isOrdersList = $isFabric && $rangeOrders !== null && !empty($rangeOrders['success']);
$isLookup = $salesOrder === '' && !$hasData && !$isOrdersList;
$presets = is_array($presets ?? null) ? $presets : [];
$presetDates = is_array($presetDates ?? null) ? $presetDates : [];
$range = is_array($range ?? null) ? $range : null;
$datePreset = (string) ($rangePreset ?? ($range['preset'] ?? ''));
$isCustom = $datePreset === 'custom';
$kindLabel = $isFabric ? 'Fabric' : 'Trims';
$catalogHome = url('/');
$qs = static function (array $extra = []) use ($search, $salesOrder, $page, $perPage, $mode): string {
    return http_build_query(array_merge([
        'mode' => $mode,
        'so' => $salesOrder,
        'q' => $search,
        'page' => $page,
        'per_page' => $perPage,
    ], $extra));
};
$tabQs = '?mode=' . rawurlencode($mode) . ($salesOrder !== '' ? ('&so=' . rawurlencode($salesOrder)) : '');
$emptyHint = $salesOrder === ''
    ? 'Enter a sales order to pull utilization from SAP.'
    : 'No ' . ($isFabric ? 'fabric' : 'trims') . ' utilization for sales order ' . $salesOrder . '.';
$bomQtyRaw      = (float) ($summary['bom_qty']        ?? 0);
$plannedQtyRaw  = (float) ($summary['planned_qty']     ?? 0);
$productionRaw  = (float) ($summary['production_qty']  ?? 0);
$poQtyRaw       = (float) ($summary['po_qty']          ?? 0);
$grnQtyRaw      = (float) ($summary['grn_qty']         ?? 0);
$issueQtyRaw    = (float) ($summary['issue_qty']       ?? 0);

$calcTrend = static function (float $val, float $bom): array {
    if ($bom <= 0) {
        if ($val > 0) {
            return ['status' => 'up',      'icon' => 'fa-arrow-trend-up',   'symbol' => '↑', 'pct' => 100.0, 'label' => 'Exceeds (BOM 0)'];
        }
        // Both zero — neutral, show minus/dash icon
        return ['status' => 'neutral', 'icon' => 'fa-minus', 'symbol' => '—', 'pct' => 0.0, 'label' => 'No data vs BOM'];
    }
    $pct = round(($val / $bom) * 100, 1);
    if ($val <= 0) {
        // val is zero but BOM has a value — neutral/empty
        return ['status' => 'neutral', 'icon' => 'fa-minus', 'symbol' => '—', 'pct' => 0.0, 'label' => '0% vs BOM (No data)'];
    }
    if ($val >= $bom) {
        return ['status' => 'up',   'icon' => 'fa-arrow-trend-up',   'symbol' => '↑', 'pct' => $pct, 'label' => $pct . '% vs BOM (Meets/Exceeds BOM)'];
    }
    return ['status' => 'down', 'icon' => 'fa-arrow-trend-down', 'symbol' => '↓', 'pct' => $pct, 'label' => $pct . '% vs BOM (Below BOM)'];
};

$plnTrend = $calcTrend($plannedQtyRaw, $bomQtyRaw);
$prdTrend = $calcTrend($productionRaw, $bomQtyRaw);
$poTrend  = $calcTrend($poQtyRaw, $bomQtyRaw);
$grnTrend = $calcTrend($grnQtyRaw, $bomQtyRaw);
$issTrend = $calcTrend($issueQtyRaw, $bomQtyRaw);

// Helper: round a raw qty to a whole number for card display
$fmtCardQty = static function ($raw): string {
    if ($raw === null || $raw === '' || $raw === '-') return '—';
    $n = (float) str_replace(',', '', (string) $raw);
    if ($n == 0) return '—';
    return number_format((int) round($n));
};

$cards = [
    ['label' => 'Materials',  'note' => 'Lines',       'icon' => 'layers',                 'tone' => 'teal',   'value' => $model->dash($summary['lines'] ?? $total)],
    ['label' => 'BOM Qty',    'note' => 'Required',    'icon' => 'schema',                 'tone' => 'sky',    'value' => $fmtCardQty($summary['bom_qty'] ?? null),
        'trend' => ['status' => 'bom', 'icon' => 'fa-bullseye', 'label' => 'Baseline target — all quantities compared against BOM'], 'trend_id' => 'statTrendIco-bom'],
    ['label' => 'Planned',    'note' => 'Planned qty', 'icon' => 'event_note',             'tone' => 'indigo', 'value' => $fmtCardQty($summary['planned_qty'] ?? null),
        'trend' => $plnTrend, 'trend_id' => 'statTrendIco-planned'],
    ['label' => 'Production', 'note' => 'Produced',    'icon' => 'precision_manufacturing','tone' => 'mint',   'value' => $fmtCardQty($summary['production_qty'] ?? null),
        'trend' => $prdTrend, 'trend_id' => 'statTrendIco-production'],
    ['label' => 'PO Qty',     'note' => 'Ordered',     'icon' => 'shopping_bag',           'tone' => 'amber',  'value' => $fmtCardQty($summary['po_qty'] ?? null),
        'trend' => $poTrend,  'trend_id' => 'statTrendIco-po'],
    ['label' => 'GRN Qty',    'note' => 'Received',    'icon' => 'inventory_2',            'tone' => 'violet', 'value' => $fmtCardQty($summary['grn_qty'] ?? null),
        'trend' => $grnTrend, 'trend_id' => 'statTrendIco-grn'],
    ['label' => 'Issue Qty',  'note' => 'Issued',      'icon' => 'output',                 'tone' => 'rose',   'value' => $fmtCardQty($summary['issue_qty'] ?? null),
        'trend' => $issTrend, 'trend_id' => 'statTrendIco-issue'],
];
$modeClass = $isLookup ? 'is-lookup is-first' : 'is-report';
?>
<div class="rpt-app <?= $isFabric ? 'is-fabric' : 'is-trims' ?> <?= $modeClass ?><?= $isEmbedded ? ' is-embedded' : '' ?>">
    <div class="rpt-boot" id="rptBoot" <?= $isLookup ? '' : 'hidden' ?>>
        <div class="rpt-boot-card">
            <div class="rpt-boot-brand"><img src="<?= e($logoUrl) ?>" alt="evolv"></div>
            <div class="rpt-spin" aria-hidden="true"></div>
            <p>Preparing Utilization Report…</p>
            <span class="rpt-boot-bar"><i></i></span>
        </div>
    </div>

    <?php
        $loaderIcon = $isFabric ? 'texture' : 'sell';
        $loaderSteps = ['Connecting to SAP…', 'Fetching ' . strtolower($kindLabel) . ' utilization…', 'Matching BOM, PO & GRN…', 'Preparing report…'];
        require base_path('app/views/partials/loader.php');
    ?>

    <?php if (!$isEmbedded): ?>
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
            <div class="rpt-name">
                <?= e($item['title']) ?>
            </div>
        </div>

        <?php if (!$isLookup): ?>
            <?php if ($showAllOptions): ?>
                <nav class="rpt-tabs" aria-label="Report type">
                    <a class="rpt-tab <?= $isFabric ? 'active' : '' ?>" href="<?= e(url('fabric') . '?mode=all' . ($salesOrder !== '' ? '&so=' . rawurlencode($salesOrder) : '')) ?>">Fabric</a>
                    <a class="rpt-tab <?= !$isFabric ? 'active' : '' ?>" href="<?= e(url('trims') . '?mode=all' . ($salesOrder !== '' ? '&so=' . rawurlencode($salesOrder) : '')) ?>">Trims</a>
                </nav>
            <?php else: ?>
                <!-- <div class="rpt-header-unit-badge <?= $isFabric ? 'is-fabric' : 'is-trims' ?>">
                    <i class="fas <?= $isFabric ? 'fa-scroll' : 'fa-tags' ?>"></i>
                    <span><?= $isFabric ? 'Fabric' : 'Trims' ?></span>
                </div> -->
            <?php endif; ?>

            <?php if (!$isFabric && $hasData): ?>
                <div class="rpt-cat-dropdown" id="rptCatDropdown">
                    <button type="button" class="rpt-cat-dd-trigger" id="rptCatDropdownBtn" aria-haspopup="true" aria-expanded="false">
                        <span class="material-icons-round rpt-cat-dd-icon">category</span>
                        <span class="rpt-cat-dd-label" id="rptCatSelectedLabel">Material Group: <b>All</b></span>
                        <span class="rpt-cat-dd-badge" id="rptCatSelectedCount"><?= count($records) ?></span>
                        <span class="material-icons-round rpt-cat-dd-arrow">expand_more</span>
                    </button>
                    <div class="rpt-cat-dd-menu" id="rptCatDropdownMenu" role="menu" style="max-height:420px; overflow-y:auto;">
                        <div class="rpt-cat-dd-header">Select Material Group</div>
                        <button type="button" class="rpt-cat-dd-item rpt-trim-pill rpt-trim-pill-all is-active" data-cat="" role="menuitem">
                            <span class="material-icons-round">apps</span>
                            <span class="rpt-cat-dd-item-text">All Material Groups</span>
                            <span class="rpt-cat-dd-item-count"><?= count($records) ?></span>
                        </button>
                        <div class="rpt-cat-dd-divider"></div>
                    </div>
                </div>
            <?php endif; ?>

            <form class="rpt-search" method="get" action="<?= e(url($report)) ?>" id="rptFilterForm"<?= $isFabric ? ' data-date-filter' : '' ?>>
                <input type="hidden" name="mode" value="<?= e($mode) ?>">
                <?php if ($isFabric): ?>
                    <label class="rpt-field rpt-field-range">
                        <span class="material-icons-round">date_range</span>
                        <select name="range" data-range-select aria-label="Date range">
                            <option value=""<?= $datePreset === '' ? ' selected' : '' ?>>Select Date Range…</option>
                            <?php foreach ($presets as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $key === $datePreset ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="rpt-field rpt-field-date" data-date-custom<?= $isCustom ? '' : ' hidden' ?>>
                        <span class="material-icons-round">calendar_today</span>
                        <input type="date" name="from" value="<?= e($isCustom ? ($_GET['from'] ?? '') : '') ?>" aria-label="From date">
                    </label>
                    <label class="rpt-field rpt-field-date" data-date-custom<?= $isCustom ? '' : ' hidden' ?>>
                        <span class="material-icons-round">event</span>
                        <input type="date" name="to" value="<?= e($isCustom ? ($_GET['to'] ?? '') : '') ?>" aria-label="To date">
                    </label>
                <?php endif; ?>
                <label class="rpt-field rpt-field-so">
                    <span class="material-icons-round">receipt_long</span>
                    <input id="so" name="so" value="<?= e($salesOrder) ?>" placeholder="Sales order" inputmode="numeric">
                </label>
                <?php if ($hasData): ?>
                    <a class="rpt-btn rpt-btn-ghost" href="<?= e(url($report) . '?' . $qs(['export' => 'csv', 'page' => 1])) ?>" title="Download CSV">
                        <span class="material-icons-round">file_download</span>
                    </a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>
    <?php endif; ?>

    <?php if ($loadError !== ''): ?>
        <div class="rpt-alert"><?= e($loadError) ?></div>
    <?php endif; ?>

    <?php if ($isLookup): ?>
        <main class="lookup">
            <div class="lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
            <div class="lookup-card">
                <p class="lookup-kicker"><?= $isFabric ? 'Fabric utilization' : 'Trims utilization' ?></p>
                <h1>Find a sales order</h1>
                <p class="lookup-lede">Pull BOM, production, PO, GRN, and issue quantities from SAP.</p>
                <?php if ($showAllOptions): ?>
                    <div class="lookup-switch">
                        <a class="<?= $isFabric ? 'on' : '' ?>" href="<?= e(url('fabric') . '?mode=all') ?>">Fabric</a>
                        <a class="<?= !$isFabric ? 'on' : '' ?>" href="<?= e(url('trims') . '?mode=all') ?>">Trims</a>
                    </div>
                <?php endif; ?>

                <form method="get" action="<?= e(url($report)) ?>" id="rptFilterForm" class="lookup-filters"<?= $isFabric ? ' data-date-filter' : '' ?> novalidate>
                    <input type="hidden" name="mode" value="<?= e($mode) ?>">

                    <?php if ($isFabric): ?>
                        <p class="lookup-label">Date range <span class="lookup-label-note">or sales order *</span></p>
                        <div class="lookup-range-wrap">
                            <label class="lookup-so lookup-range" title="Date Range">
                                <span class="material-icons-round">date_range</span>
                                <select name="range" data-range-select aria-label="Date Range">
                                    <option value=""<?= empty($datePreset) ? ' selected' : '' ?>>Select Date Range…</option>
                                    <?php foreach ($presets as $key => $label): ?>
                                        <option value="<?= e($key) ?>"<?= $key === $datePreset ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="date-custom" data-date-custom<?= $isCustom ? '' : ' hidden' ?>>
                            <label class="lookup-so date-input">
                                <span class="material-icons-round">calendar_today</span>
                                <input type="date" name="from" value="<?= e($_GET['from'] ?? ($presetDates[$datePreset]['from'] ?? '')) ?>" placeholder="From Date" aria-label="From Date">
                            </label>
                            <label class="lookup-so date-input">
                                <span class="material-icons-round">event</span>
                                <input type="date" name="to" value="<?= e($_GET['to'] ?? ($presetDates[$datePreset]['to'] ?? '')) ?>" placeholder="To Date" aria-label="To Date">
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="lookup-form"<?= $isFabric ? ' style="margin-top: 10px;"' : '' ?>>
                        <label class="lookup-so" title="Sales order number">
                            <span class="material-icons-round">receipt_long</span>
                            <input id="so" name="so" value="<?= e($salesOrder) ?>" placeholder="Sales order number" inputmode="numeric" autofocus autocomplete="off" required>
                        </label>
                    </div>

                    <button class="rpt-btn lookup-submit" type="submit" style="margin-top: 16px; width: 100%;">
                        <span class="material-icons-round">sync</span>
                        Show report
                    </button>
                </form>

            </div>
        </main>
    <?php elseif ($isOrdersList): ?>
        <main class="rpt-main orders-list-main" id="rptMain">
            <!-- First Row: Total Values Bento KPI Cards (Full Width with Modern Animations) -->
            <div class="orders-kpi-grid">
                <div class="orders-kpi-card tone-teal">
                    <div class="orders-kpi-icon"><span class="material-icons-round">inventory_2</span></div>
                    <div class="orders-kpi-info">
                        <div class="orders-kpi-label">Total Sales Orders</div>
                        <div class="orders-kpi-value"><?= number_format(count($rangeOrders['orders'] ?? [])) ?></div>
                    </div>
                </div>

                <div class="orders-kpi-card tone-purple">
                    <div class="orders-kpi-icon"><span class="material-icons-round">format_list_numbered</span></div>
                    <div class="orders-kpi-info">
                        <div class="orders-kpi-label">Total Items</div>
                        <div class="orders-kpi-value"><?= number_format((int) ($rangeOrders['line_count'] ?? 0)) ?></div>
                    </div>
                </div>

                <div class="orders-kpi-card tone-sky">
                    <div class="orders-kpi-icon"><span class="material-icons-round">shopping_bag</span></div>
                    <div class="orders-kpi-info">
                        <div class="orders-kpi-label">Total Order Quantity</div>
                        <div class="orders-kpi-value"><?= number_format((float) ($rangeOrders['total_qty'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>

            <!-- Second Row: Table View with Screenshot 2 Toolbar & Column Filters -->
            <div class="orders-table-card card">
                <div class="rpt-tb sales-qt-tb" id="ordersToolbar">
                    <div class="rpt-tb-search">
                        <i class="fas fa-search rpt-tb-icon"></i>
                        <input type="text" class="rpt-tb-input" id="ordersTableSearch" placeholder="Quick search quotation, customer, material..." autocomplete="off">
                        <button type="button" class="rpt-tb-clear" id="ordersSearchClear" style="display: none;" title="Clear search">&times;</button>
                    </div>
                    <div class="rpt-tb-actions">
                        <span class="rpt-tb-count" id="ordersTableCount">Showing <?= count($rangeOrders['orders'] ?? []) ?> of <?= count($rangeOrders['orders'] ?? []) ?> orders</span>
                        <button type="button" class="rpt-tb-btn is-active" id="ordersToggleStickyScroll" title="Keep the header fixed and scroll the rows">
                            <i class="fas fa-arrows-up-down"></i>
                            <span id="ordersStickyScrollLabel">Scroll Body Only</span>
                        </button>
                        <div class="rpt-cat-dropdown" id="ordersColumnToggleDropdown">
                            <button type="button" class="rpt-tb-btn" id="ordersColumnToggleBtn" title="Show or hide columns" aria-expanded="false" aria-haspopup="true">
                                <i class="fas fa-columns"></i>
                                <span>Columns</span>
                                <span class="material-icons-round rpt-cat-dd-arrow" style="font-size: 14px;">expand_more</span>
                            </button>
                            <div class="rpt-cat-dd-menu sales-col-menu" id="ordersColumnToggleMenu" role="menu"></div>
                        </div>
                        <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="soOrdersTable"
                                data-excel-title="<?= e($kindLabel . ' Utilization — Sales Orders') ?>"
                                data-excel-file="<?= e(strtolower($kindLabel) . '-sales-orders') ?>"
                                data-excel-sheet="Orders"
                                title="Download the visible columns as Excel">
                            <i class="fas fa-file-excel"></i>
                            <span>Excel</span>
                        </button>
                        <button type="button" class="rpt-tb-btn is-active" id="ordersBtnColFilter" aria-pressed="true" title="Show or hide a search box under each column">
                            <i class="fas fa-filter"></i>
                            <span>Column Filters</span>
                            <span class="rpt-tb-badge" id="ordersActiveFilterBadge" style="display: none;">0</span>
                        </button>
                        <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="ordersClearAllFilters" title="Clear all search, filters and sorting" style="display: none;">
                            <i class="fas fa-rotate-left"></i>
                            <span>Clear All</span>
                        </button>
                    </div>
                </div>

                <div class="table-responsive table-wrap sales-table-wrap is-scroll-body" id="ordersTableWrap">
                    <table class="sales-data-table rpt-table prod-table po-page-table text-nowrap show-col-filters" id="soOrdersTable">
                        <thead>
                            <tr class="sales-th-row rpt-header-row">
                                <th class="col-sno sno" data-col="0" style="width: 55px; text-align: center;">
                                    <div class="th-content"><span>S.No</span></div>
                                </th>
                                <th class="col-sortable is-sortable" data-col="1" data-sort="so" title="Sort by Sales Order">
                                    <div class="th-content"><span>Sales Order</span></div>
                                </th>
                                <th class="col-sortable is-sortable" data-col="2" data-sort="customer" title="Sort by Customer">
                                    <div class="th-content"><span>Customer</span></div>
                                </th>
                                <th class="col-sortable is-sortable" data-col="3" data-sort="material" title="Sort by Material / Style">
                                    <div class="th-content"><span>Material / Style</span></div>
                                </th>
                                <th class="col-sortable is-sortable" data-col="4" data-sort="date" title="Sort by Creation Date">
                                    <div class="th-content"><span>Creation Date</span></div>
                                </th>
                                <th class="col-sortable is-sortable" data-col="5" data-sort="plant" title="Sort by Plant">
                                    <div class="th-content"><span>Plant</span></div>
                                </th>
                                <th class="col-sortable is-sortable col-num num" data-col="6" data-sort="lines" style="width: 85px; text-align: right;" title="Sort by Lines">
                                    <div class="th-content num"><span>Lines</span></div>
                                </th>
                                <th class="col-sortable is-sortable col-num num" data-col="7" data-sort="qty" style="width: 120px; text-align: right;" title="Sort by Quantity">
                                    <div class="th-content num"><span>Quantity</span></div>
                                </th>
                            </tr>
                            <tr class="sales-col-filter-row rpt-filter-row" id="ordersFilterRow">
                                <th class="col-filter-th num sno" data-col="0" style="text-align: center;">
                                    <button type="button" class="rpt-col-filter-reset" id="ordersBtnClearColFilters" title="Clear all column filters" aria-label="Clear all column filters">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </th>
                                <th class="col-filter-th" data-col="1">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="1" placeholder="Filter…" title="Filter Sales Order">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="col-filter-th" data-col="2">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="2" placeholder="Filter…" title="Filter Customer">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="col-filter-th" data-col="3">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="3" placeholder="Filter…" title="Filter Material / Style">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="col-filter-th" data-col="4">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="4" placeholder="Filter…" title="Filter Creation Date">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="col-filter-th" data-col="5">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="5" placeholder="Filter…" title="Filter Plant">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="col-filter-th" data-col="6">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="6" placeholder=">0, <10…" title="Filter Lines">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="col-filter-th" data-col="7">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input col-filter-input" data-col="7" placeholder=">0, <10…" title="Filter Quantity">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rangeOrders['orders'])): ?>
                                <tr id="ordersEmptyRow">
                                    <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                        <span class="material-icons-round" style="font-size: 40px; color: #cbd5e1; display: block; margin-bottom: 8px;">event_busy</span>
                                        <strong>No sales orders found for this creation period.</strong>
                                        <p style="margin: 4px 0 0; font-size: 13px;">Try another date range.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rangeOrders['orders'] as $idx => $ord): ?>
                                    <tr data-so="<?= e($ord['sales_order']) ?>" tabindex="0" role="button" aria-label="Open sales order <?= e($ord['sales_order']) ?>">
                                        <td class="col-sno sno" data-col="0" style="text-align: center; font-weight: 600; color: #64748b;"><?= $idx + 1 ?></td>
                                        <td data-col="1">
                                            <span class="so-badge">
                                                <span class="material-icons-round">tag</span>
                                                <strong><?= e($ord['sales_order']) ?></strong>
                                            </span>
                                        </td>
                                        <td data-col="2" title="<?= e($ord['customer']) ?>"><?= e($ord['customer']) ?></td>
                                        <td data-col="3" title="<?= e($ord['material']) ?>"><code class="mat-code"><?= e($ord['material']) ?></code></td>
                                        <td data-col="4"><?= e($ord['creation_date']) ?></td>
                                        <td data-col="5"><span class="plant-pill"><?= e($ord['plant']) ?></span></td>
                                        <td data-col="6" style="text-align: right;"><span class="lines-pill"><?= e($ord['lines']) ?></span></td>
                                        <td data-col="7" style="text-align: right; font-weight: 700; color: #0f172a;"><?= number_format((float) $ord['quantity']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <!-- 90% Screen Offcanvas Canvas for Fabric Utilization -->
        <div id="soCanvas" class="so-canvas" hidden>
            <button type="button" class="so-canvas-backdrop" data-so-close aria-label="Close report"></button>
            <aside class="so-canvas-panel is-90" role="dialog" aria-modal="true" aria-labelledby="soCanvasTitle">
                <header class="so-canvas-head">
                    <button type="button" class="so-canvas-back" data-so-close aria-label="Back to sales orders">
                        <span class="material-icons-round">arrow_back</span>
                        <span>Back</span>
                    </button>
                    <h2 id="soCanvasTitle" class="so-canvas-title">Sales Order</h2>
                    <div class="so-canvas-actions">
                        <a class="so-canvas-full" id="soCanvasFull" href="#" target="_blank" rel="noopener" title="Open the full utilization report in a new tab">
                            <i class="fas fa-up-right-from-square"></i>
                            <span>Full report</span>
                        </a>
                    </div>
                </header>
                <iframe id="soCanvasFrame" title="Fabric utilization report"></iframe>
            </aside>
        </div>
    <?php else: ?>
        <main class="rpt-main">
            <div id="rptNoData" class="rpt-nodata"<?= $showNoData ? '' : ' hidden' ?> role="status" aria-live="polite">
                <div class="rpt-nodata-card">
                    <div class="rpt-nodata-orbits" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </div>
                    <div class="rpt-nodata-icon" aria-hidden="true">
                        <span class="material-icons-round">search_off</span>
                    </div>
                    <p class="rpt-nodata-kicker">No results</p>
                    <h2>Data not found</h2>
                    <p class="rpt-nodata-lede">
                        No <?= $isFabric ? 'fabric' : 'trims' ?> utilization for sales order
                        <strong id="rptNoDataSo"><?= e($salesOrder) ?></strong>.
                    </p>
                    <?php if (!$isEmbedded): ?>
                        <a class="rpt-nodata-btn" href="<?= e(url($report) . ($mode !== '' ? ('?mode=' . rawurlencode($mode)) : '')) ?>">
                            <span class="material-icons-round">arrow_back</span>
                            Try another sales order
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div id="rptReportBody" class="rpt-report-body"<?= $showNoData ? ' hidden' : '' ?>>
            <div class="rpt-stats">
                <?php foreach ($cards as $card): ?>
                    <?php
                        $extraAttrs = '';
                        if (!empty($card['data'])) {
                            foreach ($card['data'] as $attrKey => $attrVal) {
                                $extraAttrs .= ' ' . e($attrKey) . '="' . e((string)$attrVal) . '"';
                            }
                        }
                    ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>"<?= $extraAttrs ?>>
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap">
                                <b><?= e($card['value']) ?></b>
                                <?php if (!empty($card['trend'])): ?>
                                    <span class="card-trend-ico is-<?= e($card['trend']['status']) ?>" id="<?= e($card['trend_id'] ?? '') ?>" title="<?= e($card['label']) ?>: <?= e($card['trend']['label']) ?>">
                                        <i class="fas <?= e($card['trend']['icon']) ?>"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
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
                            <h2>Sale Order Quantity Utilization</h2>
                            <p>BOM, planned, production, PO, GRN, and issue</p>
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
                            <h2>Sales Order BOM Quantity By Material</h2>
                            <p>Share of BOM quantity</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut">
                        <canvas id="rptMixChart"></canvas>
                    </div>
                </section>
            </div>

            <?php if ($hasData): ?>
                <div class="rpt-table-toolbar">
                    <div class="rpt-tb-search">
                        <span class="material-icons-round rpt-search-ico">search</span>
                        <input type="search" id="rptTableSearch" class="rpt-tb-input" placeholder="Quick search table (Material, PO, Qty...)" autocomplete="off" spellcheck="false">
                        <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="rpt-tb-actions">
                        <span class="rpt-tb-count" id="rptTableCount">Showing <?= count($records) ?> of <?= count($records) ?> lines</span>

                        <div class="rpt-tb-legend" title="Compare column quantity against row BOM Qty">
                            <span class="tb-legend-item is-up" title="Met or exceeded BOM (≥ BOM)"><i class="fas fa-arrow-trend-up"></i> ≥ BOM</span>
                            <span class="tb-legend-item is-down" title="Below BOM (< BOM)"><i class="fas fa-arrow-trend-down"></i> &lt; BOM</span>
                        </div>

                        

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
                            <div class="rpt-cat-dd-menu" id="rptColumnToggleMenu" role="menu" style="right:0; left:auto; max-height:400px; overflow-y:auto;">
                                <!-- Checkboxes populated by JS -->
                            </div>
                        </div>

                        <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="rptDataTable"
                                data-excel-title="<?= e($kindLabel . ' Utilization — Sales Order ' . $salesOrder) ?>"
                                data-excel-file="<?= e(strtolower($kindLabel) . '-utilization-SO' . $salesOrder) ?>"
                                data-excel-sheet="<?= e($kindLabel . ' ' . $salesOrder) ?>"
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
            <?php endif; ?>

            <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
                <table class="rpt-table" id="rptDataTable" >
                    <thead>
                        <tr class="rpt-header-row">
                            <th class="num sno is-sortable" data-col="0" data-type="num" title="Click to sort by S.No">
                                <div class="th-content"><span>S.No</span></div>
                            </th>
                            <th class="is-sortable" data-col="1" data-type="text" title="Click to sort by Sales Order">
                                <div class="th-content"><span>Sales Order</span></div>
                            </th>
                            <th class="is-sortable" data-col="2" data-type="text" title="Click to sort by Material">
                                <div class="th-content"><span>Material</span></div>
                            </th>
                            <th class="is-sortable" data-col="3" data-type="text" title="Click to sort by Description">
                                <div class="th-content"><span>Description</span></div>
                            </th>

                            <?php if (!$isFabric): ?>
                            <th class="is-sortable" data-col="4" data-type="text" title="Click to sort by Material Type">
                                <div class="th-content"><span>MatType</span></div>
                            </th>
                            <th class="is-sortable" data-col="5" data-type="text" title="Click to sort by Material Type Description">
                                <div class="th-content"><span>MatTypeDesc</span></div>
                            </th>
                            <th class="is-sortable" data-col="6" data-type="text" title="Click to sort by Material Group">
                                <div class="th-content"><span>MatGroup</span></div>
                            </th>
                            <th class="is-sortable" data-col="7" data-type="text" title="Click to sort by Material Group Description">
                                <div class="th-content"><span>MatGroupDesc</span></div>
                            </th>
                            <th class="is-sortable" data-col="8" data-type="text" title="Click to sort by Season">
                                <div class="th-content"><span>Season</span></div>
                            </th>
                            <th class="is-sortable" data-col="9" data-type="text" title="Click to sort by Season Year">
                                <div class="th-content"><span>Season Year</span></div>
                            </th>
                            <?php else: ?>
                            <th class="is-sortable" data-col="4" data-type="text" title="Click to sort by Season">
                                <div class="th-content"><span>Season</span></div>
                            </th>
                            <th class="is-sortable" data-col="5" data-type="text" title="Click to sort by Season Year">
                                <div class="th-content"><span>Season Year</span></div>
                            </th>
                            <?php endif; ?>

                            <th class="is-sortable" data-col="<?= $isFabric ? '6' : '10' ?>" data-type="text" title="Click to sort by Purchase Order">
                                <div class="th-content"><span>Purchase Order</span></div>
                            </th>
                            <th class="is-sortable" data-col="<?= $isFabric ? '7' : '11' ?>" data-type="num" title="Click to sort by PO Line">
                                <div class="th-content"><span>PO Line</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '8' : '12' ?>" data-type="num" title="Click to sort by SO_QTY">
                                <div class="th-content num"><span>SO_QTY</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '9' : '13' ?>" data-type="num" title="Click to sort by BOM_QTY">
                                <div class="th-content num"><span>BOM_QTY</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '10' : '14' ?>" data-type="num" title="Click to sort by Total Add SO BOM Qty">
                                <div class="th-content num"><span>Total Add SO BOM Qty</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '11' : '15' ?>" data-type="num" title="Click to sort by Planned Qty">
                                <div class="th-content num"><span>Planned</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '12' : '16' ?>" data-type="num" title="Click to sort by Production Qty">
                                <div class="th-content num"><span>Production</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '13' : '17' ?>" data-type="num" title="Click to sort by PO_QTY">
                                <div class="th-content num"><span>PO_QTY</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '14' : '18' ?>" data-type="num" title="Click to sort by GRN_QTY">
                                <div class="th-content num"><span>GRN_QTY</span></div>
                            </th>
                            <th class="num is-sortable" data-col="<?= $isFabric ? '15' : '19' ?>" data-type="num" title="Click to sort by Issue Qty">
                                <div class="th-content num"><span>Issue Qty</span></div>
                            </th>
                            
                            <?php if (!$isFabric): ?>
                            <th class="is-sortable" data-col="20" data-type="text" title="Click to sort by Additional Sale Order">
                                <div class="th-content"><span>Additional Sale Order</span></div>
                            </th>
                            <th class="is-sortable" data-col="21" data-type="text" title="Click to sort by COLOR">
                                <div class="th-content"><span>COLOR</span></div>
                            </th>
                            <th class="is-sortable" data-col="22" data-type="text" title="Click to sort by SIZE1">
                                <div class="th-content"><span>SIZE1</span></div>
                            </th>
                            <th class="is-sortable" data-col="23" data-type="text" title="Click to sort by SIZE2">
                                <div class="th-content"><span>SIZE2</span></div>
                            </th>
                            <?php else: ?>
                            <th class="is-sortable" data-col="16" data-type="text" title="Click to sort by Additional Sale Order">
                                <div class="th-content"><span>Additional Sale Order</span></div>
                            </th>
                            <th class="is-sortable" data-col="17" data-type="text" title="Click to sort by Attribute 1">
                                <div class="th-content"><span>Attribute1_text</span></div>
                            </th>
                            <th class="is-sortable" data-col="18" data-type="text" title="Click to sort by Attribute 2">
                                <div class="th-content"><span>Attribute2_text</span></div>
                            </th>
                            <th class="is-sortable" data-col="19" data-type="text" title="Click to sort by Attribute 3">
                                <div class="th-content"><span>Attribute3_text</span></div>
                            </th>
                            <th class="is-sortable" data-col="20" data-type="text" title="Click to sort by Colour">
                                <div class="th-content"><span>Colour</span></div>
                            </th>
                            <?php endif; ?>
                        </tr>
                        <?php if ($hasData): ?>
                            <tr class="rpt-filter-row" id="rptFilterRow">
                                <th class="num sno">
                                    <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="1" placeholder="Filter SO..." title="Filter Sales Order">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="2" placeholder="Filter Material..." title="Filter Material">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="3" placeholder="Filter Desc..." title="Filter Description">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                
                                <?php if (!$isFabric): ?>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="4" placeholder="Filter Type..." title="Filter MatType">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="5" placeholder="Filter Type Desc..." title="Filter MatTypeDesc">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="6" placeholder="Filter Group..." title="Filter MatGroup">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="7" placeholder="Filter Group Desc..." title="Filter MatGroupDesc">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="8" placeholder="Filter Season..." title="Filter Season">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="9" placeholder="Filter Season Year..." title="Filter Season Year">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <?php else: ?>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="4" placeholder="Filter Season..." title="Filter Season">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="5" placeholder="Filter Season Year..." title="Filter Season Year">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <?php endif; ?>

                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="<?= $isFabric ? '6' : '10' ?>" placeholder="Filter PO..." title="Filter Purchase Order">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="<?= $isFabric ? '7' : '11' ?>" placeholder="PO Line..." title="Filter PO Line">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '8' : '12' ?>" data-numeric="true" placeholder="SO_QTY..." title="Filter SO_QTY">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '9' : '13' ?>" data-numeric="true" placeholder="BOM_QTY..." title="Filter BOM_QTY">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '10' : '14' ?>" data-numeric="true" placeholder="Total BOM..." title="Filter Total Add SO BOM Qty">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '11' : '15' ?>" data-numeric="true" placeholder="Planned..." title="Filter Planned Qty">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '12' : '16' ?>" data-numeric="true" placeholder="Prod..." title="Filter Production Qty">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '13' : '17' ?>" data-numeric="true" placeholder="PO_QTY..." title="Filter PO_QTY">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '14' : '18' ?>" data-numeric="true" placeholder="GRN_QTY..." title="Filter GRN_QTY">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="<?= $isFabric ? '15' : '19' ?>" data-numeric="true" placeholder="Issue..." title="Filter Issue Qty">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                
                                <?php if (!$isFabric): ?>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="20" placeholder="Filter SOs..." title="Filter Additional Sale Order">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="21" placeholder="COLOR..." title="Filter COLOR">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="22" placeholder="SIZE1..." title="Filter SIZE1">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="23" placeholder="SIZE2..." title="Filter SIZE2">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <?php else: ?>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="16" placeholder="Filter SOs..." title="Filter Additional Sale Order">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="17" placeholder="Attribute 1..." title="Filter Attribute 1">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="18" placeholder="Attribute 2..." title="Filter Attribute 2">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="19" placeholder="Attribute 3..." title="Filter Attribute 3">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="20" placeholder="Colour..." title="Filter Colour">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <?php endif; ?>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody id="rptTableBody">
                        <?php if ($awaitLive): ?>
                            <tr class="rpt-table-loading"><td colspan="<?= $isFabric ? 21 : 24 ?>" class="rpt-table-skeleton"><div class="rpt-skel-rows" aria-label="Loading live SAP data"><i></i><i></i><i></i><i></i><i></i><i></i></div></td></tr>
                            <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                                <td colspan="<?= $isFabric ? 21 : 24 ?>" class="rpt-table-empty">
                                    <div class="rpt-no-match-card">
                                        <span class="material-icons-round">filter_alt_off</span>
                                        <p>No records match the applied search or filter criteria.</p>
                                        <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                                    </div>
                                </td>
                            </tr>
                        <?php elseif ($records === []): ?>
                            <tr><td colspan="<?= $isFabric ? 21 : 24 ?>" class="rpt-table-empty"><?= e($emptyHint) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $i => $row): ?>
                                <?php
                                    $rowCat   = !$isFabric ? (string) ($row['category'] ?? '') : '';
                                    $rBom     = (float) str_replace(',', '', $row['bom_qty']        ?? 0);
                                    $rPlan    = (float) str_replace(',', '', $row['planned_qty']     ?? 0);
                                    $rProd    = (float) str_replace(',', '', $row['production_qty']  ?? 0);
                                    $rPo      = (float) str_replace(',', '', $row['po_qty']          ?? 0);
                                    $rGrn     = (float) str_replace(',', '', $row['grn_qty']         ?? 0);
                                    $rIss     = (float) str_replace(',', '', $row['issue_qty']       ?? 0);
                                ?>
                                <tr data-orig-sno="<?= (int) $i + 1 ?>" <?= $rowCat !== '' ? 'data-category="' . e($rowCat) . '"' : '' ?>
                                    data-r-bom="<?= $rBom ?>" data-r-plan="<?= $rPlan ?>" data-r-prod="<?= $rProd ?>" data-r-po="<?= $rPo ?>" data-r-grn="<?= $rGrn ?>" data-r-iss="<?= $rIss ?>">
                                    <td class="num sno"><?= (int) $i + 1 ?></td>
                                    
                                                                        <td>
                                        <?php $soNum = str_replace(',', '', trim((string) ($row['sales_order'] ?? ''))); ?>
                                        <?php if ($soNum !== '' && $soNum !== '-'): ?>
                                            <a href="<?= e(url($report) . '?so=' . rawurlencode($soNum)) ?>" class="so-main-link" title="Direct API call for Sales Order <?= e($soNum) ?>">
                                                <?= e($soNum) ?>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="rpt-mat" title="<?= e((string) ($row['material'] ?? '')) ?>"><?= e((string) ($row['material'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['description'] ?? '')) ?>"><?= e((string) ($row['description'] ?? '')) ?></td>
                                    
                                    <?php if (!$isFabric): ?>
                                    <td title="<?= e((string) ($row['mat_type'] ?? '')) ?>"><?= e((string) ($row['mat_type'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['mat_type_desc'] ?? '')) ?>"><?= e((string) ($row['mat_type_desc'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['mat_group'] ?? '')) ?>"><?= e((string) ($row['mat_group'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['mat_group_desc'] ?? '')) ?>"><?= e((string) ($row['mat_group_desc'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['season'] ?? '')) ?>"><?= e((string) ($row['season'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['season_year'] ?? '')) ?>"><?= e((string) ($row['season_year'] ?? '')) ?></td>
                                    <?php else: ?>
                                    <td title="<?= e((string) ($row['season'] ?? '')) ?>"><?= e((string) ($row['season'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['season_year'] ?? '')) ?>"><?= e((string) ($row['season_year'] ?? '')) ?></td>
                                    <?php endif; ?>

                                    <td>
                                        <?php $poNum = str_replace(',', '', $model->dash($row['purchase_order'] ?? '')); ?>
                                        <?php if (preg_match('/^\d{1,10}$/', $poNum)): ?>
                                            <button type="button" class="po-link" data-po-open="<?= e($poNum) ?>" data-po-item="<?= e(str_replace(',', '', (string) ($row['po_item'] ?? ''))) ?>" title="Show purchase order <?= e($poNum) ?> details"><?= e($poNum) ?></button>
                                        <?php else: ?>
                                            <?= e($poNum) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e(str_replace(',', '', $model->dash($row['po_item'] ?? ''))) ?>
                                    </td>
                                    <?php
                                        $soUnit = trim((string) ($row['so_unit'] ?? ''));
                                        $bomUnit = trim((string) ($row['bom_unit'] ?? ''));
                                        $poUnit = trim((string) ($row['po_unit'] ?? '')) ?: $bomUnit;
                                        $grnUnit = trim((string) ($row['grn_unit'] ?? '')) ?: $bomUnit;
                                        $giUnit = trim((string) ($row['gi_unit'] ?? '')) ?: $bomUnit;
                                        $poConvQty = (float) ($row['po_conv_qty'] ?? 0);
                                        $poConvUnit = trim((string) ($row['po_conv_unit'] ?? ''));
                                        $grnConvQty = (float) ($row['grn_conv_qty'] ?? 0);
                                        $grnConvUnit = trim((string) ($row['grn_conv_unit'] ?? ''));

                                        $soVal = str_replace(',', '', $model->dash($row['so_qty'] ?? ''));
                                        $bomVal = str_replace(',', '', $model->dash($row['bom_qty'] ?? ''));
                                        $totalBomVal = str_replace(',', '', $model->dash($row['total_bom_qty'] ?? ''));
                                        $planVal = str_replace(',', '', $model->dash($row['planned_qty'] ?? ''));
                                        $prodVal = str_replace(',', '', $model->dash($row['production_qty'] ?? ''));
                                        $poVal = str_replace(',', '', $model->dash($row['po_qty'] ?? ''));
                                        $poConvVal = str_replace(',', '', $model->dash($poConvQty));
                                        $grnVal = str_replace(',', '', $model->dash($row['grn_qty'] ?? ''));
                                        $grnConvVal = str_replace(',', '', $model->dash($grnConvQty));
                                        $issVal = str_replace(',', '', $model->dash($row['issue_qty'] ?? ''));

                                        $soHoverTitle = $soUnit !== '' ? 'SO_UNIT: ' . $soUnit : '';
                                        $bomHoverTitle = $bomUnit !== '' ? 'BOM_UNIT: ' . $bomUnit : '';

                                        $poTitleParts = [];
                                        if ($poConvVal !== '-' && $poConvVal !== '') {
                                            $poTitleParts[] = 'PO_Conv_QTY: ' . $poConvVal;
                                        }
                                        if ($poConvUnit !== '') {
                                            $poTitleParts[] = 'PO_Conv_Unit: ' . $poConvUnit;
                                        } elseif ($poUnit !== '') {
                                            $poTitleParts[] = 'PO_Conv_Unit: ' . $poUnit;
                                        }
                                        if ($poUnit !== '') {
                                            $poTitleParts[] = 'PO_UNIT: ' . $poUnit;
                                        }
                                        $poHoverTitle = implode('  ', $poTitleParts);

                                        $grnTitleParts = [];
                                        if ($grnUnit !== '') {
                                            $grnTitleParts[] = 'GRN_UNIT: ' . $grnUnit;
                                        }
                                        if ($grnConvVal !== '-' && $grnConvVal !== '') {
                                            $grnTitleParts[] = 'GRN_Conv_QTY: ' . $grnConvVal;
                                        }
                                        if ($grnConvUnit !== '') {
                                            $grnTitleParts[] = 'GRN_Conv_Unit: ' . $grnConvUnit;
                                        }
                                        $grnHoverTitle = implode('  ', $grnTitleParts);

                                        $issHoverTitle = $giUnit !== '' ? 'GI_UNIT: ' . $giUnit : '';
                                    ?>
                                    <td class="num qty-cell" data-qty-type="so" data-hover-type="so" data-qty="<?= e($soVal) ?>" data-unit="<?= e($soUnit) ?>">
                                        <span class="qty-val"><?= e($soVal) ?></span>
                                    </td>
                                    <td class="num bom-qty-cell" data-hover-type="bom" data-qty="<?= e($bomVal) ?>" data-unit="<?= e($bomUnit) ?>">
                                        <span class="bom-val"><?= e($bomVal) ?></span>
                                    </td>
                                    <td class="num bom-qty-cell total-bom-cell" data-hover-type="total_bom" data-qty="<?= e($totalBomVal) ?>" data-unit="<?= e($bomUnit) ?>">
                                        <span class="bom-val"><?= e($totalBomVal) ?></span>
                                    </td>
                                    <td class="num qty-cell" data-qty-type="plan" data-hover-type="plan" data-qty="<?= e($planVal) ?>" data-unit="<?= e($bomUnit) ?>">
                                        <span class="qty-val"><?= e($planVal) ?></span>
                                    </td>
                                    <td class="num qty-cell" data-qty-type="prod" data-hover-type="prod" data-qty="<?= e($prodVal) ?>" data-unit="<?= e($bomUnit) ?>">
                                        <span class="qty-val"><?= e($prodVal) ?></span>
                                    </td>
                                    <td class="num qty-cell" data-qty-type="po" data-hover-type="po" data-qty="<?= e($poVal) ?>" data-unit="<?= e($poUnit) ?>" data-conv-qty="<?= e($poConvVal) ?>" data-conv-unit="<?= e($poConvUnit) ?>" data-base-unit="<?= e($bomUnit) ?>">
                                        <span class="qty-val"><?= e($poVal) ?></span>
                                    </td>
                                    <td class="num qty-cell" data-qty-type="grn" data-hover-type="grn" data-qty="<?= e($grnVal) ?>" data-unit="<?= e($grnUnit) ?>" data-conv-qty="<?= e($grnConvVal) ?>" data-conv-unit="<?= e($grnConvUnit) ?>" data-base-unit="<?= e($bomUnit) ?>">
                                        <span class="qty-val"><?= e($grnVal) ?></span>
                                    </td>
                                    <td class="num qty-cell" data-qty-type="issue" data-hover-type="issue" data-qty="<?= e($issVal) ?>" data-unit="<?= e($giUnit) ?>">
                                        <span class="qty-val"><?= e($issVal) ?></span>
                                    </td>
                                    
                                    <td class="grn-sos">
                                        <?php
                                        $soQtyList = $row['grn_so_qty'] ?? array_map(fn ($so) => ['so' => $so, 'qty' => null], $row['grn_so_list'] ?? []);
                                        ?>
                                        <?php if ($soQtyList === []): ?>
                                            <span class="grn-so-empty">-</span>
                                        <?php else: ?>
                                            <div class="grn-so-tags">
                                                <?php foreach ($soQtyList as $soEntry): ?>
                                                    <?php
                                                    $soClean = str_replace(',', '', trim((string) ($soEntry['so'] ?? '')));
                                                    if ($soClean === '') continue;
                                                    $soQtyText = ($soEntry['qty'] ?? null) === null ? '' : str_replace(',', '', $model->dash($soEntry['qty']));
                                                    ?>
                                                    <a href="<?= e(url($report) . '?so=' . rawurlencode($soClean)) ?>"
                                                       class="so-chip"
                                                       data-export="<?= e($soQtyText === '' ? $soClean : $soClean . ' (' . $soQtyText . ')') ?>"
                                                       title="Direct API call for Sales Order <?= e($soClean) ?><?= $soQtyText !== '' ? e(' · Qty ' . $soQtyText) : '' ?>">
                                                        <?= e($soClean) ?><?php if ($soQtyText !== ''): ?><span class="so-chip-qty"><?= e($soQtyText) ?></span><?php endif; ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isFabric): ?>
                                    <td title="<?= e((string) ($row['colour'] ?? $row['color'] ?? '')) ?>"><?= e((string) ($row['colour'] ?? $row['color'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['size1'] ?? '')) ?>"><?= e((string) ($row['size1'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['size2'] ?? '')) ?>"><?= e((string) ($row['size2'] ?? '')) ?></td>
                                    <?php else: ?>
                                    <td title="<?= e((string) ($row['attribute1_text'] ?? '')) ?>"><?= e((string) ($row['attribute1_text'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['attribute2_text'] ?? '')) ?>"><?= e((string) ($row['attribute2_text'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['attribute3_text'] ?? '')) ?>"><?= e((string) ($row['attribute3_text'] ?? '')) ?></td>
                                    <td title="<?= e((string) ($row['colour'] ?? '')) ?>"><?= e((string) ($row['colour'] ?? '')) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                                <td colspan="<?= $isFabric ? 21 : 24 ?>" class="rpt-table-empty">
                                    <div class="rpt-no-match-card">
                                        <span class="material-icons-round">filter_alt_off</span>
                                        <p>No records match the applied search or filter criteria.</p>
                                        <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($hasData): ?>
                        <tfoot>
                            <tr id="rptTableFoot">
                                <th id="footTotalLabel">Total (<?= e($model->num($total)) ?> lines)</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <?php if (!$isFabric): ?>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <?php else: ?>
                                <th></th>
                                <th></th>
                                <?php endif; ?>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th class="num" id="footBomQty"><span class="foot-val"><?= e($model->footTotal($summary['bom_qty'] ?? null)) ?></span></th>
                                <th class="num" id="footTotalBomQty"><span class="foot-val"><?= e($model->footTotal($summary['total_bom_qty'] ?? null)) ?></span></th>
                                <th class="num foot-qty-cell" id="footPlannedQty">
                                    <span class="foot-val"><?= e($model->footTotal($summary['planned_qty'] ?? null)) ?></span>
                                    <?php if ($bomQtyRaw > 0): ?><span class="foot-trend is-<?= $plnTrend['status'] ?>" title="Total Planned vs BOM (<?= $plnTrend['label'] ?>)"><i class="fas <?= $plnTrend['icon'] ?>"></i></span><?php endif; ?>
                                </th>
                                <th class="num foot-qty-cell" id="footProductionQty">
                                    <span class="foot-val"><?= e($model->footTotal($summary['production_qty'] ?? null)) ?></span>
                                    <?php if ($bomQtyRaw > 0): ?><span class="foot-trend is-<?= $prdTrend['status'] ?>" title="Total Production vs BOM (<?= $prdTrend['label'] ?>)"><i class="fas <?= $prdTrend['icon'] ?>"></i></span><?php endif; ?>
                                </th>
                                <th class="num foot-qty-cell" id="footPoQty">
                                    <span class="foot-val"><?= e($model->footTotal($summary['po_qty'] ?? null)) ?></span>
                                    <?php if ($bomQtyRaw > 0): ?><span class="foot-trend is-<?= $poTrend['status'] ?>" title="Total PO Qty vs BOM (<?= $poTrend['label'] ?>)"><i class="fas <?= $poTrend['icon'] ?>"></i></span><?php endif; ?>
                                </th>
                                <th class="num foot-qty-cell" id="footGrnQty">
                                    <span class="foot-val"><?= e($model->footTotal($summary['grn_qty'] ?? null)) ?></span>
                                    <?php if ($bomQtyRaw > 0): ?><span class="foot-trend is-<?= $grnTrend['status'] ?>" title="Total GRN Qty vs BOM (<?= $grnTrend['label'] ?>)"><i class="fas <?= $grnTrend['icon'] ?>"></i></span><?php endif; ?>
                                </th>
                                <th class="num foot-qty-cell" id="footIssueQty">
                                    <span class="foot-val"><?= e($model->footTotal($summary['issue_qty'] ?? null)) ?></span>
                                    <?php if ($bomQtyRaw > 0): ?><span class="foot-trend is-<?= $issTrend['status'] ?>" title="Total Issue Qty vs BOM (<?= $issTrend['label'] ?>)"><i class="fas <?= $issTrend['icon'] ?>"></i></span><?php endif; ?>
                                </th>
                                <th></th>
                                <?php if (!$isFabric): ?>
                                <th></th>
                                <th></th>
                                <th></th>
                                <?php elseif ($isFabric): ?>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            </div>
        </main>
    <?php endif; ?>

    <?php if (!$isEmbedded) { require base_path('app/views/partials/footer.php'); } ?>
</div>
<script type="application/json" id="rptChartData"><?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="rptPageConfig"><?= json_encode([
    'report'      => $report,
    'isFabric'    => $isFabric,
    'so'          => $salesOrder,
    'mode'        => $mode,
    'search'      => $search,
    'live'        => $awaitLive,
    'dataUrl'     => url($report . '/data'),
    'ordersUrl'   => url($report . '/orders'),
    'pageUrl'     => url($report),
    'presetDates' => $presetDates ?? [],
    'isOrdersList'=> $isOrdersList,
    'catIcons'    => $trimCatIcons ?? [],
    'catColors'   => $trimCatColors ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="poConfig"><?= json_encode([
    'dataUrl' => url('purchase-order/data'),
    'pageUrl' => url('purchase-order'),
], JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<script src="<?= e(asset('js/po-report.js')) ?>"></script>
<?php $prefTableKey = $isFabric ? 'fabric_utilization_table' : 'trims_utilization_table'; require base_path('app/views/partials/table_preferences.php'); ?>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/utilization.js')) ?>"></script>
