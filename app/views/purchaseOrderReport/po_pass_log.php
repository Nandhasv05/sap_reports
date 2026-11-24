<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 06/11/2026
 * DESCRIPTION : PO E-Sign / Pass Log — report table view
 */
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$poBaseUrl = (string) ($poBaseUrl ?? url('purchase-order'));
$passLogUrl = (string) ($passLogUrl ?? url('purchase-order/pass-log'));
$costingUrl = (string) ($costingUrl ?? url('po-costing'));
$todayMax = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
?>
<div class="rpt-app is-po-pass-log is-po-esign-log" id="poPassLogPage"
     data-pass-log="<?= e($passLogUrl) ?>"
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
            <div class="rpt-name">PO E-Sign Log</div>
        </div>

        <div class="ppl-bar-filters" aria-label="Filters">
            <label class="ppl-field is-po">
                <span class="material-icons-round">search</span>
                <input type="search" id="pplFilterPo" placeholder="Search PO number" inputmode="numeric" maxlength="10" autocomplete="off">
            </label>
            <label class="ppl-field is-range">
                <span class="material-icons-round">date_range</span>
                <select id="pplFilterRange" aria-label="Date range">
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
            <div class="ppl-custom-dates" id="pplCustomDates" hidden>
                <input type="date" id="pplFilterFrom" max="<?= e($todayMax) ?>" aria-label="From date">
                <input type="date" id="pplFilterTo" max="<?= e($todayMax) ?>" aria-label="To date">
            </div>
            <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pplApplyFilters">
                <span class="material-icons-round">filter_alt</span>
                Apply
            </button>
            <button type="button" class="rpt-tb-btn" id="pplResetFilters">Reset</button>
            <button type="button" class="rpt-tb-btn" id="pplRefreshBtn" title="Refresh">
                <span class="material-icons-round">refresh</span>
            </button>
        </div>

        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <main class="ppl-main is-table-only ppl-esign-main">
        <div class="rpt-table-toolbar ppl-rpt-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="pplTableSearch" class="rpt-tb-input"
                       placeholder="Quick search table (PO, User, Status, Request Id…)"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="pplTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" hidden>
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="pplTableCount">Showing 0 of 0 lines</span>
                <button type="button" class="rpt-tb-btn is-active" id="pplExpandBtn" title="Expand table height">
                    <i class="fas fa-arrows-up-down"></i>
                    <span>Expand Table</span>
                </button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pplExcelBtn"
                        title="Download Excel"
                        data-excel-export="pplExportTable"
                        data-excel-title="PO E-Sign Log"
                        data-excel-file="po-esign-log"
                        data-excel-sheet="E-Sign Log">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn is-active" id="pplToggleColFilters" title="Toggle column filter inputs">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                </button>
            </div>
        </div>

        <section class="ppl-table-card ppl-esign-card" aria-label="Passed PO list">
            <div class="rpt-table-wrap is-scroll-body ppl-esign-wrap" id="pplTableWrap">
                <table class="rpt-table ppl-esign-table" id="pplTable">
                    <thead>
                        <tr class="rpt-header-row">
                            <th class="num sno"><div class="th-content"><span>S.No</span></div></th>
                            <th><div class="th-content"><span>PO Number</span></div></th>
                            <th><div class="th-content"><span>Passed By</span></div></th>
                            <th><div class="th-content"><span>Status</span></div></th>
                            <th><div class="th-content"><span>Request Id</span></div></th>
                            <th><div class="th-content"><span>Passed At (IST)</span></div></th>
                            <th><div class="th-content"><span>Payload / Response</span></div></th>
                            <th><div class="th-content"><span>File</span></div></th>
                            <th><div class="th-content"><span>Action</span></div></th>
                        </tr>
                        <tr class="rpt-filter-row" id="pplFilterRow">
                            <th>
                                <button type="button" class="rpt-tb-btn ppl-filter-home" id="pplClearColFilters" title="Clear column filters">
                                    <span class="material-icons-round">home</span>
                                </button>
                            </th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="po" placeholder="Filter PO…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="user" placeholder="Filter User…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="status" placeholder="Filter Status…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="request" placeholder="Filter Request…"></div></th>
                            <th><div class="rpt-col-input-wrap"><input type="search" class="rpt-col-input" data-col="when" placeholder="Filter When…"></div></th>
                            <th></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="pplTableBody">
                        <tr><td colspan="9" class="ppl-empty">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pvh-pager ppl-pager" id="pplPager" hidden>
                <button type="button" class="rpt-tb-btn" id="pplPrevPage" title="Previous page">
                    <span class="material-icons-round">chevron_left</span>
                    Prev
                </button>
                <span class="pvh-pager-info" id="pplPagerInfo">Page 1</span>
                <label class="pvh-page-size">
                    Rows
                    <select id="pplPageSize" aria-label="Rows per page">
                        <option value="10">10</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                    </select>
                </label>
                <button type="button" class="rpt-tb-btn" id="pplNextPage" title="Next page">
                    Next
                    <span class="material-icons-round">chevron_right</span>
                </button>
            </div>
        </section>
    </main>

    <table id="pplExportTable" hidden aria-hidden="true">
        <thead>
            <tr>
                <th>S.No</th>
                <th>PO Number</th>
                <th>Passed By</th>
                <th>Status</th>
                <th>Request Id</th>
                <th>Passed At (IST)</th>
                <th>File</th>
            </tr>
        </thead>
        <tbody id="pplExportBody"></tbody>
    </table>

    <div class="ppl-detail-backdrop" id="pplDetailModal" hidden>
        <div class="ppl-detail-card" role="dialog" aria-modal="true" aria-labelledby="pplDetailTitle">
            <header>
                <div>
                    <span class="ppl-detail-kicker">API log</span>
                    <strong id="pplDetailTitle">Payload &amp; Response</strong>
                </div>
                <button type="button" class="rpt-tb-btn" id="pplDetailClose" aria-label="Close">
                    <span class="material-icons-round">close</span>
                </button>
            </header>
            <div class="ppl-detail-grid" id="pplDetailMeta"></div>
            <div class="ppl-detail-file" id="pplDetailFile" hidden>
                <a class="rpt-tb-btn rpt-tb-btn-primary" id="pplDetailFileLink" href="#" target="_blank" rel="noopener">
                    <span class="material-icons-round">picture_as_pdf</span>
                    Open signed PDF
                </a>
                <span id="pplDetailFileName"></span>
            </div>
            <h4>Payload</h4>
            <pre id="pplDetailPayload">—</pre>
            <h4>Response</h4>
            <pre id="pplDetailResponse">—</pre>
        </div>
    </div>

    <?php require base_path('app/views/partials/footer.php'); ?>
</div>

<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/po-pass-log.js')) ?>"></script>
