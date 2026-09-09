<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Fabric / Trims utilization view
 */
$isFabric = $report === 'fabric';
$salesOrder = (string) ($salesOrder ?? '');
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$summary = is_array($summary ?? null) ? $summary : [];
$chart = is_array($chart ?? null) ? $chart : [];
$hasData = $records !== [] || (int) $total > 0;
$isLookup = $salesOrder === '' && !$hasData;
$kindLabel = $isFabric ? 'Fabric Utilization Report' : 'Trims Utilization Report';
$catalogHome = url('/');
$qs = static function (array $extra = []) use ($search, $salesOrder, $page, $perPage): string {
    return http_build_query(array_merge([
        'so' => $salesOrder,
        'q' => $search,
        'page' => $page,
        'per_page' => $perPage,
    ], $extra));
};
$tabQs = $salesOrder !== '' ? ('?so=' . rawurlencode($salesOrder)) : '';
$emptyHint = $salesOrder === ''
    ? 'Enter a sales order to pull utilization from SAP.'
    : 'No ' . ($isFabric ? 'fabric' : 'trims') . ' utilization for sales order ' . $salesOrder . '.';
$cards = [
    ['label' => 'Materials', 'note' => 'Lines', 'icon' => 'layers', 'tone' => 'teal', 'value' => $model->dash($summary['lines'] ?? $total)],
    ['label' => 'BOM Qty', 'note' => 'Required', 'icon' => 'schema', 'tone' => 'sky', 'value' => $model->dash($summary['bom_qty'] ?? null)],
    ['label' => 'Planned', 'note' => 'Planned qty', 'icon' => 'event_note', 'tone' => 'indigo', 'value' => $model->dash($summary['planned_qty'] ?? null)],
    ['label' => 'Production', 'note' => 'Produced', 'icon' => 'precision_manufacturing', 'tone' => 'mint', 'value' => $model->dash($summary['production_qty'] ?? null)],
    ['label' => 'PO Qty', 'note' => 'Ordered', 'icon' => 'shopping_bag', 'tone' => 'amber', 'value' => $model->dash($summary['po_qty'] ?? null)],
    ['label' => 'GRN Qty', 'note' => 'Received', 'icon' => 'inventory_2', 'tone' => 'violet', 'value' => $model->dash($summary['grn_qty'] ?? null)],
    ['label' => 'Issue Qty', 'note' => 'Issued', 'icon' => 'output', 'tone' => 'rose', 'value' => $model->dash($summary['issue_qty'] ?? null)],
];
$modeClass = $isLookup ? 'is-lookup is-first' : 'is-report';
?>
<div class="rpt-app <?= $isFabric ? 'is-fabric' : 'is-trims' ?> <?= $modeClass ?>">
    <div class="rpt-boot" id="rptBoot" <?= $isLookup ? '' : 'hidden' ?>>
        <div class="rpt-boot-card">
            <div class="rpt-boot-brand"><img src="<?= e($logoUrl) ?>" alt="evolv"></div>
            <div class="rpt-spin" aria-hidden="true"></div>
            <p>Preparing Utilization Report…</p>
            <span class="rpt-boot-bar"><i></i></span>
        </div>
    </div>

    <div class="rpt-spinner" id="rptSpinner" hidden>
        <div class="rpt-spinner-card">
            <div class="rpt-spin" aria-hidden="true"></div>
            <p>Loading from SAP…</p>
        </div>
    </div>

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
                <?php if (!$isLookup): ?>
                    <span class="rpt-count"><?= e($model->num($total)) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isLookup): ?>
            <nav class="rpt-tabs" aria-label="Report type">
                <a class="rpt-tab <?= $isFabric ? 'active' : '' ?>" href="<?= e(url('fabric') . $tabQs) ?>">Fabric</a>
                <a class="rpt-tab <?= !$isFabric ? 'active' : '' ?>" href="<?= e(url('trims') . $tabQs) ?>">Trims</a>
            </nav>
            <form class="rpt-search" method="get" action="<?= e(url($report)) ?>" id="rptFilterForm">
                <label class="rpt-field">
                    <span class="material-icons-round">receipt_long</span>
                    <input id="so" name="so" value="<?= e($salesOrder) ?>" placeholder="Sales order" inputmode="numeric">
                </label>
                <label class="rpt-field rpt-field-filter">
                    <span class="material-icons-round">search</span>
                    <input id="q" name="q" value="<?= e($search) ?>" placeholder="Material / PO">
                </label>
                <button class="rpt-btn" type="submit">
                    <span class="material-icons-round">sync</span>
                    <!-- Load report -->
                </button>
                <?php if ($hasData): ?>
                    <a class="rpt-btn rpt-btn-ghost" href="<?= e(url($report) . '?' . $qs(['export' => 'csv', 'page' => 1])) ?>">
                        <span class="material-icons-round">file_download</span>
                        <!-- CSV -->
                    </a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </header>

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
                <div class="lookup-switch">
                    <a class="<?= $isFabric ? 'on' : '' ?>" href="<?= e(url('fabric')) ?>">Fabric</a>
                    <a class="<?= !$isFabric ? 'on' : '' ?>" href="<?= e(url('trims')) ?>">Trims</a>
                </div>
                <form method="get" action="<?= e(url($report)) ?>" id="rptFilterForm" class="lookup-form">
                    <label class="lookup-so">
                        <span class="material-icons-round">receipt_long</span>
                        <input id="so" name="so" value="" placeholder="Sales order number" inputmode="numeric" autofocus>
                    </label>
                    <button class="rpt-btn lookup-go" type="submit">
                        <span class="material-icons-round">sync</span>
                        <!-- Load report -->
                    </button>
                </form>
            </div>
        </main>
    <?php else: ?>
        <main class="rpt-main">
            <div class="rpt-stats">
                <?php foreach ($cards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <b><?= e($card['value']) ?></b>
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

            <?php if ($records !== []): ?>
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

            <div class="rpt-table-wrap">
                <table class="rpt-table" id="rptDataTable">
                    <thead>
                        <tr class="rpt-header-row">
                            <th class="num sno is-sortable" data-col="0" data-type="num" title="Click to sort by S.No">
                                <div class="th-content">
                                    <span>S.No</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="is-sortable" data-col="1" data-type="text" title="Click to sort by Sales Order">
                                <div class="th-content">
                                    <span>Sales Order</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="is-sortable" data-col="2" data-type="text" title="Click to sort by Material">
                                <div class="th-content">
                                    <span>Material</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="is-sortable" data-col="3" data-type="text" title="Click to sort by Purchase Order">
                                <div class="th-content">
                                    <span>Purchase Order</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="is-sortable" data-col="4" data-type="num" title="Click to sort by PO Line">
                                <div class="th-content">
                                    <span>PO Line</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <!-- <th class="num">SO Qty</th> -->
                            <th class="num is-sortable" data-col="5" data-type="num" title="Click to sort by BOM Qty">
                                <div class="th-content num">
                                    <span>BOM Qty</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="num is-sortable" data-col="6" data-type="num" title="Click to sort by Planned Qty">
                                <div class="th-content num">
                                    <span>Planned</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="num is-sortable" data-col="7" data-type="num" title="Click to sort by Production Qty">
                                <div class="th-content num">
                                    <span>Production</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="num is-sortable" data-col="8" data-type="num" title="Click to sort by PO Qty">
                                <div class="th-content num">
                                    <span>PO Qty</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="num is-sortable" data-col="9" data-type="num" title="Click to sort by GRN Qty">
                                <div class="th-content num">
                                    <span>GRN Qty</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="num is-sortable" data-col="10" data-type="num" title="Click to sort by Issue Qty">
                                <div class="th-content num">
                                    <span>Issue Qty</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                            <th class="is-sortable" data-col="11" data-type="text" title="Click to sort by Additional Sale Orders">
                                <div class="th-content">
                                    <span>Additional Sale Orders</span>
                                    <span class="sort-icon"><i class="fas fa-sort"></i></span>
                                </div>
                            </th>
                        </tr>
                        <?php if ($records !== []): ?>
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
                                        <input type="text" class="rpt-col-input" data-col="3" placeholder="Filter PO..." title="Filter Purchase Order">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="4" placeholder="PO Line..." title="Filter PO Line">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="5" data-numeric="true" placeholder="BOM Qty..." title="Filter BOM Qty (e.g. >0, 100)">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="6" data-numeric="true" placeholder="Planned..." title="Filter Planned Qty (e.g. >0, 100)">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="7" data-numeric="true" placeholder="Prod Qty..." title="Filter Production Qty (e.g. >0, 100)">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="8" data-numeric="true" placeholder="PO Qty..." title="Filter PO Qty (e.g. >0, 100)">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="9" data-numeric="true" placeholder="GRN Qty..." title="Filter GRN Qty (e.g. >0, 100)">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th class="num">
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input num" data-col="10" data-numeric="true" placeholder="Issue Qty..." title="Filter Issue Qty (e.g. >0, 100)">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                                <th>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="11" placeholder="Filter Add. SO..." title="Filter Additional Sale Orders">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody id="rptTableBody">
                        <?php if ($records === []): ?>
                            <tr><td colspan="12" class="rpt-table-empty"><?= e($emptyHint) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $i => $row): ?>
                                <tr data-orig-sno="<?= (int) $i + 1 ?>">
                                    <td class="num sno"><?= (int) $i + 1 ?></td>
                                    <td>
                                        <?= e(str_replace(',', '', $model->dash($row['sales_order'] ?? ''))) ?>
                                    </td>
                                    <td class="rpt-mat"><?= e($model->dash($row['material'] ?? '')) ?></td>
                                    <td>
                                        <?= e(str_replace(',', '', $model->dash($row['purchase_order'] ?? ''))) ?>
                                    </td>
                                    <td>
                                        <?= e(str_replace(',', '', $model->dash($row['po_item'] ?? ''))) ?>
                                    </td>
                                    <td class="num">
                                        <?= e(str_replace(',', '', $model->dash($row['bom_qty'] ?? ''))) ?>
                                    </td>
                                    <td class="num">
                                        <?= e(str_replace(',', '', $model->dash($row['planned_qty'] ?? ''))) ?>
                                    </td>
                                    <td class="num">
                                        <?= e(str_replace(',', '', $model->dash($row['production_qty'] ?? ''))) ?>
                                    </td>
                                    <td class="num">
                                        <?= e(str_replace(',', '', $model->dash($row['po_qty'] ?? ''))) ?>
                                    </td>
                                    <td class="num">
                                        <?= e(str_replace(',', '', $model->dash($row['grn_qty'] ?? ''))) ?>
                                    </td>
                                    <td class="num">
                                        <?= e(str_replace(',', '', $model->dash($row['issue_qty'] ?? ''))) ?>
                                    </td>
                                    <td class="grn-sos"><?= e($model->dash($row['grn_sales_orders'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                                <td colspan="12" class="rpt-table-empty">
                                    <div class="rpt-no-match-card">
                                        <span class="material-icons-round">filter_alt_off</span>
                                        <p>No records match the applied search or filter criteria.</p>
                                        <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($records !== []): ?>
                        <tfoot>
                            <tr id="rptTableFoot">
                                <th colspan="5" id="footTotalLabel">Total (<?= e($model->num($total)) ?> lines)</th>
                                <th class="num" id="footBomQty"><?= e(str_replace(',', '', $model->dash($summary['bom_qty'] ?? null))) ?></th>
                                <th class="num" id="footPlannedQty"><?= e(str_replace(',', '', $model->dash($summary['planned_qty'] ?? null))) ?></th>
                                <th class="num" id="footProductionQty"><?= e(str_replace(',', '', $model->dash($summary['production_qty'] ?? null))) ?></th>
                                <th class="num" id="footPoQty"><?= e(str_replace(',', '', $model->dash($summary['po_qty'] ?? null))) ?></th>
                                <th class="num" id="footGrnQty"><?= e(str_replace(',', '', $model->dash($summary['grn_qty'] ?? null))) ?></th>
                                <th class="num" id="footIssueQty"><?= e(str_replace(',', '', $model->dash($summary['issue_qty'] ?? null))) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </main>
    <?php endif; ?>
</div>
<script type="application/json" id="rptChartData"><?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/utilization.js')) ?>"></script>
