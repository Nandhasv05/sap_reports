<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : PO Valuation Report panel — KPI cards + Recent Activity cards / modal
 *               (PO / date filters live in the page header)
*/
$valuationCards = [
    ['id' => 'pending',  'label' => 'Pending PO',  'icon' => 'hourglass_empty', 'tone' => 'amber'],
    ['id' => 'review',   'label' => 'Review PO',   'icon' => 'rate_review',     'tone' => 'sky'],
    ['id' => 'released', 'label' => 'Released PO', 'icon' => 'verified',        'tone' => 'teal'],
    ['id' => 'total',    'label' => 'Total POs',   'icon' => 'receipt_long',    'tone' => 'violet'],
    ['id' => 'approved', 'label' => 'Approved PO', 'icon' => 'task_alt',        'tone' => 'mint'],
];
?>
<div class="poc-panel" id="pocPanelValuation" data-panel="valuation" hidden>
    <div class="poc-panel-frame is-native is-rpt-style is-valuation-report" id="pocValuationFrame">
        <div class="rpt-stats po-stat-row pvr-stats" id="pvrStats">
            <?php foreach ($valuationCards as $card): ?>
                <article class="rpt-stat tone-<?= e($card['tone']) ?>" title="<?= e($card['label']) ?>">
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
                       placeholder="Quick search PO, status, user, remarks…"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="pvrTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" hidden>
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pvrExcelBtn"
                        data-excel-export="pvrExcelTable"
                        data-excel-title="PO Valuation Report"
                        data-excel-file="po-valuation-report"
                        data-excel-sheet="Activity"
                        title="Download Excel">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
            </div>
        </div>

        <div class="pvr-activity-list" id="pvrActivityList" aria-live="polite">
            <div class="pvr-activity-empty" id="pvrActivityEmpty">Apply filters to load PO activity.</div>
        </div>

        <!-- Hidden table kept only for Excel export -->
        <table class="rpt-table pvr-table" id="pvrExcelTable" hidden aria-hidden="true">
            <thead>
                <tr>
                    <th>S.No</th>
                    <th>PO Number</th>
                    <th>Activity</th>
                    <th>Status</th>
                    <th>User</th>
                    <th>Remarks</th>
                    <th>When (IST)</th>
                </tr>
            </thead>
            <tbody id="pvrTableBody"></tbody>
        </table>
    </div>
</div>
