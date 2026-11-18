<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 18/11/2026
 * DESCRIPTION : Related PO full-screen NotApproved list (filters live in page header)
 */
require_once base_path('app/services/PoCostValidationService.php');
$relatedCols = PoCostValidationService::relatedPoColumns();
$relatedColCount = count($relatedCols);
$relatedCards = [
    ['id' => 'pos',       'label' => 'Purchase Orders', 'icon' => 'receipt_long',         'tone' => 'violet'],
    ['id' => 'items',     'label' => 'Total Items',     'icon' => 'format_list_numbered', 'tone' => 'teal'],
    ['id' => 'qty',       'label' => 'Total Order Qty', 'icon' => 'inventory_2',          'tone' => 'sky'],
    ['id' => 'value',     'label' => 'Total Net Value', 'icon' => 'payments',             'tone' => 'mint'],
    ['id' => 'suppliers', 'label' => 'Suppliers',       'icon' => 'local_shipping',       'tone' => 'amber'],
];
?>
<div class="poc-panel" id="pocPanelRelated" data-panel="related" data-related-mode="results" hidden>
    <div class="poc-panel-frame is-native is-rpt-style is-related-po is-related-results" id="pocRelatedResults">
        <div class="rpt-stats po-stat-row poc-related-stats" id="pocRelatedStats">
            <?php foreach ($relatedCards as $card): ?>
                <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                    <span class="material-icons-round"><?= e($card['icon']) ?></span>
                    <div>
                        <div class="stat-value-wrap">
                            <b id="pocRelStat-<?= e($card['id']) ?>">0</b>
                            <em class="po-stat-unit" id="pocRelStatUnit-<?= e($card['id']) ?>"></em>
                        </div>
                        <small><?= e($card['label']) ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="rpt-table-toolbar poc-related-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="pocRelatedSearch" class="rpt-tb-input"
                       placeholder="Quick search PO #, Supplier, Plant, Brand…"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="pocRelatedSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" hidden>
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rpt-tb-actions">
                <span class="rpt-tb-count" id="pocRelatedCount">Showing 0 purchase orders</span>
                <span class="poc-related-filter-pill" id="pocRelatedActiveFilters" title="Active header filters">All Materials · All Brands</span>

                <button type="button" class="rpt-tb-btn is-active" id="pocRelatedStickyScroll" title="Keep table header fixed and scroll body only">
                    <i class="fas fa-arrows-up-down"></i>
                    <span id="pocRelatedStickyLabel">Scroll Body Only</span>
                </button>

                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="pocRelatedTable"
                        data-excel-title="Related Purchase Orders"
                        data-excel-file="related-purchase-orders"
                        data-excel-sheet="Related PO"
                        title="Download the table as an Excel file">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>

                <div class="rpt-cat-dropdown" id="pocRelatedColDropdown">
                    <button type="button" class="rpt-tb-btn" id="pocRelatedColumnToggleBtn"
                            title="Manage columns: reorder, show or hide (saved to your account)"
                            aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-columns"></i>
                        <span>Columns</span>
                        <span class="material-icons-round rpt-cat-dd-arrow" style="font-size:14px;">expand_more</span>
                    </button>
                    <div class="rpt-cat-dd-menu" id="pocRelatedColumnToggleMenu" role="menu"
                         style="right:0; left:auto; max-height:400px; overflow-y:auto;"></div>
                </div>
                <span class="rpt-col-save" id="pocRelatedColSaveStatus" role="status" aria-live="polite" hidden></span>

                <button type="button" class="rpt-tb-btn" id="pocRelatedColFilters" title="Toggle column filter inputs">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                    <span class="rpt-tb-badge" id="pocRelatedFilterBadge" hidden>0</span>
                </button>

                <button type="button" class="rpt-tb-btn" id="pocRelatedRefresh" title="Refresh from SAP">
                    <span class="material-icons-round">refresh</span>
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <div class="rpt-table-wrap is-scroll-body poc-related-wrap" id="pocRelatedTableWrap">
            <table class="rpt-table prod-table po-page-table text-nowrap" id="pocRelatedTable">
                <thead>
                    <tr class="rpt-header-row">
                        <?php foreach ($relatedCols as $i => $col): ?>
                            <?php
                                $colKey = (string) ($col['key'] ?? '');
                                $isNum = ($col['type'] ?? '') === 'num';
                            ?>
                            <th class="is-sortable<?= $isNum ? ' num' : '' ?><?= $colKey === 'sno' ? ' sno' : '' ?>"
                                data-col="<?= (int) $i ?>"
                                data-key="<?= e($colKey) ?>"
                                data-col-id="<?= e($colKey) ?>"
                                data-type="<?= e($col['type'] ?? 'text') ?>"
                                title="Click to sort by <?= e($col['label'] ?? '') ?>">
                                <div class="th-content<?= $isNum ? ' num' : '' ?>"><span><?= e($col['label'] ?? '') ?></span></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="rpt-filter-row" id="pocRelatedFilterRow" style="display:none;">
                        <?php foreach ($relatedCols as $i => $col): ?>
                            <?php $colKey = (string) ($col['key'] ?? ''); ?>
                            <?php if ($colKey === 'sno'): ?>
                                <th class="num sno" data-col-id="<?= e($colKey) ?>">
                                    <button type="button" class="rpt-col-filter-reset" id="pocRelatedFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </th>
                            <?php else: ?>
                                <th<?= ($col['type'] ?? '') === 'num' ? ' class="num"' : '' ?> data-col-id="<?= e($colKey) ?>">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="<?= (int) $i ?>" data-key="<?= e($colKey) ?>"
                                               <?= ($col['type'] ?? '') === 'num' ? ' data-numeric' : '' ?>
                                               placeholder="<?= e(($col['type'] ?? '') === 'num' ? '>0, <10…' : 'Filter…') ?>"
                                               title="Filter <?= e($col['label'] ?? '') ?>">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="pocRelatedBody">
                    <tr class="rpt-table-loading" id="pocRelatedLoading">
                        <td colspan="<?= (int) $relatedColCount ?>" class="rpt-table-skeleton">
                            <div class="rpt-skel-rows" aria-label="Loading related purchase orders"><i></i><i></i><i></i><i></i><i></i></div>
                        </td>
                    </tr>
                    <tr id="pocRelatedEmpty" class="rpt-table-no-match" hidden>
                        <td colspan="<?= (int) $relatedColCount ?>" class="rpt-table-empty">
                            <div class="rpt-no-match-card">
                                <span class="material-icons-round">filter_alt_off</span>
                                <p>No purchase orders found matching the selected conditions.</p>
                                <button type="button" class="rpt-btn rpt-btn-sm" id="pocRelatedClearFilters">Clear All Filters</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr id="pocRelatedFoot">
                        <?php foreach ($relatedCols as $col): ?>
                            <?php $colKey = (string) ($col['key'] ?? ''); ?>
                            <?php if ($colKey === 'sno'): ?>
                                <th id="pocRelatedFootLabel" data-col-id="<?= e($colKey) ?>">TOTAL</th>
                            <?php else: ?>
                                <th<?= ($col['type'] ?? '') === 'num' ? ' class="num"' : '' ?>
                                   data-foot-key="<?= e($colKey) ?>"
                                   data-col-id="<?= e($colKey) ?>"></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="pcb-rules-popover poc-related-mail-pop" id="pocRelatedMailPopover" hidden role="dialog" aria-labelledby="pocRelatedMailTitle" aria-modal="true">
        <div class="pcb-mail-card">
            <div class="pcb-mail-head">
                <div class="pcb-mail-head-main">
                    <span class="pcb-mail-head-ico material-icons-round" aria-hidden="true">history</span>
                    <div>
                        <span class="pcb-mail-kicker">Sent / Receive Log</span>
                        <h3 id="pocRelatedMailTitle">Mailbox details</h3>
                    </div>
                </div>
                <button type="button" class="pcb-rules-close" id="pocRelatedMailClose" aria-label="Close sent / receive log">&times;</button>
            </div>
            <div class="pcb-mail-body" id="pocRelatedMailBody"></div>
        </div>
    </div>
</div>
