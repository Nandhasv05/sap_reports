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
$kindLabel = $isFabric ? 'Fabric Reports' : 'Trims Reports';
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
            <p>Preparing <?= e($kindLabel) ?>…</p>
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
            <a href="<?= e($home) ?>" class="rpt-brand" aria-label="EVOLV">
                <img src="<?= e($logoUrl) ?>" alt="evolv">
            </a>
            <a href="<?= e($home) ?>" class="rpt-home" aria-label="Portal home">
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
                        Load report
                    </button>
                </form>
                <p class="lookup-hint">Example: <a href="<?= e(url($report) . '?so=4203') ?>">4203</a></p>
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
                            <h2>Quantity mix</h2>
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
                            <h2>BOM by material</h2>
                            <p>Share of BOM quantity</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut">
                        <canvas id="rptMixChart"></canvas>
                    </div>
                </section>
            </div>

            <div class="rpt-table-wrap">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th class="num sno">S.No</th>
                            <th>Sales Order</th>
                            <th>Material</th>
                            <th>Purchase Order</th>
                            <th>PO Item</th>
                            <th class="num">SO Qty</th>
                            <th class="num">BOM Qty</th>
                            <th class="num">Planned</th>
                            <th class="num">Production</th>
                            <th class="num">PO Qty</th>
                            <th class="num">GRN Qty</th>
                            <th class="num">Issue Qty</th>
                            <th>GRN Sales Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($records === []): ?>
                            <tr><td colspan="13" class="rpt-table-empty"><?= e($emptyHint) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $i => $row): ?>
                                <tr>
                                    <td class="num sno"><?= (int) $i + 1 ?></td>
                                    <td><?= e($model->dash($row['sales_order'] ?? '')) ?></td>
                                    <td class="rpt-mat"><?= e($model->dash($row['material'] ?? '')) ?></td>
                                    <td><?= e($model->dash($row['purchase_order'] ?? '')) ?></td>
                                    <td><?= e($model->dash($row['po_item'] ?? '')) ?></td>
                                    <td class="num"><?= e($model->dash($row['so_qty'] ?? null)) ?></td>
                                    <td class="num"><?= e($model->dash($row['bom_qty'] ?? null)) ?></td>
                                    <td class="num"><?= e($model->dash($row['planned_qty'] ?? null)) ?></td>
                                    <td class="num"><?= e($model->dash($row['production_qty'] ?? null)) ?></td>
                                    <td class="num"><?= e($model->dash($row['po_qty'] ?? null)) ?></td>
                                    <td class="num"><?= e($model->dash($row['grn_qty'] ?? null)) ?></td>
                                    <td class="num"><?= e($model->dash($row['issue_qty'] ?? null)) ?></td>
                                    <td class="grn-sos"><?= e($model->dash($row['grn_sales_orders'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($records !== []): ?>
                        <tfoot>
                            <tr>
                                <th colspan="5">Total (<?= e($model->num($total)) ?> lines)</th>
                                <th class="num"><?= e($model->dash($summary['so_qty'] ?? null)) ?></th>
                                <th class="num"><?= e($model->dash($summary['bom_qty'] ?? null)) ?></th>
                                <th class="num"><?= e($model->dash($summary['planned_qty'] ?? null)) ?></th>
                                <th class="num"><?= e($model->dash($summary['production_qty'] ?? null)) ?></th>
                                <th class="num"><?= e($model->dash($summary['po_qty'] ?? null)) ?></th>
                                <th class="num"><?= e($model->dash($summary['grn_qty'] ?? null)) ?></th>
                                <th class="num"><?= e($model->dash($summary['issue_qty'] ?? null)) ?></th>
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
