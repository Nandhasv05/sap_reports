<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : PO Validation Report — KPI cards + Draft-PO-style activity table
 */
$valuationCards = [
    ['id' => 'draft',    'label' => 'Draft',     'hint' => 'Draft / not released', 'icon' => 'edit_note',     'tone' => 'sky'],
    ['id' => 'review',   'label' => 'Review',    'hint' => 'Checker review',       'icon' => 'rate_review',   'tone' => 'amber'],
    ['id' => 'released', 'label' => 'Released',  'hint' => 'Released / eSign pending', 'icon' => 'verified', 'tone' => 'teal'],
    ['id' => 'approved', 'label' => 'Approved',  'hint' => 'Approved PO',          'icon' => 'task_alt',      'tone' => 'mint'],
    ['id' => 'total',    'label' => 'Total POs', 'hint' => 'Draft + Review + Released + Approved', 'icon' => 'receipt_long', 'tone' => 'violet'],
];
$pvrCols = [
    ['key' => 'sno',      'label' => 'S.No'],
    ['key' => 'po',       'label' => 'PO Number'],
    ['key' => 'status',   'label' => 'Status'],
    ['key' => 'updated',  'label' => 'Updated'],
    ['key' => 'owner',    'label' => 'Owner Name'],
    ['key' => 'action',   'label' => 'Last Action'],
    ['key' => 'progress', 'label' => 'Progress'],
];
$pvrColCount = count($pvrCols);
?>
<div class="poc-panel" id="pocPanelValuation" data-panel="valuation" hidden>
    <div class="poc-panel-frame is-native is-rpt-style is-valuation-report" id="pocValuationFrame">
        <div class="rpt-stats po-stat-row pvr-stats" id="pvrStats">
            <?php foreach ($valuationCards as $card): ?>
                <article class="rpt-stat tone-<?= e($card['tone']) ?>" title="<?= e($card['hint']) ?>">
                    <span class="material-icons-round" aria-hidden="true"><?= e($card['icon']) ?></span>
                    <div class="pvr-stat-copy">
                        <div class="stat-value-wrap">
                            <b id="pvrStat-<?= e($card['id']) ?>">0</b>
                        </div>
                        <small><?= e($card['label']) ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="rpt-table-toolbar pvr-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="pvrTableSearch" class="rpt-tb-input"
                       placeholder="Quick search PO #, status, owner, stage…"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="pvrTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" hidden>
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="pvrShowingCount">Showing 0 purchase orders</span>
                <span class="poc-related-status-pills pvr-status-pills" id="pvrStatusFilters" aria-label="Filter by status">
                    <button type="button" class="poc-related-status-pill pvr-status-pill is-all is-on" data-status="" title="Show all">
                        <em>All</em>
                        <b id="pvrPillAll">0</b>
                    </button>
                    <button type="button" class="poc-related-status-pill pvr-status-pill is-draft" data-status="draft" title="Draft">
                        <span class="material-icons-round" aria-hidden="true">edit_note</span>
                        <em>Draft</em>
                        <b id="pvrPillDraft">0</b>
                    </button>
                    <button type="button" class="poc-related-status-pill pvr-status-pill is-review" data-status="review" title="Review">
                        <span class="material-icons-round" aria-hidden="true">rate_review</span>
                        <em>Review</em>
                        <b id="pvrPillReview">0</b>
                    </button>
                    <button type="button" class="poc-related-status-pill pvr-status-pill is-released" data-status="released" title="Released / eSign pending">
                        <span class="material-icons-round" aria-hidden="true">verified</span>
                        <em>Released</em>
                        <b id="pvrPillReleased">0</b>
                    </button>
                    <button type="button" class="poc-related-status-pill pvr-status-pill is-approved" data-status="approved" title="Approved">
                        <span class="material-icons-round" aria-hidden="true">task_alt</span>
                        <em>Approved</em>
                        <b id="pvrPillApproved">0</b>
                    </button>
                </span>

                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pvrExcelBtn"
                        data-excel-export="pvrExcelTable"
                        data-excel-title="PO Validation Report"
                        data-excel-file="po-validation-report"
                        data-excel-sheet="PO Summary"
                        title="Download Excel">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn" id="pvrRefreshBtn" title="Refresh report">
                    <span class="material-icons-round">refresh</span>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <div class="rpt-table-wrap is-scroll-body pvr-table-wrap" id="pvrTableWrap">
            <table class="rpt-table prod-table po-page-table text-nowrap pvr-rpt-table" id="pvrReportTable" aria-label="PO validation activity">
                <thead>
                    <tr class="rpt-header-row">
                        <?php foreach ($pvrCols as $col): ?>
                            <th class="<?= $col['key'] === 'sno' ? 'sno' : '' ?>" data-key="<?= e($col['key']) ?>">
                                <div class="th-content"><span><?= e($col['label']) ?></span></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="pvrActivityList" aria-live="polite">
                    <tr class="rpt-table-no-match" id="pvrActivityEmpty">
                        <td colspan="<?= (int) $pvrColCount ?>" class="rpt-table-empty">
                            <div class="rpt-no-match-card">
                                <span class="material-icons-round">fact_check</span>
                                <p>Apply filters to load PO validation activity.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Hidden table for Excel export (one row per PO) — needs rpt-header-row + data-orig-sno for excel-export.js -->
        <table class="rpt-table pvr-table" id="pvrExcelTable" aria-hidden="true"
               style="position:absolute;left:-9999px;top:0;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;">
            <thead>
                <tr class="rpt-header-row">
                    <th>S.No</th>
                    <th>PO Number</th>
                    <th>Status</th>
                    <th>Updated (IST)</th>
                    <th>Owner Name</th>
                    <th>Last Action</th>
                    <th>Progress</th>
                </tr>
            </thead>
            <tbody id="pvrTableBody"></tbody>
        </table>
    </div>
</div>
