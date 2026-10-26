<?php
/**
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 21/09/2026
 * DESCRIPTION : Sales hub — colorful charts, loader, and detail dashboards
 * 
*/
$currencySymbol = $currencySymbol ?? '$';
$year = (int) ($year ?? date('Y'));
$sapError = $sapError ?? null;
$dataSource = $dataSource ?? 'static';
$catalogHome = url('/');
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();

$extraHead = '<link rel="stylesheet" href="' . e(asset('css/sales.css')) . '">'
    . '<link rel="stylesheet" href="' . e(asset('css/sales-dashboard.css')) . '?v=' . time() . '">';

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.SALES_PAGE = {
    apiUrl: <?= json_encode(url('sales/data')) ?>,
    procurementUrl: <?= json_encode(url('sales/procurement')) ?>,
    procurementPrUrl: <?= json_encode(url('sales/procurement-pr')) ?>,
    bomUrl: <?= json_encode(url('sales/bom')) ?>,
    materialApiUrl: <?= json_encode(url('sales/material-api')) ?>,
    salesApiHubUrl: <?= json_encode(url('sales/sales-api')) ?>,
    currencySymbol: <?= json_encode($currencySymbol) ?>,
    year: <?= $year ?>,
    defaultPeriod: 'this_week',
    defaultFrom: <?= json_encode($defaultFrom ?? '') ?>,
    defaultTo: <?= json_encode($defaultTo ?? '') ?>,
    defaultLabel: 'This Week',
    pageSize: 8000
};
</script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/sales.js')) ?>?v=<?= time() ?>"></script>
<?php
$extraScripts = ob_get_clean();
?>

<div class="rpt-app is-sales-page">
    <!-- Ambient mesh background with animated floating glow orbs -->
    <div class="hub-ambient-bg" aria-hidden="true">
        <span class="hub-glow-orb orb-1"></span>
        <span class="hub-glow-orb orb-2"></span>
        <span class="hub-glow-orb orb-3"></span>
    </div>

    <header class="rpt-bar">
        <div class="rpt-bar-left">
            <a href="<?= e($catalogHome) ?>" class="hub-brand" aria-label="EVOLV" title="SAP Reports Analytics Hub">
                <img src="<?= e($logoUrl) ?>" alt="evolv" class="hub-brand-logo">
                <div class="hub-brand-text">
                    <span class="hub-brand-title">Sales Report</span>
                </div>
            </a>
            
            <div class="hub-quick-nav">
                <a href="<?= e($catalogHome) ?>" class="hub-quick-icon-btn is-active" aria-label="All Reports" title="Reports Catalog">
                    <span class="material-icons-round">apps</span>
                </a>
                <a href="<?= e($home) ?>" class="hub-quick-icon-btn" aria-label="Portal home" title="Portal Dashboard">
                    <span class="material-icons-round">home</span>
                </a>
            </div>
        </div>

        <div class="rpt-bar-center">
            <div class="header-so-search" id="headerSoSearch">
                <div class="header-so-search-wrap" id="headerSoSearchWrap">
                    <span class="material-icons-round header-search-ico">search</span>
                    <input type="search" id="headerSoSearchInput" placeholder="Search quotation or sales order…" autocomplete="off" aria-label="Search quotation or sales order">
                    <kbd class="header-search-kbd" title="Press / to focus search">/</kbd>
                    <button type="button" class="header-search-clear" id="headerSoSearchClear" hidden aria-label="Clear sales order search">
                        <span class="material-icons-round">close</span>
                    </button>
                    <button type="button" class="header-search-btn" id="btnHeaderSoSearch" title="Search sales order">
                        <span class="material-icons-round">arrow_forward</span>
                    </button>
                </div>
                <div class="header-so-autocomplete" id="headerSoAutocomplete" hidden></div>
            </div>

            <div class="period-filter" id="periodFilter">
                <button type="button" class="period-trigger" id="periodTrigger" aria-haspopup="listbox" aria-expanded="false" title="Filter by date condition">
                    <span class="material-icons-round">calendar_today</span>
                    <span id="periodTriggerLabel"><?= e($periodLabel ?? 'This Week') ?></span>
                    <span class="material-icons-round chev">expand_more</span>
                </button>
                <div class="period-menu" id="periodMenu" role="listbox" hidden>
                    <button type="button" class="period-option" data-period="today">Today</button>
                    <button type="button" class="period-option" data-period="yesterday">Yesterday</button>
                    <button type="button" class="period-option active" data-period="this_week">This Week</button>
                    <button type="button" class="period-option" data-period="prev_week">Previous Week</button>
                    <button type="button" class="period-option" data-period="this_month">This Month</button>
                    <button type="button" class="period-option" data-period="prev_month">Previous Month</button>
                    <button type="button" class="period-option" data-period="custom">Custom Date</button>
                </div>
            </div>
        </div>

        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <div class="custom-date-modal" id="customDateModal" hidden>
        <div class="custom-date-card">
            <div class="custom-date-head">
                <div class="custom-date-icon">
                    <span class="material-icons-round">date_range</span>
                </div>
                <div>
                    <h3>Custom Date Range</h3>
                    <p class="custom-date-hint">Choose a quotation date range of at most 6 months.</p>
                </div>
            </div>
            <div class="custom-date-fields">
                <div class="md-field">
                    <label for="headerDateFrom">From Date</label>
                    <input type="date" id="headerDateFrom">
                </div>
                <div class="md-field">
                    <label for="headerDateTo">To Date</label>
                    <input type="date" id="headerDateTo">
                </div>
            </div>
            <p class="custom-date-error" id="customDateError" hidden></p>
            <div class="custom-date-actions">
                <button type="button" class="md-btn md-btn-ghost" id="customDateCancel">Cancel</button>
                <button type="button" class="md-btn md-btn-filled ripple-btn" id="customDateApply">Apply Range</button>
            </div>
        </div>
    </div>

    <div class="kpi-modal" id="kpiModal" hidden>
        <div class="kpi-modal-card">
            <div class="kpi-modal-head">
                <div class="kpi-modal-icon" id="kpiModalIcon">
                    <span class="material-icons-round" id="kpiModalIconName">insights</span>
                </div>
                <div>
                    <p class="kpi-modal-eyebrow">KPI details</p>
                    <h3 id="kpiModalTitle">—</h3>
                </div>
                <button type="button" class="icon-btn" id="kpiModalClose" aria-label="Close details">
                    <span class="material-icons-round">close</span>
                </button>
            </div>
            <div class="kpi-modal-hero">
                <div class="label" id="kpiModalLabel">Value</div>
                <div class="value" id="kpiModalValue">—</div>
                <p id="kpiModalNote"></p>
            </div>
            <div class="kpi-modal-grid" id="kpiModalBody"></div>
        </div>
    </div>

