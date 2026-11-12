<?php
/*
 * Bulk PO Costing Validation — body (filters live in default header)
 */
?>
<section class="pcb" id="poCostingBulk" aria-label="Bulk PO Costing Validation">
    <div class="pcb-toast" id="pcbToast" hidden role="status" aria-live="polite"></div>

    <div class="pcb-rules-popover" id="pcbRulesPopover" hidden role="dialog" aria-labelledby="pcbRulesTitle" aria-modal="false">
        <div class="pcb-rules-card">
            <div class="pcb-rules-head">
                <div>
                    <span class="pcb-rules-kicker">PO vs zCOST</span>
                    <h3 id="pcbRulesTitle">Compare rules &amp; status decode</h3>
                </div>
                <button type="button" class="pcb-rules-close" id="pcbRulesClose" aria-label="Close rules">&times;</button>
            </div>
            <div class="pcb-rules-body">
                <section>
                    <h4>How values are compared</h4>
                    <ol>
                        <li>Load PO items from SAP (TEMPFIN / PO hub).</li>
                        <li>Link each PO line to Sales Order → Quotation → zCOST sheet.</li>
                        <li>Match lines by Material Group / Description / Trim code / Material Code.</li>
                        <li>Compare <strong>Actual Cost (PO)</strong> vs <strong>Budget Cost (zCOST)</strong>.</li>
                        <li>Classic tolerance: Actual ≤ Budget + <strong>0.005</strong> → Passed.</li>
                        <li>Match score ≥ <strong>90</strong> → automatic Pass / Unmatched decision.</li>
                        <li>Match score <strong>40–89</strong> → Needs Review (uncertain mapping).</li>
                        <li>Match score &lt; <strong>40</strong> or no zCOST link → Unmatched.</li>
                    </ol>
                </section>
                <section>
                    <h4>Status decode</h4>
                    <ul class="pcb-rules-status">
                        <li><span class="pcb-badge is-pass">PASSED</span> PO actual cost is within budget / matched successfully.</li>
                        <li><span class="pcb-badge is-fail">UNMATCHED</span> No matching zCOST line, missing budget, or cost above budget.</li>
                        <li><span class="pcb-badge is-review">NEEDS REVIEW</span> Partial match — confirm material mapping manually.</li>
                        <li><span class="pcb-badge is-pending">PENDING</span> Not validated yet. Click <strong>Validated</strong> or row <strong>Validate</strong>.</li>
                    </ul>
                </section>
                <section>
                    <h4>Unmatched means</h4>
                    <p>Count of POs (or lines) where PO value could not be matched to zCOST under the rules above. Click this card anytime to reopen these rules.</p>
                </section>
            </div>
        </div>
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
        <article class="pcb-kpi is-fail pcb-kpi-rules" id="pcbUnmatchedKpi" role="button" tabindex="0" title="Click to view PO vs zCOST compare rules" aria-controls="pcbRulesPopover" aria-expanded="false">
            <span class="pcb-kpi-icon material-icons-round">error</span>
            <div class="pcb-kpi-body">
                <b id="pcbKpiExceptions">—</b>
                <small id="pcbKpiExcPct">
                    Data Not Matched
                    <span class="material-icons-round pcb-kpi-help" aria-hidden="true">info</span>
                </small>
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
                <button type="button" class="rpt-tb-btn" id="pcbToggleStickyScroll" title="Toggle fixed header with scrollable body">
                    <i class="fas fa-arrows-up-down"></i>
                    <span id="pcbStickyScrollLabel">Expand Table</span>
                </button>
                <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pcbExportBtn" title="Download Excel / CSV">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>
                <button type="button" class="rpt-tb-btn" id="pcbToggleColFilters" title="Show column filter inputs">
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

        <?php
            require_once base_path('app/services/TableColumnRegistry.php');
            $bulkTableKey = 'purchase_order_costing_bulk_table';
            $bulkTableDef = TableColumnRegistry::get($bulkTableKey) ?? ['columns' => []];
            $bulkColumns = $bulkTableDef['columns'] ?? [];
            $bulkColCount = max(1, count($bulkColumns));
            $bulkNumKeys = [
                'sno', 'items', 'passed', 'exceptions', 'pending', 'totvalue',
            ];
            $bulkNoFilter = ['pdf', 'sno'];
        ?>
        <div class="pcb-table-wrap is-expanded" id="pcbTableWrap">
            <div class="pcb-table-scrollx">
            <table class="pcb-table" id="pcbTable">
                <thead>
                    <tr class="pcb-header-row">
                        <?php foreach ($bulkColumns as $col): ?>
                            <?php
                            $key = (string) ($col['id'] ?? '');
                            $isNum = in_array($key, $bulkNumKeys, true);
                            $locked = !empty($col['locked']);
                            ?>
                            <th class="<?= $key === 'check' ? 'pcb-check' : '' ?><?= $isNum ? ' num' : '' ?><?= $locked ? '' : ' is-sortable' ?>"
                                data-col-key="<?= e($key) ?>"
                                <?= $locked ? '' : ' data-sort="' . e($key) . '"' ?>
                                title="<?= e($col['label'] ?? $key) ?>">
                                <?php if ($key === 'check'): ?>
                                <?php else: ?>
                                    <span class="pcb-th-label"><?= e($col['label'] ?? $key) ?></span>
                                    <?php if (!$locked): ?><span class="pcb-sort-ico" aria-hidden="true"></span><?php endif; ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="pcb-filter-row" id="pcbFilterRow" style="display: none;">
                        <?php foreach ($bulkColumns as $col): ?>
                            <?php
                            $key = (string) ($col['id'] ?? '');
                            $isNum = in_array($key, $bulkNumKeys, true);
                            ?>
                            <?php if ($key === 'check'): ?>
                                <th class="pcb-check" data-col-key="check">
                                    <button type="button" class="rpt-col-filter-reset" id="pcbColFilterReset" title="Clear all column filters">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </th>
                            <?php elseif (in_array($key, $bulkNoFilter, true)): ?>
                                <th<?= $isNum ? ' class="num"' : '' ?> data-col-key="<?= e($key) ?>"></th>
                            <?php else: ?>
                                <th<?= $isNum ? ' class="num"' : '' ?> data-col-key="<?= e($key) ?>">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input pcb-col-filter" data-key="<?= e($key) ?>"<?= $isNum ? ' data-numeric' : '' ?> placeholder="Filter…">
                                    </div>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="pcbTableBody">
                    <tr class="pcb-empty"><td colspan="<?= (int) $bulkColCount ?>">Select a date range to load purchase orders.</td></tr>
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

    <?php
        require_once base_path('app/services/TableColumnRegistry.php');
        $itemTableKey = 'purchase_order_costing_items_table';
        $itemTableDef = TableColumnRegistry::get($itemTableKey) ?? ['columns' => []];
        $itemColumns = $itemTableDef['columns'] ?? [];
        $itemColCount = max(1, count($itemColumns));
        $itemNumKeys = [
            'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
            'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
            'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
        ];
        $itemZcostKeys = [
            'costing_quotation_no', 'costing_item_no', 'zsection', 'maktx',
            'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
            'conv', 'buom', 'actual_cost', 'variance_pct',
        ];
        $itemPoColCount = 0;
        $itemZcostColCount = 0;
        foreach ($itemColumns as $col) {
            $id = (string) ($col['id'] ?? '');
            if (in_array($id, $itemZcostKeys, true)) {
                $itemZcostColCount++;
            } else {
                $itemPoColCount++;
            }
        }
    ?>
    <div class="pcb-offcanvas" id="pcbOffcanvas" hidden aria-hidden="true">
        <div class="pcb-offcanvas-backdrop" id="pcbOffcanvasBackdrop"></div>
        <aside class="pcb-offcanvas-panel" role="dialog" aria-modal="true" aria-labelledby="pcbDrillTitle">
            <div class="pcb-offcanvas-head">
                <div class="pcb-offcanvas-title">
                    <span class="pcb-offcanvas-kicker">PO Validation</span>
                    <h2 id="pcbDrillTitle">PO —</h2>
                    <p id="pcbDrillSub">PO Data vs Costing Data</p>
                </div>
                <div class="pcb-offcanvas-actions">
                    <a class="rpt-tb-btn" id="pcbOpenWorkbench" href="#" hidden>
                        <span class="material-icons-round">open_in_new</span>
                        Open Workbench
                    </a>
                    <button type="button" class="pcb-offcanvas-close" id="pcbOffcanvasClose" aria-label="Close">&times;</button>
                </div>
            </div>
            <div class="pcb-offcanvas-body">
                <div class="pcb-oc-kpis" aria-hidden="true">
                    <span>Items <b id="pcbOcKpiItems">—</b></span>
                    <span>Passed <b id="pcbOcKpiPassed">—</b></span>
                    <span>Data Not Matched <b id="pcbOcKpiExceptions">—</b></span>
                    <span>Review <b id="pcbOcKpiReview">—</b></span>
                    <span>Value <b id="pcbOcKpiValue">—</b></span>
                </div>
                <div class="rpt-table-toolbar pcb-item-toolbar">
                    <div class="rpt-tb-search">
                        <span class="material-icons-round rpt-search-ico">search</span>
                        <input type="search" id="pcbItemSearch" class="rpt-tb-input" placeholder="Search PONO, MATERIAL, MAKTX…" autocomplete="off">
                    </div>
                    <div class="rpt-tb-actions">
                        <span class="rpt-tb-count" id="pcbItemCount">—</span>
                        <div class="rpt-cat-dropdown pcb-col-dropdown" id="pcbItemColDropdown">
                            <button type="button" class="rpt-tb-btn" id="pcbItemColToggle" title="Show or hide columns" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-columns"></i>
                                <span>Columns</span>
                                <span class="material-icons-round rpt-cat-dd-arrow" style="font-size:14px;">expand_more</span>
                            </button>
                            <div class="rpt-cat-dd-menu pcb-col-menu" id="pcbItemColumnMenu" role="menu"></div>
                        </div>
                        <span class="rpt-col-save" id="pcbItemColSaveStatus" role="status" aria-live="polite" hidden></span>
                        <button type="button" class="rpt-tb-btn" id="pcbItemToggleFilters" title="Show column filter inputs">
                            <i class="fas fa-filter"></i>
                            <span>Column Filters</span>
                            <span class="rpt-tb-badge" id="pcbItemFilterBadge" style="display:none;">0</span>
                        </button>
                        <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="pcbItemClearFilters" style="display:none;">
                            <i class="fas fa-rotate-left"></i>
                            <span>Clear All</span>
                        </button>
                        <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" id="pcbItemExcelBtn">
                            <i class="fas fa-file-excel"></i>
                            <span>Excel</span>
                        </button>
                    </div>
                </div>
                <div class="pcb-oc-table-wrap" id="pcbItemTableWrap">
                    <div class="pcb-oc-table-scrollx">
                        <table class="rpt-table text-nowrap pcw-unified-table pcb-item-table" id="pcbItemTable">
                            <thead>
                                <tr class="pcw-group-row">
                                    <th class="pcw-group-th is-po" data-group="po" colspan="<?= (int) max(1, $itemPoColCount) ?>">
                                        <span class="pcw-group-label">
                                            <span class="material-icons-round">receipt_long</span>
                                            PO Data
                                        </span>
                                    </th>
                                    <th class="pcw-group-th is-zcost" data-group="zcost" colspan="<?= (int) max(1, $itemZcostColCount) ?>">
                                        <span class="pcw-group-label">
                                            <span class="material-icons-round">calculate</span>
                                            Costing Data
                                        </span>
                                    </th>
                                </tr>
                                <tr class="pcb-item-header-row">
                                    <?php foreach ($itemColumns as $col): ?>
                                        <?php
                                        $key = (string) ($col['id'] ?? '');
                                        $isNum = in_array($key, $itemNumKeys, true);
                                        $group = in_array($key, $itemZcostKeys, true) ? 'zcost' : 'po';
                                        ?>
                                        <th class="<?= $isNum ? 'num ' : '' ?>pcw-col-<?= e($group) ?>" data-col-key="<?= e($key) ?>" data-col-group="<?= e($group) ?>">
                                            <span class="pcb-th-label"><?= e($col['label'] ?? $key) ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                                <tr class="pcb-item-filter-row pcb-filter-row" id="pcbItemFilterRow" style="display: none;">
                                    <?php foreach ($itemColumns as $col): ?>
                                        <?php
                                        $key = (string) ($col['id'] ?? '');
                                        $isNum = in_array($key, $itemNumKeys, true);
                                        ?>
                                        <?php if ($key === 'status'): ?>
                                            <th data-col-key="status">
                                                <button type="button" class="rpt-col-filter-reset" id="pcbItemColFilterReset" title="Clear column filters">
                                                    <i class="fas fa-eraser"></i>
                                                </button>
                                            </th>
                                        <?php else: ?>
                                            <th<?= $isNum ? ' class="num"' : '' ?> data-col-key="<?= e($key) ?>">
                                                <div class="rpt-col-input-wrap">
                                                    <input type="text" class="rpt-col-input pcb-item-col-filter" data-key="<?= e($key) ?>"<?= $isNum ? ' data-numeric' : '' ?> placeholder="Filter…">
                                                </div>
                                            </th>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody id="pcbItemBody">
                                <tr class="pcw-empty"><td colspan="<?= (int) $itemColCount ?>">Select a PO to load comparison lines.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <?php
        $pvLoginUser = trim((string) (
            $_SESSION['username']
            ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User'))
        ));
        $pvKicker = 'PO Validation';
        require base_path('app/views/reports/partials/po_validation_offcanvas.php');
    ?>

</section>
