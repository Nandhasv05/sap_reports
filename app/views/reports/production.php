<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Production report view (SAP ZPROD_NEWSet) - rows are loaded live by production.js
 */
$salesOrder = (string) ($salesOrder ?? '');
$plant = (string) ($plant ?? '');
$plants = is_array($plants ?? null) ? $plants : [];
$columns = is_array($columns ?? null) ? $columns : [];
$range = is_array($range ?? null) ? $range : null;
$rangeError = (string) ($rangeError ?? '');
$rangeInput = is_array($rangeInput ?? null) ? $rangeInput : null;
$presets = is_array($presets ?? null) ? $presets : [];
$isRange = $range !== null;
$isLookup = $rangeError !== '' || ($salesOrder === '' && !$isRange);
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$catalogHome = url('/');
$colCount = count($columns);
$stageNames = ['order' => 'Order', 'cut' => 'Cutting', 'sew' => 'Sewing', 'wash' => 'Washing', 'fin' => 'Finishing', 'ship' => 'Shipment'];
$csvUrl = url('production') . '?' . http_build_query(array_filter($isRange
    ? ['range' => $range['preset'], 'from' => $range['from'], 'to' => $range['to'], 'so' => $salesOrder, 'plant' => $plant, 'export' => 'csv']
    : ['so' => $salesOrder, 'plant' => $plant, 'export' => 'csv'], static fn ($v): bool => $v !== ''));
$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$datePreset = (string) ($range['preset'] ?? $rangeInput['preset'] ?? '');
$filterText = trim(($salesOrder !== '' ? 'SO ' . $salesOrder : '') . ($salesOrder !== '' && $plant !== '' ? ' · ' : '') . ($plant !== '' ? 'Plant ' . $plant : ''));
$dateFrom = (string) ($range['from'] ?? ($_GET['from'] ?? ''));
$dateTo = (string) ($range['to'] ?? ($_GET['to'] ?? ''));
$rangeText = $isRange
    ? ($range['preset'] === 'custom' ? $range['label'] : $range['label'] . ' (' . ($range['from'] === $range['to'] ? date('d M Y', strtotime($range['from'])) : date('d M', strtotime($range['from'])) . ' – ' . date('d M Y', strtotime($range['to']))) . ')')
    : '';
$reportTag = $isRange
    ? $range['from'] . '_to_' . $range['to'] . ($salesOrder !== '' ? '-SO' . $salesOrder : '') . ($plant !== '' ? '-' . $plant : '')
    : 'SO' . $salesOrder;
$soInput = static function (string $class, string $placeholder) use ($salesOrder): string {
    return '<label class="' . e($class) . '" title="Sales order number (optional when a date is chosen)">'
        . '<span class="material-icons-round">receipt_long</span>'
        . '<input id="so" name="so" value="' . e($salesOrder) . '" placeholder="' . e($placeholder) . '" inputmode="numeric" autocomplete="off"></label>';
};

$dateInputs = static function (string $fieldClass, bool $showCustom) use ($dateFrom, $dateTo, $today): string {
    $attrs = ' max="' . e($today) . '"' . ($showCustom ? ' required' : ' disabled');
    return '<label class="' . e($fieldClass) . '" data-date-custom' . ($showCustom ? '' : ' hidden') . ' title="From (SO creation date)">'
        . '<span class="material-icons-round">event</span>'
        . '<input type="date" name="from" value="' . e($dateFrom) . '"' . $attrs . ' aria-label="From date"></label>'
        . '<label class="' . e($fieldClass) . '" data-date-custom' . ($showCustom ? '' : ' hidden') . ' title="To (SO creation date)">'
        . '<span class="material-icons-round">event</span>'
        . '<input type="date" name="to" value="' . e($dateTo) . '"' . $attrs . ' aria-label="To date"></label>';
};

