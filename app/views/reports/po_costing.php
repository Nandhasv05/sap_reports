<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/11/2026 - Updated: 13/11/2026
 * DESCRIPTION : PO Costing Validation — Modern Unified UI
 *               Left icon sidenav · Unified Data Tables across Receive, Sent & Approved POs
*/
// Keep boot/page loader over main content only — aside + header stay visible
$loaderContentScoped = true;
$home = (string) ($home ?? sap_reports_evol_url('portal_dashboard.php'));
$catalogHome = (string) ($catalogHome ?? url('/'));
$logoUrl = (string) ($logoUrl ?? sap_reports_logo_url());
$poBaseUrl = (string) ($poBaseUrl ?? url('purchase-order'));
$poEsignUrl = (string) ($poEsignUrl ?? sap_reports_evol_url('po_e_signature.php'));
$passLogUrl = (string) ($passLogUrl ?? url('purchase-order/pass-log'));
$passLogPageUrl = (string) ($passLogPageUrl ?? url('po-costing/pass-log'));
$sentBoxUrl = (string) ($sentBoxUrl ?? url('purchase-order/sent-box'));
$pdfUrl = (string) ($pdfUrl ?? url('purchase-order/pdf'));
$sendMailUrl = (string) ($sendMailUrl ?? url('purchase-order/send-mail'));
$usersUrl = (string) ($usersUrl ?? url('purchase-order/users'));
$workflowUrl = (string) ($workflowUrl ?? url('purchase-order/workflow'));
$workflowSaveUrl = (string) ($workflowSaveUrl ?? url('purchase-order/workflow/save'));
$workflowNotifyUrl = (string) ($workflowNotifyUrl ?? url('purchase-order/workflow/notify'));
$workflowDecideUrl = (string) ($workflowDecideUrl ?? url('purchase-order/workflow/decide'));
$workflowAckUrl = (string) ($workflowAckUrl ?? url('purchase-order/workflow/ack'));
if (!isset($canView)) {
    $canView = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'view')
        : true;
}
$canView = (bool) $canView;
if (!isset($canPassPo)) {
    $canPassPo = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'approve')
        : true;
}
$canPassPo = (bool) $canPassPo;
if (!isset($canAssign)) {
    $canAssign = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'assign')
        : true;
}
$canAssign = (bool) $canAssign;
if (!isset($canValidate)) {
    $canValidate = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'validate')
        : true;
}
$canValidate = (bool) $canValidate;
if (!isset($canEsign)) {
    $canEsign = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'esign')
        : true;
}
$canEsign = (bool) $canEsign;
if (!isset($canWorkbench)) {
    $canWorkbench = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'workbench')
        : true;
}
$canWorkbench = (bool) $canWorkbench;
if (!isset($canReceivePage)) {
    $canReceivePage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'receive')
        : true;
}
$canReceivePage = (bool) $canReceivePage;
if (!isset($canSentPage)) {
    $canSentPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'sent')
        : true;
}
$canSentPage = (bool) $canSentPage;
if (!isset($canWorkflowPage)) {
    $canWorkflowPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'workflow')
        : true;
}
$canWorkflowPage = (bool) $canWorkflowPage;
if (!isset($canApprovedPage)) {
    $canApprovedPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'approved')
        : true;
}
$canApprovedPage = (bool) $canApprovedPage;
if (!isset($canRelatedPage)) {
    $canRelatedPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'related')
        : true;
}
$canRelatedPage = (bool) $canRelatedPage;
if (!isset($canFindPoPage)) {
    $canFindPoPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'find_po')
        : true;
}
$canFindPoPage = (bool) $canFindPoPage;
// Aside pages follow Access Studio toggles (Find PO is separate from View / Related / etc.)
$canAsideDashboard = $canFindPoPage;
$canAsideReceive = $canReceivePage;
$canAsideSent = $canSentPage;
$canAsideWorkflow = $canWorkflowPage;
$canAsideApproved = $canApprovedPage;
$canAsideRelated = $canRelatedPage;
$relatedPoDataUrl = (string) ($relatedPoDataUrl ?? url('po-costing/related-po'));
$relatedPoStatusUrl = (string) ($relatedPoStatusUrl ?? url('po-costing/related-po/status'));
$relatedPoSentLogUrl = (string) ($relatedPoSentLogUrl ?? url('po-costing/related-po/sent-log'));
$searchPoDataUrl = (string) ($searchPoDataUrl ?? url('po-costing/search-po'));
$valuationReportUrl = (string) ($valuationReportUrl ?? url('po-costing/valuation-report'));
if (!isset($canSearchPoPage)) {
    $canSearchPoPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'search_po')
        : true;
}
$canSearchPoPage = (bool) $canSearchPoPage;
$canAsideSearchPo = $canSearchPoPage;
if (!isset($canValuationReportPage)) {
    $canValuationReportPage = function_exists('sap_reports_user_can')
        ? sap_reports_user_can('po-costing', 'valuation_report')
        : true;
}
$canAsideValuation = (bool) $canValuationReportPage;
$costingHomeUrl = (string) ($costingHomeUrl ?? url('po-costing'));
$panelHref = static function (string $panel) use ($costingHomeUrl): string {
    $panel = trim($panel);
    if ($panel === '' || $panel === 'dashboard') {
        return $costingHomeUrl;
    }
    $sep = (strpos($costingHomeUrl, '?') !== false) ? '&' : '?';
    return $costingHomeUrl . $sep . 'panel=' . rawurlencode($panel);
};
?>
<div class="rpt-app is-po-costing is-workflow is-costing-bulk is-released-po is-poc-v2" id="poCostingPage"
     data-po-base="<?= e($poBaseUrl) ?>"
     data-pass-log="<?= e($passLogUrl) ?>"
     data-pass-log-page="<?= e($passLogPageUrl) ?>"
     data-sent-box="<?= e($sentBoxUrl) ?>"
     data-pdf-url="<?= e($pdfUrl) ?>"
     data-send-mail="<?= e($sendMailUrl) ?>"
     data-users-url="<?= e($usersUrl) ?>"
     data-workflow-url="<?= e($workflowUrl) ?>"
     data-workflow-save="<?= e($workflowSaveUrl) ?>"
     data-workflow-notify="<?= e($workflowNotifyUrl) ?>"
     data-workflow-decide="<?= e($workflowDecideUrl) ?>"
     data-workflow-ack="<?= e($workflowAckUrl) ?>"
     data-related-po-url="<?= e($relatedPoDataUrl) ?>"
     data-related-po-status-url="<?= e($relatedPoStatusUrl) ?>"
     data-related-po-sent-log-url="<?= e($relatedPoSentLogUrl) ?>"
     data-search-po-url="<?= e($searchPoDataUrl) ?>"
     data-valuation-report-url="<?= e($valuationReportUrl) ?>"
     data-can-view="<?= $canView ? '1' : '0' ?>"
     data-can-pass-po="<?= $canPassPo ? '1' : '0' ?>"
     data-can-esign="<?= $canEsign ? '1' : '0' ?>"
     data-can-sent="<?= $canSentPage ? '1' : '0' ?>"
     data-can-assign="<?= $canAssign ? '1' : '0' ?>"
     data-can-validate="<?= $canValidate ? '1' : '0' ?>"
     data-can-workflow="<?= $canWorkflowPage ? '1' : '0' ?>"
     data-aside-dashboard="<?= $canAsideDashboard ? '1' : '0' ?>"
     data-aside-received="<?= $canAsideReceive ? '1' : '0' ?>"
     data-aside-sent="<?= $canAsideSent ? '1' : '0' ?>"
     data-aside-workflow="<?= $canAsideWorkflow ? '1' : '0' ?>"
     data-aside-approved="<?= $canAsideApproved ? '1' : '0' ?>"
     data-aside-related="<?= $canAsideRelated ? '1' : '0' ?>"
     data-aside-search="<?= $canAsideSearchPo ? '1' : '0' ?>"
     data-aside-valuation="<?= !empty($canAsideValuation) ? '1' : '0' ?>">

    <?php
        // Soft panels (Related / Search PO): skip full-page boot spinner — nothing heavy to load
        $startPanelHint = strtolower(trim((string) ($_GET['panel'] ?? '')));
        $skipBootLoader = (
            ($startPanelHint === 'related' && !empty($canAsideRelated))
            || ($startPanelHint === 'search' && !empty($canAsideSearchPo))
            || ($startPanelHint === 'valuation' && !empty($canAsideValuation))
        );
        $loaderVisible = !$skipBootLoader;
        $loaderAutoHide = true;
        $loaderMessage = $startPanelHint === 'search'
            ? 'Opening Search PO…'
            : 'Preparing PO eSign…';
        $loaderSteps = $startPanelHint === 'search'
            ? ['Opening Search PO…']
            : ['Connecting…', 'Loading PO eSign…', 'Preparing PO eSign…'];
    ?>
    <?php $relatedBrandOptions = is_array($brands ?? null) ? $brands : []; ?>
    <header class="rpt-bar">
        <div class="rpt-bar-left">
            <a href="<?= e($home) ?>" class="rpt-brand" aria-label="EVOLV" title="EVOLV Portal Dashboard">
                <img src="<?= e($logoUrl) ?>" alt="evolv">
            </a>
            <a href="<?= e($catalogHome) ?>" class="rpt-home" aria-label="All Reports" title="Reports Catalog">
                <span class="material-icons-round">apps</span>
            </a>
            <a href="<?= e($home) ?>" class="rpt-home" aria-label="Portal home" title="Portal Dashboard">
                <span class="material-icons-round">home</span>
            </a>
            <div class="rpt-name pcb-bar-title" id="pocPageTitle"><strong>Released Po</strong></div>
        </div>
        <?php $brandOptions = is_array($brands ?? null) ? $brands : []; ?>
        <?php if (!empty($canAsideDashboard)): ?>
        <?php
            require_once base_path('app/services/SapPurchaseOrderService.php');
            $bulkMatHeader = 'all';
            $bulkBrandOptions = $brandOptions;
            $bulkSelectedBrands = [];
            $bulkBrandCount = 0;
            $bulkBrandLabel = 'All Brands';
        ?>
        <form class="rpt-search prod-filters po-filters pcb-header-filters poc-released-header-filters" id="pcbFilterForm" autocomplete="off" novalidate aria-label="Released Po filters">
            <input type="hidden" name="view" value="costing">
            <div class="pcb-mat-seg" role="radiogroup" aria-label="Material type">
                <label class="pcb-mat-opt">
                    <input type="radio" name="pcb_mat" value="all" checked>
                    <span>All</span>
                </label>
                <label class="pcb-mat-opt">
                    <input type="radio" name="pcb_mat" value="fabric">
                    <span>Fabric</span>
                </label>
                <label class="pcb-mat-opt">
                    <input type="radio" name="pcb_mat" value="trims">
                    <span>Trims</span>
                </label>
            </div>
            <input type="hidden" id="pcbMat" name="mat_cat" value="all">
            <div class="po-multi-dropdown pcb-header-brand" id="pcbBrandDropdown" title="Filter by brand name">
                <input type="hidden" name="brand" id="pcbBrand" value="">
                <button type="button" class="po-multi-trigger" id="pcbBrandTrigger" aria-haspopup="true" aria-expanded="false" aria-label="Brand name">
                    <span class="material-icons-round po-multi-icon">sell</span>
                    <span class="po-multi-label" id="pcbBrandLabel"><?= e($bulkBrandLabel) ?></span>
                    <span class="po-multi-badge" id="pcbBrandBadge" style="display:none;">0</span>
                    <span class="material-icons-round po-multi-arrow">expand_more</span>
                </button>
                <div class="po-multi-menu" id="pcbBrandMenu" role="menu">
                    <div class="po-multi-search-wrap">
                        <span class="material-icons-round">search</span>
                        <input type="text" id="pcbBrandSearch" class="po-multi-search" placeholder="Search brands..." autocomplete="off">
                        <button type="button" id="pcbBrandSearchClear" class="po-multi-search-clear" style="display:none;" title="Clear search">
                            <span class="material-icons-round">close</span>
                        </button>
                    </div>
                    <div class="po-multi-actions">
                        <button type="button" class="po-multi-action-btn" id="pcbBrandSelectAll">Select All</button>
                        <span class="po-multi-action-dot">·</span>
                        <button type="button" class="po-multi-action-btn" id="pcbBrandClearAll">Clear</button>
                        <span class="po-multi-selected-info" id="pcbBrandCountInfo">
                            0 of <?= count($bulkBrandOptions) ?> selected
                        </span>
                    </div>
                    <div class="po-multi-list" id="pcbBrandList">
                        <?php foreach ($bulkBrandOptions as $b): ?>
                            <?php
                            $bCode = (string) ($b['code'] ?? '');
                            $bName = (string) ($b['name'] ?? $bCode);
                            if ($bCode === '') {
                                continue;
                            }
                            ?>
                            <label class="po-multi-item" data-code="<?= e($bCode) ?>" data-name="<?= e(strtolower($bName)) ?>">
                                <input type="checkbox" class="po-multi-checkbox" value="<?= e($bCode) ?>">
                                <span class="po-multi-item-name"><?= e($bName) ?></span>
                                <span class="po-multi-item-code"><?= e($bCode) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="po-multi-footer">
                        <button type="button" class="po-multi-btn-cancel" id="pcbBrandCancel">Cancel</button>
                        <button type="button" class="po-multi-btn-apply" id="pcbBrandApply">
                            <span class="material-icons-round">check</span>
                            <span>Apply</span>
                        </button>
                    </div>
                </div>
            </div>
            <input type="hidden" id="pcbRange" name="range" value="all">
            <input type="hidden" id="pcbFrom" name="from" value="">
            <input type="hidden" id="pcbTo" name="to" value="">
            <label class="rpt-field po-direct-search pcb-header-search poc-released-po-search" title="Search PO number — Enter opens Costing Workbench">
                <span class="material-icons-round">search</span>
                <input type="search" id="pcbPoSearch" name="po_search" value=""
                       placeholder="Search PO #"
                       inputmode="numeric" maxlength="10" autocomplete="off"
                       aria-label="Search purchase order number">
            </label>
            <button type="button" class="poc-find-header-search" id="pcbPoSearchBtn"
                    title="Search PO — find in list or open Costing Workbench">
                <span class="material-icons-round">search</span>
                <span>Search</span>
            </button>
        </form>
        <?php endif; ?>
        <?php if (!empty($canAsideSearchPo)): ?>
        <form class="rpt-search poc-search-header-filters" id="pocSearchPoHeaderFilters" autocomplete="off" hidden aria-label="Search PO">
            <label class="rpt-field po-direct-search poc-search-po-field"
                   title="Purchase order number — type up to 10 digits, then Search to open Costing Workbench.">
                <span class="material-icons-round">receipt_long</span>
                <input type="search" id="pocSearchPoInput" name="po" value=""
                       placeholder="Enter PO # (e.g. 4000008618)"
                       inputmode="numeric" maxlength="10" autocomplete="off"
                       aria-label="Purchase order number"
                       title="Enter a purchase order number (up to 10 digits).">
            </label>
            <button type="submit" class="poc-find-header-search" id="pocSearchPoBtn"
                    title="Search — enter a PO number and open the Costing Workbench (needs Workbench access to use actions there).">
                <span class="material-icons-round">search</span>
                <span>Search</span>
            </button>
        </form>
        <?php endif; ?>
        <?php if (!empty($canAsideValuation)):
            $pvrTodayMax = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        ?>
        <div class="rpt-search poc-valuation-header-filters" id="pocValuationHeaderFilters" hidden aria-label="poValaition Report filters">
            <label class="rpt-field po-direct-search pvr-header-po" title="Filter by purchase order number">
                <span class="material-icons-round">search</span>
                <input type="search" id="pvrFilterPo" name="po" value=""
                       placeholder="Search PO # (e.g. 40000…)"
                       inputmode="numeric" maxlength="10" autocomplete="off"
                       aria-label="Purchase order number">
            </label>
            <label class="rpt-field pvr-header-range" title="Filter by date range">
                <span class="material-icons-round">date_range</span>
                <select id="pvrFilterRange" aria-label="Date range">
                    <option value="" selected>All dates</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="this_week">This week</option>
                    <option value="previous_week">Previous week</option>
                    <option value="this_month">This month</option>
                    <option value="previous_month">Previous month</option>
                    <option value="custom">Custom date</option>
                </select>
            </label>
            <div class="pvr-custom-dates" id="pvrCustomDates" hidden>
                <label class="rpt-field pvr-header-date">
                    <span class="material-icons-round">event</span>
                    <input type="date" id="pvrFilterFrom" max="<?= e($pvrTodayMax) ?>" aria-label="From date">
                </label>
                <span class="pvr-date-sep">to</span>
                <label class="rpt-field pvr-header-date">
                    <span class="material-icons-round">event</span>
                    <input type="date" id="pvrFilterTo" max="<?= e($pvrTodayMax) ?>" aria-label="To date">
                </label>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($canAsideRelated)): ?>
        <form class="rpt-search poc-related-header-filters" id="pocRelatedHeaderFilters" autocomplete="off" hidden aria-label="Draft PO filters">
            <label class="rpt-field rpt-field-mat-cat" title="Filter by Fabric, Trims, or All materials">
                <span class="material-icons-round">category</span>
                <select id="pocRelatedMatCat" name="mat_cat" aria-label="Material category">
                    <option value="all" selected>All Materials</option>
                    <option value="fabric">Fabric</option>
                    <option value="trims">Trims</option>
                </select>
            </label>
            <div class="po-multi-dropdown poc-related-brand" id="pocRelatedBrandDropdown" title="Filter by brand name (multi-select)">
                <input type="hidden" name="brand" id="pocRelatedBrand" value="">
                <button type="button" class="po-multi-trigger" id="pocRelatedBrandTrigger" aria-haspopup="true" aria-expanded="false" aria-label="Brand name">
                    <span class="material-icons-round po-multi-icon">sell</span>
                    <span class="po-multi-label" id="pocRelatedBrandLabel">All Brands</span>
                    <span class="po-multi-badge" id="pocRelatedBrandBadge" style="display:none;">0</span>
                    <span class="material-icons-round po-multi-arrow">expand_more</span>
                </button>
                <div class="po-multi-menu" id="pocRelatedBrandMenu" role="menu">
                    <div class="po-multi-search-wrap">
                        <span class="material-icons-round">search</span>
                        <input type="text" id="pocRelatedBrandSearch" class="po-multi-search" placeholder="Search brands…" autocomplete="off">
                        <button type="button" id="pocRelatedBrandSearchClear" class="po-multi-search-clear" style="display:none;" title="Clear search">
                            <span class="material-icons-round">close</span>
                        </button>
                    </div>
                    <div class="po-multi-actions">
                        <button type="button" class="po-multi-action-btn" id="pocRelatedBrandSelectAll">Select All</button>
                        <span class="po-multi-action-dot">·</span>
                        <button type="button" class="po-multi-action-btn" id="pocRelatedBrandClearAll">Clear</button>
                        <span class="po-multi-selected-info" id="pocRelatedBrandCountInfo">0 of <?= count($relatedBrandOptions) ?> selected</span>
                    </div>
                    <div class="po-multi-list" id="pocRelatedBrandList">
                        <?php foreach ($relatedBrandOptions as $b): ?>
                            <?php
                            $bCode = (string) ($b['code'] ?? '');
                            $bName = (string) ($b['name'] ?? $bCode);
                            if ($bCode === '') {
                                continue;
                            }
                            ?>
                            <label class="po-multi-item" data-code="<?= e($bCode) ?>" data-name="<?= e(strtolower($bName)) ?>">
                                <input type="checkbox" class="po-multi-checkbox" value="<?= e($bCode) ?>">
                                <span class="po-multi-item-name"><?= e($bName) ?></span>
                                <span class="po-multi-item-code"><?= e($bCode) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="po-multi-footer">
                        <button type="button" class="po-multi-btn-cancel" id="pocRelatedBrandCancel">Cancel</button>
                        <button type="button" class="po-multi-btn-apply" id="pocRelatedBrandApply">
                            <span class="material-icons-round">check</span>
                            <span>Apply</span>
                        </button>
                    </div>
                </div>
            </div>
            <button type="button" class="poc-related-clear" id="pocRelatedClearHeaderBtn" title="Clear Draft PO filters">
                <span class="material-icons-round">filter_alt_off</span>
                <span>Clear Filter</span>
            </button>
        </form>
        <?php endif; ?>
        <?php require base_path('app/views/partials/header_nav_right.php'); ?>
    </header>

    <div class="poc-body is-full-width">
        <aside class="poc-sidenav is-icons-only" aria-label="PO Costing sections">
            <nav class="poc-sidenav-menu" role="tablist" aria-label="PO Costing Sections">
                <?php if ($canAsideDashboard): ?>
                <a href="<?= e($panelHref('dashboard')) ?>" class="poc-sidenav-item is-active" data-poc-panel="dashboard" data-access="find_po" role="tab" aria-selected="true"
                   title="Released Po — shown because Access Studio granted Released PO. Bulk list of purchase orders for costing validation.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">space_dashboard</span></span>
                    <span class="poc-sidenav-copy">Released Po</span>
                </a>
                <?php endif; ?>
                <?php if ($canAsideReceive): ?>
                <a href="<?= e($panelHref('received')) ?>" class="poc-sidenav-item" data-poc-panel="received" data-access="receive" role="tab" aria-selected="false"
                   title="Receive PO — shown because Access Studio granted Receive. Inbox of POs sent / assigned to you.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">move_to_inbox</span></span>
                    <span class="poc-sidenav-copy">Receive PO</span>
                    <span class="poc-tab-badge is-recv" id="pocTabBadgeRecv">0</span>
                </a>
                <?php endif; ?>
                <?php if ($canAsideSent): ?>
                <a href="<?= e($panelHref('sent')) ?>" class="poc-sidenav-item" data-poc-panel="sent" data-access="sent" role="tab" aria-selected="false"
                   title="Sent PO — shown because Access Studio granted Sent. POs you forwarded or mailed.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">send</span></span>
                    <span class="poc-sidenav-copy">Sent PO</span>
                    <span class="poc-tab-badge is-sent" id="pocTabBadgeSent">0</span>
                </a>
                <?php endif; ?>
                <?php if ($canAsideWorkflow): ?>
                <a href="<?= e($panelHref('workflow')) ?>" class="poc-sidenav-item" data-poc-panel="workflow" data-access="workflow" role="tab" aria-selected="false"
                   title="Workflow — shown because Access Studio granted Workflow. Configure who reviews / approves POs.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">account_tree</span></span>
                    <span class="poc-sidenav-copy">Workflow</span>
                </a>
                <?php endif; ?>
                <?php if ($canAsideApproved): ?>
                <a href="<?= e($panelHref('approved')) ?>" class="poc-sidenav-item" data-poc-panel="approved" data-access="approved" role="tab" aria-selected="false"
                   title="Approved PO — shown because Access Studio granted Approved. Completed / approved purchase orders.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">verified</span></span>
                    <span class="poc-sidenav-copy">Approved PO</span>
                    <span class="poc-tab-badge is-approved" id="pocTabBadgeApproved">0</span>
                </a>
                <?php endif; ?>
                <?php if ($canAsideRelated): ?>
                <a href="<?= e($panelHref('related')) ?>" class="poc-sidenav-item" data-poc-panel="related" data-access="view" role="tab" aria-selected="false"
                   title="Draft PO — shown because Access Studio granted Draft PO. NotApproved / draft quotations from Z_QuotationZCostCom.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">link</span></span>
                    <span class="poc-sidenav-copy">Draft PO</span>
                </a>
                <?php endif; ?>
                <?php if (!empty($canAsideSearchPo)): ?>
                <a href="<?= e($panelHref('search')) ?>" class="poc-sidenav-item" data-poc-panel="search" data-access="search_po" role="tab" aria-selected="false"
                   title="Search PO — shown because Access Studio granted Search PO. Open a purchase order in Costing Workbench by PO number.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">travel_explore</span></span>
                    <span class="poc-sidenav-copy">Search PO</span>
                </a>
                <?php endif; ?>
                <?php if (!empty($canAsideValuation)): ?>
                <a href="<?= e($panelHref('valuation')) ?>" class="poc-sidenav-item" data-poc-panel="valuation" data-access="valuation_report" role="tab" aria-selected="false"
                   title="poValaition Report — shown because Access Studio granted poValaition Report. Pending / Review / Released / Approved KPIs and Recent Activity.">
                    <span class="poc-sidenav-ico" aria-hidden="true"><span class="material-icons-round">assessment</span></span>
                    <span class="poc-sidenav-copy">poValaition Report</span>
                </a>
                <?php endif; ?>
            </nav>
        </aside>

        <main class="poc-landing" id="poCostingLanding">
            <!-- 1. Released Po — same UI as PO Validation bulk list -->
            <div class="poc-panel is-active" id="pocPanelDashboard" data-panel="dashboard">
                <div class="poc-panel-frame is-native is-rpt-style is-released-po-frame" id="pocReleasedFrame">
                    <div class="rpt-alert" id="poAlert" hidden></div>
                    <?php if (!empty($canAsideDashboard)): ?>
                        <?php require base_path('app/views/reports/partials/po_costing_bulk.php'); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Mailbox — Receive PO / Sent PO (switched via top tabs) -->
            <div class="poc-panel" id="pocPanelMailbox" data-panel="mailbox" hidden>
                <div class="poc-panel-frame is-native is-theme-receive is-rpt-style" id="pocMailboxFrame">
                    <span id="pocMailboxKicker" hidden>Receive PO</span>
                    <span id="pocMailboxTitle" hidden>Received PO list</span>

                    <div class="rpt-table-toolbar poc-mbox-toolbar">
                        <div class="rpt-tb-search">
                            <span class="material-icons-round rpt-search-ico">search</span>
                            <input type="search" id="pocMailboxSearch" class="rpt-tb-input"
                                   placeholder="Quick search table (PO, user, remarks)"
                                   inputmode="numeric" maxlength="40" autocomplete="off">
                        </div>
                        <div class="rpt-tb-actions">
                            <span class="rpt-tb-count" id="pocMailboxCount">—</span>
                            <span class="poc-mbox-pill is-ok" id="pocMboxPillOk" title="Received / Sent count">
                                <span class="material-icons-round">trending_up</span>
                                <span id="pocMboxPillOkText">0</span>
                            </span>
                            <span class="poc-mbox-pill is-warn" id="pocMboxPillWarn" title="Without remarks">
                                <span class="material-icons-round">trending_down</span>
                                <span id="pocMboxPillWarnText">0 no remarks</span>
                            </span>
                            <button type="button" class="rpt-tb-btn" id="pocMailboxRefresh" title="Refresh list">
                                <span class="material-icons-round">refresh</span>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>

                    <div class="poc-approved-list poc-flow-list" id="pocMailboxList">
                        <div class="poc-aside-empty">Loading…</div>
                    </div>
                </div>
            </div>

            <!-- 3. Workflow Assignment (separate page) -->
            <div class="poc-panel" id="pocPanelWorkflow" data-panel="workflow" hidden>
                <div class="poc-panel-frame is-native is-theme-approved is-rpt-style is-po-flow" id="pocWorkflowFrame">
                    <section class="pcw-workflow poc-flow-page" id="pocWorkflowPanel" aria-label="Workflow Assignment">
                        <header class="pcw-workflow-head">
                            <div class="pcw-workflow-head-left">
                                <span class="pcw-workflow-ico material-icons-round" aria-hidden="true">account_tree</span>
                                <div>
                                    <p class="pcw-workflow-kicker">PO Costing</p>
                                    <h3>Workflow Assignment</h3>
                                    <p class="pcw-workflow-sub">Approver Assignment is for PO approval flow. Daily Report Assignment is separate — only those staff get the 11:00 PM daily e-sign report email.</p>
                                </div>
                            </div>
                            <div class="pcw-workflow-summary" id="pocWorkflowSummary" aria-live="polite">
                                <span class="pcw-wf-pill is-approver"><span class="material-icons-round">verified_user</span> <b id="pocWfApproverCount">0</b> approvers</span>
                                <span class="pcw-wf-pill is-daily"><span class="material-icons-round">today</span> <b id="pocWfDailyCount">0</b> daily report</span>
                            </div>
                        </header>

                        <div class="pcw-workflow-grid">
                            <div class="pcw-workflow-card" data-role="approver">
                                <header class="pcw-workflow-card-head">
                                    <span class="material-icons-round">verified_user</span>
                                    <div>
                                        <strong>Approver Assignment</strong>
                                        <p>Multi-select portal users. When a PO enters the flow they get an approval request and can Approve / Reject with remarks.</p>
                                    </div>
                                </header>
                                <label class="pcw-workflow-search">
                                    <span class="material-icons-round" aria-hidden="true">search</span>
                                    <input type="search" id="pocWfApproverSearch" placeholder="Search users to add as Approver…" autocomplete="off">
                                </label>
                                <div class="pcw-workflow-suggest" id="pocWfApproverSuggest" hidden role="listbox"></div>
                                <div class="pcw-workflow-chips" id="pocWfApproverChips" aria-label="Selected approvers"></div>
                                <ul class="pcw-workflow-status-list" id="pocWfApproverStatus" aria-label="Configured approvers"></ul>
                            </div>

                            <div class="pcw-workflow-card" data-role="daily_report">
                                <header class="pcw-workflow-card-head">
                                    <span class="material-icons-round">today</span>
                                    <div>
                                        <strong>Daily Report Assignment</strong>
                                        <p>Only these staff receive the PO E-Signature Daily Report at 11:00 PM IST. Approvers are not emailed this report.</p>
                                    </div>
                                </header>
                                <label class="pcw-workflow-search">
                                    <span class="material-icons-round" aria-hidden="true">search</span>
                                    <input type="search" id="pocWfDailySearch" placeholder="Search users to add for Daily Report…" autocomplete="off">
                                </label>
                                <div class="pcw-workflow-suggest" id="pocWfDailySuggest" hidden role="listbox"></div>
                                <div class="pcw-workflow-chips" id="pocWfDailyChips" aria-label="Selected daily report users"></div>
                                <ul class="pcw-workflow-status-list" id="pocWfDailyStatus" aria-label="Configured daily report users"></ul>
                            </div>
                        </div>

                        <footer class="pcw-workflow-foot">
                            <p class="pcw-workflow-msg" id="pocWorkflowMsg" role="status" hidden></p>
                            <div class="pcw-workflow-actions">
                                <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pocWfSaveFlowBtn"
                                        title="Save Approver and Daily Report configuration">
                                    <span class="pcw-wf-spin" aria-hidden="true"></span>
                                    <span class="material-icons-round pcw-wf-ico">save</span>
                                    <span class="pcw-wf-label">Save Workflow Configuration</span>
                                </button>
                            </div>
                        </footer>
                    </section>
                </div>
            </div>

            <!-- 4. Approved PO (separate page) -->
            <div class="poc-panel" id="pocPanelApproved" data-panel="approved" hidden>
                <div class="poc-panel-frame is-native is-theme-approved is-rpt-style" id="pocApprovedFrame">
                    <div class="rpt-table-toolbar poc-mbox-toolbar">
                        <div class="rpt-tb-search">
                            <span class="material-icons-round rpt-search-ico">search</span>
                            <input type="search" id="pocApprovedSearch" class="rpt-tb-input"
                                   placeholder="Search approved history (PO, user, note)"
                                   inputmode="numeric" maxlength="40" autocomplete="off">
                        </div>
                        <div class="rpt-tb-actions">
                            <span class="rpt-tb-count" id="pocApprovedCount">—</span>
                            <span class="poc-mbox-pill is-ok" id="pocApprovedPillOk" title="Approved count">
                                <span class="material-icons-round">verified</span>
                                <span id="pocApprovedPillOkText">0 approved</span>
                            </span>
                            <button type="button" class="rpt-tb-btn" id="pocApprovedRefresh" title="Refresh list">
                                <span class="material-icons-round">refresh</span>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>
                    <div class="poc-approved-list poc-flow-list" id="pocApprovedList" role="list">
                        <div class="poc-aside-empty">Loading approved POs…</div>
                    </div>
                </div>
            </div>

            <!-- 5. Related PO — NotApproved from Z_QuotationZCostCom -->
            <?php if ($canAsideRelated): ?>
                <?php require base_path('app/views/reports/partials/po_related.php'); ?>
            <?php endif; ?>

            <!-- 6. Search PO — header search opens Costing Workbench -->
            <?php if (!empty($canAsideSearchPo)): ?>
                <?php require base_path('app/views/reports/partials/po_search.php'); ?>
            <?php endif; ?>

            <!-- 7. PO Valuation Report — KPIs + PO-wise log activity -->
            <?php if (!empty($canAsideValuation)): ?>
                <?php require base_path('app/views/reports/partials/po_valuation_report.php'); ?>
            <?php endif; ?>
        </main>
    </div>

    <?php require base_path('app/views/reports/partials/po_sent_modal.php'); ?>
    <?php if (!empty($canAsideValuation)): ?>
    <div class="pvr-modal" id="pvrActivityModal" hidden aria-hidden="true">
        <div class="pvr-modal-backdrop" id="pvrModalBackdrop"></div>
        <div class="pvr-modal-card" role="dialog" aria-modal="true" aria-labelledby="pvrModalTitle">
            <div class="pvr-modal-head">
                <div>
                    <p class="pvr-modal-kicker">Recent Activity</p>
                    <h3 id="pvrModalTitle">PO —</h3>
                </div>
                <button type="button" class="pvr-modal-close" id="pvrModalClose" title="Close" aria-label="Close">
                    <span class="material-icons-round">close</span>
                </button>
            </div>
            <div class="pvr-modal-body" id="pvrModalBody"></div>
        </div>
    </div>
    <?php endif; ?>
    <?php require base_path('app/views/partials/footer.php'); ?>
