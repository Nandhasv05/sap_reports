<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : Purchase Order report view (SAP ZI_PurchaseOrderAPI_HUB)
 *               - List mode: condition-wise PO list (date range, plant, supplier, search)
 *               - Detail mode: single PO report (Hero, KPI cards, charts, items table)
*/
$mode = (string) ($mode ?? 'list');
$isList = $mode === 'list';
$purchaseOrder = (string) ($purchaseOrder ?? '');
$poInput = (string) ($poInput ?? '');
$poError = $poError ?? null;
$home = sap_reports_evol_url('portal_dashboard.php');
$logoUrl = sap_reports_logo_url();
$catalogHome = url('/');
$pageUrl = url('purchase-order');
$isCostingView = !empty($isCostingView);
$pageHeading = $isCostingView
    ? ($isList ? 'PO Costing Validation' : ('PO Costing Workbench · ' . e($purchaseOrder)))
    : ($isList ? 'Purchase Order Report' : ('PO ' . e($purchaseOrder)));
$isCostingBulk = $isCostingView && $isList;
$plant = (string) ($plant ?? '');
$plants = is_array($plants ?? null) ? $plants : [];
$range = is_array($range ?? null) ? $range : ['preset' => 'this_month', 'from' => '', 'to' => '', 'label' => 'This Month'];
$rangePreset = (string) ($rangePreset ?? 'this_month');
$rangeError = $rangeError ?? null;
$presets = is_array($presets ?? null) ? $presets : [];
$presetDates = is_array($presetDates ?? null) ? $presetDates : [];
$supplier = (string) ($supplier ?? '');
$suppliers = is_array($suppliers ?? null) ? $suppliers : array_values(array_filter(array_map('trim', explode(',', $supplier))));
$salesOrder = (string) ($salesOrder ?? '');
$poType = (string) ($poType ?? '');
$poTypes = is_array($poTypes ?? null) ? $poTypes : array_values(array_filter(array_map('trim', explode(',', $poType))));
$matCat = (string) ($matCat ?? 'all');
$matCategories = is_array($matCategories ?? null) ? $matCategories : SapPurchaseOrderService::materialCategories();
$docTypes = is_array($docTypes ?? null) ? $docTypes : SapPurchaseOrderService::documentTypes();
$columns = is_array($columns ?? null) ? $columns : [];
$colCount = count($columns);

$csvUrl = $pageUrl . '?' . http_build_query(array_filter([
    'view'     => $isCostingView ? 'costing' : '',
    'po'       => $purchaseOrder,
    'range'    => $rangePreset,
    'from'     => $range['from'] ?? '',
    'to'       => $range['to'] ?? '',
    'plant'    => $plant,
    'supplier' => $supplier,
    'so'       => $salesOrder,
    'po_type'  => $poType,
    'mat_cat'  => $matCat !== 'all' ? $matCat : '',
    'export'   => 'csv',
], static fn ($v): bool => $v !== null && $v !== ''));

$detailCards = [
    ['id' => 'items',     'label' => 'Items',           'icon' => 'format_list_numbered', 'tone' => 'indigo'],
    ['id' => 'materials', 'label' => 'Materials',       'icon' => 'category',             'tone' => 'teal'],
    ['id' => 'qty',       'label' => 'Total Order Qty', 'icon' => 'straighten',           'tone' => 'sky'],
    ['id' => 'value',     'label' => 'Total Net Value', 'icon' => 'payments',             'tone' => 'mint'],
    ['id' => 'so',        'label' => 'Sales Orders',    'icon' => 'receipt_long',         'tone' => 'amber'],
];

$listCards = $isCostingView
    ? [
        ['id' => 'pos',           'label' => 'Purchase Orders',    'icon' => 'receipt_long',  'tone' => 'indigo'],
        ['id' => 'validated',     'label' => 'Validated',          'icon' => 'verified',      'tone' => 'mint'],
        ['id' => 'in_review',     'label' => 'In Review',          'icon' => 'pending_actions','tone' => 'sky'],
        ['id' => 'pending_val',   'label' => 'Pending Validation', 'icon' => 'hourglass_top', 'tone' => 'amber'],
        ['id' => 'not_started',   'label' => 'Not Started',        'icon' => 'radio_button_unchecked', 'tone' => 'indigo'],
        ['id' => 'rejected',      'label' => 'Rejected',           'icon' => 'cancel',        'tone' => 'rose'],
    ]
    : [
        ['id' => 'pos',       'label' => 'Purchase Orders', 'icon' => 'receipt_long',         'tone' => 'indigo'],
        ['id' => 'items',     'label' => 'Total Items',     'icon' => 'format_list_numbered', 'tone' => 'teal'],
        ['id' => 'qty',       'label' => 'Total Order Qty', 'icon' => 'straighten',           'tone' => 'sky'],
        ['id' => 'value',     'label' => 'Total Net Value', 'icon' => 'payments',             'tone' => 'mint'],
        ['id' => 'suppliers', 'label' => 'Suppliers',       'icon' => 'storefront',           'tone' => 'amber'],
    ];