<!-- Sales Hub Main Workspace -->
<section class="sales-hub is-loading" id="salesHub">
    <?php
    $loaderIcon = 'request_quote';
    $loaderVisible = true;
    $loaderSteps = ['Connecting to SAP…', 'Fetching quotations…', 'Preparing the table…'];
    require base_path('app/views/partials/loader.php');
    ?>
    <script>
    (function () {
        document.documentElement.classList.add('sales-is-loading');
        document.body && document.body.classList.add('sales-is-loading');
        var splash = document.getElementById('kapisSplash');
        if (splash) {
            splash.classList.add('is-done');
            splash.setAttribute('hidden', '');
        }
    })();
    </script>

    <?php if (!empty($sapError)): ?>
    <div class="sap-banner warn" style="display:flex">
        <span class="material-icons-round">cloud_off</span>
        <div>
            <strong>SAP Connection Unavailable</strong>
            <span><?= e((string) $sapError) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Active Filter Banner (shows when filtered by Quotation or Sales Order) -->
    <div class="sales-active-filter-banner" id="salesActiveFilterBanner" style="display: none;">
        <div class="filter-banner-content">
            <span class="material-icons-round filter-banner-icon">filter_alt</span>
            <span class="filter-banner-text" id="salesActiveFilterText">Filtered by quotation / sales order</span>
        </div>
        <button type="button" class="btn-clear-active-filter" id="salesActiveFilterClear" title="Clear filter">
            <span class="material-icons-round">close</span>
            <span>Clear Filter</span>
        </button>
    </div>

    <!-- 3 Hero Executive Bento KPI Cards -->
    <div class="kpi-grid sales-kpi-grid">
        <!-- 1. Net Sales Value -->
        <div class="kpi-card proj-card tone-sky" role="button" tabindex="0" data-kpi="net" title="Total Net Sales Value (Click for breakdown)">
            <div class="kpi-medallion-wrap">
                <div class="kpi-icon"><span class="material-icons-round">payments</span></div>
            </div>
            <div class="kpi-info">
                <div class="kpi-kicker">
                    <span class="kpi-kicker-dot"></span>
                    <span>LIVE SAP • VALUE</span>
                </div>
                <div class="label">Quotation Net Value</div>
                <div class="kpi-value-row">
                    <div class="value" id="skNet">—</div>
                </div>
                <div class="change" id="skNetNote">Quotation net value</div>
            </div>
            <div class="kpi-spark" id="skNetSpark" aria-hidden="true">
                <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
            </div>
        </div>

        <!-- 2. Order Quantity -->
        <div class="kpi-card proj-card tone-mint" role="button" tabindex="0" data-kpi="qty" title="Total Quoted Quantity (Click for breakdown)">
            <div class="kpi-medallion-wrap">
                <div class="kpi-icon"><span class="material-icons-round">inventory_2</span></div>
            </div>
            <div class="kpi-info">
                <div class="kpi-kicker">
                    <span class="kpi-kicker-dot"></span>
                    <span>AGGREGATE • DEMAND</span>
                </div>
                <div class="label">Quotation Quantity</div>
                <div class="kpi-value-row">
                    <div class="value" id="skQty">—</div>
                </div>
                <div class="change">Total quoted pieces</div>
            </div>
            <div class="kpi-spark" id="skQtySpark" aria-hidden="true">
                <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
            </div>
        </div>

        <!-- 3. Total Quotations -->
        <div class="kpi-card proj-card tone-amber" role="button" tabindex="0" data-kpi="orders" title="Total Quotations (Click to search in table)">
            <div class="kpi-medallion-wrap">
                <div class="kpi-icon"><span class="material-icons-round">request_quote</span></div>
            </div>
            <div class="kpi-info">
                <div class="kpi-kicker">
                    <span class="kpi-kicker-dot"></span>
                    <span>ACTIVE PIPELINE • QUOTATIONS</span>
                </div>
                <div class="label">Total Quotations</div>
                <div class="kpi-value-row">
                    <div class="value" id="skOrders">—</div>
                    <span class="kpi-sub-pill" id="skLinesPill">0 Items</span>
                </div>
                <div class="change" id="skOrdersSub">0 Quoted Line Items</div>
            </div>
            <div class="kpi-spark" id="skOrdersSpark" aria-hidden="true">
                <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
            </div>
        </div>

    </div>

    <!-- Charts Section: Trend / Item Breakdown & Category / Customer / Material Mix -->
    <div class="sales-charts-main" id="salesChartsMain">
        <!-- 1. Left Chart: Trend / Items Breakdown -->
        <div class="card chart-panel sales-panel-sky">
            <div class="chart-panel-head">
                <div class="chart-head-left">
                    <span class="chart-panel-icon"><span class="material-icons-round" id="trendChartIcon">show_chart</span></span>
                    <div>
                        <h2 id="trendChartTitle">Quotation Value Trend</h2>
                        <p id="trendChartSubtitle">Net value aggregated by quotation date</p>
                    </div>
                </div>
                <div class="chart-header-actions">
                    <div class="chart-tab-group" role="tablist" aria-label="Trend Mode">
                        <button type="button" class="chart-tab-btn active" id="btnChartModeTrend" title="Show Quotation Value Trend by Date">
                            <span class="material-icons-round">show_chart</span>
                            <span>Trend</span>
                        </button>
                        <button type="button" class="chart-tab-btn" id="btnChartModeItems" title="Show Net Value & Quantity per Line Item">
                            <span class="material-icons-round">bar_chart</span>
                            <span>Items</span>
                        </button>
                    </div>
                    <div class="chart-header-badge trend-badge" id="trendBadge">
                        <span class="badge-pulse-dot"></span>
                        <span class="material-icons-round">trending_up</span>
                        <span id="trendPeakStat">Daily Activity</span>
                    </div>
                </div>
            </div>
            <div class="chart-wrap tall"><canvas id="salesTrendChart"></canvas></div>
        </div>

        <!-- 2. Right Chart: Category / Customer / Material Mix -->
        <div class="card chart-panel sales-panel-violet">
            <div class="chart-panel-head">
                <div class="chart-head-left">
                    <span class="chart-panel-icon"><span class="material-icons-round">donut_large</span></span>
                    <div>
                        <h2 id="salesMixTitle">Item Category Mix</h2>
                        <p id="salesMixSubtitle">Net quoted amount by category</p>
                    </div>
                </div>
                <div class="chart-header-actions">
                    <div class="chart-tab-group" role="tablist" aria-label="Mix Mode">
                        <button type="button" class="chart-tab-btn active" id="btnMixModeCategory" title="Group by Item Category">
                            <span class="material-icons-round">category</span>
                            <span>Category</span>
                        </button>
                        <button type="button" class="chart-tab-btn" id="btnMixModeCustomer" title="Group by Customer">
                            <span class="material-icons-round">groups</span>
                            <span>Customer</span>
                        </button>
                        <button type="button" class="chart-tab-btn" id="btnMixModeMaterial" title="Group by Material">
                            <span class="material-icons-round">inventory_2</span>
                            <span>Material</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="chart-wrap tall chart-wrap-donut">
                <canvas id="salesDivisionChart"></canvas>
                <div class="doughnut-center-badge" id="plantMixCenterBadge" aria-hidden="true">
                    <span class="doughnut-center-val" id="plantMixCenterVal">—</span>
                    <span class="doughnut-center-lbl" id="plantMixCenterLbl">Distribution</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quotations Master Table Shell -->
    <div class="sales-table-shell card proj-panel" id="salesTableShell">
        <div class="rpt-table-toolbar sales-qt-toolbar">
            <div class="rpt-tb-search">
                <i class="fas fa-search rpt-search-ico"></i>
                <input type="search" id="salesSearch" class="rpt-tb-input" placeholder="Quick search quotation, customer, material…" autocomplete="off" spellcheck="false" aria-label="Search quotations in table">
                <button type="button" id="salesSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="salesRangeInfo">Showing 0 of 0 quotations</span>
                <span class="sales-count-num" id="salesTableCount" hidden>—</span>
                <button type="button" class="rpt-tb-btn is-active" id="salesToggleStickyScroll" title="Keep the header fixed and scroll the rows">
                    <i class="fas fa-arrows-up-down"></i>
                    <span id="salesStickyScrollLabel">Scroll Body Only</span>
                </button>
                <div class="rpt-cat-dropdown" id="salesColumnToggleDropdown">
                    <button type="button" class="rpt-tb-btn" id="salesColumnToggleBtn" title="Show or hide columns" aria-expanded="false" aria-haspopup="true">
                        <i class="fas fa-columns"></i>
                        <span>Columns</span>
                        <span class="material-icons-round rpt-cat-dd-arrow" style="font-size: 14px;">expand_more</span>
                    </button>
                    <div class="rpt-cat-dd-menu sales-col-menu" id="salesColumnToggleMenu" role="menu"></div>
                </div>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="salesDataTable" data-excel-title="Quotations" data-excel-file="quotations" data-excel-sheet="Quotations" title="Download the visible columns as Excel">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn is-active" id="btnSalesColFilter" aria-pressed="true" title="Show or hide a search box under each column">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                    <span class="rpt-tb-badge" id="salesActiveFilterBadge" style="display: none;">0</span>
                </button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="salesClearAllFilters" title="Clear all search, filters and sorting" style="display: none;">
                    <i class="fas fa-rotate-left"></i>
                    <span>Clear All</span>
                </button>
            </div>
        </div>
        <div class="table-responsive table-wrap sales-table-wrap is-scroll-body" id="salesTableWrap">
            <table class="sales-data-table rpt-table prod-table po-page-table text-nowrap show-col-filters" id="salesDataTable">
                <thead>
                    <tr class="sales-th-row rpt-header-row">
                        <th class="col-sno sno" data-col-id="sno" style="width: 55px; text-align: center;">
                            <div class="th-content"><span>S.No</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="quotation" data-sort="quotation" title="Sort by Quotation">
                            <div class="th-content"><span>Quotation</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="line_item" data-sort="line_item" title="Sort by Item">
                            <div class="th-content"><span>Item</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="material" data-sort="material" title="Sort by Material">
                            <div class="th-content"><span>Material</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="item_category" data-sort="item_category" title="Sort by Category">
                            <div class="th-content"><span>Category</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="customer" data-sort="customer" title="Sort by Customer">
                            <div class="th-content"><span>Customer</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="customer_ref" data-sort="customer_ref" title="Sort by Customer Reference">
                            <div class="th-content"><span>Customer Reference</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="date" data-sort="date" title="Sort by Quotation Date">
                            <div class="th-content"><span>Quotation Date</span></div>
                        </th>
                        <th class="col-sortable is-sortable col-num num" data-col-id="qty" data-sort="qty" title="Sort by Quantity">
                            <div class="th-content num"><span>Quantity</span></div>
                        </th>
                        <th class="col-sortable is-sortable col-num num" data-col-id="net_amount" data-sort="net_amount" title="Sort by Net Value">
                            <div class="th-content num"><span>Net Value</span></div>
                        </th>
                        <th class="col-sortable is-sortable" data-col-id="sales_order" data-sort="sales_order" title="Sort by Sales Order">
                            <div class="th-content"><span>Sales Order</span></div>
                        </th>
                    </tr>
                    <tr class="sales-col-filter-row rpt-filter-row" id="salesFilterRow">
                        <th class="col-filter-th num sno" data-col-id="sno" style="text-align:center;">
                            <button type="button" class="rpt-col-filter-reset" id="btnClearColFilters" title="Clear all column filters" aria-label="Clear all column filters">
                                <i class="fas fa-eraser"></i>
                            </button>
                        </th>
                        <th class="col-filter-th" data-col-id="quotation">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="quotation" placeholder="Filter…" title="Filter Quotation">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="line_item">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="line_item" placeholder="Filter…" title="Filter Item">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="material">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="material" placeholder="Filter…" title="Filter Material">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="item_category">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="item_category" placeholder="Filter…" title="Filter Category">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="customer">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="customer" placeholder="Filter…" title="Filter Customer">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="customer_ref">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="customer_ref" placeholder="Filter…" title="Filter Customer Reference">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="date">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="date" placeholder="Filter…" title="Filter Quotation Date">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th num" data-col-id="qty">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="qty" data-numeric placeholder=">0, <10…" title="Filter Quantity">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th num" data-col-id="net_amount">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="net_amount" data-numeric placeholder=">0, <10…" title="Filter Net Value">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                        <th class="col-filter-th" data-col-id="sales_order">
                            <div class="rpt-col-input-wrap">
                                <input type="text" class="rpt-col-input col-filter-input" data-filter-col="sales_order" placeholder="Filter…" title="Filter Sales Order">
                                <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody id="salesTableBody">
                    <tr><td colspan="11" class="empty-state">Loading…</td></tr>
                </tbody>
                <tfoot>
                    <tr id="salesTableFoot">
                        <th id="salesFootLabel" data-col-id="sno">Total</th>
                        <th data-col-id="quotation"></th>
                        <th data-col-id="line_item"></th>
                        <th data-col-id="material"></th>
                        <th data-col-id="item_category"></th>
                        <th data-col-id="customer"></th>
                        <th data-col-id="customer_ref"></th>
                        <th data-col-id="date"></th>
                        <th id="salesFootQty" class="num" data-col-id="qty"></th>
                        <th id="salesFootNet" class="num" data-col-id="net_amount"></th>
                        <th data-col-id="sales_order"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<!-- Sales Order Detail Drawer -->