</div>

<script>
(function () {
    // Named window so workbench Back can focus this tab and close without reload
    try { window.name = 'sap_reports_po_costing'; } catch (e) {}
    // Workbench Back posts here → focus this already-open Released Po tab
    try {
        var poNavBc = new BroadcastChannel('sap_reports_po_nav');
        poNavBc.onmessage = function (ev) {
            var data = ev && ev.data;
            if (!data || data.type !== 'focus-released-po') return;
            try { window.focus(); } catch (err) {}
            try {
                if (document.hidden && typeof document.hasFocus === 'function') {
                    window.focus();
                }
            } catch (err2) {}
        };
    } catch (bcErr) {}

    var page = document.getElementById('poCostingPage');
    if (!page) return;

    var form = null;
    var findHeaderFilters = document.getElementById('pcbFilterForm');
    var poInput = null;
    var matSel = null;
    var brandSel = null;
    var formError = null;
    var poBaseUrl = page.getAttribute('data-po-base') || '';
    var passLogUrl = page.getAttribute('data-pass-log') || '';
    var passLogPageUrl = page.getAttribute('data-pass-log-page') || '';
    var sentBoxUrl = page.getAttribute('data-sent-box') || '';
    var pdfBaseUrl = page.getAttribute('data-pdf-url') || '';
    var sendMailUrl = page.getAttribute('data-send-mail') || '';
    var usersUrl = page.getAttribute('data-users-url') || '';
    var workflowUrl = page.getAttribute('data-workflow-url') || '';
    var workflowSaveUrl = page.getAttribute('data-workflow-save') || '';
    var workflowNotifyUrl = page.getAttribute('data-workflow-notify') || '';
    var workflowDecideUrl = page.getAttribute('data-workflow-decide') || '';
    var canEsign = page.getAttribute('data-can-esign') !== '0';
    var canAssign = page.getAttribute('data-can-assign') !== '0';
    var canWorkflow = page.getAttribute('data-can-workflow') !== '0';
    var asideAccess = {
        dashboard: page.getAttribute('data-aside-dashboard') !== '0',
        received: page.getAttribute('data-aside-received') !== '0',
        sent: page.getAttribute('data-aside-sent') !== '0',
        workflow: page.getAttribute('data-aside-workflow') !== '0',
        approved: page.getAttribute('data-aside-approved') !== '0',
        related: page.getAttribute('data-aside-related') !== '0',
        search: page.getAttribute('data-aside-search') !== '0',
        valuation: page.getAttribute('data-aside-valuation') !== '0',
        mailbox: page.getAttribute('data-aside-received') !== '0' || page.getAttribute('data-aside-sent') !== '0'
    };
    function firstAllowedPanel() {
        if (asideAccess.dashboard) return 'dashboard';
        if (asideAccess.received) return 'received';
        if (asideAccess.sent) return 'sent';
        if (asideAccess.workflow) return 'workflow';
        if (asideAccess.approved) return 'approved';
        if (asideAccess.related) return 'related';
        if (asideAccess.search) return 'search';
        if (asideAccess.valuation) return 'valuation';
        return 'dashboard';
    }
    function canOpenPanel(name) {
        var k = String(name || 'dashboard');
        if (k === 'flow') k = 'workflow';
        if (k === 'mailbox') return !!asideAccess.mailbox;
        return !!asideAccess[k];
    }
    var navBtns = page.querySelectorAll('[data-poc-panel]');
    var panels = page.querySelectorAll('.poc-panel');
    var approvedList = document.getElementById('pocApprovedList');
    var approvedCount = document.getElementById('pocApprovedCount');
    var approvedSearch = document.getElementById('pocApprovedSearch');
    var approvedRefresh = document.getElementById('pocApprovedRefresh');
    var mailboxFrame = document.getElementById('pocMailboxFrame');
    var mailboxList = document.getElementById('pocMailboxList');
    var mailboxCount = document.getElementById('pocMailboxCount');
    var mailboxSearch = document.getElementById('pocMailboxSearch');
    var mailboxRefresh = document.getElementById('pocMailboxRefresh');
    var mailboxTitle = document.getElementById('pocMailboxTitle');
    var mailboxKicker = document.getElementById('pocMailboxKicker');
    var mailboxHeroIco = null;

    var tabBadgeRecv = document.getElementById('pocTabBadgeRecv');
    var tabBadgeSent = document.getElementById('pocTabBadgeSent');
    var tabBadgeApproved = document.getElementById('pocTabBadgeApproved');

    /** @type {Array<Record<string, mixed>>} */
    var approvedRows = [];
    /** @type {Array<Record<string, mixed>>} */
    var receivedRows = [];
    /** @type {Array<Record<string, mixed>>} */
    var sentRows = [];
    var boxLoaded = { received: false, sent: false };
    var approvedLoaded = false;
    var mailboxTab = 'received';

    function updateTabBadges() {
        if (tabBadgeRecv) tabBadgeRecv.textContent = String(receivedRows.length || 0);
        if (tabBadgeSent) tabBadgeSent.textContent = String(sentRows.length || 0);
        if (tabBadgeApproved) tabBadgeApproved.textContent = String(approvedRows.length || 0);
    }

    function pdfUrlFor(po) {
        var digits = String(po || '').replace(/\D/g, '');
        if (!digits) return '#';
        try {
            var u = new URL(pdfBaseUrl || (poBaseUrl.replace(/\/?$/, '') + '/pdf'), window.location.origin);
            if (!pdfBaseUrl) {
                u = new URL(String(poBaseUrl || 'purchase-order').replace(/\/?$/, '') + '/pdf', window.location.origin);
            }
            u.searchParams.set('po', digits);
            return u.toString();
        } catch (_) {
            return (pdfBaseUrl || 'purchase-order/pdf') + '?po=' + encodeURIComponent(digits);
        }
    }

    function bindFlowCardActions(listEl) {
        if (!listEl) return;
        listEl.querySelectorAll('[data-open-po]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPo(btn.getAttribute('data-open-po') || '');
            });
        });
        listEl.querySelectorAll('[data-open-pdf]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var href = btn.getAttribute('href') || pdfUrlFor(btn.getAttribute('data-open-pdf') || '');
                if (href && href !== '#') window.open(href, '_blank', 'noopener');
            });
        });
        listEl.querySelectorAll('[data-open-sent]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openSentModal(btn.getAttribute('data-open-sent') || '');
            });
        });
        listEl.querySelectorAll('[data-open-esign]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openEsignPo(btn.getAttribute('data-open-esign') || '');
            });
        });
        listEl.querySelectorAll('.poc-mailbox-row').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.target.closest('a,button')) return;
                openPo(card.getAttribute('data-po') || '');
            });
            card.addEventListener('keydown', function (e) {
                if (e.target.closest('a,button')) return;
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                openPo(card.getAttribute('data-po') || '');
            });
        });
    }

    function sentShowError(msg) {
        var el = document.getElementById('pcwSentError');
        if (!el) return;
        el.textContent = msg || '';
        el.hidden = !msg;
    }

    var sentUsersCache = [];
    var sentUserActiveIdx = -1;

    function clearSentUserSelection(keepQuery) {
        var combo = document.getElementById('pcwSentUserCombo');
        var hiddenId = document.getElementById('pcwSentUser');
        var hiddenName = document.getElementById('pcwSentUsername');
        var search = document.getElementById('pcwSentUserSearch');
        var clearBtn = document.getElementById('pcwSentUserClear');
        var emailEl = document.getElementById('pcwSentEmail');
        if (hiddenId) hiddenId.value = '';
        if (hiddenName) hiddenName.value = '';
        if (!keepQuery && search) search.value = '';
        if (emailEl) emailEl.value = '';
        if (clearBtn) clearBtn.hidden = true;
        if (combo) combo.classList.remove('is-invalid');
        sentShowError('');
    }

    function closeSentUserList() {
        var combo = document.getElementById('pcwSentUserCombo');
        var search = document.getElementById('pcwSentUserSearch');
        var list = document.getElementById('pcwSentUserList');
        if (list) list.hidden = true;
        if (combo) combo.classList.remove('is-open');
        if (search) search.setAttribute('aria-expanded', 'false');
        sentUserActiveIdx = -1;
    }

    function openSentUserList() {
        var combo = document.getElementById('pcwSentUserCombo');
        var search = document.getElementById('pcwSentUserSearch');
        var list = document.getElementById('pcwSentUserList');
        if (!list) return;
        list.hidden = false;
        if (combo) combo.classList.add('is-open');
        if (search) search.setAttribute('aria-expanded', 'true');
    }

    function setSentUserSelection(user) {
        var combo = document.getElementById('pcwSentUserCombo');
        var hiddenId = document.getElementById('pcwSentUser');
        var hiddenName = document.getElementById('pcwSentUsername');
        var search = document.getElementById('pcwSentUserSearch');
        var clearBtn = document.getElementById('pcwSentUserClear');
        var emailEl = document.getElementById('pcwSentEmail');
        if (!user) {
            clearSentUserSelection(false);
            return;
        }
        if (hiddenId) hiddenId.value = String(user.id || '');
        if (hiddenName) hiddenName.value = String(user.username || '');
        if (search) search.value = String(user.username || '');
        if (emailEl) emailEl.value = String(user.email || '');
        if (clearBtn) clearBtn.hidden = !user.id;
        if (combo) combo.classList.remove('is-invalid');
        sentShowError('');
        closeSentUserList();
    }

    function filteredSentUsers(query) {
        var q = String(query || '').trim().toLowerCase();
        if (!q) return sentUsersCache.slice(0, 80);
        return sentUsersCache.filter(function (u) {
            return (String(u.username) + ' ' + String(u.email) + ' ' + String(u.id)).toLowerCase().indexOf(q) !== -1;
        }).slice(0, 80);
    }

    function highlightSentUserOption() {
        var list = document.getElementById('pcwSentUserList');
        if (!list) return;
        var options = list.querySelectorAll('.pcw-sent-combo-option');
        options.forEach(function (el, i) {
            el.classList.toggle('is-active', i === sentUserActiveIdx);
        });
        var active = list.querySelector('.pcw-sent-combo-option.is-active');
        if (active && typeof active.scrollIntoView === 'function') {
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    function renderSentUserList(query, openIfNeeded) {
        var list = document.getElementById('pcwSentUserList');
        if (!list) return;
        var rows = filteredSentUsers(query);
        var selectedId = String((document.getElementById('pcwSentUser') || {}).value || '');
        if (!rows.length) {
            list.innerHTML = '<li class="pcw-sent-combo-empty">No matching users</li>';
        } else {
            list.innerHTML = rows.map(function (u, i) {
                var selected = selectedId && selectedId === String(u.id);
                return '<li class="pcw-sent-combo-option' + (selected ? ' is-selected' : '') + '" role="option"'
                    + ' data-idx="' + i + '" data-id="' + esc(u.id) + '" data-username="' + esc(u.username) + '" data-email="' + esc(u.email) + '"'
                    + ' aria-selected="' + (selected ? 'true' : 'false') + '">'
                    + '<span class="pcw-sent-combo-name">' + esc(u.username) + '</span>'
                    + (u.email ? '<span class="pcw-sent-combo-email">' + esc(u.email) + '</span>' : '')
                    + '</li>';
            }).join('');
        }
        sentUserActiveIdx = rows.length ? 0 : -1;
        highlightSentUserOption();
        if (openIfNeeded !== false) openSentUserList();
    }

    function pickSentUserFromOption(el) {
        if (!el) return;
        setSentUserSelection({
            id: el.getAttribute('data-id') || '',
            username: el.getAttribute('data-username') || '',
            email: el.getAttribute('data-email') || ''
        });
    }

    async function loadSentUsers() {
        var search = document.getElementById('pcwSentUserSearch');
        if (!search || !usersUrl) return;
        try {
            var res = await fetch(usersUrl + '?limit=80&_=' + Date.now(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var json = await res.json();
            var users = Array.isArray(json && json.data && json.data.users) ? json.data.users : [];
            sentUsersCache = users.map(function (u) {
                return {
                    id: String(u.id != null ? u.id : (u.user_id != null ? u.user_id : (u.username || ''))),
                    username: String(u.username || u.name || u.id || ''),
                    email: String(u.email || '')
                };
            }).filter(function (u) { return u.id || u.username; });
            var prevId = String((document.getElementById('pcwSentUser') || {}).value || '');
            if (prevId) {
                var match = sentUsersCache.find(function (u) { return u.id === prevId; });
                if (match) setSentUserSelection(match);
            }
            renderSentUserList(search.value || '', false);
            closeSentUserList();
        } catch (_) { /* keep options */ }
    }

    function openSentModal(po) {
        var digits = String(po || '').replace(/\D/g, '');
        var shell = document.getElementById('pcwSentModal');
        if (!shell || !digits) return;
        var poHidden = document.getElementById('pcwSentPo');
        var poLabel = document.getElementById('pcwSentPoLabel');
        var remarksEl = document.getElementById('pcwSentRemarks');
        var statusEl = document.getElementById('pcwSentStatus');
        var searchEl = document.getElementById('pcwSentUserSearch');
        if (poHidden) poHidden.value = digits;
        if (poLabel) poLabel.textContent = 'PO ' + digits;
        if (remarksEl) {
            remarksEl.value = '';
            remarksEl.classList.remove('is-invalid');
        }
        if (statusEl) {
            statusEl.value = '';
            statusEl.classList.remove('is-invalid');
        }
        clearSentUserSelection(false);
        var combo = document.getElementById('pcwSentUserCombo');
        if (combo) combo.classList.remove('is-invalid');
        sentShowError('');
        shell.hidden = false;
        shell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pcw-sent-open');
        requestAnimationFrame(function () { shell.classList.add('is-open'); });
        void loadSentUsers();
        setTimeout(function () { if (searchEl) searchEl.focus(); }, 40);
    }

    function closeSentModal() {
        var shell = document.getElementById('pcwSentModal');
        if (!shell) return;
        closeSentUserList();
        shell.classList.remove('is-open');
        shell.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pcw-sent-open');
        setTimeout(function () { shell.hidden = true; }, 200);
    }

    async function submitSentMail() {
        var poHidden = document.getElementById('pcwSentPo');
        var hiddenId = document.getElementById('pcwSentUser');
        var hiddenName = document.getElementById('pcwSentUsername');
        var searchEl = document.getElementById('pcwSentUserSearch');
        var combo = document.getElementById('pcwSentUserCombo');
        var emailEl = document.getElementById('pcwSentEmail');
        var remarksEl = document.getElementById('pcwSentRemarks');
        var statusEl = document.getElementById('pcwSentStatus');
        var btn = document.getElementById('pcwSentSubmit');
        var po = String((poHidden && poHidden.value) || '').replace(/\D/g, '');
        var userId = String((hiddenId && hiddenId.value) || '').trim();
        var username = String((hiddenName && hiddenName.value) || (searchEl && searchEl.value) || '').trim();
        var email = String((emailEl && emailEl.value) || '').trim();
        var remarks = String((remarksEl && remarksEl.value) || '').trim();
        var status = String((statusEl && statusEl.value) || '').trim();
        if (combo) combo.classList.remove('is-invalid');
        if (statusEl) statusEl.classList.remove('is-invalid');
        if (remarksEl) remarksEl.classList.remove('is-invalid');
        sentShowError('');

        if (!po) {
            sentShowError('Purchase order is missing.');
            return;
        }
        if (!userId && !username) {
            if (combo) combo.classList.add('is-invalid');
            if (searchEl) searchEl.focus();
            sentShowError('Select a username.');
            return;
        }
        if (!status) {
            if (statusEl) {
                statusEl.classList.add('is-invalid');
                statusEl.focus();
            }
            sentShowError('Enter a status.');
            return;
        }
        if (!remarks) {
            if (remarksEl) {
                remarksEl.classList.add('is-invalid');
                remarksEl.focus();
            }
            sentShowError('Note is required.');
            return;
        }
        if (!sendMailUrl) {
            sentShowError('Send mail endpoint is missing.');
            return;
        }

        if (btn && (btn.classList.contains('is-loading') || btn.disabled)) return;
        var label = btn ? btn.querySelector('.pcw-sent-submit-label') : null;
        var cancelBtn = document.getElementById('pcwSentCancel');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
        }
        if (label) label.textContent = 'Sending…';
        if (cancelBtn) cancelBtn.disabled = true;
        try {
            var res = await fetch(sendMailUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    po: po,
                    user_id: userId,
                    username: username,
                    email: email,
                    remarks: remarks,
                    note: remarks,
                    status: status
                })
            });
            var json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || ('HTTP ' + res.status));
            }
            var toName = String((json.data && json.data.username) || username);
            var toEmail = String((json.data && json.data.email) || email);
            closeSentModal();
            var toast = document.createElement('div');
            var mailOk = json.email_sent !== false && !json.warning;
            toast.className = mailOk ? 'pcw-toast is-ok' : 'pcw-toast is-warn';
            toast.textContent = String(json.message || (mailOk
                ? ('Mail sent to ' + toName + ' (' + toEmail + ')')
                : ('Saved for ' + toName + ', but email was not delivered')));
            document.body.appendChild(toast);
            setTimeout(function () { toast.remove(); }, mailOk ? 4200 : 7000);
            boxLoaded.sent = false;
            boxLoaded.received = false;
            if (mailboxTab === 'sent' || mailboxTab === 'received') {
                loadBox(mailboxTab, true);
            }
            try {
                if (window.pocRelatedPo && typeof window.pocRelatedPo.load === 'function') {
                    window.pocRelatedPo.load(true);
                }
            } catch (_) {}
        } catch (err) {
            sentShowError(String((err && err.message) || 'Could not send mail'));
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('is-loading');
                btn.removeAttribute('aria-busy');
            }
            if (label) label.textContent = 'Send mail';
            if (cancelBtn) cancelBtn.disabled = false;
        }
    }

    function showError(msg) {
        if (!formError) return;
        formError.textContent = msg || '';
        formError.hidden = !msg;
    }

    function clearPo() {
        // no-op (PO number input removed from Find PO page)
    }

    function clearFilters() {
        if (matSel) matSel.value = 'all';
        if (brandSel) brandSel.value = '';
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function openPo(po) {
        var digits = String(po || '').replace(/\D/g, '');
        if (!digits) return;
        var url = new URL(poBaseUrl || window.location.href, window.location.origin);
        url.searchParams.set('view', 'costing');
        url.searchParams.set('po', digits);
        window.location.href = url.toString();
    }

    function openEsignPo(po) {
        if (!canEsign) {
            alert('You do not have eSign access for PO Costing.');
            return;
        }
        var digits = String(po || '').replace(/\D/g, '');
        if (!digits) return;
        var url = new URL(poBaseUrl || window.location.href, window.location.origin);
        url.searchParams.set('view', 'costing');
        url.searchParams.set('po', digits);
        url.searchParams.set('open', 'esign');
        window.open(url.toString(), '_blank', 'noopener');
    }

    function filterBoxRows(rows) {
        var q = String(mailboxSearch && mailboxSearch.value || '').trim().toLowerCase();
        if (!q) return rows;
        return rows.filter(function (r) {
            var poMatch = String(r.purchase_order || '').replace(/\D/g, '').indexOf(q.replace(/\D/g, '')) >= 0;
            var userMatch = String(r.to_username || r.from_username || '').toLowerCase().indexOf(q) >= 0;
            var remarksMatch = String(r.remarks || '').toLowerCase().indexOf(q) >= 0;
            var statusMatch = String(r.status || r.esign_status || r.approve_status || r.workflow_status || '').toLowerCase().indexOf(q) >= 0;
            return poMatch || userMatch || remarksMatch || statusMatch;
        });
    }

    function formatMailboxWhen(raw) {
        var s = String(raw || '').trim();
        if (!s) return '—';
        // Prefer DD/MM/YYYY HH:MM (Received PO list style)
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
        var d = new Date(s.replace(' ', 'T'));
        if (!isNaN(d.getTime())) {
            var pad = function (n) { return n < 10 ? '0' + n : String(n); };
            return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
        return s;
    }

    function updateMailboxSummary(kind, rows, visible) {
        var isRecv = kind === 'received';
        var list = Array.isArray(rows) ? rows : [];
        var vis = Array.isArray(visible) ? visible : list;
        var withRemarks = 0;
        list.forEach(function (r) {
            if (String(r.remarks || '').trim()) withRemarks += 1;
        });
        var noRemarks = Math.max(0, list.length - withRemarks);
        var pillOk = document.getElementById('pocMboxPillOkText');
        var pillWarn = document.getElementById('pocMboxPillWarnText');
        if (mailboxCount) {
            mailboxCount.textContent = vis.length
                ? ('Showing ' + vis.length + ' of ' + list.length + ' lines')
                : (list.length ? 'Showing 0 of ' + list.length + ' lines' : 'Showing 0 of 0 lines');
        }
        if (pillOk) pillOk.textContent = list.length + (isRecv ? ' received' : ' sent');
        if (pillWarn) pillWarn.textContent = noRemarks + ' no note';
    }

    function syncMailboxChrome(kind) {
        var isRecv = kind === 'received';
        mailboxTab = isRecv ? 'received' : 'sent';
        if (mailboxFrame) {
            mailboxFrame.classList.toggle('is-theme-receive', isRecv);
            mailboxFrame.classList.toggle('is-theme-sent', !isRecv);
            mailboxFrame.classList.add('is-rpt-style');
        }
        if (mailboxTitle) mailboxTitle.textContent = isRecv ? 'Received PO list' : 'Sent PO list';
        if (mailboxKicker) mailboxKicker.textContent = isRecv ? 'Receive PO' : 'Sent PO';
        navBtns.forEach(function (b) {
            var p = b.getAttribute('data-poc-panel');
            b.classList.toggle('is-active', p === mailboxTab);
            b.setAttribute('aria-selected', p === mailboxTab ? 'true' : 'false');
        });
    }

    function renderBoxList(kind) {
        var isRecv = kind === 'received';
        var rows = isRecv ? receivedRows : sentRows;
        if (!mailboxList) return;
        var list = filterBoxRows(rows);
        updateMailboxSummary(kind, rows, list);
        updateTabBadges();

        if (!rows.length) {
            mailboxList.innerHTML = '<div class="poc-empty-state">' +
                '<div class="poc-empty-ico ' + (isRecv ? 'is-recv' : 'is-sent') + '"><span class="material-icons-round">' + (isRecv ? 'move_to_inbox' : 'send') + '</span></div>' +
                '<h3>' + (isRecv ? 'No POs waiting for your action.' : 'No POs sent yet') + '</h3>' +
                '<p>' + (isRecv ? 'Incoming purchase orders forwarded or assigned to you will appear here.' : 'Purchase orders forwarded to other users will be tracked here.') + '</p>' +
                '</div>';
            return;
        }
        if (!list.length) {
            mailboxList.innerHTML = '<div class="poc-empty-state">' +
                '<div class="poc-empty-ico"><span class="material-icons-round">search_off</span></div>' +
                '<h3>No matching purchase orders</h3>' +
                '<p>Try adjusting your search criteria above.</p>' +
                '</div>';
            return;
        }
        var peerLabel = isRecv ? 'From' : 'To';
        var defaultStatus = isRecv ? 'Received' : 'Sent';

        var bodyHtml = list.map(function (r, i) {
            var po = String(r.purchase_order || '').replace(/\D/g, '');
            var when = formatMailboxWhen(r.created_at);
            var peerName = isRecv ? String(r.from_username || '—') : String(r.to_username || '—');
            var poType = String(r.po_type || r.mat_category || r.material_type || '').trim().toUpperCase() || '—';
            var remarks = String(r.remarks || '').trim() || '—';
            var rowStatus = String(r.status || '').trim();
            if (!rowStatus || /^sent$/i.test(rowStatus)) rowStatus = defaultStatus;
            var esignStatus = String(r.esign_status || '').trim();
            var approveStatus = String(r.approve_status || '').trim();
            var validateStatus = String(r.validate_status || '').trim();
            var workflow = String(r.workflow_status || approveStatus || esignStatus || validateStatus || '').trim();
            if (!workflow) workflow = 'Not Started';
            var wfFail = /fail/i.test(workflow);
            var wfPending = /not\s*started/i.test(workflow);
            var wfClass = 'poc-rpt-status' + (wfFail ? ' is-fail' : (wfPending ? ' is-pending' : (esignStatus ? ' is-esign' : (approveStatus ? ' is-ok' : ''))));
            var pdfHref = esc(pdfUrlFor(po));
            return '<tr class="poc-mailbox-row ' + (isRecv ? 'is-receive' : 'is-sent') + '" data-po="' + esc(po) + '" tabindex="0">' +
                '<td class="sno" data-label="S.No">' + (i + 1) + '</td>' +
                '<td data-label="Purchase Order"><a class="poc-rpt-link" href="javascript:void(0)" data-open-po="' + esc(po) + '">' + esc(po) + '</a></td>' +
                '<td data-label="' + peerLabel + '">' + esc(peerName) + '</td>' +
                '<td data-label="PO Type"><strong>' + esc(poType) + '</strong></td>' +
                '<td data-label="Status"><span class="poc-rpt-status">' + esc(rowStatus) + '</span></td>' +
                '<td data-label="E-Sign / Approve"><span class="' + wfClass + '" title="' + esc(
                    [esignStatus && ('E-Sign: ' + esignStatus), approveStatus && ('Approve: ' + approveStatus), validateStatus && ('Validate: ' + validateStatus)]
                        .filter(Boolean).join(' · ') || workflow
                ) + '">' + esc(workflow) + '</span></td>' +
                '<td data-label="Date &amp; Time">' + esc(when) + '</td>' +
                '<td class="poc-rpt-remarks" data-label="Note" title="' + esc(remarks) + '">' + esc(remarks) + '</td>' +
                '<td class="poc-rpt-actions" data-label="Actions">' +
                    '<div class="poc-rpt-act-group" role="group" aria-label="Actions">' +
                        '<button type="button" class="poc-inbox-btn is-icon is-view" data-open-po="' + esc(po) + '" title="View" aria-label="View">' +
                            '<span class="material-icons-round">visibility</span>' +
                        '</button>' +
                        '<a class="poc-inbox-btn is-icon is-pdf" href="' + pdfHref + '" data-open-pdf="' + esc(po) + '" target="_blank" rel="noopener" title="PDF" aria-label="PDF">' +
                            '<span class="material-icons-round">picture_as_pdf</span>' +
                        '</a>' +
                        '<button type="button" class="poc-inbox-btn is-icon is-sent" data-open-sent="' + esc(po) + '" title="Send" aria-label="Send">' +
                            '<span class="material-icons-round">send</span>' +
                        '</button>' +
                        (canEsign
                            ? ('<button type="button" class="poc-inbox-btn is-icon is-esign" data-open-esign="' + esc(po) + '" title="E-Sign" aria-label="E-Sign">' +
                                '<span class="material-icons-round">draw</span>' +
                            '</button>')
                            : '') +
                    '</div>' +
                '</td>' +
            '</tr>';
        }).join('');

        mailboxList.innerHTML =
            '<div class="poc-mailbox-table-wrap is-rpt">' +
                '<table class="poc-mailbox-table is-rpt rpt-table" role="table" aria-label="' + (isRecv ? 'Received PO list' : 'Sent PO list') + '">' +
                    '<thead>' +
                        '<tr class="rpt-header-row">' +
                            '<th scope="col">S.No</th>' +
                            '<th scope="col">Purchase Order</th>' +
                            '<th scope="col">' + peerLabel + '</th>' +
                            '<th scope="col">PO Type</th>' +
                            '<th scope="col">Status</th>' +
                            '<th scope="col">E-Sign / Approve</th>' +
                            '<th scope="col">Date &amp; Time</th>' +
                            '<th scope="col">Note</th>' +
                            '<th scope="col">Actions</th>' +
                        '</tr>' +
                    '</thead>' +
                    '<tbody>' + bodyHtml + '</tbody>' +
                '</table>' +
            '</div>';
        bindFlowCardActions(mailboxList);
    }

    async function loadBox(kind, force) {
        var isRecv = kind === 'received';
        if (!mailboxList || !sentBoxUrl) return;
        syncMailboxChrome(kind);
        if (boxLoaded[kind] && !force) {
            renderBoxList(kind);
            return;
        }
        mailboxList.innerHTML = '<div class="poc-aside-empty">' + (isRecv ? 'Loading inbox…' : 'Loading sent POs…') + '</div>';
        if (mailboxCount) mailboxCount.textContent = '…';
        try {
            var params = new URLSearchParams({
                box: kind,
                limit: '150',
                _: String(Date.now())
            });
            var res = await fetch(sentBoxUrl + '?' + params.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || ('HTTP ' + res.status));
            }
            var rows = Array.isArray(json && json.data && json.data.rows) ? json.data.rows : [];
            if (isRecv) receivedRows = rows;
            else sentRows = rows;
            boxLoaded[kind] = true;
            renderBoxList(kind);
        } catch (err) {
            if (isRecv) receivedRows = [];
            else sentRows = [];
            boxLoaded[kind] = false;
            if (mailboxCount) mailboxCount.textContent = 'Error';
            mailboxList.innerHTML = '<div class="poc-aside-empty is-error">' +
                esc((err && err.message) || 'Could not load list') +
                '</div>';
        }
    }

    function filteredApproved() {
        var q = String(approvedSearch && approvedSearch.value || '').trim().toLowerCase();
        if (!q) return approvedRows;
        return approvedRows.filter(function (r) {
            var poMatch = String(r.purchase_order || '').replace(/\D/g, '').indexOf(q.replace(/\D/g, '')) >= 0;
            var userMatch = String(r.username || '').toLowerCase().indexOf(q) >= 0;
            var remarksMatch = String(r.remarks || r.error_message || r.status || '').toLowerCase().indexOf(q) >= 0;
            return poMatch || userMatch || remarksMatch;
        });
    }

    function renderApproved() {
        if (!approvedList) return;
        // Only successful Approved rows (e-sign / approve mapped to Approved)
        approvedRows = (approvedRows || []).filter(function (r) {
            return r.success !== false && r.success !== 0 && r.success !== '0'
                && !/fail/i.test(String(r.status || ''));
        });
        var list = filteredApproved();
        if (approvedCount) {
            approvedCount.textContent = list.length
                ? ('Showing ' + list.length + ' of ' + approvedRows.length + ' lines')
                : (approvedRows.length ? 'Showing 0 of ' + approvedRows.length + ' lines' : 'Showing 0 of 0 lines');
        }
        var pillOk = document.getElementById('pocApprovedPillOkText');
        if (pillOk) pillOk.textContent = approvedRows.length + ' approved';
        updateTabBadges();

        if (!approvedRows.length) {
            approvedList.innerHTML = '<div class="poc-empty-state">' +
                '<div class="poc-empty-ico is-approved"><span class="material-icons-round">verified</span></div>' +
                '<h3>No approved PO history yet</h3>' +
                '<p>Only purchase orders with Approved status appear here.</p>' +
                '</div>';
            return;
        }
        if (!list.length) {
            approvedList.innerHTML = '<div class="poc-empty-state">' +
                '<div class="poc-empty-ico"><span class="material-icons-round">search_off</span></div>' +
                '<h3>No matching approved purchase orders</h3>' +
                '<p>Try adjusting your search criteria above.</p>' +
                '</div>';
            return;
        }

        var bodyHtml = list.map(function (r, i) {
            var po = String(r.purchase_order || '').replace(/\D/g, '');
            var when = formatMailboxWhen(r.created_at);
            var user = String(r.username || 'admin');
            var rowStatus = 'Approved';
            var remarks = 'Approved';
            var statusClass = 'poc-rpt-status is-ok';
            var pdfHref = esc(pdfUrlFor(po));
            return '<tr class="poc-mailbox-row is-approved" data-po="' + esc(po) + '" tabindex="0">' +
                '<td class="sno" data-label="S.No">' + (i + 1) + '</td>' +
                '<td data-label="Purchase Order"><a class="poc-rpt-link" href="javascript:void(0)" data-open-po="' + esc(po) + '">' + esc(po) + '</a></td>' +
                '<td data-label="Approved By">' + esc(user) + '</td>' +
                '<td data-label="Status"><span class="' + statusClass + '">' + esc(rowStatus) + '</span></td>' +
                '<td data-label="Date &amp; Time">' + esc(when) + '</td>' +
                '<td class="poc-rpt-remarks" data-label="Note" title="' + esc(remarks) + '">' + esc(remarks) + '</td>' +
                '<td class="poc-rpt-actions" data-label="Actions">' +
                    '<div class="poc-rpt-act-group" role="group" aria-label="Actions">' +
                        '<button type="button" class="poc-inbox-btn is-icon is-view" data-open-po="' + esc(po) + '" title="View" aria-label="View">' +
                            '<span class="material-icons-round">visibility</span>' +
                        '</button>' +
                        '<a class="poc-inbox-btn is-icon is-pdf" href="' + pdfHref + '" data-open-pdf="' + esc(po) + '" target="_blank" rel="noopener" title="PDF" aria-label="PDF">' +
                            '<span class="material-icons-round">picture_as_pdf</span>' +
                        '</a>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        }).join('');

        approvedList.innerHTML =
            '<div class="poc-mailbox-table-wrap is-rpt">' +
                '<table class="poc-mailbox-table is-rpt rpt-table" role="table" aria-label="Approved PO list">' +
                    '<thead>' +
                        '<tr class="rpt-header-row">' +
                            '<th scope="col">S.No</th>' +
                            '<th scope="col">Purchase Order</th>' +
                            '<th scope="col">Approved By</th>' +
                            '<th scope="col">Status</th>' +
                            '<th scope="col">Date &amp; Time</th>' +
                            '<th scope="col">Note</th>' +
                            '<th scope="col">Actions</th>' +
                        '</tr>' +
                    '</thead>' +
                    '<tbody>' + bodyHtml + '</tbody>' +
                '</table>' +
            '</div>';
        bindFlowCardActions(approvedList);
    }

    async function loadApproved(force) {
        if (!approvedList || !passLogUrl) return;
        if (approvedLoaded && !force) {
            renderApproved();
            return;
        }
        approvedList.innerHTML = '<div class="poc-aside-empty">Loading approved POs…</div>';
        if (approvedCount) approvedCount.textContent = '…';
        var pillLoading = document.getElementById('pocApprovedPillOkText');
        if (pillLoading) pillLoading.textContent = '…';
        try {
            var params = new URLSearchParams({
                limit: '150',
                success: '1',
                approved_only: '1',
                _: String(Date.now())
            });
            var res = await fetch(passLogUrl + '?' + params.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || ('HTTP ' + res.status));
            }
            approvedRows = Array.isArray(json && json.data && json.data.rows) ? json.data.rows : [];
            approvedLoaded = true;
            renderApproved();
        } catch (err) {
            approvedRows = [];
            approvedLoaded = false;
            if (approvedCount) approvedCount.textContent = 'Error';
            approvedList.innerHTML = '<div class="poc-aside-empty is-error">' +
                esc((err && err.message) || 'Could not load approved POs') +
                '</div>';
        }
    }

    /* ========== PO Flow — Approver + Daily Report Assignment ========== */
    var wfApprovers = [];
    var wfDailyReporters = [];
    var wfSelectedApprovers = [];
    var wfSelectedDaily = [];
    var wfSearchTimers = { approver: null, daily_report: null };
    var wfConfigLoaded = false;

    function wfEl(id) { return document.getElementById(id); }

    function wfMsg(text, isErr) {
        var el = wfEl('pocWorkflowMsg');
        if (!el) return;
        if (!text) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.textContent = text;
        el.classList.toggle('is-err', !!isErr);
    }

    function wfStatusClass(status) {
        var s = String(status || '');
        if (/fail|reject/i.test(s)) return 'is-fail';
        if (/approved|signed|configured/i.test(s)) return 'is-ok';
        if (/pending|e-sign|viewed|email/i.test(s)) return 'is-warn';
        return 'is-info';
    }

    function wfUserKey(u) {
        return String((u && (u.username || u.id)) || '').trim().toLowerCase();
    }

    function wfRoleMeta(role) {
        if (role === 'daily_report') {
            return {
                chips: 'pocWfDailyChips',
                status: 'pocWfDailyStatus',
                count: 'pocWfDailyCount',
                search: 'pocWfDailySearch',
                suggest: 'pocWfDailySuggest',
                selected: function () { return wfSelectedDaily; },
                setSelected: function (list) { wfSelectedDaily = list; },
                rows: function () { return wfDailyReporters; }
            };
        }
        return {
            chips: 'pocWfApproverChips',
            status: 'pocWfApproverStatus',
            count: 'pocWfApproverCount',
            search: 'pocWfApproverSearch',
            suggest: 'pocWfApproverSuggest',
            selected: function () { return wfSelectedApprovers; },
            setSelected: function (list) { wfSelectedApprovers = list; },
            rows: function () { return wfApprovers; }
        };
    }

    function renderWfChips(role) {
        var meta = wfRoleMeta(role);
        var wrap = wfEl(meta.chips);
        var list = meta.selected();
        if (!wrap) return;
        if (!list.length) {
            wrap.innerHTML = '<span style="font-size:11px;color:#94a3b8;font-weight:600;">No users selected</span>';
            return;
        }
        wrap.innerHTML = list.map(function (u) {
            var name = esc(u.username || '');
            var key = esc(wfUserKey(u));
            return '<span class="pcw-wf-chip" data-user="' + key + '">' + name
                + '<button type="button" data-wf-remove="' + key + '" data-wf-role="' + role + '" title="Remove" aria-label="Remove">'
                + '<span class="material-icons-round">close</span></button></span>';
        }).join('');
    }

    function renderWfStatusLists() {
        var paint = function (role) {
            var meta = wfRoleMeta(role);
            var el = wfEl(meta.status);
            var rows = meta.rows();
            if (!el) return;
            if (!rows.length) {
                el.innerHTML = '<li><span class="pcw-wf-user" style="color:#94a3b8;font-weight:600;">No users configured</span></li>';
            } else {
                el.innerHTML = rows.map(function (r) {
                    var st = esc(r.status || 'Configured');
                    return '<li><span class="pcw-wf-user">' + esc(r.username || '—') + '</span>'
                        + '<span class="pcw-wf-st ' + wfStatusClass(st) + '">' + st + '</span></li>';
                }).join('');
            }
            if (wfEl(meta.count)) {
                wfEl(meta.count).textContent = String(meta.selected().length || rows.length);
            }
        };
        paint('approver');
        paint('daily_report');
    }

    function applyWorkflowData(data) {
        data = data || {};
        wfApprovers = Array.isArray(data.approvers) ? data.approvers : [];
        wfDailyReporters = Array.isArray(data.daily_reporters) ? data.daily_reporters : [];
        wfSelectedApprovers = wfApprovers.map(function (r) {
            return { id: r.user_id, username: r.username, email: r.email };
        });
        wfSelectedDaily = wfDailyReporters.map(function (r) {
            return { id: r.user_id, username: r.username, email: r.email };
        });
        renderWfChips('approver');
        renderWfChips('daily_report');
        renderWfStatusLists();
    }

    async function loadGlobalWorkflowConfig(force) {
        if (wfConfigLoaded && !force) {
            renderWfChips('approver');
            renderWfChips('daily_report');
            renderWfStatusLists();
            return;
        }
        if (!workflowUrl) {
            wfMsg('Workflow API is not configured.', true);
            return;
        }
        wfMsg('Loading configuration…');
        try {
            var res = await fetch(workflowUrl + '?_=' + Date.now(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var json = await res.json().catch(function () { return {}; });
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || ('HTTP ' + res.status));
            }
            applyWorkflowData(json.data || {});
            wfConfigLoaded = true;
            wfMsg('');
        } catch (err) {
            applyWorkflowData({});
            wfConfigLoaded = false;
            wfMsg(String((err && err.message) || 'Could not load workflow configuration'), true);
        }
    }

    async function searchWfUsers(q, role) {
        var meta = wfRoleMeta(role);
        var suggest = wfEl(meta.suggest);
        if (!suggest || !usersUrl) return;
        try {
            var res = await fetch(usersUrl + '?q=' + encodeURIComponent(String(q || '').trim()) + '&limit=20', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });
            var json = await res.json().catch(function () { return {}; });
            var users = Array.isArray(json && json.data && json.data.users) ? json.data.users : [];
            var selectedKeys = {};
            meta.selected().forEach(function (u) { selectedKeys[wfUserKey(u)] = true; });
            var filtered = users.filter(function (u) { return !selectedKeys[wfUserKey(u)]; });
            if (!filtered.length) {
                suggest.innerHTML = '<button type="button" disabled>No users found</button>';
                suggest.hidden = false;
                return;
            }
            suggest.innerHTML = filtered.map(function (u) {
                var uname = esc(u.username || '');
                var email = esc(u.email || '');
                var id = esc(u.id != null ? u.id : '');
                return '<button type="button" role="option" data-wf-add="' + uname + '" data-wf-role="' + role + '"'
                    + ' data-id="' + id + '" data-email="' + email + '" data-username="' + uname + '">'
                    + '<strong>' + uname + '</strong><span>' + (email || 'no email') + '</span></button>';
            }).join('');
            suggest.hidden = false;
        } catch (_) {
            suggest.hidden = true;
        }
    }

    function addWfUser(role, user) {
        var meta = wfRoleMeta(role);
        var list = meta.selected().slice();
        var key = wfUserKey(user);
        if (!key) return;
        if (list.some(function (u) { return wfUserKey(u) === key; })) return;
        var rawId = user.id != null ? user.id : (user.user_id != null ? user.user_id : null);
        var numId = (rawId != null && String(rawId).match(/^\d+$/)) ? parseInt(String(rawId), 10) : null;
        list.push({
            id: numId,
            username: String(user.username || '').trim(),
            email: String(user.email || '').trim()
        });
        meta.setSelected(list);
        renderWfChips(role);
        renderWfStatusLists();
    }

    function removeWfUser(role, key) {
        var meta = wfRoleMeta(role);
        var k = String(key || '').toLowerCase();
        meta.setSelected(meta.selected().filter(function (u) { return wfUserKey(u) !== k; }));
        renderWfChips(role);
        renderWfStatusLists();
    }

    function setWfBtnLoading(btn, on, labelIdle) {
        if (!btn) return;
        btn.classList.toggle('is-loading', !!on);
        btn.disabled = !!on;
        var label = btn.querySelector('.pcw-wf-label');
        if (label && on) label.textContent = 'Working…';
        if (label && !on && labelIdle) label.textContent = labelIdle;
    }

    /** Save Approver + Daily Report config — emails fire later when a PO enters the flow. */
    async function saveWorkflowConfiguration() {
        if (!canWorkflow) {
            wfMsg('You do not have Workflow page access for PO Costing.', true);
            return;
        }
        var btn = wfEl('pocWfSaveFlowBtn');
        setWfBtnLoading(btn, true, 'Save Workflow Configuration');
        wfMsg('');
        try {
            var saveRes = await fetch(workflowSaveUrl || 'purchase-order/workflow/save', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    viewers: [],
                    approvers: wfSelectedApprovers,
                    daily_reporters: wfSelectedDaily
                })
            });
            var saveJson = await saveRes.json().catch(function () { return {}; });
            if (!saveRes.ok || saveJson.success === false) {
                throw new Error(saveJson.message || saveJson.error || 'Could not save configuration');
            }
            applyWorkflowData(saveJson.data || {});
            wfConfigLoaded = true;
            wfMsg(String(saveJson.message || 'Workflow configuration saved'));
        } catch (err) {
            wfMsg(String((err && err.message) || 'Save failed'), true);
        } finally {
            setWfBtnLoading(btn, false, 'Save Workflow Configuration');
        }
    }

    function bindWorkflowPageUi() {
        if (!wfEl('pocWorkflowPanel')) return;

        var bindSearch = function (role) {
            var meta = wfRoleMeta(role);
            var input = wfEl(meta.search);
            var suggest = wfEl(meta.suggest);
            if (!input) return;
            input.addEventListener('input', function () {
                clearTimeout(wfSearchTimers[role]);
                wfSearchTimers[role] = setTimeout(function () {
                    void searchWfUsers(input.value, role);
                }, 180);
            });
            input.addEventListener('focus', function () { void searchWfUsers(input.value, role); });
            input.addEventListener('blur', function () {
                setTimeout(function () { if (suggest) suggest.hidden = true; }, 180);
            });
            if (suggest) {
                suggest.addEventListener('mousedown', function (e) {
                    var btn = e.target.closest('[data-wf-add]');
                    if (!btn) return;
                    e.preventDefault();
                    addWfUser(role, {
                        id: btn.getAttribute('data-id'),
                        username: btn.getAttribute('data-username'),
                        email: btn.getAttribute('data-email')
                    });
                    input.value = '';
                    suggest.hidden = true;
                });
            }
        };
        bindSearch('approver');
        bindSearch('daily_report');

        ['pocWfApproverChips', 'pocWfDailyChips'].forEach(function (id) {
            var el = wfEl(id);
            if (!el) return;
            el.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-wf-remove]');
                if (!btn) return;
                removeWfUser(btn.getAttribute('data-wf-role') || 'approver', btn.getAttribute('data-wf-remove'));
            });
        });

        var saveFlowBtn = wfEl('pocWfSaveFlowBtn');
        if (saveFlowBtn) saveFlowBtn.addEventListener('click', function () { void saveWorkflowConfiguration(); });

        if (!canWorkflow) {
            ['pocWfApproverSearch', 'pocWfDailySearch', 'pocWfSaveFlowBtn'].forEach(function (id) {
                var el = wfEl(id);
                if (el) el.disabled = true;
            });
        }

        renderWfChips('approver');
        renderWfChips('daily_report');
        renderWfStatusLists();
    }

    function showPanel(name) {
        var key = String(name || 'dashboard');
        if (key === 'flow') key = 'workflow';
        if (['dashboard', 'received', 'sent', 'mailbox', 'workflow', 'approved', 'related', 'search', 'valuation'].indexOf(key) < 0) key = 'dashboard';
        if (!canOpenPanel(key)) {
            key = firstAllowedPanel();
        }

        var panelKey = key;
        if (key === 'received' || key === 'sent' || key === 'mailbox') panelKey = 'mailbox';

        panels.forEach(function (p) {
            var on = p.getAttribute('data-panel') === panelKey;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
        navBtns.forEach(function (b) {
            var p = b.getAttribute('data-poc-panel');
            var active = (panelKey === 'mailbox')
                ? (p === (key === 'sent' ? 'sent' : 'received') || (key === 'mailbox' && p === 'received'))
                : (p === key);
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (window.pocSearchPo && typeof window.pocSearchPo.close === 'function' && key !== 'search') {
            window.pocSearchPo.close();
        }
        if (window.pocValuationReport && typeof window.pocValuationReport.close === 'function' && key !== 'valuation') {
            window.pocValuationReport.close();
        }

        if (key === 'related') {
            page.classList.toggle('is-lookup', false);
            page.classList.toggle('is-workflow', true);
            page.classList.add('is-related-po', 'is-related-results');
            page.classList.remove('is-related-lookup', 'is-costing-bulk', 'is-released-po', 'is-search-po', 'is-valuation-report', 'is-approved-po');
            if (findHeaderFilters) findHeaderFilters.hidden = true;
            var relatedTitle = document.getElementById('pocPageTitle');
            if (relatedTitle) relatedTitle.innerHTML = '<strong>Draft PO</strong>';
            try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
            if (window.pocRelatedPo && typeof window.pocRelatedPo.open === 'function') {
                window.pocRelatedPo.open();
            }
        } else if (key === 'search') {
            if (window.pocRelatedPo && typeof window.pocRelatedPo.close === 'function') {
                window.pocRelatedPo.close();
            }
            page.classList.remove('is-related-po', 'is-related-results', 'is-related-lookup', 'is-costing-bulk', 'is-released-po', 'is-workflow', 'is-valuation-report', 'is-approved-po');
            page.classList.add('is-lookup', 'is-search-po');
            if (findHeaderFilters) findHeaderFilters.hidden = true;
            try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
            if (window.pocSearchPo && typeof window.pocSearchPo.open === 'function') {
                window.pocSearchPo.open();
            }
        } else if (key === 'valuation') {
            if (window.pocRelatedPo && typeof window.pocRelatedPo.close === 'function') {
                window.pocRelatedPo.close();
            }
            page.classList.remove('is-related-po', 'is-related-results', 'is-related-lookup', 'is-costing-bulk', 'is-released-po', 'is-search-po', 'is-lookup', 'is-approved-po');
            page.classList.add('is-workflow', 'is-valuation-report');
            if (findHeaderFilters) findHeaderFilters.hidden = true;
            try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
            if (window.pocValuationReport && typeof window.pocValuationReport.open === 'function') {
                window.pocValuationReport.open();
            }
        } else if (key === 'dashboard') {
            if (window.pocRelatedPo && typeof window.pocRelatedPo.close === 'function') {
                window.pocRelatedPo.close();
            }
            page.classList.remove('is-related-po', 'is-related-results', 'is-related-lookup', 'is-lookup', 'is-search-po', 'is-valuation-report', 'is-approved-po');
            page.classList.add('is-workflow', 'is-costing-bulk', 'is-released-po');
            if (findHeaderFilters) findHeaderFilters.hidden = false;
            var titleEl = document.getElementById('pocPageTitle');
            if (titleEl) titleEl.innerHTML = '<strong>Released Po</strong>';
            // Load Released Po list now (deferred when user landed on Search PO / other panels)
            try {
                if (window.pcbBulkList && typeof window.pcbBulkList.ensureLoaded === 'function') {
                    window.pcbBulkList.ensureLoaded();
                }
            } catch (_) {}
        } else if (key === 'approved') {
            if (window.pocRelatedPo && typeof window.pocRelatedPo.close === 'function') {
                window.pocRelatedPo.close();
            }
            page.classList.remove('is-related-po', 'is-related-results', 'is-related-lookup', 'is-lookup', 'is-costing-bulk', 'is-released-po', 'is-search-po', 'is-valuation-report');
            page.classList.add('is-workflow', 'is-approved-po');
            if (findHeaderFilters) findHeaderFilters.hidden = true;
            var approvedTitle = document.getElementById('pocPageTitle');
            if (approvedTitle) approvedTitle.innerHTML = '<strong>Approved PO</strong>';
        } else {
            if (window.pocRelatedPo && typeof window.pocRelatedPo.close === 'function') {
                window.pocRelatedPo.close();
            }
            page.classList.remove('is-related-po', 'is-related-results', 'is-related-lookup', 'is-lookup', 'is-costing-bulk', 'is-released-po', 'is-search-po', 'is-valuation-report', 'is-approved-po');
            page.classList.toggle('is-workflow', true);
            if (findHeaderFilters) findHeaderFilters.hidden = true;
            var otherTitle = document.getElementById('pocPageTitle');
            if (otherTitle && key === 'workflow') otherTitle.innerHTML = '<strong>Workflow</strong>';
            else if (otherTitle && (key === 'received' || key === 'mailbox')) otherTitle.innerHTML = '<strong>Receive PO</strong>';
            else if (otherTitle && key === 'sent') otherTitle.innerHTML = '<strong>Sent PO</strong>';
        }

        if (panelKey === 'mailbox') {
            var tab = (key === 'sent') ? 'sent' : 'received';
            if (key === 'mailbox') tab = mailboxTab || 'received';
            if (tab === 'sent' && !asideAccess.sent) tab = 'received';
            if (tab === 'received' && !asideAccess.received && asideAccess.sent) tab = 'sent';
            loadBox(tab, false);
        }
        if (key === 'workflow') {
            void loadGlobalWorkflowConfig(false);
        }
        if (key === 'approved') {
            loadApproved(true);
        }

        try {
            var u = new URL(window.location.href);
            if (key === 'dashboard') u.searchParams.delete('panel');
            else u.searchParams.set('panel', key === 'mailbox' ? mailboxTab : key);
            window.history.replaceState(null, '', u.toString());
        } catch (_) {}
    }

    navBtns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            // Keep in-page panel switch; href is fallback if JS fails / for open-in-new-tab
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
            e.preventDefault();
            // Capture-phase loader.js may have opened the spinner for <a href> — force close
            try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
            showPanel(btn.getAttribute('data-poc-panel'));
        });
    });

    if (mailboxRefresh) {
        mailboxRefresh.addEventListener('click', function () {
            boxLoaded[mailboxTab] = false;
            loadBox(mailboxTab, true);
        });
    }

    (function bindSentModal() {
        var closeBtn = document.getElementById('pcwSentClose');
        var cancelBtn = document.getElementById('pcwSentCancel');
        var backdrop = document.getElementById('pcwSentBackdrop');
        var searchEl = document.getElementById('pcwSentUserSearch');
        var listEl = document.getElementById('pcwSentUserList');
        var clearBtn = document.getElementById('pcwSentUserClear');
        var submitBtn = document.getElementById('pcwSentSubmit');
        var remarksEl = document.getElementById('pcwSentRemarks');
        if (closeBtn) closeBtn.addEventListener('click', closeSentModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeSentModal);
        if (backdrop) backdrop.addEventListener('click', closeSentModal);
        if (searchEl) {
            searchEl.addEventListener('focus', function () {
                renderSentUserList(searchEl.value || '', true);
            });
            searchEl.addEventListener('input', function () {
                var q = String(searchEl.value || '');
                var selectedName = String((document.getElementById('pcwSentUsername') || {}).value || '');
                if (selectedName && q !== selectedName) {
                    var hiddenId = document.getElementById('pcwSentUser');
                    var hiddenName = document.getElementById('pcwSentUsername');
                    var emailEl = document.getElementById('pcwSentEmail');
                    var clr = document.getElementById('pcwSentUserClear');
                    if (hiddenId) hiddenId.value = '';
                    if (hiddenName) hiddenName.value = '';
                    if (emailEl) emailEl.value = '';
                    if (clr) clr.hidden = true;
                }
                var combo = document.getElementById('pcwSentUserCombo');
                if (combo) combo.classList.remove('is-invalid');
                sentShowError('');
                renderSentUserList(q, true);
            });
            searchEl.addEventListener('keydown', function (e) {
                var list = document.getElementById('pcwSentUserList');
                var options = list ? Array.prototype.slice.call(list.querySelectorAll('.pcw-sent-combo-option')) : [];
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (list && list.hidden) renderSentUserList(searchEl.value || '', true);
                    if (!options.length) return;
                    sentUserActiveIdx = Math.min(options.length - 1, Math.max(0, sentUserActiveIdx + 1));
                    highlightSentUserOption();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!options.length) return;
                    sentUserActiveIdx = Math.max(0, sentUserActiveIdx - 1);
                    highlightSentUserOption();
                } else if (e.key === 'Enter') {
                    if (list && !list.hidden && options[sentUserActiveIdx]) {
                        e.preventDefault();
                        pickSentUserFromOption(options[sentUserActiveIdx]);
                    }
                } else if (e.key === 'Escape') {
                    if (list && !list.hidden) {
                        e.preventDefault();
                        closeSentUserList();
                    }
                }
            });
        }
        if (listEl) {
            listEl.addEventListener('mousedown', function (e) {
                var opt = e.target && e.target.closest ? e.target.closest('.pcw-sent-combo-option') : null;
                if (!opt) return;
                e.preventDefault();
                pickSentUserFromOption(opt);
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                clearSentUserSelection(false);
                closeSentUserList();
                if (searchEl) searchEl.focus();
            });
        }
        document.addEventListener('click', function (e) {
            var combo = document.getElementById('pcwSentUserCombo');
            if (!combo || combo.contains(e.target)) return;
            closeSentUserList();
        });
        if (submitBtn) submitBtn.addEventListener('click', function () { void submitSentMail(); });
        if (remarksEl) {
            remarksEl.addEventListener('input', function () {
                remarksEl.classList.remove('is-invalid');
                sentShowError('');
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var shell = document.getElementById('pcwSentModal');
            if (shell && !shell.hidden) closeSentModal();
        });
        window.pocOpenSentModal = openSentModal;
    })();

    if (approvedSearch) {
        var t = null;
        approvedSearch.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(renderApproved, 120);
        });
    }
    if (approvedRefresh) {
        approvedRefresh.addEventListener('click', function () { loadApproved(true); });
    }
    if (mailboxSearch) {
        var mt = null;
        mailboxSearch.addEventListener('input', function () {
            clearTimeout(mt);
            mt = setTimeout(function () { renderBoxList(mailboxTab); }, 120);
        });
    }

    // Preload counts for badges silently
    function preloadBadges() {
        if (sentBoxUrl) {
            fetch(sentBoxUrl + '?box=received&limit=80', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.data && Array.isArray(j.data.rows)) {
                        receivedRows = j.data.rows;
                        boxLoaded.received = true;
                        updateTabBadges();
                    }
                }).catch(function () {});
            fetch(sentBoxUrl + '?box=sent&limit=80', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.data && Array.isArray(j.data.rows)) {
                        sentRows = j.data.rows;
                        boxLoaded.sent = true;
                        updateTabBadges();
                    }
                }).catch(function () {});
        }
        if (passLogUrl) {
            fetch(passLogUrl + '?limit=80&success=1&approved_only=1', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.data && Array.isArray(j.data.rows)) {
                        approvedRows = j.data.rows;
                        approvedLoaded = true;
                        updateTabBadges();
                    }
                }).catch(function () {});
        }
    }

    var startPanel = firstAllowedPanel();
    try {
        var urlPanel = String(new URL(window.location.href).searchParams.get('panel') || '').trim();
        if (urlPanel === 'flow') urlPanel = 'workflow';
        if (urlPanel && canOpenPanel(urlPanel)) {
            startPanel = urlPanel;
        }
    } catch (_) {}
    bindWorkflowPageUi();
    showPanel(startPanel);
    preloadBadges();
    // Ensure full-page loader is never left stuck after soft panel boot
    try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}

})();
</script>
<?php
    require_once base_path('app/services/TableColumnRegistry.php');
    $pcbPortalUser = function_exists('portal_user') ? portal_user() : null;
    $pcbCurrentUser = trim((string) (
        ($pcbPortalUser['username'] ?? null)
        ?? $_SESSION['username']
        ?? $_SESSION['user_name']
        ?? $_SESSION['name']
        ?? $_SESSION['email']
        ?? ''
    ));
    $pcbCurrentUserEmail = trim((string) (
        ($pcbPortalUser['email'] ?? null)
        ?? $_SESSION['email']
        ?? ''
    ));
