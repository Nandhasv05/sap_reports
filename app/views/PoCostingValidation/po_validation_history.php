<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 11/11/2026
 * DESCRIPTION : PO Validation History — Status & Remarks / validate activity
 */
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$poBaseUrl = (string) ($poBaseUrl ?? url('purchase-order'));
$validationLogUrl = (string) ($validationLogUrl ?? url('purchase-order/validation-log'));
$costingUrl = (string) ($costingUrl ?? url('po-costing'));
$todayMax = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
?>
<div class="rpt-app is-po-pass-log is-po-validation-history" id="poValidationHistoryPage"
     data-validation-log="<?= e($validationLogUrl) ?>"
     data-po-base="<?= e($poBaseUrl) ?>">

    <header class="rpt-bar ppl-bar">
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
            <a href="<?= e($costingUrl) ?>" class="rpt-home" aria-label="Back to PO Costing" title="PO Costing Validation">
                <span class="material-icons-round">arrow_back</span>
            </a>
            <div class="rpt-name">PO Validation History</div>
        </div>

        <div class="ppl-bar-filters" aria-label="Filters">
            <label class="ppl-field is-po">
                <span class="material-icons-round">search</span>
                <input type="search" id="pvhFilterPo" placeholder="Search PO number" inputmode="numeric" maxlength="10" autocomplete="off">
            </label>
            <label class="ppl-field is-range">
                <span class="material-icons-round">date_range</span>
                <select id="pvhFilterRange" aria-label="Date range">
                    <option value="">All dates</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="this_week">This week</option>
                    <option value="previous_week">Previous week</option>
                    <option value="this_month">This month</option>
                    <option value="previous_month">Previous month</option>
                    <option value="custom">Custom date</option>
                </select>
            </label>
            <div class="ppl-custom-dates" id="pvhCustomDates" hidden>
                <input type="date" id="pvhFilterFrom" max="<?= e($todayMax) ?>" aria-label="From date">
                <input type="date" id="pvhFilterTo" max="<?= e($todayMax) ?>" aria-label="To date">
            </div>
            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pvhApplyFilters">
                <span class="material-icons-round">filter_alt</span>
                Apply
            </button>
            <button type="button" class="rpt-tb-btn" id="pvhResetFilters">Reset</button>
            <button type="button" class="rpt-tb-btn" id="pvhRefreshBtn" title="Refresh">
                <span class="material-icons-round">refresh</span>
            </button>
        </div>

        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <main class="ppl-main is-table-only pvh-main">
        <div class="rpt-table-toolbar pvh-rpt-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="pvhTableSearch" class="rpt-tb-input"
                       placeholder="Quick search table (PO, Status, User, Mail, Remarks…)"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="pvhTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" hidden>
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="pvhTableCount">Showing 0 of 0 lines</span>
                <button type="button" class="rpt-tb-btn is-active" id="pvhExpandBtn" title="Expand table height">
                    <i class="fas fa-arrows-up-down"></i>
                    <span>Expand Table</span>
                </button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pvhExcelBtn"
                        title="Download Excel"
                        data-excel-export="pvhExportTable"
                        data-excel-title="PO Validation History"
                        data-excel-file="po-validation-history"
                        data-excel-sheet="Validation History">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn is-active" id="pvhToggleColFilters" title="Toggle column filter inputs">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                </button>
            </div>
        </div>

        <section class="ppl-table-card pvh-card" aria-label="Validation history list">
            <div class="rpt-table-wrap is-scroll-body pvh-table-wrap" id="pvhTableWrap">
                <table class="rpt-table pvh-table" id="pvhTable">
                    <thead>
                        <tr class="rpt-header-row">
                            <th class="num sno"><div class="th-content"><span>S.No</span></div></th>
                            <th><div class="th-content"><span>PO Number</span></div></th>
                            <th><div class="th-content"><span>Status</span></div></th>
                            <th><div class="th-content"><span>Assigned To</span></div></th>
                            <th><div class="th-content"><span>Created By</span></div></th>
                            <th><div class="th-content"><span>Remarks</span></div></th>
                            <th><div class="th-content"><span>When (IST)</span></div></th>
                        </tr>
                        <tr class="rpt-filter-row" id="pvhFilterRow">
                            <th>
                                <button type="button" class="rpt-tb-btn pvh-filter-home" id="pvhClearColFilters" title="Clear column filters">
                                    <span class="material-icons-round">home</span>
                                </button>
                            </th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="po" placeholder="Filter PO…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="status" placeholder="Filter Status…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="assigned" placeholder="Filter User / Mail…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="created" placeholder="Filter Created By…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="remarks" placeholder="Filter Remarks…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="when" placeholder="Filter When…"></div></th>
                        </tr>
                    </thead>
                    <tbody id="pvhTableBody">
                        <tr><td colspan="7" class="ppl-empty">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pvh-pager" id="pvhPager" hidden>
                <button type="button" class="rpt-tb-btn" id="pvhPrevPage" title="Previous page">
                    <span class="material-icons-round">chevron_left</span>
                    Prev
                </button>
                <span class="pvh-pager-info" id="pvhPagerInfo">Page 1</span>
                <label class="pvh-page-size">
                    Rows
                    <select id="pvhPageSize" aria-label="Rows per page">
                        <option value="10">10</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                    </select>
                </label>
                <button type="button" class="rpt-tb-btn" id="pvhNextPage" title="Next page">
                    Next
                    <span class="material-icons-round">chevron_right</span>
                </button>
            </div>
        </section>
    </main>

    <table id="pvhExportTable" hidden aria-hidden="true">
        <thead>
            <tr>
                <th>S.No</th>
                <th>PO Number</th>
                <th>Status</th>
                <th>Assigned To</th>
                <th>Mail ID</th>
                <th>Created By</th>
                <th>Remarks</th>
                <th>When (IST)</th>
            </tr>
        </thead>
        <tbody id="pvhExportBody"></tbody>
    </table>

    <?php require base_path('app/views/partials/footer.php'); ?>
</div>

<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/po-validation-history.js')) ?>"></script>
