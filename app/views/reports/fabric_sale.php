<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/10/2026
 * DESCRIPTION : Fabric Sale Order — Production-style lookup → dashboard → 90% fabric offcanvas
 */
$from = (string) ($from ?? '');
$to = (string) ($to ?? ''); 
$rangePreset = (string) ($rangePreset ?? '');
$presets = is_array($presets ?? null) ? $presets : [];
$presetDates = is_array($presetDates ?? null) ? $presetDates : [];
$soQuery = (string) ($soQuery ?? '');
$autoSearch = !empty($autoSearch);
$isCustom = $rangePreset === 'custom';
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$extraHead = '<link rel="stylesheet" href="' . e(asset('css/fabricSale.css')) . '?v=' . time() . '">';
?>
<div class="rpt-app is-fabric-sale <?= $autoSearch ? 'is-details' : 'is-lookup' ?>" id="fsoPage"
     data-from="<?= e($from) ?>"
     data-to="<?= e($to) ?>"
     data-preset="<?= e($rangePreset) ?>"
     data-so="<?= e($soQuery) ?>"
     data-auto-search="<?= $autoSearch ? '1' : '0' ?>"
     data-orders-url="<?= e(url('sales/orders-by-date')) ?>"
     data-so-url="<?= e(url('sales/sales-api')) ?>"
     data-fabric-url="<?= e(url('fabric/data')) ?>"
     data-fabric-report-url="<?= e(url('fabric')) ?>"
     data-preset-dates="<?= e(json_encode($presetDates, JSON_UNESCAPED_SLASHES)) ?>">

    <?php
        $loaderIcon = 'checkroom';
        $loaderSteps = ['Connecting to SAP…', 'Loading sale orders…', 'Preparing dashboard…'];
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
            <div class="rpt-name">Fabric Sale Order</div>
        </div>
        <form class="rpt-search prod-filters fso-header-filters" id="fsoHeaderForm" autocomplete="off" novalidate>
            <label class="rpt-field rpt-field-range" title="Creation date range">
                <span class="material-icons-round">date_range</span>
                <select id="fsoHdrPreset" name="range" aria-label="Date range">
                    <option value=""<?= $rangePreset === '' ? ' selected' : '' ?>>Date range</option>
                    <?php foreach ($presets as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"<?= $rangePreset === (string) $key ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="date-custom fso-hdr-custom" id="fsoHdrCustomDates"<?= $isCustom ? '' : ' hidden' ?> style="display: <?= $isCustom ? 'flex' : 'none' ?>; gap: 6px;">
                <label class="rpt-field rpt-field-date" title="From date">
                    <span class="date-cap">From</span>
                    <input type="date" id="fsoHdrFrom" name="from" value="<?= e($from) ?>" aria-label="From date">
                </label>
                <label class="rpt-field rpt-field-date" title="To date">
                    <span class="date-cap">To</span>
                    <input type="date" id="fsoHdrTo" name="to" value="<?= e($to) ?>" aria-label="To date">
                </label>
            </div>
            <label class="rpt-field po-direct-search fso-hdr-search" title="Search sales order">
                <span class="material-icons-round">search</span>
                <input type="search" id="fsoHdrSo" name="so" value="<?= e($soQuery) ?>" placeholder="Search SO #" inputmode="numeric" autocomplete="off" aria-label="Search sales order">
            </label>
            <button type="submit" class="rpt-btn po-direct-search-btn" id="fsoHdrSubmit" title="Show report">
                <span class="material-icons-round">arrow_forward</span>
            </button>
            <button type="button" class="rpt-btn rpt-btn-ghost" id="fsoHdrLookup" title="Open search screen">
                <span class="material-icons-round">apps</span>
            </button>
        </form>
        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <!-- Lookup only (Production-style) -->
    <main class="lookup" id="fsoLanding">
        <div class="lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="lookup-card">
            <p class="lookup-kicker">Fabric Sale Order</p>
            <h1>Find fabric sale orders</h1>
            <p class="lookup-lede">Search by sales order <b>or</b> date range (one at a time), then open sale order details.</p>

            <form id="fsoSearchForm" class="lookup-filters" autocomplete="off" novalidate>
                <div class="lookup-form">
                    <label class="lookup-so" title="Sales order number">
                        <span class="material-icons-round">receipt_long</span>
                        <input type="text" id="fsoSoInput" name="so" value="<?= e($soQuery) ?>"
                               placeholder="Sales order number" inputmode="numeric" autocomplete="off">
                    </label>
                </div>

                <p class="lookup-label">Date range <span class="lookup-label-note">or sales order — one only *</span></p>
                <div class="lookup-range-wrap">
                    <label class="lookup-so lookup-range" title="Date Range">
                        <span class="material-icons-round">date_range</span>
                        <select id="fsoPreset" name="range" aria-label="Date Range">
                            <option value=""<?= $rangePreset === '' ? ' selected' : '' ?>>Select Date Range…</option>
                            <?php foreach ($presets as $key => $label): ?>
                                <option value="<?= e((string) $key) ?>"<?= $rangePreset === (string) $key ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="date-custom" id="fsoCustomDates"<?= $isCustom ? '' : ' hidden' ?> style="display: flex; gap: 10px; margin-top: 10px;">
                    <label class="lookup-so date-input" title="From date">
                        <span class="material-icons-round">event</span>
                        <input type="date" id="fsoFrom" name="from" value="<?= e($from) ?>" aria-label="From date">
                    </label>
                    <label class="lookup-so date-input" title="To date">
                        <span class="material-icons-round">event</span>
                        <input type="date" id="fsoTo" name="to" value="<?= e($to) ?>" aria-label="To date">
                    </label>
                </div>

                <p class="date-error" role="alert" id="fsoFormError" hidden></p>

                <button class="rpt-btn lookup-submit" type="submit" id="fsoSearchBtn">
                    <span class="material-icons-round">sync</span>
                    Show report
                </button>
            </form>

            <p class="lookup-hint">Use <b>either</b> a sales order <b>or</b> a date range — not both. Choosing one clears the other.</p>
        </div>
    </main>

    <!-- Full-screen sale order details -->
    <section class="fso-dashboard" id="fsoDashboard">
        <div class="rpt-stats po-stat-row fso-stat-row" id="fsoKpis">
            <article class="rpt-stat tone-indigo">
                <span class="material-icons-round">receipt_long</span>
                <div>
                    <div class="stat-value-wrap"><b id="kpiOrders">0</b></div>
                    <small>Sale Orders</small>
                </div>
            </article>
            <article class="rpt-stat tone-teal">
                <span class="material-icons-round">format_list_numbered</span>
                <div>
                    <div class="stat-value-wrap"><b id="kpiLines">0</b></div>
                    <small>Total Lines</small>
                </div>
            </article>
            <article class="rpt-stat tone-sky">
                <span class="material-icons-round">straighten</span>
                <div>
                    <div class="stat-value-wrap"><b id="kpiQty">0</b></div>
                    <small>Total Order Qty</small>
                </div>
            </article>
            <article class="rpt-stat tone-mint">
                <span class="material-icons-round">payments</span>
                <div>
                    <div class="stat-value-wrap"><b id="kpiValue">0</b><em class="po-stat-unit" id="kpiCurrency">INR</em></div>
                    <small>Total Net Value</small>
                </div>
            </article>
        </div>

        <div class="fso-table-card">
            <div class="rpt-table-toolbar fso-table-toolbar">
                <div class="rpt-tb-search">
                    <span class="material-icons-round rpt-search-ico">search</span>
                    <input type="search" id="fsoTableFilter" class="rpt-tb-input"
                           placeholder="Quick search SO #, Buyer, Material, Status…"
                           autocomplete="off" spellcheck="false">
                    <button type="button" id="fsoTableFilterClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="rpt-tb-actions">
                    <span class="rpt-tb-count" id="fsoTableCount">Showing 0 sale orders</span>

                    <button type="button" class="rpt-tb-btn is-active" id="fsoToggleStickyScroll" title="Keep table header fixed and scroll body only">
                        <i class="fas fa-arrows-up-down"></i>
                        <span id="fsoStickyScrollLabel">Scroll Body Only</span>
                    </button>

                    <div class="rpt-cat-dropdown" id="rptColumnToggleDropdown">
                        <button type="button" class="rpt-tb-btn" id="rptColumnToggleBtn" title="Manage columns: reorder, show or hide (saved to your account)">
                            <i class="fas fa-columns"></i>
                            <span>Columns</span>
                            <span class="material-icons-round rpt-cat-dd-arrow" style="font-size: 14px;">expand_more</span>
                        </button>
                        <div class="rpt-cat-dd-menu" id="rptColumnToggleMenu" role="menu" style="right:0; left:auto;"></div>
                    </div>

                    <button type="button" class="rpt-tb-btn rpt-tb-btn-excel" data-excel-export="fsoTable"
                            data-excel-title="Fabric Sale Orders"
                            data-excel-file="fabric-sale-orders"
                            data-excel-sheet="Sale Orders"
                            title="Download the table as an Excel file">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                    </button>

                    <button type="button" class="rpt-tb-btn is-active" id="fsoToggleColFilters" title="Toggle column filter inputs">
                        <i class="fas fa-filter"></i>
                        <span>Column Filters</span>
                        <span class="rpt-tb-badge" id="fsoActiveFilterBadge" style="display: none;">0</span>
                    </button>

                    <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="fsoClearAllFilters" title="Clear all search and filters" style="display: none;">
                        <i class="fas fa-rotate-left"></i>
                        <span>Clear All</span>
                    </button>
                </div>
            </div>

            <?php
            require_once base_path('app/services/TableColumnRegistry.php');
            $fsoNumKeys = [
                'sno', 'lines', 'qty', 'requested_qty', 'confd_deliv_qty',
                'unit_price', 'value', 'cost', 'tax',
            ];
            $fsoRegistry = TableColumnRegistry::get('fabric_sale_table') ?? ['columns' => []];
            $fsoColumns = [];
            foreach ($fsoRegistry['columns'] as $col) {
                $id = (string) ($col['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $fsoColumns[] = [
                    'key'    => $id,
                    'label'  => (string) ($col['label'] ?? $id),
                    'type'   => in_array($id, $fsoNumKeys, true) ? 'num' : 'text',
                    'locked' => !empty($col['locked']),
                ];
            }
            $fsoColCount = count($fsoColumns);
            ?>
            <div class="rpt-table-wrap fso-table-wrap is-scroll-body" id="fsoTableWrap">
                <table class="rpt-table prod-table fso-page-table text-nowrap" id="fsoTable"
                       data-fso-columns="<?= e(json_encode($fsoColumns, JSON_UNESCAPED_SLASHES)) ?>">
                    <thead>
                        <tr class="rpt-header-row">
                            <?php foreach ($fsoColumns as $col): ?>
                                <?php $isNum = ($col['type'] ?? '') === 'num'; ?>
                                <th class="<?= $isNum ? 'num ' : '' ?><?= ($col['key'] ?? '') === 'sno' ? 'sno ' : '' ?>is-sortable"
                                    data-col-id="<?= e($col['key']) ?>"
                                    data-fso-key="<?= e($col['key']) ?>"
                                    data-type="<?= e($col['type'] ?? 'text') ?>">
                                    <div class="th-content<?= $isNum ? ' num' : '' ?>"><span><?= e($col['label']) ?></span></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="rpt-filter-row" id="fsoFilterRow">
                            <?php foreach ($fsoColumns as $col): ?>
                                <?php if (($col['key'] ?? '') === 'sno'): ?>
                                    <th class="sno" data-col-id="sno" data-fso-key="sno">
                                        <button type="button" class="rpt-col-filter-reset" id="fsoColFilterReset" title="Clear column filters" aria-label="Clear column filters">
                                            <i class="fas fa-eraser"></i>
                                        </button>
                                    </th>
                                <?php else: ?>
                                    <th class="<?= ($col['type'] ?? '') === 'num' ? 'num' : '' ?>"
                                        data-col-id="<?= e($col['key']) ?>"
                                        data-fso-key="<?= e($col['key']) ?>">
                                        <div class="rpt-col-input-wrap">
                                            <input type="text" class="rpt-col-input" data-fso-col="<?= e($col['key']) ?>"
                                                   placeholder="<?= ($col['type'] ?? '') === 'num' ? '>0…' : 'Filter…' ?>">
                                            <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                        </div>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="fsoTableBody">
                        <tr><td colspan="<?= (int) $fsoColCount ?>" class="rpt-table-empty">Run a search to load sale orders.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php require base_path('app/views/partials/footer.php'); ?>

    <!-- 90% Fabric Utilization offcanvas -->
    <div class="fso-drawer" id="fsoDrawer" hidden aria-hidden="true">
        <div class="fso-drawer-backdrop" id="fsoDrawerBackdrop"></div>
        <aside class="fso-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="fsoDrawerTitle">
            <header class="fso-drawer-head">
                <div class="fso-drawer-head-main">
                    <p class="fso-drawer-eyebrow">Fabric Utilization · FABRIC_UTILIZATIONSet</p>
                    <h2 id="fsoDrawerTitle">Sale Order</h2>
                    <p class="fso-drawer-meta" id="fsoDrawerMeta"></p>
                </div>
                <div class="fso-drawer-head-actions">
                    <a class="fso-drawer-open-full" id="fsoDrawerOpenFull" href="#" target="_blank" rel="noopener" hidden>
                        <span class="material-icons-round">open_in_new</span>
                        <span>Full report</span>
                    </a>
                    <button type="button" class="fso-drawer-close" id="fsoDrawerClose" aria-label="Close">&times;</button>
                </div>
            </header>
            <div class="fso-drawer-body" id="fsoDrawerBody">
                <div class="fso-drawer-loading" id="fsoDrawerLoading">Loading fabric data…</div>
                <div class="fso-drawer-error" id="fsoDrawerError" hidden></div>
                <div class="fso-drawer-content" id="fsoDrawerContent" hidden></div>
            </div>
        </aside>
    </div>
</div>

<?php $prefTableKey = 'fabric_sale_table'; require base_path('app/views/partials/table_preferences.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/fabric-sale.js')) ?>?v=<?= time() ?>" defer></script>