?>
<?php if (!empty($canAsideDashboard)): ?>
<script type="application/json" id="poConfig"><?= json_encode([
    'mode'               => 'list',
    'po'                 => '',
    'range'              => ['preset' => 'all', 'from' => '', 'to' => '', 'label' => 'All'],
    'rangeError'         => null,
    'plant'              => '',
    'supplier'           => '',
    'suppliers'          => [],
    'salesOrder'         => '',
    'poType'             => '',
    'matCat'             => 'all',
    'brand'              => '',
    'brands'             => is_array($brands ?? null) ? $brands : [],
    'approvedStatus'     => 'NotApproved',
    'presetDates'        => [],
    'dataUrl'            => url('purchase-order/data'),
    'validateUrl'        => url('purchase-order/validate'),
    'chargesUrl'         => url('purchase-order/charges'),
    'manualMapUrl'       => url('purchase-order/manual-map'),
    'pdfUrl'             => url('purchase-order/pdf'),
    'passPoUrl'          => url('purchase-order/pass'),
    'esignSaveUrl'       => url('purchase-order/esign-save'),
    'esignSignatureUrl'  => url('purchase-order/esign-signature'),
    'releaseStatusUrl'   => url('po-costing/related-po/status'),
    'passLogUrl'         => url('purchase-order/pass-log'),
    'sendMailUrl'        => url('purchase-order/send-mail'),
    'workflowUrl'        => url('purchase-order/workflow'),
    'workflowSaveUrl'    => url('purchase-order/workflow/save'),
    'workflowNotifyUrl'  => url('purchase-order/workflow/notify'),
    'workflowDecideUrl'  => url('purchase-order/workflow/decide'),
    'workflowAckUrl'     => url('purchase-order/workflow/ack'),
    'zcostUrl'           => url('zcost'),
    'zcostAppUrl'        => url('zcost'),
    'usersUrl'           => url('purchase-order/users'),
    'validationLogUrl'   => url('purchase-order/validation-log'),
    'pageUrl'            => url('purchase-order'),
    'view'               => 'costing',
    'isCostingView'      => true,
    'isCostingWorkbench' => false,
    'isCostingBulk'      => true,
    'columns'            => [],
    'currentUser'        => $pcbCurrentUser,
    'currentUserEmail'   => $pcbCurrentUserEmail,
    'canPassPo'          => !empty($canPassPo),
    'canAssign'          => !empty($canAssign),
    'canValidate'        => !empty($canValidate),
    'canEsign'           => !empty($canEsign),
    'canWorkbench'       => !empty($canWorkbench),
    'canSearchPo'        => !empty($canSearchPoPage),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php
    $bulkTableKey = 'purchase_order_costing_bulk_table';
    $bulkTableDef = TableColumnRegistry::get($bulkTableKey);
    $itemTableKey = 'purchase_order_costing_items_table';
    $itemTableDef = TableColumnRegistry::get($itemTableKey);
?>
<script type="application/json" id="pcbTablePrefs"><?= json_encode([
    'tableKey'      => $bulkTableKey,
    'apiUrl'        => url('api/user/table-preferences/' . $bulkTableKey),
    'userId'        => (string) ($_SESSION['user_id'] ?? ''),
    'columns'       => $bulkTableDef['columns'] ?? [],
    'defaultHidden' => $bulkTableDef['defaultHidden'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="pcbItemTablePrefs"><?= json_encode([
    'tableKey'      => $itemTableKey,
    'apiUrl'        => url('api/user/table-preferences/' . $itemTableKey),
    'userId'        => (string) ($_SESSION['user_id'] ?? ''),
    'columns'       => $itemTableDef['columns'] ?? [],
    'defaultHidden' => $itemTableDef['defaultHidden'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/table-columns.js')) ?>?v=<?= @filemtime(base_path('public/js/table-columns.js')) ?: time() ?>"></script>
<script src="<?= e(asset('js/excel-export.js')) ?>?v=<?= @filemtime(base_path('public/js/excel-export.js')) ?: time() ?>"></script>
<?php if (!empty($canAsideDashboard)): ?>
<script src="<?= e(asset('js/po-costing-bulk.js')) ?>?v=<?= @filemtime(base_path('public/js/po-costing-bulk.js')) ?: time() ?>"></script>
<?php endif; ?>
<?php if (!empty($canAsideRelated)): ?>
<?php
    $relatedTableKey = 'purchase_order_related_po_table';
    $relatedTableDef = TableColumnRegistry::get($relatedTableKey) ?? ['columns' => [], 'defaultHidden' => []];
?>
<script type="application/json" id="pocRelatedTablePrefs"><?= json_encode([
    'tableKey'      => $relatedTableKey,
    'apiUrl'        => url('api/user/table-preferences/' . $relatedTableKey),
    'userId'        => (string) ($_SESSION['user_id'] ?? ''),
    'columns'       => $relatedTableDef['columns'] ?? [],
    'defaultHidden' => $relatedTableDef['defaultHidden'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/related-po.js')) ?>?v=<?= @filemtime(base_path('public/js/related-po.js')) ?: time() ?>"></script>
<?php endif; ?>
<?php if (!empty($canAsideSearchPo)): ?>
<script src="<?= e(asset('js/search-po.js')) ?>?v=<?= @filemtime(base_path('public/js/search-po.js')) ?: time() ?>"></script>
<?php endif; ?>
<?php if (!empty($canAsideValuation)): ?>
<script src="<?= e(asset('js/po-valuation-report.js')) ?>?v=<?= @filemtime(base_path('public/js/po-valuation-report.js')) ?: time() ?>"></script>
<?php endif; ?>
<script>
(function () {
    try {
        var panel = new URL(window.location.href).searchParams.get('panel') || '';
        if (panel === 'related' && window.pocRelatedPo && typeof window.pocRelatedPo.open === 'function') {
            window.pocRelatedPo.open();
        } else if (window.pocRelatedPo && typeof window.pocRelatedPo.close === 'function') {
            window.pocRelatedPo.close();
        }
        if (panel === 'search' && window.pocSearchPo && typeof window.pocSearchPo.open === 'function') {
            window.pocSearchPo.open();
        } else if (window.pocSearchPo && typeof window.pocSearchPo.close === 'function' && panel !== 'search') {
            window.pocSearchPo.close();
        }
        if (panel === 'valuation' && window.pocValuationReport && typeof window.pocValuationReport.open === 'function') {
            window.pocValuationReport.open();
        } else if (window.pocValuationReport && typeof window.pocValuationReport.close === 'function' && panel !== 'valuation') {
            window.pocValuationReport.close();
        }
    } catch (_) {}
})();
</script>
