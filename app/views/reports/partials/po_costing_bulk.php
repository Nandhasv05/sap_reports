<?php
/*
 * Bulk PO Costing Validation — body (filters live in default header)
 */
?>
<section class="pcb" id="poCostingBulk" aria-label="Bulk PO Costing Validation">
    <div class="pcb-toast" id="pcbToast" hidden role="status" aria-live="polite"></div>

    <div class="pcb-progress" id="pcbProgress" hidden>
        <div class="pcb-progress-meta">
            <span id="pcbProgressLabel">Validating…</span>
            <span id="pcbProgressPct">0%</span>
        </div>
        <div class="pcb-progress-track"><div class="pcb-progress-bar" id="pcbProgressBar"></div></div>
    </div>

    <div class="pcb-kpis" id="pcbKpis">
        <article class="pcb-kpi is-total">
            <span class="pcb-kpi-icon material-icons-round">description</span>
            <div class="pcb-kpi-body">
                <b id="pcbKpiTotal">—</b>
                <small>Total POs</small>
            </div>
            <div class="pcb-kpi-bar" aria-hidden="true" style="display:none;"><i id="pcbKpiTotalBar" style="width:100%"></i></div>
        </article>
        <article class="pcb-kpi is-pass">
            <span class="pcb-kpi-icon material-icons-round">check_circle</span>
            <div class="pcb-kpi-body">
                <b id="pcbKpiPassed">—</b>
                <small id="pcbKpiPassedPct">Passed</small>
            </div>
            <div class="pcb-kpi-bar" aria-hidden="true" style="display:none;"><i id="pcbKpiPassedBar" style="width:0%"></i></div>
        </article>
        <article class="pcb-kpi is-fail">
            <span class="pcb-kpi-icon material-icons-round">error</span>
            <div class="pcb-kpi-body">
                <b id="pcbKpiExceptions">—</b>
                <small id="pcbKpiExcPct">Exceptions</small>
            </div>
            <div class="pcb-kpi-bar" aria-hidden="true" style="display:none;"><i id="pcbKpiExcBar" style="width:0%"></i></div>
        </article>
        <article class="pcb-kpi is-pending">
            <span class="pcb-kpi-icon material-icons-round">schedule</span>
            <div class="pcb-kpi-body">
                <b id="pcbKpiPending">—</b>
                <small id="pcbKpiPendPct">Pending</small>
            </div>
            <div class="pcb-kpi-bar" aria-hidden="true" style="display:none;"><i id="pcbKpiPendBar" style="width:0%"></i></div>
        </article>
        <article class="pcb-kpi is-value">
            <span class="pcb-kpi-icon material-icons-round">payments</span>
            <div class="pcb-kpi-body">
                <b id="pcbKpiValue">—</b>
                <small>Total PO Value</small>
            </div>
            <div class="pcb-kpi-bar" aria-hidden="true" style="display:none;"><i style="width:100%"></i></div>
        </article>
    </div>

    <div class="pcb-table-card">
        <div class="rpt-table-toolbar pcb-table-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="pcbQuickSearch" class="rpt-tb-input" placeholder="Quick search PO #, Vendor…" autocomplete="off" spellcheck="false">
                <button type="button" id="pcbQuickSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display:none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="pcbCount">—</span>
                <span class="pcb-runtime" id="pcbRuntimeStatus" hidden>
                    <span class="pcb-runtime-dot" aria-hidden="true"></span>
                    <em id="pcbRuntimeText">Validating selected…</em>
                </span>
                <button type="button" class="rpt-tb-btn" id="pcbToggleStickyScroll" title="Toggle fixed header with scrollable body">
                    <i class="fas fa-arrows-up-down"></i>
                    <span id="pcbStickyScrollLabel">Expand Table</span>
                </button>
                <div class="rpt-cat-dropdown pcb-col-dropdown" id="pcbColumnToggleDropdown">
                    <button type="button" class="rpt-tb-btn" id="pcbColumnToggleBtn" title="Show or hide columns" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-columns"></i>
                        <span>Columns</span>
                        <span class="material-icons-round rpt-cat-dd-arrow" style="font-size:14px;">expand_more</span>
                    </button>
                    <div class="rpt-cat-dd-menu pcb-col-menu" id="pcbColumnToggleMenu" role="menu"></div>
                </div>
                <span class="rpt-col-save" id="pcbColSaveStatus" role="status" aria-live="polite" hidden></span>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pcbExportBtn" title="Download Excel / CSV">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn is-active" id="pcbToggleColFilters" title="Toggle column filter inputs">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                    <span class="rpt-tb-badge" id="pcbActiveFilterBadge" style="display:none;">0</span>
                </button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="pcbClearAllFilters" title="Clear search and column filters" style="display:none;">
                    <i class="fas fa-rotate-left"></i>
                    <span>Clear All</span>
                </button>
            </div>
        </div>

        <div class="pcb-table-wrap is-expanded" id="pcbTableWrap">
            <div class="pcb-table-scrollx">
            <table class="pcb-table" id="pcbTable">
                <thead>
                    <tr class="pcb-header-row">
                        <th class="pcb-check" data-col-key="check"><input type="checkbox" id="pcbSelectAll" title="Select page"></th>
                        <th class="is-sortable" data-col-key="po" data-sort="po" title="Sort by PO Number"><span class="pcb-th-label">PO Number</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="date" data-sort="date" title="Sort by PO Date"><span class="pcb-th-label">PO Date</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="vendor_code" data-sort="vendor_code" title="Sort by Vendor Code"><span class="pcb-th-label">Vendor Code</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="vendor_name" data-sort="vendor_name" title="Sort by Vendor Name"><span class="pcb-th-label">Vendor Name</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="material" data-sort="material" title="Sort by Material Type"><span class="pcb-th-label">Material Type</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="num is-sortable" data-col-key="items" data-sort="items" title="Sort by No. of Items"><span class="pcb-th-label">No. of Items</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="num is-sortable" data-col-key="value" data-sort="value" title="Sort by PO Value"><span class="pcb-th-label">PO Value (₹)</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="num is-sortable" data-col-key="passed" data-sort="passed" title="Sort by Passed"><span class="pcb-th-label">Passed</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="num is-sortable" data-col-key="exceptions" data-sort="exceptions" title="Sort by Exceptions"><span class="pcb-th-label">Exceptions</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="num is-sortable" data-col-key="pending" data-sort="pending" title="Sort by Pending"><span class="pcb-th-label">Pending</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="status" data-sort="status" title="Sort by Validation Status"><span class="pcb-th-label">Validation Status</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="last" data-sort="last" title="Sort by Last Validated"><span class="pcb-th-label">Last Validated</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                        <th class="is-sortable" data-col-key="validated_by" data-sort="validated_by" title="Sort by Validated By"><span class="pcb-th-label">Validated By</span><span class="pcb-sort-ico" aria-hidden="true"></span></th>
                    </tr>
                    <tr class="pcb-filter-row" id="pcbFilterRow">
                        <th class="pcb-check" data-col-key="check">
                            <button type="button" class="rpt-col-filter-reset" id="pcbColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                <i class="fas fa-eraser"></i>
                            </button>
                        </th>
                        <th data-col-key="po"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="po" placeholder="Filter…"></div></th>
                        <th data-col-key="date"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="date" placeholder="Filter…"></div></th>
                        <th data-col-key="vendor_code"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="vendor_code" placeholder="Filter…"></div></th>
                        <th data-col-key="vendor_name"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="vendor_name" placeholder="Filter…"></div></th>
                        <th data-col-key="material"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="material" placeholder="Filter…"></div></th>
                        <th class="num" data-col-key="items"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="items" data-numeric placeholder="Filter…"></div></th>
                        <th class="num" data-col-key="value"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="value" data-numeric placeholder="Filter…"></div></th>
                        <th class="num" data-col-key="passed"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="passed" data-numeric placeholder="Filter…"></div></th>
                        <th class="num" data-col-key="exceptions"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="exceptions" data-numeric placeholder="Filter…"></div></th>
                        <th class="num" data-col-key="pending"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="pending" data-numeric placeholder="Filter…"></div></th>
                        <th data-col-key="status"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="status" placeholder="Filter…"></div></th>
                        <th data-col-key="last"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="last" placeholder="Filter…"></div></th>
                        <th data-col-key="validated_by"><div class="rpt-col-input-wrap"><input type="text" class="rpt-col-input pcb-col-filter" data-key="validated_by" placeholder="Filter…"></div></th>
                    </tr>
                </thead>
                <tbody id="pcbTableBody">
                    <tr class="pcb-empty"><td colspan="14">Click Fetch POs in the header to load purchase orders.</td></tr>
                </tbody>
            </table>
            </div>
        </div>

        <div class="pcb-cards" id="pcbCards" aria-label="PO list cards"></div>

        <div class="pcb-pager" id="pcbPager" hidden>
            <button type="button" id="pcbPrev" class="pcb-btn pcb-btn-ghost">Prev</button>
            <span id="pcbPageInfo">Page 1</span>
            <button type="button" id="pcbNext" class="pcb-btn pcb-btn-ghost">Next</button>
        </div>
    </div>

</section>
