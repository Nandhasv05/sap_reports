<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : Purchase Order report view (SAP ZI_PurchaseOrderAPI_HUB) - header and items are loaded live by purchase-order.js
 */
$purchaseOrder = (string) ($purchaseOrder ?? '');
$poInput = (string) ($poInput ?? '');
$poError = $poError ?? null;
$isLookup = $purchaseOrder === '';
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$catalogHome = url('/');
$pageUrl = url('purchase-order');
$columns = [
    ['label' => 'S.No',         'num' => true,  'cls' => 'sno'],
    ['label' => 'Item',         'num' => false, 'cls' => ''],
    ['label' => 'Material',     'num' => false, 'cls' => ''],
    ['label' => 'Description',  'num' => false, 'cls' => ''],
    ['label' => 'Plant',        'num' => false, 'cls' => ''],
    ['label' => 'Storage Loc.', 'num' => false, 'cls' => ''],
    ['label' => 'Order Qty',    'num' => true,  'cls' => ''],
    ['label' => 'Unit',         'num' => false, 'cls' => ''],
    ['label' => 'Net Price',    'num' => true,  'cls' => ''],
    ['label' => 'Net Value',    'num' => true,  'cls' => ''],
    ['label' => 'Currency',     'num' => false, 'cls' => ''],
    ['label' => 'Value Share',  'num' => true,  'cls' => ''],
    ['label' => 'Sales Orders', 'num' => false, 'cls' => 'po-col-sos'],
];
$cards = [
    ['id' => 'items',     'label' => 'Items',           'icon' => 'format_list_numbered', 'tone' => 'indigo'],
    ['id' => 'materials', 'label' => 'Materials',       'icon' => 'category',             'tone' => 'teal'],
    ['id' => 'qty',       'label' => 'Total Order Qty', 'icon' => 'straighten',           'tone' => 'sky'],
    ['id' => 'value',     'label' => 'Total Net Value', 'icon' => 'payments',             'tone' => 'mint'],
    ['id' => 'so',        'label' => 'Sales Orders',    'icon' => 'receipt_long',         'tone' => 'amber'],
];
?>
<div class="rpt-app is-purchase-order <?= $isLookup ? 'is-lookup' : 'is-report' ?>">
    <?php
        $loaderIcon = 'receipt';
        $loaderSteps = ['Connecting to SAP…', 'Fetching purchase order…', 'Reading items & sales orders…', 'Preparing report…'];
        require base_path('app/views/partials/loader.php');
    ?>

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
            <div class="rpt-name"><?= e($item['title']) ?></div>
        </div>

        <?php if (!$isLookup): ?>
            <form class="rpt-search" method="get" action="<?= e($pageUrl) ?>" id="poFilterForm" novalidate>
                <label class="rpt-field">
                    <span class="material-icons-round">receipt</span>
                    <input id="po" name="po" value="<?= e($purchaseOrder) ?>" placeholder="Purchase order" inputmode="numeric" maxlength="10" autocomplete="off" required>
                </label>
                <button class="rpt-btn" type="submit" title="Load report">
                    <span class="material-icons-round">sync</span>
                </button>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($isLookup): ?>
        <main class="lookup">
            <div class="lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
            <div class="lookup-card">
                <p class="lookup-kicker">Purchase order report</p>
                <h1>Find a purchase order</h1>
                <p class="lookup-lede">Pull the purchase order header, items, quantities, prices and linked sales orders from SAP.</p>
                <form method="get" action="<?= e($pageUrl) ?>" id="poFilterForm" class="lookup-form" novalidate>
                    <label class="lookup-so<?= $poError !== null ? ' is-invalid' : '' ?>">
                        <span class="material-icons-round">receipt</span>
                        <input id="po" name="po" value="<?= e($poInput) ?>" placeholder="Purchase order number" inputmode="numeric" maxlength="10" autocomplete="off" autofocus required aria-describedby="poFieldError">
                    </label>
                    <button class="rpt-btn lookup-go" type="submit" title="Load report">
                        <span class="material-icons-round">sync</span>
                    </button>
                </form>
                <p class="po-field-error" id="poFieldError" role="alert"<?= $poError === null ? ' hidden' : '' ?>><?= e((string) $poError) ?></p>
                <p class="lookup-hint">Example: <b>4000006524</b></p>
            </div>
        </main>
    <?php else: ?>
        <main class="rpt-main">
            <div class="rpt-alert" id="poAlert" hidden></div>

            <section class="po-hero" id="poHero">
                <div class="po-hero-main">
                    <span class="po-hero-icon material-icons-round" aria-hidden="true">receipt</span>
                    <div class="po-hero-title">
                        <span class="po-hero-kicker">Purchase Order</span>
                        <h1><?= e($purchaseOrder) ?></h1>
                        <p id="poHeroSub">Loading from SAP…</p>
                    </div>
                </div>
                <dl class="po-hero-meta" id="poHeroMeta">
                    <?php foreach (['PO Date', 'Supplier', 'Company Code', 'Purchasing Org.', 'Purchasing Group', 'Created By', 'Plant'] as $label): ?>
                        <div><dt><?= e($label) ?></dt><dd>—</dd></div>
                    <?php endforeach; ?>
                </dl>
                <div class="po-hero-sos">
                    <span>Linked Sales Orders</span>
                    <div id="poHeroSos"><span class="po-muted">—</span></div>
                </div>
            </section>

            <div class="rpt-stats po-stat-row">
                <?php foreach ($cards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap"><b id="poStat-<?= e($card['id']) ?>">—</b><em class="po-stat-unit" id="poStatUnit-<?= e($card['id']) ?>"></em></div>
                            <small><?= e($card['label']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="rpt-charts po-charts">
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">bar_chart</span>
                        <div>
                            <h2>Order Qty &amp; Net Value By Item</h2>
                            <p>Bars show order quantity, the line shows net value</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body"><canvas id="poItemChart"></canvas></div>
                </section>
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">donut_large</span>
                        <div>
                            <h2>Net Value Share By Material</h2>
                            <p>Share of the purchase order value</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut"><canvas id="poShareChart"></canvas></div>
                </section>
            </div>

            <div class="rpt-table-toolbar">
                <div class="rpt-tb-search">
                    <span class="material-icons-round rpt-search-ico">search</span>
                    <input type="search" id="rptTableSearch" class="rpt-tb-input" placeholder="Quick search (Material, Description, Sales order...)" autocomplete="off" spellcheck="false">
                    <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="rpt-tb-actions">
                    <span class="rpt-tb-count" id="rptTableCount">Loading…</span>
                    <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="rptDataTable"
                            data-excel-title="<?= e('Purchase Order ' . $purchaseOrder) ?>"
                            data-excel-file="<?= e('purchase-order-' . $purchaseOrder) ?>"
                            data-excel-sheet="<?= e('PO ' . $purchaseOrder) ?>"
                            title="Download the table as an Excel file (filtered rows)">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                    </button>
                </div>
            </div>

            <div class="rpt-table-wrap po-table-card" id="rptTableWrap">
                <table class="rpt-table prod-table po-page-table" id="rptDataTable">
                    <thead>
                        <tr class="rpt-header-row">
                            <?php foreach ($columns as $col): ?>
                                <th class="<?= trim(($col['num'] ? 'num ' : '') . $col['cls']) ?>">
                                    <div class="th-content<?= $col['num'] ? ' num' : '' ?>"><span><?= e($col['label']) ?></span></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="rptTableBody">
                        <tr class="rpt-table-loading">
                            <td colspan="<?= count($columns) ?>" class="rpt-table-skeleton"><div class="rpt-skel-rows" aria-label="Loading purchase order <?= e($purchaseOrder) ?>"><i></i><i></i><i></i><i></i></div></td>
                        </tr>
                        <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                            <td colspan="<?= count($columns) ?>" class="rpt-table-empty">
                                <div class="rpt-no-match-card">
                                    <span class="material-icons-round">filter_alt_off</span>
                                    <p>No items match the search.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr id="rptTableFoot"></tr>
                    </tfoot>
                </table>
            </div>
        </main>
    <?php endif; ?>
</div>
<script type="application/json" id="poConfig"><?= json_encode([
    'po'      => $purchaseOrder,
    'dataUrl' => url('purchase-order/data'),
    'pageUrl' => $pageUrl,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<script src="<?= e(asset('js/po-report.js')) ?>"></script>
<?php if (!$isLookup): ?>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/purchase-order.js')) ?>"></script>