$cards = [
    ['id' => 'total_so_qty',     'label' => 'Total SO Qty',     'icon' => 'receipt_long',            'tone' => 'teal'],
    ['id' => 'cut_made',         'label' => 'Cut Made',         'icon' => 'content_cut',             'tone' => 'sky'],
    ['id' => 'sew_made',         'label' => 'Sew Made',         'icon' => 'checkroom',               'tone' => 'indigo'],
    ['id' => 'wash_received',    'label' => 'Wash Received',    'icon' => 'local_laundry_service',   'tone' => 'mint'],
    ['id' => 'fin_made',         'label' => 'Finished',         'icon' => 'verified',                'tone' => 'amber'],
    ['id' => 'shipment',         'label' => 'Shipped',          'icon' => 'local_shipping',          'tone' => 'violet'],
    ['id' => 'pending_delivery', 'label' => 'Pending Delivery', 'icon' => 'pending_actions',         'tone' => 'rose'],
];

$plantInput = static function (string $id) use ($plant): string {
    return '<input id="' . e($id) . '" name="plant" class="plant-input" value="' . e($plant) . '" placeholder="Plant"'
        . ' maxlength="4" autocomplete="off" spellcheck="false" list="prodPlantList"'
        . ' title="Plant code, e.g. P002 (optional). Leave empty to use every plant.">';
};
?>
<datalist id="prodPlantList">
    <?php foreach ($plants as $p): ?>
        <option value="<?= e($p) ?>"></option>
    <?php endforeach; ?>