?>
<?php $isCostingWorkbench = $isCostingView && !$isList; ?>
<div class="rpt-app is-purchase-order <?= $isList ? 'is-po-list' : 'is-po-detail' ?><?= $isCostingWorkbench ? ' is-costing-workbench' : '' ?><?= $isCostingBulk ? ' is-costing-bulk' : '' ?><?= $isCostingView ? ' is-costing-view' : '' ?>">
    <?php
        $loaderIcon = 'receipt';
        $loaderSteps = $isCostingBulk
            ? ['Connecting to SAP…', 'Fetching purchase orders…', 'Preparing bulk validation…']
            : ($isList
                ? ['Connecting to SAP…', 'Fetching purchase orders…', 'Reading items & suppliers…', 'Preparing report…']
                : ($isCostingWorkbench
                    ? ['Connecting to SAP…', 'Fetching purchase order…', 'Loading ZCOST sheet…', 'Validating costs…']
                    : ['Connecting to SAP…', 'Fetching purchase order ' . ($purchaseOrder ?: '…'), 'Reading items & sales orders…', 'Preparing report…']));
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
            <div class="rpt-name"><?= $pageHeading ?></div>
        </div>

        <?php if ($isCostingBulk): ?>
            <?php
                $bulkPresets = is_array($presets ?? null) && $presets !== [] ? $presets : SapPurchaseOrderService::rangePresets();
                $bulkRangePreset = (string) ($rangePreset ?? ($range['preset'] ?? 'this_month'));
                if (!array_key_exists($bulkRangePreset, $bulkPresets)) {
                    $bulkRangePreset = 'this_month';
                }
                $bulkMatHeader = (string) ($matCat ?? 'all');
                $dateMin = date('Y-m-d', strtotime('-' . SapPurchaseOrderService::MAX_RANGE_DAYS . ' days'));
                $dateMax = date('Y-m-d');
            ?>
            <form class="rpt-search prod-filters po-filters pcb-header-filters" id="pcbFilterForm" autocomplete="off" novalidate>
                <input type="hidden" name="view" value="costing">
                <label class="rpt-field rpt-field-range" title="PO creation date range">
                    <span class="material-icons-round">date_range</span>
                    <select id="pcbRange" name="range" aria-label="PO date range">
                        <?php foreach ($bulkPresets as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $bulkRangePreset ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="date-custom pcb-header-custom" id="pcbCustomDates"<?= $bulkRangePreset === 'custom' ? '' : ' hidden' ?> style="display: <?= $bulkRangePreset === 'custom' ? 'flex' : 'none' ?>; gap: 6px;">
                    <label class="rpt-field rpt-field-date" title="From date (max <?= (int) SapPurchaseOrderService::MAX_RANGE_DAYS ?> days)">
                        <span class="date-cap">From</span>
                        <input type="date" id="pcbFrom" name="from" min="<?= e($dateMin) ?>" max="<?= e($dateMax) ?>" value="<?= e($range['from'] ?? '') ?>" aria-label="From date">
                    </label>
                    <label class="rpt-field rpt-field-date" title="To date (max <?= (int) SapPurchaseOrderService::MAX_RANGE_DAYS ?> days)">
                        <span class="date-cap">To</span>
                        <input type="date" id="pcbTo" name="to" min="<?= e($dateMin) ?>" max="<?= e($dateMax) ?>" value="<?= e($range['to'] ?? '') ?>" aria-label="To date">
                    </label>
                </div>
                <label class="rpt-field rpt-field-mat-cat" title="Material type">
                    <span class="material-icons-round">category</span>
                    <select id="pcbMat" name="mat_cat" aria-label="Material type">
                        <option value="all"<?= !in_array($bulkMatHeader, ['fabric', 'trims'], true) ? ' selected' : '' ?>>All Materials</option>
                        <option value="fabric"<?= $bulkMatHeader === 'fabric' ? ' selected' : '' ?>>Fabric</option>
                        <option value="trims"<?= $bulkMatHeader === 'trims' ? ' selected' : '' ?>>Trims</option>
                    </select>
                </label>
                <label class="rpt-field rpt-field-supplier" title="Vendor">
                    <span class="material-icons-round">storefront</span>
                    <select id="pcbSupplier" name="supplier" aria-label="Vendor">
                        <option value="">All Vendors</option>
                        <?php foreach ($suppliers as $code): ?>
                            <option value="<?= e($code) ?>"<?= $supplier === $code ? ' selected' : '' ?>>Supplier <?= e($code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="rpt-field rpt-field-status" title="Validation status">
                    <span class="material-icons-round">verified</span>
                    <select id="pcbValStatus" aria-label="Validation status">
                        <option value="all">All Status</option>
                        <option value="PASSED">Passed</option>
                        <option value="EXCEPTION">Exception</option>
                        <option value="PENDING">Pending</option>
                    </select>
                </label>
                <label class="rpt-field po-direct-search pcb-header-search" title="Search PO number (Press Enter to search)">
                    <span class="material-icons-round">search</span>
                    <input type="search" id="pcbPoSearch" value="" placeholder="Search PO #" inputmode="numeric" maxlength="10" autocomplete="off" aria-label="Search purchase order number">
                </label>
            </form>
        <?php elseif ($isList): ?>
            <!-- Condition-wise filter form for PO List -->
            <form class="rpt-search prod-filters po-filters" method="get" action="<?= e($pageUrl) ?>" id="poFilterForm" novalidate>
                <?php if ($isCostingView): ?>
                    <input type="hidden" name="view" value="costing">
                <?php endif; ?>
                <!-- Date Range Preset -->
                <label class="rpt-field rpt-field-range" title="PO creation date range">
                    <span class="material-icons-round">date_range</span>
                    <select name="range" data-range-select aria-label="PO date range">
                        <?php foreach ($presets as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $rangePreset ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- Custom dates stay hidden; modal collects From/To (max 100 days) -->
                <?php
                    $poDateMin = date('Y-m-d', strtotime('-' . SapPurchaseOrderService::MAX_RANGE_DAYS . ' days'));
                    $poDateMax = date('Y-m-d');
                    $poMaxDays = (int) SapPurchaseOrderService::MAX_RANGE_DAYS;
                ?>
                <input type="hidden" name="from" id="poFromHidden" value="<?= e($rangePreset === 'custom' ? ($range['from'] ?? '') : '') ?>"<?= $rangePreset === 'custom' ? '' : ' disabled' ?>>
                <input type="hidden" name="to" id="poToHidden" value="<?= e($rangePreset === 'custom' ? ($range['to'] ?? '') : '') ?>"<?= $rangePreset === 'custom' ? '' : ' disabled' ?>>

                <!-- Material category: All / Fabric / Trims -->
                <label class="rpt-field rpt-field-mat-cat" title="Filter by Fabric, Trims, or All materials">
                    <span class="material-icons-round">category</span>
                    <select name="mat_cat" data-mat-cat-select aria-label="Material category">
                        <?php foreach ($matCategories as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $matCat ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- Purchasing Document Type Multi-Select Dropdown -->
                <div class="po-multi-dropdown" id="poDocTypeDropdown">
                    <input type="hidden" name="po_type" id="poTypeInput" value="<?= e($poType) ?>">
                    <button type="button" class="po-multi-trigger" id="poDocTypeTrigger" aria-haspopup="true" aria-expanded="false" title="Filter by Purchasing Document Type">
                        <span class="material-icons-round po-multi-icon">description</span>
                        <span class="po-multi-label" id="poDocTypeLabel">
                            <?php if (empty($poTypes)): ?>
                                All Doc Types
                            <?php elseif (count($poTypes) === 1): ?>
                                <?php
                                    $firstCode = $poTypes[0];
                                    $firstName = SapPurchaseOrderService::documentTypeMap()[$firstCode] ?? $firstCode;
                                ?>
                                <?= e($firstName) ?>
                            <?php else: ?>
                                <?= count($poTypes) ?> Doc Types Selected
                            <?php endif; ?>
                        </span>
                        <span class="po-multi-badge" id="poDocTypeBadge" style="<?= empty($poTypes) ? 'display:none;' : '' ?>">
                            <?= count($poTypes) ?>
                        </span>
                        <span class="material-icons-round po-multi-arrow">expand_more</span>
                    </button>

                    <div class="po-multi-menu" id="poDocTypeMenu" role="menu">
                        <div class="po-multi-search-wrap">
                            <span class="material-icons-round">search</span>
                            <input type="text" id="poDocTypeSearch" class="po-multi-search" placeholder="Search doc types..." autocomplete="off">
                            <button type="button" id="poDocTypeSearchClear" class="po-multi-search-clear" style="display:none;" title="Clear search">
                                <span class="material-icons-round">close</span>
                            </button>
                        </div>
                        <div class="po-multi-actions">
                            <button type="button" class="po-multi-action-btn" id="poDocTypeSelectAll">Select All</button>
                            <span class="po-multi-action-dot">·</span>
                            <button type="button" class="po-multi-action-btn" id="poDocTypeClearAll">Clear</button>
                            <span class="po-multi-selected-info" id="poDocTypeCountInfo">
                                <?= count($poTypes) ?> of <?= count($docTypes) ?> selected
                            </span>
                        </div>
                        <div class="po-multi-list" id="poDocTypeList">
                            <?php foreach ($docTypes as $dt): ?>
                                <?php $checked = in_array(strtoupper($dt['code']), $poTypes, true); ?>
                                <label class="po-multi-item" data-code="<?= e($dt['code']) ?>" data-name="<?= e(strtolower($dt['name'])) ?>">
                                    <input type="checkbox" class="po-multi-checkbox" value="<?= e($dt['code']) ?>"<?= $checked ? ' checked' : '' ?>>
                                    <span class="po-multi-item-name"><?= e($dt['name']) ?></span>
                                    <span class="po-multi-item-code"><?= e($dt['code']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="po-multi-footer">
                            <button type="button" class="po-multi-btn-cancel" id="poDocTypeCancel">Cancel</button>
                            <button type="button" class="po-multi-btn-apply" id="poDocTypeApply">
                                <span class="material-icons-round">check</span>
                                <span>Apply</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Supplier / Vendor Multi-Select Dropdown -->
                <div class="po-multi-dropdown" id="poSupplierDropdown">
                    <input type="hidden" name="supplier" id="poSupplierInput" value="<?= e($supplier) ?>">
                    <button type="button" class="po-multi-trigger" id="poSupplierTrigger" aria-haspopup="true" aria-expanded="false" title="Filter by Supplier / Vendor">
                        <span class="material-icons-round po-multi-icon">storefront</span>
                        <span class="po-multi-label" id="poSupplierLabel">
                            <?php if (empty($suppliers)): ?>
                                All Suppliers
                            <?php elseif (count($suppliers) === 1): ?>
                                Supplier <?= e($suppliers[0]) ?>
                            <?php else: ?>
                                <?= count($suppliers) ?> Suppliers Selected
                            <?php endif; ?>
                        </span>
                        <span class="po-multi-badge" id="poSupplierBadge" style="<?= empty($suppliers) ? 'display:none;' : '' ?>">
                            <?= count($suppliers) ?>
                        </span>
                        <span class="material-icons-round po-multi-arrow">expand_more</span>
                    </button>

                    <div class="po-multi-menu po-supplier-menu" id="poSupplierMenu" role="menu">
                        <div class="po-multi-search-wrap">
                            <span class="material-icons-round">search</span>
                            <input type="text" id="poSupplierSearch" class="po-multi-search" placeholder="Search supplier or vendor..." autocomplete="off">
                            <button type="button" id="poSupplierSearchClear" class="po-multi-search-clear" style="display:none;" title="Clear search">
                                <span class="material-icons-round">close</span>
                            </button>
                        </div>
                        <div class="po-multi-actions">
                            <button type="button" class="po-multi-action-btn" id="poSupplierSelectAll">Select All</button>
                            <span class="po-multi-action-dot">·</span>
                            <button type="button" class="po-multi-action-btn" id="poSupplierClearAll">Clear</button>
                            <span class="po-multi-selected-info" id="poSupplierCountInfo">
                                <?= count($suppliers) ?> selected
                            </span>
                        </div>
                        <div class="po-multi-list" id="poSupplierList">
                            <div class="po-multi-empty" id="poSupplierEmpty" style="padding: 14px 10px; text-align: center; color: #64748b; font-size: 12px;">
                                <span class="material-icons-round" style="font-size: 20px; display: block; margin-bottom: 4px; color: #94a3b8;">storefront</span>
                                Loading suppliers from SAP…
                            </div>
                        </div>
                        <div class="po-multi-footer">
                            <button type="button" class="po-multi-btn-cancel" id="poSupplierCancel">Cancel</button>
                            <button type="button" class="po-multi-btn-apply" id="poSupplierApply">
                                <span class="material-icons-round">check</span>
                                <span>Apply</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Direct PO Search -->
                <label class="rpt-field po-direct-search" title="Search particular PO Number to view its details (Press Enter)">
                    <span class="material-icons-round">search</span>
                    <input id="po" name="po" value="" placeholder="Search PO #" inputmode="numeric" maxlength="10" autocomplete="off" aria-label="Search purchase order number">
                </label>
                <button type="button" class="rpt-btn rpt-btn-ghost" id="poResetBtn" title="Reset all filters">
                    <span class="material-icons-round">restart_alt</span>
                </button>
            </form>
        <?php else: ?>
            <!-- Detail / Workbench header with Back to List & PO Search -->
            <div style="display: flex; align-items: center; gap: 10px;">
                <a href="<?= e($pageUrl . ($isCostingView ? '?view=costing' : '')) ?>" class="po-back-nav" title="Back to Bulk Validation">
                    <span class="material-icons-round">arrow_back</span>
                    <span><?= $isCostingView ? 'Bulk List' : 'PO List' ?></span>
                </a>
                <form class="rpt-search" method="get" action="<?= e($pageUrl) ?>" id="poFilterForm" novalidate>
                    <?php if ($isCostingView): ?>
                        <input type="hidden" name="view" value="costing">
                    <?php endif; ?>
                    <label class="rpt-field">
                        <span class="material-icons-round">receipt</span>
                        <input id="po" name="po" value="<?= e($purchaseOrder) ?>" placeholder="Purchase order" inputmode="numeric" maxlength="10" autocomplete="off" required>
                    </label>
                    <button class="rpt-btn" type="submit" title="Load report">
                        <span class="material-icons-round">sync</span>
                    </button>
                    <?php if (!$isCostingWorkbench): ?>
                        <a class="rpt-btn rpt-btn-ghost" href="<?= e($csvUrl) ?>" title="Download CSV">
                            <span class="material-icons-round">file_download</span>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <?php if ($isList && !$isCostingBulk): ?>
        <?php
            $poDateMin = $poDateMin ?? date('Y-m-d', strtotime('-' . SapPurchaseOrderService::MAX_RANGE_DAYS . ' days'));
            $poDateMax = $poDateMax ?? date('Y-m-d');
            $poMaxDays = $poMaxDays ?? (int) SapPurchaseOrderService::MAX_RANGE_DAYS;
        ?>
        <div class="modal-backdrop po-custom-modal" id="poCustomDateModal" hidden data-max-days="<?= (int) $poMaxDays ?>">
            <div class="modal-card po-custom-modal-card" role="dialog" aria-modal="true" aria-labelledby="poCustomDateTitle">
                <div class="modal-header">
                    <div>
                        <span class="modal-kicker po-custom-modal-kicker">Purchase Order</span>
                        <h3 id="poCustomDateTitle">Custom Date</h3>
                        <p>Choose From and To within the last <?= (int) $poMaxDays ?> days.</p>
                    </div>
                    <button type="button" class="modal-close" id="poCustomDateClose" title="Close" aria-label="Close">
                        <span class="material-icons-round">close</span>
                    </button>
                </div>
                <div class="modal-body po-custom-modal-body">
                    <div class="po-custom-modal-fields">
                        <label class="po-custom-modal-field">
                            <span class="date-cap">From</span>
                            <input type="date" id="poCustomModalFrom" min="<?= e($poDateMin) ?>" max="<?= e($poDateMax) ?>" value="<?= e($rangePreset === 'custom' ? ($range['from'] ?? '') : '') ?>" aria-label="From date">
                        </label>
                        <label class="po-custom-modal-field">
                            <span class="date-cap">To</span>
                            <input type="date" id="poCustomModalTo" min="<?= e($poDateMin) ?>" max="<?= e($poDateMax) ?>" value="<?= e($rangePreset === 'custom' ? ($range['to'] ?? '') : '') ?>" aria-label="To date">
                        </label>
                    </div>
                    <p class="po-custom-modal-hint" id="poCustomDateHint">Maximum range: <?= (int) $poMaxDays ?> days</p>
                    <p class="po-custom-modal-error" id="poCustomDateError" hidden></p>
                    <div class="po-custom-modal-actions">
                        <button type="button" class="rpt-btn rpt-btn-ghost" id="poCustomDateCancel">Cancel</button>
                        <button type="button" class="rpt-btn" id="poCustomDateApply">
                            <span class="material-icons-round">check</span>
                            <span>Apply &amp; Load</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <main class="rpt-main">
        <div class="rpt-alert" id="poAlert" hidden></div>

        <?php if ($isCostingBulk): ?>
            <?php require base_path('app/views/reports/partials/po_costing_bulk.php'); ?>
        <?php elseif ($isList): ?>
            <!-- ===================== PO LIST VIEW ===================== -->
            <div class="rpt-stats po-stat-row">
                <?php foreach ($listCards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap">
                                <b id="poStat-<?= e($card['id']) ?>">—</b>
                                <em class="po-stat-unit" id="poStatUnit-<?= e($card['id']) ?>"></em>
                            </div>
                            <small><?= e($card['label']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php elseif ($isCostingWorkbench): ?>
            <?php require base_path('app/views/reports/partials/po_costing_workbench.php'); ?>
        <?php else: ?>
            <!-- ===================== PO DETAIL VIEW ===================== -->
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
                    <?php foreach (['PO Category', 'PO Type', 'PO Type Name', 'PO Date', 'Supplier', 'Supplier Name', 'Company Code', 'Purchasing Org.', 'Purchasing Group', 'Created By', 'Plant'] as $label): ?>
                        <div><dt><?= e($label) ?></dt><dd>—</dd></div>
                    <?php endforeach; ?>
                </dl>
                <div class="po-hero-sos">
                    <span>Linked Sales Orders</span>
                    <div id="poHeroSos"><span class="po-muted">—</span></div>
                </div>
            </section>

            <div class="rpt-stats po-stat-row">
                <?php foreach ($detailCards as $card): ?>
                    <article class="rpt-stat tone-<?= e($card['tone']) ?>">
                        <span class="material-icons-round"><?= e($card['icon']) ?></span>
                        <div>
                            <div class="stat-value-wrap">
                                <b id="poStat-<?= e($card['id']) ?>">—</b>
                                <em class="po-stat-unit" id="poStatUnit-<?= e($card['id']) ?>"></em>
                            </div>
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
        <?php endif; ?>

        <?php if ($isCostingWorkbench || $isCostingBulk): ?>
            <!-- Costing bulk / workbench render their own tables -->
        <?php else: ?>
        <!-- Table Toolbar (shared for both list & detail with appropriate columns) -->
        <div class="rpt-table-toolbar">
            <div class="rpt-tb-search">
                <span class="material-icons-round rpt-search-ico">search</span>
                <input type="search" id="rptTableSearch" class="rpt-tb-input"
                       placeholder="<?= $isList ? 'Quick search PO #, Supplier, Plant, Sales Orders, Created By...' : 'Quick search Material, Description, Sales order...' ?>"
                       autocomplete="off" spellcheck="false">
                <button type="button" id="rptTableSearchClear" class="rpt-tb-clear" title="Clear search" aria-label="Clear search" style="display: none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php if (!$isList): ?>
                <label class="rpt-field rpt-field-mat-cat po-detail-mat-cat" title="Show All, Fabric, or Trims lines">
                    <span class="material-icons-round">category</span>
                    <select data-detail-mat-cat aria-label="Material category">
                        <?php foreach ($matCategories as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $key === $matCat ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
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
                        data-excel-title="<?= e($isList ? 'Purchase Orders List' : ('Purchase Order ' . $purchaseOrder)) ?>"
                        data-excel-file="<?= e($isList ? 'purchase-orders-list' : ('purchase-order-' . $purchaseOrder)) ?>"
                        data-excel-sheet="<?= e($isList ? 'PO List' : ('PO ' . $purchaseOrder)) ?>"
                        title="Download the table as an Excel file (visible columns and filtered rows)">
                    <i class="fas fa-file-excel"></i>
                    <span>Excel</span>
                </button>

                <button type="button" class="rpt-tb-btn is-active" id="rptToggleColFilters" title="Toggle column filter inputs">
                    <i class="fas fa-filter"></i>
                    <span>Column Filters</span>
                    <span class="rpt-tb-badge" id="rptActiveFilterBadge" style="display: none;">0</span>
                </button>

                <button type="button" class="rpt-tb-btn rpt-tb-btn-reset" id="rptClearAllFilters" title="Clear all search, filters and sorting" style="display: none;">
                    <i class="fas fa-rotate-left"></i>
                    <span>Clear All</span>
                </button>
            </div>
        </div>

        <div class="rpt-table-wrap is-scroll-body" id="rptTableWrap">
            <table class="rpt-table prod-table po-page-table text-nowrap" id="rptDataTable">
                <thead>
                    <tr class="rpt-header-row">
                        <?php foreach ($columns as $i => $col): ?>
                            <?php $isNum = ($col['type'] ?? '') === 'num'; ?>
                            <th class="is-sortable<?= $isNum ? ' num' : '' ?><?= ($col['key'] ?? '') === 'sno' ? ' sno' : '' ?>"
                                data-col="<?= (int) $i ?>" data-type="<?= e($col['type'] ?? 'text') ?>" title="Click to sort by <?= e($col['label'] ?? '') ?>">
                                <div class="th-content<?= $isNum ? ' num' : '' ?>"><span><?= e($col['label'] ?? '') ?></span></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="rpt-filter-row" id="rptFilterRow">
                        <?php foreach ($columns as $i => $col): ?>
                            <?php if (($col['key'] ?? '') === 'sno'): ?>
                                <th class="num sno">
                                    <button type="button" class="rpt-col-filter-reset" id="rptColFilterReset" title="Clear all column filters" aria-label="Clear all column filters">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                </th>
                            <?php elseif (($col['key'] ?? '') === 'action'): ?>
                                <th></th>
                            <?php else: ?>
                                <th<?= ($col['type'] ?? '') === 'num' ? ' class="num"' : '' ?>>
                                    <div class="rpt-col-input-wrap">
                                        <input type="text" class="rpt-col-input" data-col="<?= (int) $i ?>"<?= ($col['type'] ?? '') === 'num' ? ' data-numeric' : '' ?>
                                               placeholder="<?= e(($col['type'] ?? '') === 'num' ? '>0, <10…' : 'Filter…') ?>" title="Filter <?= e($col['label'] ?? '') ?>">
                                        <button type="button" class="rpt-col-clear" tabindex="-1">&times;</button>
                                    </div>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="rptTableBody">
                    <tr class="rpt-table-loading">
                        <td colspan="<?= (int) $colCount ?>" class="rpt-table-skeleton">
                            <div class="rpt-skel-rows" aria-label="Loading purchase orders from SAP"><i></i><i></i><i></i><i></i><i></i></div>
                        </td>
                    </tr>
                    <tr id="rptNoMatchRow" class="rpt-table-no-match" style="display: none;">
                        <td colspan="<?= (int) $colCount ?>" class="rpt-table-empty">
                            <div class="rpt-no-match-card">
                                <span class="material-icons-round">filter_alt_off</span>
                                <p><?= $isList ? 'No purchase orders match the applied search or filter criteria.' : 'No items match the applied search or filter criteria.' ?></p>
                                <button type="button" class="rpt-btn rpt-btn-sm" id="rptResetFilterTableBtn">Clear All Filters</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr id="rptTableFoot">
                        <?php foreach ($columns as $i => $col): ?>
                            <?php if (($col['key'] ?? '') === 'sno'): ?>
                                <th id="footTotalLabel">Total</th>
                            <?php else: ?>
                                <th<?= ($col['type'] ?? '') === 'num' ? ' class="num"' : '' ?> data-foot-key="<?= e($col['key'] ?? '') ?>"></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="rpt-pagination" id="rptPagination" hidden>
            <div class="rpt-page-size">
                <label for="rptPageSize">Rows</label>
                <select id="rptPageSize" aria-label="Rows per page">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                </select>
            </div>
            <div class="rpt-page-info" id="rptPageInfo">Page 1 of 1</div>
            <div class="rpt-page-controls" role="navigation" aria-label="Table pagination">
                <button type="button" class="rpt-page-btn" id="rptPageFirst" title="First page" aria-label="First page">
                    <i class="fas fa-angles-left"></i>
                </button>
                <button type="button" class="rpt-page-btn" id="rptPagePrev" title="Previous page" aria-label="Previous page">
                    <i class="fas fa-angle-left"></i>
                </button>
                <div class="rpt-page-numbers" id="rptPageNumbers"></div>
                <button type="button" class="rpt-page-btn" id="rptPageNext" title="Next page" aria-label="Next page">
                    <i class="fas fa-angle-right"></i>
                </button>
                <button type="button" class="rpt-page-btn" id="rptPageLast" title="Last page" aria-label="Last page">
                    <i class="fas fa-angles-right"></i>
                </button>
            </div>
        </div>
        <?php endif; /* not costing workbench */ ?>
    </main>

    <?php require base_path('app/views/partials/footer.php'); ?>
</div>

<?php
$pcbCurrentUser = trim((string) (
    $_SESSION['username']
    ?? $_SESSION['user_name']
    ?? $_SESSION['name']
    ?? $_SESSION['email']
    ?? ''
));
?>
<script type="application/json" id="poConfig"><?= json_encode([
    'mode'               => $mode,
    'po'                 => $purchaseOrder,
    'range'              => $range,
    'rangeError'         => $rangeError,
    'plant'              => $plant,
    'supplier'           => $supplier,
    'suppliers'          => $suppliers,
    'salesOrder'         => $salesOrder,
    'poType'             => $poType,
    'matCat'             => $matCat,
    'presetDates'        => $presetDates,
    'dataUrl'            => url('purchase-order/data'),
    'validateUrl'        => url('purchase-order/validate'),
    'pageUrl'            => $pageUrl,
    'view'               => $isCostingView ? 'costing' : '',
    'isCostingView'      => $isCostingView,
    'isCostingWorkbench' => $isCostingWorkbench,
    'isCostingBulk'      => $isCostingBulk,
    'columns'            => $columns,
    'currentUser'        => $pcbCurrentUser,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/loader.js')) ?>"></script>
<?php if ($isCostingWorkbench): ?>
<?php
    $prefTableKey = 'purchase_order_costing_workbench_table';
    require base_path('app/views/partials/table_preferences.php');
?>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/po-costing-workbench.js')) ?>"></script>
<?php elseif ($isCostingBulk): ?>
<?php
    require_once base_path('app/services/TableColumnRegistry.php');
    $bulkTableKey = 'purchase_order_costing_bulk_table';
    $bulkItemsTableKey = 'purchase_order_costing_items_table';
    $bulkTableDef = TableColumnRegistry::get($bulkTableKey);
    $bulkItemsDef = TableColumnRegistry::get($bulkItemsTableKey);
?>
<script type="application/json" id="pcbTablePrefs"><?= json_encode([
    'tableKey'      => $bulkTableKey,
    'apiUrl'        => url('api/user/table-preferences/' . $bulkTableKey),
    'userId'        => (string) ($_SESSION['user_id'] ?? ''),
    'columns'       => $bulkTableDef['columns'] ?? [],
    'defaultHidden' => $bulkTableDef['defaultHidden'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="pcbItemTablePrefs"><?= json_encode([
    'tableKey'      => $bulkItemsTableKey,
    'apiUrl'        => url('api/user/table-preferences/' . $bulkItemsTableKey),
    'userId'        => (string) ($_SESSION['user_id'] ?? ''),
    'columns'       => $bulkItemsDef['columns'] ?? [],
    'defaultHidden' => $bulkItemsDef['defaultHidden'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/po-costing-bulk.js')) ?>"></script>
<?php else: ?>
<script src="<?= e(asset('js/po-report.js')) ?>"></script>
<?php
    $prefTableKey = $isList ? 'purchase_order_list_table' : 'purchase_order_table';
    require base_path('app/views/partials/table_preferences.php');
?>
<script src="<?= e(asset('js/table-columns.js')) ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>"></script>
<script src="<?= e(asset('js/purchase-order.js')) ?>"></script>
<?php endif; ?>
