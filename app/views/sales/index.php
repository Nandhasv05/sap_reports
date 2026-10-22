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

$extraHead = '<link rel="stylesheet" href="' . e(asset('css/sales.css')) . '">';

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
    pageSize: 10
};
</script>
<script src="<?= e(asset('js/sales.js')) ?>"></script>
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
                    <span class="hub-brand-title">SAP Reports</span>
                    <span class="hub-brand-subtitle">Sales Intelligence Studio</span>
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

            <div class="hub-status-pill" title="SAP RFC &amp; OData Services Connected - Heartbeat Pulse Active">
                <span class="hub-heartbeat-box" aria-hidden="true">
                    <span class="hub-heartbeat-core"></span>
                    <span class="hub-heartbeat-radar"></span>
                </span>
                <span class="hub-status-label">SAP Live</span>
                <i class="fas fa-heart-pulse hub-ecg-icon" title="Heartbeat active"></i>
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
    <div class="sales-loader is-on" id="salesLoader" role="status" aria-live="polite">
        <div class="sales-loader-panel">
            <div class="sales-spinner" aria-hidden="true">
                <span class="sales-spinner-ring sales-spinner-ring--outer"></span>
                <span class="sales-spinner-ring sales-spinner-ring--inner"></span>
            </div>
            <strong>Loading SAP Quotations</strong>
            <span class="sales-loader-hint" id="salesLoaderNote">Fetching real-time quotations from SAP…</span>
        </div>
    </div>
    <script>
    (function () {
        var el = document.getElementById('salesLoader');
        if (el) document.documentElement.appendChild(el);
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
                <div class="kpi-icon"><span class="material-icons-round">shopping_bag</span></div>
            </div>
            <div class="kpi-info">
                <div class="kpi-kicker">
                    <span class="kpi-kicker-dot"></span>
                    <span>ACTIVE PIPELINE • ORDERS</span>
                </div>
                <div class="label">Total Quotations</div>
                <div class="kpi-value-row">
                    <div class="value" id="skOrders">—</div>
                </div>
                <div class="change">Total active lines</div>
            </div>
            <div class="kpi-spark" id="skOrdersSpark" aria-hidden="true">
                <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
            </div>
        </div>
    </div>

    <!-- Charts Section: Trend & Customer Distribution -->
    <div class="sales-charts-main">
        <div class="card chart-panel sales-panel-sky">
            <div class="chart-panel-head">
                <div class="chart-head-left">
                    <span class="chart-panel-icon"><span class="material-icons-round">show_chart</span></span>
                    <div>
                        <h2>Quotation Value Trend</h2>
                        <p>Net value aggregated by quotation date</p>
                    </div>
                </div>
                <div class="chart-header-badge trend-badge" id="trendBadge">
                    <span class="badge-pulse-dot"></span>
                    <span class="material-icons-round">trending_up</span>
                    <span id="trendPeakStat">Daily Activity</span>
                </div>
            </div>
            <div class="chart-wrap tall"><canvas id="salesTrendChart"></canvas></div>
        </div>
        <div class="card chart-panel sales-panel-violet">
            <div class="chart-panel-head">
                <div class="chart-head-left">
                    <span class="chart-panel-icon"><span class="material-icons-round">pie_chart</span></span>
                    <div>
                        <h2 id="salesMixTitle">Customer Mix</h2>
                        <p id="salesMixSubtitle">Net value breakdown by customer</p>
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
        <div class="sales-table-head">
            <div class="sales-table-head-left">
                <div class="sales-table-title">
                    <span class="sales-table-title-icon"><span class="material-icons-round">receipt_long</span></span>
                    <h2>Quotations</h2>
                </div>
                <span class="sales-count-badge" id="salesTableCountBadge">
                    <span class="badge-pulse-dot"></span>
                    <span class="sales-count-num" id="salesTableCount">—</span>
                    <span class="sales-count-label">Total Records</span>
                </span>
            </div>
            <div class="sales-table-head-right">
                <div class="sales-table-search">
                    <div class="sales-search-wrap">
                        <span class="material-icons-round search-icon">search</span>
                        <input type="search" id="salesSearch" placeholder="Search quotation or sales order…" autocomplete="off" aria-label="Search quotations in table">
                        <button type="button" class="sales-search-clear" id="salesSearchClear" hidden aria-label="Clear search">
                            <span class="material-icons-round">close</span>
                        </button>
                        <button type="button" class="sales-search-btn" id="btnSalesSearchSubmit" title="Search">
                            <span class="material-icons-round">arrow_forward</span>
                        </button>
                    </div>
                </div>
                <div class="sales-table-tools">
                    <button type="button" class="sales-tool-btn" id="btnSalesColFilter" aria-pressed="false" title="Show/Hide Column Filters">
                        <span class="material-icons-round">filter_alt</span>
                        <span class="sales-tool-btn-label">Filter</span>
                    </button>
                </div>
                <div class="sales-table-export" id="salesExport">
                    <button type="button" class="sales-export-btn" id="salesExportBtn" aria-haspopup="menu" aria-expanded="false" title="Export Quotations">
                        <span class="material-icons-round">file_download</span>
                        <span>Export</span>
                        <span class="material-icons-round chev">expand_more</span>
                    </button>
                    <div class="sales-export-menu" id="salesExportMenu" hidden>
                        <button type="button" data-export="page">
                            <span class="material-icons-round">table_view</span>
                            <span>This page (CSV)</span>
                        </button>
                        <button type="button" data-export="all">
                            <span class="material-icons-round">cloud_download</span>
                            <span>All filtered (CSV)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive table-wrap sales-table-wrap">
            <table class="sales-data-table" id="salesDataTable">
                <thead>
                    <tr class="sales-th-row">
                        <th class="col-sno" style="width: 55px; text-align: center;">S.No</th>
                        <th class="col-sortable" data-sort="quotation" title="Click to sort by Quotation">
                            <span>Quotation</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="line_item" title="Click to sort by Item">
                            <span>Item</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="material" title="Click to sort by Material">
                            <span>Material</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="item_category" title="Click to sort by Category">
                            <span>Category</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="customer" title="Click to sort by Customer">
                            <span>Customer</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="customer_ref" title="Click to sort by Customer Reference">
                            <span>Customer Reference</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="date" title="Click to sort by Quotation Date">
                            <span>Quotation Date</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable col-num" data-sort="qty" title="Click to sort by Quantity">
                            <span>Quantity</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable col-num" data-sort="net_amount" title="Click to sort by Net Value">
                            <span>Net Value</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                        <th class="col-sortable" data-sort="sales_order" title="Click to sort by Sales Order">
                            <span>Sales Order</span>
                            <span class="sort-icon material-icons-round">unfold_more</span>
                        </th>
                    </tr>
                    <tr class="sales-col-filter-row">
                        <th class="col-filter-th" style="text-align:center;"><span class="material-icons-round filter-header-icon" title="Column Filters">filter_alt</span></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="quotation" placeholder="Quotation..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="line_item" placeholder="Item..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="material" placeholder="Material..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="item_category" placeholder="Cat..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="customer" placeholder="Customer..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="customer_ref" placeholder="Reference..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="date" placeholder="Date..."></th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input col-num" data-filter-col="qty" placeholder="Min qty..."></th>
                        <th class="col-filter-th">
                            <div class="col-filter-net-clear">
                                <input type="text" class="col-filter-input col-num" data-filter-col="net_amount" placeholder="Min amt...">
                                <button type="button" class="btn-clear-col-filters" id="btnClearColFilters" title="Clear all column filters">
                                    <span class="material-icons-round">clear</span>
                                </button>
                            </div>
                        </th>
                        <th class="col-filter-th"><input type="text" class="col-filter-input" data-filter-col="sales_order" placeholder="Filter SO..."></th>
                    </tr>
                </thead>
                <tbody id="salesTableBody">
                    <tr><td colspan="11" class="empty-state">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="sales-pagination" id="salesPagination">
            <div class="sales-page-left">
                <span class="sales-range-info" id="salesRangeInfo">Showing 0–0 of 0</span>
                <div class="sales-page-nav">
                    <button type="button" class="sales-page-btn" id="salesPrev" disabled title="Previous page">
                        <span class="material-icons-round">chevron_left</span> Previous
                    </button>
                    <div class="sales-page-numbers" id="salesPageNumbers"></div>
                    <button type="button" class="sales-page-btn" id="salesNext" disabled title="Next page">
                        Next <span class="material-icons-round">chevron_right</span>
                    </button>
                </div>
            </div>
            <div class="sales-page-right">
                <label for="salesPageSize">Per page</label>
                <select id="salesPageSize" class="sales-select" aria-label="Rows per page">
                    <option value="10" selected>10 / page</option>
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>
        </div>
    </div>
</section>

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
        <div class="so-related-head">
            <span class="material-icons-round">view_list</span>
            <div>
                <strong>Sales Order table</strong>
            </div>
            <div class="so-related-tools">
                <label class="so-related-search">
                    <span class="material-icons-round">search</span>
                    <input type="search" id="soRelatedSearch" placeholder="Search table..." autocomplete="off">
                </label>
                <button type="button" class="so-related-export" id="soRelatedFilterBtn" aria-pressed="false" title="Column filters">
                    <span class="material-icons-round">filter_alt</span>
                    Filter
                </button>
                <button type="button" class="so-related-export" id="soRelatedExport">
                    <span class="material-icons-round">file_download</span>
                    Export
                </button>
                <span class="so-related-count" id="soRelatedCount">0 items</span>
            </div>
        </div>
        <div class="so-related-table-wrap">
            <table class="so-related-table" id="soRelatedTable">
                <thead>
                    <tr>
                        <th>Line</th>
                        <th>Category</th>
                        <th>Material</th>
                        <th>Plant</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th class="col-num">Qty</th>
                        <th class="col-num">Net</th>
                        <th>Sales Order</th>
                    </tr>
                    <tr class="so-related-filter-row">
                        <th><input type="text" class="so-col-filter" data-so-filter="line_item" placeholder="Line..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="item_category" placeholder="Cat..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="material" placeholder="Material..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="plant" placeholder="Plant..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="customer" placeholder="Customer..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="type" placeholder="Type..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="qty" placeholder="Qty..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="net_amount" placeholder="Net..."></th>
                        <th><input type="text" class="so-col-filter" data-so-filter="sales_order" placeholder="SO..."></th>
                    </tr>
                </thead>
                <tbody id="soRelatedLinesBody">
                    <tr><td colspan="9" class="so-related-empty">Open a quotation to see sales order items.</td></tr>
                </tbody>
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
</div><!-- /.rpt-app -->