</datalist>
<div class="rpt-app is-production <?= $isLookup ? 'is-lookup' : 'is-report' ?>">
    <?php
        $loaderIcon = 'precision_manufacturing';
        $loaderSteps = $isRange || $isLookup
            ? ['Connecting to SAP…', 'Finding sales orders created in this period…', 'Preparing report…']
            : ['Connecting to SAP…', 'Finding plant for the sales order…', 'Fetching cutting, sewing & washing…', 'Fetching finishing & shipment…', 'Preparing report…'];
        $loaderVisible = !$isLookup;
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
            <form class="rpt-search prod-filters" method="get" action="<?= e(url('production')) ?>" id="rptFilterForm" data-date-filter>
                <label class="rpt-field rpt-field-range" title="SO creation date">
                    <span class="material-icons-round">date_range</span>
                    <select name="range" data-range-select aria-label="SO creation date">
                        <option value=""<?= $datePreset === '' ? ' selected' : '' ?>>All dates</option>
                        <?php foreach ($presets as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $datePreset ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?= $dateInputs('rpt-field rpt-field-date', $datePreset === 'custom') ?>
                <?= $soInput('rpt-field rpt-field-so', 'Sales order') ?>
                <label class="rpt-field rpt-field-plant">
                    <span class="material-icons-round">factory</span>
                    <?= $plantInput('plant') ?>
                </label>
                <button class="rpt-btn" type="submit" title="Load report">
                    <span class="material-icons-round">sync</span>
                </button>
                <a class="rpt-btn rpt-btn-ghost" href="<?= e($csvUrl) ?>" title="Download CSV">
                    <span class="material-icons-round">file_download</span>
                </a>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($isLookup): ?>
        <main class="lookup">
            <div class="lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
            <div class="lookup-card">
                <p class="lookup-kicker">Production report</p>
                <h1>Find production</h1>
                <p class="lookup-lede">Pull cutting, sewing, washing, finishing and shipment quantities from SAP.</p>
                <form method="get" action="<?= e(url('production')) ?>" id="rptFilterForm" class="lookup-filters" data-date-filter>
                    <div class="lookup-form">
                        <?= $soInput('lookup-so', 'Sales order number') ?>
                        <label class="lookup-so lookup-plant">
                            <span class="material-icons-round">factory</span>
                            <?= $plantInput('plant') ?>
                        </label>
                    </div>

                    <p class="lookup-label">SO created</p>
                    <div class="date-chips" role="radiogroup" aria-label="SO creation date">
                        <?php foreach (['' => 'All dates'] + $presets as $key => $label): ?>
                            <label class="date-chip<?= (string) $key === $datePreset ? ' on' : '' ?>">
                                <input type="radio" name="range" value="<?= e((string) $key) ?>"<?= (string) $key === $datePreset ? ' checked' : '' ?>>
                                <?php if ($key === 'custom'): ?><span class="material-icons-round">edit_calendar</span><?php endif; ?>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="date-custom" data-date-custom<?= $datePreset === 'custom' ? '' : ' hidden' ?>>
                        <?= $dateInputs('lookup-so date-input', $datePreset === 'custom') ?>
                    </div>
                    <?php if ($rangeError !== ''): ?>
                        <p class="date-error" role="alert"><?= e($rangeError) ?></p>
                    <?php endif; ?>

                    <button class="rpt-btn lookup-submit" type="submit">
                        <span class="material-icons-round">sync</span>
                        Show report
                    </button>
                </form>
                <p class="lookup-hint">Enter a <b>sales order</b>, pick an <b>SO created</b> date, or both. Plant is optional (dates up to <?= (int) SapProductionService::MAX_RANGE_DAYS ?> days).</p>
            </div>
        </main>
    <?php else: ?>
        <main class="rpt-main is-pending" id="rptMain">
            <div class="prod-notice" id="prodNotice" hidden>
                <span class="material-icons-round">info</span>
                <span id="prodNoticeText"></span>
            </div>
            <?php if ($isRange): ?>
                <div class="prod-info" id="prodInfo" hidden>
                    <div class="prod-info-item is-wide"><small>SO Created</small><b><?= e($rangeText) ?></b></div>
                    <?php if ($filterText !== ''): ?>
                        <div class="prod-info-item"><small>Filter</small><b><?= e($filterText) ?></b></div>
                    <?php endif; ?>
                    <div class="prod-info-item"><small>Sales Orders</small><b id="prodInfoOrders">—</b></div>
                    <div class="prod-info-item"><small>Plants</small><b id="prodInfoPlant">—</b></div>
                    <div class="prod-info-item"><small>Customers</small><b id="prodInfoCustomers">—</b></div>
                    <div class="prod-info-item"><small>Lines</small><b id="prodInfoLines">—</b></div>
                </div>
            <?php else: ?>
                <div class="prod-info" id="prodInfo" hidden>
                    <div class="prod-info-item"><small>Sales Order</small><b id="prodInfoSo"><?= e($salesOrder) ?></b></div>
                    <div class="prod-info-item"><small>Plant</small><b id="prodInfoPlant">—</b></div>
                    <div class="prod-info-item is-wide"><small>Customer</small><b id="prodInfoCustomer">—</b></div>
                    <div class="prod-info-item"><small>Header Material</small><b id="prodInfoMaterial">—</b></div>
                    <div class="prod-info-item"><small>Req. Delivery</small><b id="prodInfoDelivery">—</b></div>
                    <div class="prod-info-item"><small>Over Del. Tol.</small><b id="prodInfoTolerance">—</b></div>
                </div>
            <?php endif; ?>

            <div class="rpt-stats">
                <?php foreach ($cards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap"><b id="prodStat-<?= e($card['id']) ?>">—</b></div>
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
                            <h2>Production Stage Progress</h2>
                            <p>Total SO qty against cut, sewn, washed, finished and shipped</p>
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
                            <h2>Total SO Qty By Colour</h2>
                            <p>Share of order quantity</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut">
                        <canvas id="rptMixChart"></canvas>
                    </div>
                </section>
            </div>

            <div class="rpt-table-toolbar">
                <div class="rpt-tb-search">
                    <span class="material-icons-round rpt-search-ico">search</span>
                    <input type="search" id="rptTableSearch" class="rpt-tb-input" placeholder="<?= e($isRange ? 'Quick search table (Sales Order, Customer, Style...)' : 'Quick search table (Style, Colour, Size...)') ?>" autocomplete="off" spellcheck="false">
                    <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="rpt-tb-actions">
                    <span class="rpt-tb-count" id="rptTableCount">Loading…</span>

                    <button type="button" class="rpt-tb-btn is-active" id="rptToggleStickyScroll" title="Keep table header fixed and scroll body only">
                        <i class="fas fa-arrows-up-down"></i>
                        <span id="rptStickyScrollLabel">Scroll Body Only</span>
                    </button>

                    <div class="rpt-cat-dropdown" id="rptColumnToggleDropdown">
                        <button type="button" class="rpt-tb-btn" id="rptColumnToggleBtn" title="Manage columns: reorder, show or hide (saved to your account)">
                            <i class="fas fa-columns"></i>
                            <span>Columns</span>
                            <span class="material-icons-round rpt-cat-dd-arrow" style="font-size: 14px;">expand_more</span>
                        </button>
                        <div class="rpt-cat-dd-menu" id="rptColumnToggleMenu" role="menu" style="right:0; left:auto; max-height:400px; overflow-y:auto;"></div>
                    </div>

                    <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="rptDataTable"
                            data-excel-title="<?= e('Production Report — ' . ($isRange ? 'SO Created ' . $rangeText . ($filterText !== '' ? ' · ' . $filterText : '') : 'Sales Order ' . $salesOrder)) ?>"
                            data-excel-file="<?= e('production-' . $reportTag) ?>"
                            data-excel-sheet="<?= e($isRange ? 'Production' : 'Production ' . $salesOrder) ?>"
                            title="Download the table as an Excel file (visible columns and filtered rows)">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                    </button>

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

            <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
                <table class="rpt-table prod-table" id="rptDataTable">
                    <thead>
                        <tr class="rpt-header-row">
                            <?php foreach ($columns as $i => $col): ?>
                                <?php $stageTitle = $stageNames[$col['stage']] ?? ''; ?>
                                <th class="is-sortable<?= $col['type'] === 'num' ? ' num' : '' ?><?= $col['key'] === 'sno' ? ' sno' : '' ?><?= $col['stage'] !== '' ? ' stage-' . e($col['stage']) : '' ?>"
                                    data-col="<?= (int) $i ?>" data-type="<?= e($col['type']) ?>"
                                    title="<?= e(($stageTitle !== '' ? $stageTitle . ': ' : '') . 'Click to sort by ' . $col['label']) ?>">
                                    <div class="th-content<?= $col['type'] === 'num' ? ' num' : '' ?>"><span><?= e($col['label']) ?></span></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="rpt-filter-row" id="rptFilterRow">
                            <?php foreach ($columns as $i => $col): ?>
                                <?php if ($col['key'] === 'sno'): ?>
                                    <th class="num sno">
                                        <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                            <i class="fas fa-eraser"></i>
                                        </button>
                                    </th>
                                <?php else: ?>
                                    <th<?= $col['type'] === 'num' ? ' class="num"' : '' ?>>
                                        <div class="rpt-col-input-wrap">
                                            <input type="text" class="rpt-col-input" data-col="<?= (int) $i ?>"<?= $col['type'] === 'num' ? ' data-numeric' : '' ?>
                                                   placeholder="<?= e($col['type'] === 'num' ? '>0, <10…' : 'Filter…') ?>" title="Filter <?= e($col['label']) ?>">
                                            <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                        </div>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="rptTableBody">
                        <tr class="rpt-table-loading">
                            <td colspan="<?= (int) $colCount ?>" class="rpt-table-skeleton"><div class="rpt-skel-rows" aria-label="<?= e($isRange ? 'Loading production data for ' . $rangeText : 'Loading production data for sales order ' . $salesOrder) ?>"><i></i><i></i><i></i><i></i><i></i><i></i></div></td>
                        </tr>
                        <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                            <td colspan="<?= (int) $colCount ?>" class="rpt-table-empty">
                                <div class="rpt-no-match-card">
                                    <span class="material-icons-round">filter_alt_off</span>
                                    <p>No records match the applied search or filter criteria.</p>
                                    <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr id="rptTableFoot">
                            <?php foreach ($columns as $i => $col): ?>
                                <?php if ($col['key'] === 'sno'): ?>
                                    <th id="footTotalLabel">Total</th>
                                <?php else: ?>
                                    <th<?= $col['type'] === 'num' ? ' class="num" data-foot-col="' . (int) $i . '"' : '' ?>></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </main>
    <?php endif; ?>
</div>
<script type="application/json" id="rptPageConfig"><?= json_encode([
    'report'        => 'production',
    'so'            => $salesOrder,
    'plant'         => $plant,
    'live'          => !$isLookup,
    'range'         => $isRange ? ['preset' => $range['preset'], 'from' => $range['from'], 'to' => $range['to'], 'text' => $rangeText, 'filter' => $filterText] : null,
    'maxRangeDays'  => SapProductionService::MAX_RANGE_DAYS,
    'dataUrl'       => url('production/data'),
    'pageUrl'       => url('production'),
    'columns'       => $columns,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<?php if (!$isLookup): ?>
<?php $prefTableKey = $isRange ? 'production_range_table' : 'production_table'; require base_path('app/views/partials/table_preferences.php'); ?>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/production.js')) ?>"></script>