<div class="sales-detail-backdrop" id="salesDetailBackdrop" hidden></div>
<aside class="sales-detail-drawer so-sheet" id="salesDetailDrawer" aria-hidden="true" role="dialog" aria-labelledby="salesDetailTitle">
    <header class="so-qt-head">
        <div class="so-head-title-wrap">
            <button type="button" class="btn-subdrawer-back so-dash-back" id="salesDetailClose" aria-label="Back to quotations" title="Back to Quotations">
                <span class="material-icons-round">arrow_back</span>
                <span>Back</span>
            </button>
            <div class="so-head-titles">
                <div class="so-head-eyebrow">
                    <span class="so-live-dot"></span>
                    <span>SAP Sales Order Intelligence</span>
                </div>
                <h2 id="salesDetailTitle" class="so-head-h2">Sales Order <span class="so-h2-highlight" id="salesDetailSoHeading">—</span></h2>
            </div>
        </div>
        <p class="sales-detail-sub" id="salesDetailSub" hidden>—</p>
        <div class="so-qt-badges">
            <span class="so-qt-badge is-blue" title="Quotation Reference"><span class="material-icons-round">request_quote</span> Quotation <strong id="salesDetailQtNum">—</strong></span>
            <span class="so-qt-badge is-green" title="Follow-on Sales Order"><span class="material-icons-round">receipt_long</span> SO <strong id="salesDetailSoNum">—</strong></span>
            <span class="so-qt-badge is-amber" title="Manufacturing Plant"><span class="material-icons-round">factory</span> Plant <strong id="salesDetailPlantNum">—</strong></span>
            <span class="so-qt-badge is-violet" title="Order Document Type"><span class="material-icons-round">label</span> Type <strong id="salesDetailQtType">—</strong></span>
            <span class="so-qt-badge is-slate" title="Customer Reference"><span class="material-icons-round">person</span> Customer <strong id="salesDetailCustomer">—</strong></span>
            <span class="so-qt-badge is-cyan" title="Quotation Date"><span class="material-icons-round">event</span> Date <strong id="salesDetailDateVal">—</strong></span>
        </div>
        <button type="button" class="so-head-close-btn" id="salesDetailCloseX" aria-label="Close Sales Order Drawer" title="Close (Esc)">
            <span class="material-icons-round">close</span>
        </button>
    </header>
    <span id="salesDetailStatusPill" hidden></span>
    <span id="salesDetailLineNum" hidden>—</span>
    <span id="salesDetailNetVal" hidden>—</span>
    <span id="salesDetailQtyVal" hidden>—</span>

    <div class="so-dash" id="soDash">
        <div class="so-dash-kpis">
            <div class="so-dash-kpi so-kpi-sky">
                <span class="so-dash-kpi-icon"><span class="material-icons-round">view_list</span></span>
                <div class="so-dash-kpi-copy">
                    <span class="so-dash-kpi-label">Items</span>
                    <strong id="soDashItems">—</strong>
                    <span class="so-dash-kpi-hint">Order line items</span>
                </div>
                <svg class="so-dash-spark" viewBox="0 0 72 28" aria-hidden="true"><path d="M2 22 V10 M14 22 V16 M26 22 V8 M38 22 V14 M50 22 V6 M62 22 V12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </div>
            <div class="so-dash-kpi so-kpi-mint">
                <span class="so-dash-kpi-icon"><span class="material-icons-round">inventory_2</span></span>
                <div class="so-dash-kpi-copy">
                    <span class="so-dash-kpi-label">Quantity</span>
                    <strong id="soDashQty">—</strong>
                    <span class="so-dash-kpi-hint">Confirmed quantity</span>
                </div>
                <svg class="so-dash-spark" viewBox="0 0 72 28" aria-hidden="true"><path d="M2 20 V12 M14 20 V8 M26 20 V14 M38 20 V6 M50 20 V10 M62 20 V16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </div>
            <div class="so-dash-kpi so-kpi-amber">
                <span class="so-dash-kpi-icon"><span class="material-icons-round">payments</span></span>
                <div class="so-dash-kpi-copy">
                    <span class="so-dash-kpi-label">Net Value</span>
                    <strong id="soDashNet">—</strong>
                    <span class="so-dash-kpi-hint">Sales order net value</span>
                </div>
                <svg class="so-dash-spark" viewBox="0 0 72 28" aria-hidden="true"><path d="M2 22 V14 M14 22 V10 M26 22 V16 M38 22 V8 M50 22 V12 M62 22 V18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </div>
        </div>
    </div>

    <div class="so-related-panel" id="soRelatedPanel">
        <div class="rpt-table-toolbar so-related-toolbar">
            <div class="rpt-tb-search">
                <i class="fas fa-search rpt-search-ico"></i>
                <input type="search" id="soRelatedSearch" class="rpt-tb-input" placeholder="Quick search line, material, plant…" autocomplete="off" spellcheck="false" aria-label="Search sales order table">
                <button type="button" id="soRelatedSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="soRelatedCount">Showing 0 of 0 items</span>
                <div class="rpt-cat-dropdown" id="soColumnToggleDropdown">
                    <button type="button" class="rpt-tb-btn" id="soColumnToggleBtn" title="Show or hide columns" aria-expanded="false" aria-haspopup="true">
                        <i class="fas fa-columns"></i>
                        <span>Columns</span>
                        <span class="material-icons-round rpt-cat-dd-arrow" style="font-size: 14px;">expand_more</span>
                    </button>
                    <div class="rpt-cat-dd-menu so-col-menu" id="soColumnToggleMenu" role="menu"></div>
                </div>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="soRelatedTable" data-excel-title="Sales Order" data-excel-file="sales-order" data-excel-sheet="Sales Order" title="Download the visible columns as Excel">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn" id="soRelatedFilterBtn" aria-pressed="false" title="Show or hide a search box under each column">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                </button>
            </div>
        </div>
        <div class="so-related-table-wrap is-scroll-body">
            <table class="so-related-table rpt-table po-page-table" id="soRelatedTable">
                <thead>
                    <tr class="rpt-header-row">
                        <th class="is-sortable" data-col-id="line_item" data-sort="line_item" title="Sort by Line"><div class="th-content"><span>Line</span></div></th>
                        <th class="is-sortable" data-col-id="item_category" data-sort="item_category" title="Sort by Category"><div class="th-content"><span>Category</span></div></th>
                        <th class="is-sortable" data-col-id="material" data-sort="material" title="Sort by Material"><div class="th-content"><span>Material</span></div></th>
                        <th class="is-sortable" data-col-id="plant" data-sort="plant" title="Sort by Plant"><div class="th-content"><span>Plant</span></div></th>
                        <th class="is-sortable" data-col-id="customer" data-sort="customer" title="Sort by Customer"><div class="th-content"><span>Customer</span></div></th>
                        <th class="is-sortable" data-col-id="type" data-sort="type" title="Sort by Type"><div class="th-content"><span>Type</span></div></th>
                        <th class="is-sortable num" data-col-id="qty" data-sort="qty" title="Sort by Quantity"><div class="th-content num"><span>Qty</span></div></th>
                        <th class="is-sortable num" data-col-id="net_amount" data-sort="net_amount" title="Sort by Net Value"><div class="th-content num"><span>Net</span></div></th>
                        <th class="is-sortable" data-col-id="sales_order" data-sort="sales_order" title="Sort by Sales Order"><div class="th-content"><span>Sales Order</span></div></th>
                    </tr>
                    <tr class="so-related-filter-row rpt-filter-row">
                        <th data-col-id="line_item"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="line_item" placeholder="Filter…"></th>
                        <th data-col-id="item_category"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="item_category" placeholder="Filter…"></th>
                        <th data-col-id="material"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="material" placeholder="Filter…"></th>
                        <th data-col-id="plant"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="plant" placeholder="Filter…"></th>
                        <th data-col-id="customer"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="customer" placeholder="Filter…"></th>
                        <th data-col-id="type"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="type" placeholder="Filter…"></th>
                        <th data-col-id="qty"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="qty" placeholder="Min…"></th>
                        <th data-col-id="net_amount"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="net_amount" placeholder="Min…"></th>
                        <th data-col-id="sales_order"><input type="text" class="so-col-filter rpt-col-input" data-so-filter="sales_order" placeholder="Filter…"></th>
                    </tr>
                </thead>
                <tbody id="soRelatedLinesBody">
                    <tr><td colspan="9" class="so-related-empty">Open a quotation to see sales order items.</td></tr>
                </tbody>
                <tfoot>
                    <tr id="soRelatedFoot">
                        <th id="soFootLabel" data-col-id="line_item">Total</th>
                        <th data-col-id="item_category"></th>
                        <th data-col-id="material"></th>
                        <th data-col-id="plant"></th>
                        <th data-col-id="customer"></th>
                        <th data-col-id="type"></th>
                        <th id="soFootQty" class="num" data-col-id="qty"></th>
                        <th id="soFootNet" class="num" data-col-id="net_amount"></th>
                        <th data-col-id="sales_order"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="so-line-drawer" id="soLineDrawer" aria-hidden="true">
        <header class="so-line-head">
            <button type="button" class="btn-subdrawer-back" id="soLineBack" title="Back to sales order">
                <span class="material-icons-round">arrow_back</span>
                <span>Back</span>
            </button>
            <div class="so-line-head-mid">
                <div class="so-line-head-copy">
                    <span class="so-line-kicker" id="soLineKicker">Sales API line</span>
                    <h3 id="soLineTitle">—</h3>
                    <p id="soLineSub" hidden>Select a table row to load SAP line details</p>
                </div>
                <div class="so-line-badges" id="soLineFacts"></div>
            </div>
            <div class="so-head-pills">
                <span class="so-line-line-pill" id="soLineLineBadge" title="Sales order line">
                    <span class="material-icons-round">format_list_numbered</span>
                    <span>Line</span>
                    <strong id="soLineLineNum">—</strong>
                </span>
                <span class="so-line-so-pill" id="soLineSoBadge" title="Sales order">
                    <span class="material-icons-round">receipt_long</span>
                    <span>SO</span>
                    <strong id="soLineSoNum">—</strong>
                </span>
            </div>
            <button type="button" class="icon-btn sales-drawer-close-btn so-line-close-hidden" id="soLineClose" aria-label="Close line" hidden>
                <span class="material-icons-round">close</span>
            </button>
        </header>
        <div class="so-line-body">
        <nav class="sales-drawer-nav-cards" id="salesDrawerNavCards" role="tablist" aria-label="Order sections">
        <button type="button" class="drawer-nav-card card-bom" data-tab="bom" role="tab" id="tabCardBom">
            <div class="dnc-top">
                <span class="dnc-icon-wrap"><span class="material-icons-round">schema</span></span>
                <span class="dnc-tag">BOM</span>
            </div>
            <div class="dnc-header-group">
                <span class="dnc-eyebrow">Bill of Materials</span>
                <span class="dnc-title">BOM Explosion</span>
            </div>
            <div class="dnc-metric-box">
                <span class="dnc-metric-label">Component Status</span>
                <span class="dnc-value" id="dncBomVal">Live SAP BOM</span>
            </div>
            <div class="dnc-footer">Explore BOM <span class="material-icons-round dnc-arrow">arrow_forward</span></div>
        </button>

        <button type="button" class="drawer-nav-card card-material" data-tab="material" role="tab" id="tabCardMaterial">
            <div class="dnc-top">
                <span class="dnc-icon-wrap"><span class="material-icons-round">inventory_2</span></span>
                <span class="dnc-tag">Master</span>
            </div>
            <div class="dnc-header-group">
                <span class="dnc-eyebrow">Material Specs &amp; Hierarchy</span>
                <span class="dnc-title">Material Master</span>
            </div>
            <div class="dnc-metric-box">
                <span class="dnc-metric-label">Parent Material Code</span>
                <span class="dnc-value font-mono" id="dncMaterialVal">—</span>
            </div>
            <div class="dnc-footer">View Specs <span class="material-icons-round dnc-arrow">arrow_forward</span></div>
        </button>

        <button type="button" class="drawer-nav-card card-procurement" data-tab="procurement" role="tab" id="tabCardProcurement">
            <div class="dnc-top">
                <span class="dnc-icon-wrap"><span class="material-icons-round">local_shipping</span></span>
                <span class="dnc-tag proc-live-tag"><span class="pulse-dot"></span> Live SAP</span>
            </div>
            <div class="dnc-header-group">
                <span class="dnc-eyebrow">Demand &amp; Supply Chain</span>
                <span class="dnc-title">Procurement</span>
            </div>
            <div class="dnc-metric-box">
                <span class="dnc-metric-label">SAP PR &amp; PO Records</span>
                <span class="dnc-value" id="dncProcVal">Syncing...</span>
            </div>
            <div class="dnc-footer">Open Dashboard <span class="material-icons-round dnc-arrow">arrow_forward</span></div>
        </button>
    </nav>
        </div>

    <!-- Detail Sub-Offcanvas (Opens when any card is clicked, with Back button) -->
    <div class="sales-subdrawer" id="salesSubDrawer" aria-hidden="true">
        <div class="sales-subdrawer-header">
            <div class="subdrawer-header-left">
                <button type="button" class="btn-subdrawer-back" id="btnSubDrawerBack" title="Back to categories">
                    <span class="material-icons-round">arrow_back</span>
                    <span>Back</span>
                </button>
                <div class="subdrawer-title-group">
                    <h3 class="subdrawer-title" id="subDrawerTitle">Delivery &amp; Schedule Planning</h3>
                </div>
            </div>
            <div class="so-head-pills">
                <span class="so-line-line-pill subdrawer-so-pill" id="subDrawerLineBadge" title="Sales order line">
                    <span class="material-icons-round">format_list_numbered</span>
                    <span>Line</span>
                    <strong id="subDrawerLineNum">—</strong>
                </span>
                <span class="so-line-so-pill subdrawer-so-pill" id="subDrawerSoBadge" title="Sales order">
                    <span class="material-icons-round">receipt_long</span>
                    <span>SO</span>
                    <strong id="subDrawerSoNum">—</strong>
                </span>
            </div>
            <button type="button" class="icon-btn sales-drawer-close-btn so-line-close-hidden" id="subDrawerClose" aria-label="Close" title="Close" hidden>
                <span class="material-icons-round">close</span>
            </button>
        </div>

        <div class="sales-subdrawer-body" id="salesSubDrawerBody">
            <!-- Planning Panel -->
            <div class="drawer-tab-panel" id="panelPlanning" role="tabpanel" hidden></div>

            <!-- BOM Panel -->
            <div class="drawer-tab-panel" id="panelBom" role="tabpanel" hidden></div>

            <!-- Material Panel -->
            <div class="drawer-tab-panel" id="panelMaterial" role="tabpanel" hidden></div>

            <!-- Procurement Panel -->
            <div class="drawer-tab-panel" id="panelProcurement" role="tabpanel" hidden></div>
        </div>
    </div>
    </div>
</aside>

<input type="hidden" id="dateFrom" value="<?= e($defaultFrom ?? "{$year}-01-01") ?>">
<input type="hidden" id="dateTo" value="<?= e($defaultTo ?? "{$year}-12-31") ?>">

<!-- Procurement Offcanvas & PR Drilldown -->
<div class="procurement-backdrop is-hidden" id="procurementBackdrop" style="display:none" hidden></div>
<div class="procurement-modal is-hidden" id="procurementModal" role="dialog" aria-modal="true" aria-labelledby="procurementModalTitle" style="display:none" hidden>
    <!-- Modern Sleek Header -->
    <div class="procurement-modal-header">
        <div class="proc-header-left">
            <div class="proc-header-icon">
                <span class="material-icons-round">inventory_2</span>
            </div>
            <div class="proc-header-meta">
                <div class="proc-header-top-row">
                    <h2 id="procurementModalTitle">Procurement Overview</h2>
                    <span class="proc-pill proc-pill-so">Sales Doc #<strong id="procSalesDocLabel">—</strong></span>
                    <span class="proc-pill proc-pill-live"><span class="pulse-dot"></span> Live SAP</span>
                </div>
                <div class="procurement-subtitle">
                    <span class="mat-label">Material:</span>
                    <strong id="procHeaderMaterial">—</strong>
                    <span class="mat-sep">·</span>
                    <span id="procHeaderDesc">—</span>
                </div>
            </div>
        </div>
        <div class="procurement-header-actions">
            <button type="button" class="proc-btn-header" id="btnProcReload" title="Sync live data from SAP">
                <span class="material-icons-round">sync</span>
                <span>Sync</span>
            </button>
            <button type="button" class="proc-btn-close" id="procurementModalClose" aria-label="Close Procurement Drawer" title="Close">
                <span class="material-icons-round">close</span>
            </button>
        </div>
    </div>

    <!-- Colorful Modern Offcanvas KPI Cards -->
    <div class="procurement-cards-grid">
        <div class="proc-stat-card proc-stat-indigo">
            <div class="proc-stat-top">
                <span class="proc-stat-lbl">Components</span>
                <div class="proc-stat-icon-wrap"><span class="material-icons-round">category</span></div>
            </div>
            <div class="proc-stat-num"><b id="procStatComponents">0</b><span class="proc-stat-unit"> items</span></div>
            <div class="proc-stat-sub">Bill of Materials</div>
        </div>
        <div class="proc-stat-card proc-stat-cyan">
            <div class="proc-stat-top">
                <span class="proc-stat-lbl">Required Qty</span>
                <div class="proc-stat-icon-wrap"><span class="material-icons-round">shopping_bag</span></div>
            </div>
            <div class="proc-stat-num"><b id="procStatReq">0</b></div>
            <div class="proc-stat-sub">Demand Requirement</div>
        </div>
        <div class="proc-stat-card proc-stat-amber">
            <div class="proc-stat-top">
                <span class="proc-stat-lbl">Total PR Qty</span>
                <div class="proc-stat-icon-wrap"><span class="material-icons-round">receipt_long</span></div>
            </div>
            <div class="proc-stat-num"><b id="procStatPR">0</b></div>
            <div class="proc-stat-sub">Purchase Requisitions</div>
        </div>
        <div class="proc-stat-card proc-stat-emerald">
            <div class="proc-stat-top">
                <span class="proc-stat-lbl">Total PO Qty</span>
                <div class="proc-stat-icon-wrap"><span class="material-icons-round">local_shipping</span></div>
            </div>
            <div class="proc-stat-num"><b id="procStatPO">0</b></div>
            <div class="proc-stat-sub">Purchase Orders</div>
        </div>
        <div class="proc-stat-card proc-stat-purple">
            <div class="proc-stat-top">
                <span class="proc-stat-lbl">Stock Available</span>
                <div class="proc-stat-icon-wrap"><span class="material-icons-round">warehouse</span></div>
            </div>
            <div class="proc-stat-num"><b id="procStatStock">0</b></div>
            <div class="proc-stat-sub">On-hand Inventory</div>
        </div>
    </div>

    <!-- Clean Toolbar: Search & Group Filter Pills -->
    <div class="procurement-toolbar">
        <div class="procurement-search-wrap">
            <span class="material-icons-round">search</span>
            <input type="search" id="procSearchInput" placeholder="Search material code, description, shade..." autocomplete="off">
            <button type="button" class="proc-search-clear" id="procSearchClear" aria-label="Clear search" title="Clear search" hidden>
                <span class="material-icons-round">clear</span>
            </button>
        </div>
        <div class="procurement-group-filters" id="procGroupFilters">
            <button type="button" class="proc-filter-pill active" data-group="all">All (<span id="procFilterCountAll">0</span>)</button>
            <button type="button" class="proc-filter-pill" data-group="fabric">Fabric</button>
            <button type="button" class="proc-filter-pill" data-group="lining">Lining</button>
            <button type="button" class="proc-filter-pill" data-group="label">Labels</button>
            <button type="button" class="proc-filter-pill" data-group="thread">Threads</button>
            <button type="button" class="proc-filter-pill" data-group="button">Buttons</button>
        </div>
        <div class="procurement-count-badge" id="procFilteredCount">0 items</div>
    </div>

    <!-- Main Content: Data Table -->
    <div class="procurement-content-body">
        <div class="procurement-table-scroll" id="procurementTableView">
            <table class="procurement-table" id="procurementTable">
                <thead>
                    <tr>
                        <th style="min-width:180px">Component Material</th>
                        <th style="min-width:240px">Description</th>
                        <th style="min-width:95px">Group</th>
                        <th class="col-num" style="min-width:90px">Req Qty</th>
                        <th class="col-num" style="min-width:110px">Total PR Qty</th>
                        <th class="col-num" style="min-width:110px">Total PO Qty</th>
                        <th class="col-num" style="min-width:110px">Balance PR</th>
                        <th class="col-num" style="min-width:105px">Stock Qty</th>
                        <th class="col-action" style="text-align:center;min-width:90px">PR Set</th>
                    </tr>
                </thead>
                <tbody id="procurementTableBody">
                    <tr><td colspan="9" class="empty-state">Loading Procurement data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Nested PR Set Drilldown Drawer inside Modal -->
    <div class="procurement-pr-drawer" id="procurementPrDrawer" aria-hidden="true">
        <div class="pr-drawer-header">
            <div>
                <span class="pr-drawer-eyebrow"><span class="material-icons-round" style="font-size:14px;vertical-align:-1px;">receipt_long</span> PR &amp; PO Details</span>
                <h3 id="prDrawerTitle">PR Set</h3>
                <p id="prDrawerSubtitle" class="pr-drawer-sub"></p>
            </div>
            <button type="button" class="icon-btn" id="prDrawerClose" aria-label="Close PR Drawer">
                <span class="material-icons-round">close</span>
            </button>
        </div>
        <div class="pr-drawer-body" id="prDrawerBody">
            <div class="loading-state">Loading ProcurementPRSet from SAP...</div>
        </div>
    </div>
</div>
    <?php require base_path('app/views/partials/footer.php'); ?>
</div><!-- /.rpt-app -->
