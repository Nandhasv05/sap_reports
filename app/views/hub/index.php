<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports catalog — Executive card grid (Utilization, Production, PO, Sales, …)
*/
$user = function_exists('portal_user') ? portal_user() : null;
$userName = !empty($user['username']) ? $user['username'] : 'admin';
$userRole = !empty($user['role']) ? $user['role'] : (!empty($user['department']) ? $user['department'] : 'Administrator');
$userEmail = $user['email'] ?? '';
$initial = strtoupper(substr($userName, 0, 1) ?: 'A');

$homeUrl = sap_reports_evol_url('portal_dashboard.php');
$logoutUrl = url('logout.php');
$logoUrl = sap_reports_logo_url();
$currentYear = date('Y');
$uacAllowedAdmins = ['admin', 'saravan', 'saravanan', 'nandhakumar'];
$canAccessUac = in_array(strtolower(trim((string) $userName)), $uacAllowedAdmins, true);

$canUtil = sap_reports_user_can_access('utilization', $user);
$canFabric = sap_reports_user_can_access('fabric', $user);
$canTrims = sap_reports_user_can_access('trims', $user);
$canProd = sap_reports_user_can_access('production', $user);
$canProc = sap_reports_user_can_access('procurement', $user);
$canPo = sap_reports_user_can_access('purchase-order', $user);
$canSales = sap_reports_user_can_access('sales', $user);
$canPoCosting = sap_reports_user_can_access('po-costing', $user);
$canFabricSale = sap_reports_user_can_access('fabric-sale', $user);
$canAudit = sap_reports_user_can_access('audit-log', $user);
$canUac = $canAccessUac;
$canZcost = sap_reports_user_can_access('zcost', $user);

$totalVisibleCards = ($canUtil ? 1 : 0) + ($canFabric ? 1 : 0) + ($canTrims ? 1 : 0) + ($canProd ? 1 : 0) + ($canProc ? 1 : 0) + ($canPo ? 1 : 0) + ($canSales ? 1 : 0) + ($canPoCosting ? 1 : 0) + ($canFabricSale ? 1 : 0) + ($canAudit ? 1 : 0) + ($canUac ? 1 : 0) + ($canZcost ? 1 : 0);
?>

<div class="hub-wrapper" id="hubWrapper">
    <!-- Ambient mesh background with animated floating glow orbs -->
    <div class="hub-ambient-bg" aria-hidden="true">
        <span class="hub-glow-orb orb-1"></span>
        <span class="hub-glow-orb orb-2"></span>
        <span class="hub-glow-orb orb-3"></span>
    </div>

    <!-- Executive Top Navbar -->
    <header class="hub-header">
        <div class="hub-header-container">
            <!-- Left: Brand / Logo + Quick Nav Icons + Live SAP Indicator with Heartbeat -->
            <div class="hub-header-left">
                <a href="<?= e($homeUrl) ?>" class="hub-brand" title="EVOLV Portal Dashboard">
                    <img src="<?= e($logoUrl) ?>" alt="EVOLV Logo" class="hub-brand-logo">
                    <div class="hub-brand-text">
                        <span class="hub-brand-title">SAP Insight</span>
                        <span class="hub-brand-subtitle">Analytics Hub</span>
                    </div>
                </a>

                <!-- Quick Navigation Shortcut Buttons (matches report view header) -->
                <div class="hub-quick-nav">
                    <a href="<?= e(url('/')) ?>" class="hub-quick-icon-btn is-active" title="SAP Insight Catalog" aria-label="SAP Insight Catalog">
                        <span class="material-icons-round">apps</span>
                    </a>
                    <a href="<?= e($homeUrl) ?>" class="hub-quick-icon-btn" title="Portal Dashboard" aria-label="Portal Dashboard">
                        <span class="material-icons-round">home</span>
                    </a>
                </div>

                <!-- Live SAP Connection Pill with Authentic Heartbeat & Radar Pulse -->
                <div class="hub-status-pill" title="SAP RFC &amp; OData Services Connected - Heartbeat Pulse Active">
                    <span class="hub-heartbeat-box" aria-hidden="true">
                        <span class="hub-heartbeat-core"></span>
                        <span class="hub-heartbeat-radar"></span>
                    </span>
                    <span class="hub-status-label">SAP Live</span>
                    <i class="fas fa-heart-pulse hub-ecg-icon" title="Heartbeat active"></i>
                </div>
            </div>

            <!-- Center: Instant Module Search -->
            <div class="hub-header-center">
                <div class="hub-search-box">
                    <i class="fas fa-search hub-search-icon"></i>
                    <input type="text" id="reportSearchInput" class="hub-search-input" placeholder="Search modules (Fabric, PO, Procurement, Production)..." autocomplete="off" spellcheck="false" aria-label="Search report modules">
                    <kbd class="hub-search-kbd" title="Press / to focus search">/</kbd>
                    <button type="button" id="clearSearchBtn" class="hub-search-clear" style="display: none;" title="Clear search" aria-label="Clear search">&times;</button>
                </div>
            </div>

            <!-- Right: Executive User Profile Dropdown (Initial: Profile only; Click: Dropdown with Back to Card, Back to Portal, Logout) -->
            <?php require base_path('app/views/partials/header_nav_right.php'); ?>
        </div>
    </header>

    <!-- Compact colorful catalog — fixed module order -->
    <main class="hub-main-container">
        <div class="hub-cards-grid" id="reportCardsGrid">
            <?php if ($canUtil): ?>
            <!-- 1. Utilization -->
            <div class="hub-bento-card is-util animate-card" style="--stagger: 1;" id="openUtilModalBtn" role="button" tabindex="0" aria-haspopup="dialog" data-category="utilization" data-keywords="utilization all materials fabric trims bom po grn issue combined">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-layer-group"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 01 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">ALL MATERIALS</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Utilization Report</h2>
                    <p class="hub-bento-desc">Combined SAP material analytics for BOM, PO, GRN &amp; issue across Fabric and Trims.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-cubes"></i> Combined BOM</span>
                        <span class="hub-bento-chip"><i class="fas fa-arrow-right-arrow-left"></i> PO vs GRN</span>
                        <span class="hub-bento-chip"><i class="fas fa-chart-line"></i> Issue Tracking</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Select Material Category</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($canFabric): ?>
            <!-- 2. Fabric -->
            <a class="hub-bento-card is-fabric animate-card" style="--stagger: 2;" href="<?= e(url('fabric') . '?mode=unit') ?>" data-category="utilization" data-keywords="fabric utilization unit material sales order bom cutting roll issue live">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-scroll"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 02 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">FABRIC UNIT</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Fabric Utilization</h2>
                    <p class="hub-bento-desc">Dedicated SAP fabric report by sales order with live BOM, PO, GRN &amp; issue data.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-receipt"></i> Sales Order BOM</span>
                        <span class="hub-bento-chip"><i class="fas fa-scroll"></i> Fabric Rolls</span>
                        <span class="hub-bento-chip"><i class="fas fa-boxes-stacked"></i> Unit Issue</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Fabric Unit</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canTrims): ?>
            <!-- 3. Trims -->
            <a class="hub-bento-card is-trims animate-card" style="--stagger: 3;" href="<?= e(url('trims') . '?mode=unit') ?>" data-category="utilization" data-keywords="trims utilization unit buttons zippers thread labels consumables accessories">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-tags"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 03 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">TRIMS UNIT</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Trims Utilization</h2>
                    <p class="hub-bento-desc">Trims breakdown for Buttons, Zippers, Thread, Labels &amp; Consumables from live SAP.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-circle-dot"></i> Buttons &amp; Zippers</span>
                        <span class="hub-bento-chip"><i class="fas fa-tag"></i> Thread &amp; Labels</span>
                        <span class="hub-bento-chip"><i class="fas fa-box-open"></i> Consumables</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Trims Unit</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canProd): ?>
            <!-- 4. Production -->
            <a class="hub-bento-card is-production animate-card" style="--stagger: 4;" href="<?= e(url('production')) ?>" data-category="operations" data-keywords="production cut sew wash finish packing shipment stage tracking orders operations">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-industry"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 04 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">PRODUCTION</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Production Report</h2>
                    <p class="hub-bento-desc">Live production by sales order: cutting, sewing, washing, finishing &amp; shipment.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-scissors"></i> Cutting &amp; Sewing</span>
                        <span class="hub-bento-chip"><i class="fas fa-soap"></i> Wash &amp; Finish</span>
                        <span class="hub-bento-chip"><i class="fas fa-truck-fast"></i> Shipment</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Production</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canProc): ?>
            <!-- 5. Procurement -->
            <a class="hub-bento-card is-procurement animate-card" style="--stagger: 5;" href="<?= e(url('procurement')) ?>" data-category="operations" data-keywords="procurement pr purchase requisition po balance stock component requirement allocation">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-cart-shopping"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 05 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">PROCUREMENT</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Procurement Report</h2>
                    <p class="hub-bento-desc">Component requirement, PR, PO &amp; stock by sales order with PR line allocation.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-list-check"></i> PR Drilldown</span>
                        <span class="hub-bento-chip"><i class="fas fa-warehouse"></i> Stock vs Req</span>
                        <span class="hub-bento-chip"><i class="fas fa-scale-balanced"></i> Balance</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Procurement</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canPo): ?>
            <!-- 6. Purchase -->
            <a class="hub-bento-card is-po animate-card" style="--stagger: 6;" href="<?= e(url('purchase-order')) ?>" data-category="orders" data-keywords="purchase order po vendor supplier item pricing net price grn pending currency orders">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 06 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">PURCHASE ORDER</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Purchase Order Report</h2>
                    <p class="hub-bento-desc">Purchase order details: supplier, items, quantities, pricing and linked sales orders.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-building"></i> Vendor Intel</span>
                        <span class="hub-bento-chip"><i class="fas fa-clock-rotate-left"></i> Order vs Pending</span>
                        <span class="hub-bento-chip"><i class="fas fa-barcode"></i> Items</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Purchase Orders</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canSales): ?>
            <!-- 7. Sale Dashboard -->
            <a class="hub-bento-card is-sales animate-card" style="--stagger: 7;" href="<?= e(url('sales')) ?>" data-category="sales" data-keywords="sales dashboard quotations orders sap revenue net value order qty buyers customers pipeline">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-chart-line"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 07 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">SALES &amp; QUOTATIONS</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Sales Dashboard</h2>
                    <p class="hub-bento-desc">Sales quotations &amp; orders: value, order qty, buyer intel, conversion &amp; procurement.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-file-invoice-dollar"></i> Quotations</span>
                        <span class="hub-bento-chip"><i class="fas fa-chart-pie"></i> KPI Analytics</span>
                        <span class="hub-bento-chip"><i class="fas fa-boxes-packing"></i> Drilldown</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Sales Dashboard</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canPoCosting): ?>
            <!-- 8. PO Costing Validation — full-page lookup -->
            <a class="hub-bento-card is-po-costing animate-card" style="--stagger: 8;" href="<?= e(url('po-costing')) ?>" data-category="orders" data-keywords="bulk po validation costing purchase order cost budget actual supplier plant net value orders workbench">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-calculator"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 08 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">PO COSTING</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">PO Validation</h2>
                    <p class="hub-bento-desc">Validate POs vs zCOST, assign next users, and open the PO vs zCOST workbench.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-list-check"></i> Bulk List</span>
                        <span class="hub-bento-chip"><i class="fas fa-scale-balanced"></i> PO vs zCOST</span>
                        <span class="hub-bento-chip"><i class="fas fa-user-check"></i> Assign &amp; Validate</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch PO Costing</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>

            <!-- 8b. PO Wise Costing Validation — list → detail aside -->
            <a class="hub-bento-card is-po-costing animate-card" style="--stagger: 8;" href="<?= e(url('posalecosting')) ?>" data-category="orders" data-keywords="po wise costing validation item aside bom sales order pass po posalecosting">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-clipboard-check"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 08B • LIVE SAP</span></div>
                        <span class="hub-bento-tag">PO WISE</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">PO Wise Costing</h2>
                    <p class="hub-bento-desc">PO list with item aside validation, SO/BOM checks, coverage, and Pass PO.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-table"></i> Draft PO List</span>
                        <span class="hub-bento-chip"><i class="fas fa-list"></i> PO Items Aside</span>
                        <span class="hub-bento-chip"><i class="fas fa-check"></i> Pass PO</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch PO Wise Costing</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canFabricSale): ?>
            <!-- 9. Fabric Sale Order -->
            <a class="hub-bento-card is-fabric-sale animate-card" style="--stagger: 9;" href="<?= e(url('fabric-sale')) ?>" data-category="sales" data-keywords="fabric sale order report sales fabric quotations orders revenue buyers pipeline">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-shirt"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 09 • LIVE SAP</span></div>
                        <span class="hub-bento-tag">FABRIC SALE</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Fabric Sale Order</h2>
                    <p class="hub-bento-desc">Fabric sales quotations &amp; orders with buyer intel, order qty and revenue tracking.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-tags"></i> Fabric Quotes</span>
                        <span class="hub-bento-chip"><i class="fas fa-users"></i> Buyer Intel</span>
                        <span class="hub-bento-chip"><i class="fas fa-chart-column"></i> Revenue</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Fabric Sale Order</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canAudit): ?>
            <!-- 10. Audit Log -->
            <a class="hub-bento-card is-audit-log animate-card" style="--stagger: 10;" href="<?= e(url('audit-log')) ?>" data-category="admin" data-keywords="audit log security activity trace events history api transactions admin">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-clock-rotate-left"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 10 • ACTIVITY LOG</span></div>
                        <span class="hub-bento-tag">AUDIT LOG</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Audit Log</h2>
                    <p class="hub-bento-desc">Full-screen activity logs, user transactions, and SAP API operation trail.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-list-check"></i> Real-time Logs</span>
                        <span class="hub-bento-chip"><i class="fas fa-user-shield"></i> User Auditing</span>
                        <span class="hub-bento-chip"><i class="fas fa-shield"></i> API Trace</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Open Audit Log</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canAccessUac): ?>
            <!-- 11. User Access Control (Visible only to admin, saravan, nandhakumar) -->
            <a class="hub-bento-card is-uac animate-card" style="--stagger: 11;" href="<?= e(url('user-access-control')) ?>" data-category="admin" data-keywords="user access control rbac permissions security roles department admin useracesscontrol">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-user-lock"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 11 • PERMISSIONS</span></div>
                        <span class="hub-bento-tag">ACCESS CONTROL</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">UserAcessControl</h2>
                    <p class="hub-bento-desc">Review and configure granular role-based permissions (Add, View, Edit, Approve) across all 12 SAP report pages.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-users-gear"></i> Role Permissions</span>
                        <span class="hub-bento-chip"><i class="fas fa-toggle-on"></i> Add/View/Edit/Approve</span>
                        <span class="hub-bento-chip"><i class="fas fa-shield"></i> Page Access</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Open User Access Control</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($canZcost): ?>
            <!-- 12. Zcost -->
            <a class="hub-bento-card is-zcost animate-card" style="--stagger: 12;" href="<?= e(url('zcost')) ?>" data-category="costing" data-keywords="zcost costing matrix sheet precosting actual budget variance garment cost z cost">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion"><i class="fas fa-calculator"></i></div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker"><span class="hub-bento-dot"></span><span>MODULE 12 • COST SHEET</span></div>
                        <span class="hub-bento-tag">ZCOST MATRIX</span>
                    </div>
                </div>
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Zcost</h2>
                    <p class="hub-bento-desc">Garment costing sheet, pre-costing matrix, approval flow, and budget vs actual variance.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-table-cells"></i> Cost Matrix</span>
                        <span class="hub-bento-chip"><i class="fas fa-file-invoice-dollar"></i> Pre-Costing</span>
                        <span class="hub-bento-chip"><i class="fas fa-check-double"></i> Approvals</span>
                    </div>
                </div>
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Zcost Module</span>
                    <span class="hub-bento-arrow"><i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($totalVisibleCards === 0): ?>
        <!-- Empty permission state when user has 0 viewable pages -->
        <div class="hub-empty-state is-no-access" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 24px; text-align: center; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(12px); border-radius: 20px; border: 1.5px dashed #cbd5e1; margin: 32px auto; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.04);">
            <div style="width: 68px; height: 68px; border-radius: 50%; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 16px;">
                <i class="fas fa-user-lock"></i>
            </div>
            <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 8px;">No Report Modules Assigned</h3>
            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.5; margin-bottom: 24px; max-width: 440px;">
                You currently do not have view permissions for any SAP report modules. Please contact an authorized administrator (<strong>admin, saravan, nandhakumar</strong>) to assign page permissions in User Access Control.
            </p>
            <a href="<?= e($homeUrl) ?>" class="hub-btn hub-btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #4f46e5; color: #fff; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                <i class="fas fa-home"></i> Back to Portal
            </a>
        </div>
        <?php endif; ?>
        </div>

        <!-- Empty search state -->
        <div id="noReportsFound" class="hub-empty-state" style="display: none;">
            <div class="hub-empty-icon">
                <i class="fas fa-search"></i>
            </div>
            <h3 class="hub-empty-title">No matching report modules</h3>
            <p class="hub-empty-desc">We couldn't find any reports matching "<span id="emptyQueryText"></span>". Try searching for keywords like "Fabric", "PO", or "Procurement".</p>
            <button type="button" class="hub-btn hub-btn-primary" id="resetSearchBtn">Clear Search Filter</button>
        </div>
    </main>

    <!-- Default Executive Footer: Copyright & Version Only -->
    <footer class="hub-footer">
        <div class="hub-footer-container">
            <div class="hub-footer-left">
                <span class="hub-footer-brand">EVOLV</span>
                <span class="hub-footer-copy">© <?= e($currentYear) ?> EVOLV Clothing. All rights reserved.</span>
            </div>
            <div class="hub-footer-center">
                <span class="hub-footer-heartbeat" aria-hidden="true" title="Live">
                    <svg class="hub-heartbeat-svg" viewBox="0 0 80 24" width="80" height="16" focusable="false">
                        <path class="hub-heartbeat-track" d="M1 12 H18 L22 12 L26 4 L32 20 L38 8 L42 12 H79" />
                        <path class="hub-heartbeat-pulse" d="M1 12 H18 L22 12 L26 4 L32 20 L38 8 L42 12 H79" />
                    </svg>
                </span>
                <span class="hub-footer-text">SAP ERP Operational Analytics Hub</span>
            </div>
        </div>
    </footer>
</div>

<!-- Glassmorphic Utilization Modal -->
<div class="hub-modal-backdrop" id="utilModal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="hub-modal-card">
        <div class="hub-modal-header">
            <div>
                <span class="hub-modal-kicker">SELECT REPORT CATEGORY</span>
                <h3 id="modalTitle">Material Utilization Reports</h3>
                <p>Choose material category to pull comprehensive utilization records from SAP.</p>
            </div>
            <button type="button" class="hub-modal-close" id="closeUtilModalBtn" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="hub-modal-body">
            <div class="hub-modal-grid">
                <!-- Fabric Option -->
                <a class="hub-modal-tile is-fabric-tile" href="<?= e(url('fabric') . '?mode=all') ?>">
                    <div class="hub-modal-tile-icon">
                        <i class="fas fa-scroll"></i>
                    </div>
                    <div class="hub-modal-tile-content">
                        <span class="hub-modal-pill pill-fabric">FABRIC MATERIAL</span>
                        <h4>Fabric Utilization</h4>
                        <p>Complete SAP fabric utilization across all sales orders with live BOM, PO, GRN &amp; issue status.</p>
                    </div>
                    <div class="hub-modal-tile-action">
                        <span>Open Fabric Report</span>
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </a>

                <!-- Trims Option -->
                <a class="hub-modal-tile is-trims-tile" href="<?= e(url('trims') . '?mode=all') ?>">
                    <div class="hub-modal-tile-icon">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="hub-modal-tile-content">
                        <span class="hub-modal-pill pill-trims">TRIMS &amp; ACCESSORIES</span>
                        <h4>Trims Utilization</h4>
                        <p>Complete SAP trims analytics across all sales orders for Buttons, Zippers, Thread &amp; Consumables.</p>
                    </div>
                    <div class="hub-modal-tile-action">
                        <span>Open Trims Report</span>
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Page Baseline Setup */
html, body {
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    background: #f8fafc !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    color: #0f172a !important;
    -webkit-font-smoothing: antialiased;
}

/* App Wrapper */
.hub-wrapper {
    min-height: 100vh;
    width: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    background-color: #f8fafc;
}

/* Ambient Radial Mesh Background with Floating Animated Orbs */
.hub-ambient-bg {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
}

.hub-glow-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.45;
    animation: orbFloat 18s ease-in-out infinite alternate;
}

.orb-1 {
    width: 480px;
    height: 480px;
    top: -100px;
    left: -100px;
    background: radial-gradient(circle, rgba(13, 148, 136, 0.22) 0%, transparent 70%);
}

.orb-2 {
    width: 520px;
    height: 520px;
    top: 15%;
    right: -120px;
    background: radial-gradient(circle, rgba(79, 70, 229, 0.18) 0%, transparent 70%);
    animation-delay: -6s;
    animation-duration: 22s;
}

.orb-3 {
    width: 600px;
    height: 600px;
    bottom: -150px;
    left: 25%;
    background: radial-gradient(circle, rgba(2, 132, 199, 0.16) 0%, transparent 70%);
    animation-delay: -12s;
    animation-duration: 25s;
}

@keyframes orbFloat {
    0% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(35px, 25px) scale(1.06); }
    100% { transform: translate(-25px, 35px) scale(0.95); }
}

/* ==========================================================================
   TOP EXECUTIVE NAVBAR
   ========================================================================== */
.hub-header {
    height: 52px;
    min-height: 52px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    position: sticky;
    top: 0;
    z-index: 100;
    flex-shrink: 0;
    box-shadow: 0 1px 0 rgba(15, 23, 42, 0.03);
}

.hub-header-container {
    height: 100%;
    padding: 0 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    max-width: 1680px;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}

/* Keep profile dropdown above sticky header siblings */
.hub-header .hub-header-right,
.hub-header .hub-profile-dropdown {
    position: relative;
    z-index: 110;
}

.hub-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.hub-brand {
    display: flex;
    align-items: center;
    gap: 9px;
    text-decoration: none;
}

.hub-brand-logo {
    height: 28px;
    width: auto;
    max-width: 90px;
    object-fit: contain;
}

.hub-brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
}

.hub-brand-title {
    font-family: Outfit, sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
}

.hub-brand-subtitle {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #0284c7;
}

/* Quick Nav Shortcut Icons (apps & home) */
.hub-quick-nav {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 6px;
    border-left: 1px solid #e2e8f0;
    border-right: 1px solid #e2e8f0;
}

.hub-quick-icon-btn {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #0f766e;
    background: #ecfdf5;
    text-decoration: none;
    transition: all 0.2s ease;
}

.hub-quick-icon-btn span {
    font-size: 17px;
}

.hub-quick-icon-btn:hover {
    background: #d1fae5;
    color: #047857;
    transform: translateY(-1px);
}

.hub-quick-icon-btn.is-active {
    background: #0f766e;
    color: #ffffff;
}

/* ==========================================================================
   HEARTBEAT ANIMATION & RADAR PULSE
   ========================================================================== */
.hub-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.35);
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    color: #065f46;
}

.hub-heartbeat-box {
    position: relative;
    width: 8px;
    height: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.hub-heartbeat-core {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
    animation: hubCorePulse 1.8s ease-in-out infinite;
    position: relative;
    z-index: 2;
}

.hub-heartbeat-radar {
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    border: 1.5px solid #10b981;
    animation: hubRadarPing 2s cubic-bezier(0.2, 0.6, 0.35, 1) infinite;
    pointer-events: none;
}

@keyframes hubCorePulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.25); opacity: 0.85; }
}

@keyframes hubRadarPing {
    0% { transform: scale(0.6); opacity: 0.9; }
    70% { transform: scale(2.3); opacity: 0; }
    100% { transform: scale(2.3); opacity: 0; }
}

.hub-ecg-icon {
    font-size: 10px;
    color: #10b981;
    animation: hubHeartbeatIcon 1.8s ease-in-out infinite;
}

@keyframes hubHeartbeatIcon {
    0%, 100% { transform: scale(1); opacity: 0.85; }
    15% { transform: scale(1.35); opacity: 1; color: #059669; }
    30% { transform: scale(1); opacity: 0.85; }
    45% { transform: scale(1.22); opacity: 1; }
    60% { transform: scale(1); opacity: 0.85; }
}

/* Center Search */
.hub-header-center {
    flex: 1;
    max-width: 440px;
}

.hub-search-box {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
}

.hub-search-input {
    width: 100%;
    height: 34px;
    padding: 0 40px 0 34px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    font-size: 12px;
    font-family: inherit;
    color: #0f172a;
    outline: none;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.hub-search-input:focus {
    background: #ffffff;
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}

.hub-search-input::placeholder {
    color: #94a3b8;
}

.hub-search-icon {
    position: absolute;
    left: 12px;
    color: #94a3b8;
    font-size: 12px;
    pointer-events: none;
}

.hub-search-kbd {
    position: absolute;
    right: 12px;
    padding: 1.5px 5.5px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-family: inherit;
    font-size: 9.5px;
    font-weight: 600;
    color: #64748b;
    pointer-events: none;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
}

.hub-search-clear {
    position: absolute;
    right: 10px;
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 18px;
    cursor: pointer;
    line-height: 1;
    padding: 0;
}

.hub-search-clear:hover {
    color: #475569;
}

/* Right Nav Buttons */
.hub-header-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.hub-divider {
    width: 1px;
    height: 20px;
    background: #e2e8f0;
}

.hub-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    white-space: nowrap;
}

.hub-btn-home {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #334155;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}

.hub-btn-home:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0284c7;
    transform: translateY(-1px);
}

.hub-btn-signout {
    background: rgba(254, 242, 242, 0.9);
    border: 1px solid #fecaca;
    color: #dc2626;
}

.hub-btn-signout:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #b91c1c;
    transform: translateY(-1px);
}

/* ==========================================================================
   USER AVATAR & PROFILE
   ========================================================================== */
.hub-user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 2px 10px 2px 3px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
    transition: all 0.2s ease;
}

.hub-user-badge:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
}

.hub-avatar-wrap {
    position: relative;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.hub-avatar-svg {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    display: block;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
}

.hub-avatar-online-dot {
    position: absolute;
    bottom: -1px;
    right: -1px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    border: 1.5px solid #ffffff;
    box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.4);
}

.hub-user-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}

.hub-user-name {
    font-size: 11px;
    font-weight: 700;
    color: #0f172a;
}

.hub-user-role {
    font-size: 8.5px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.04em;
}

/* ==========================================================================
   CARDS GRID CONTAINER (RESPONSIVE GRID)
   ========================================================================== */
.hub-main-container {
    flex: 1 0 auto;
    padding: 16px 20px 24px;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 10;
    max-width: 1680px;
    width: 100%;
    margin: 0 auto;
    box-sizing: border-box;
}

.hub-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    width: 100%;
    align-items: stretch;
}

/* ==========================================================================
   COLORFUL CATALOG CARDS — accent icons, badges, launch actions
   ========================================================================== */
.hub-bento-card {
    position: relative;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.95);
    padding: 12px 12px 10px;
    display: flex;
    flex-direction: column;
    min-height: 168px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    overflow: hidden;
    transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.28s ease, border-color 0.28s ease;
    box-shadow: 0 4px 14px -6px rgba(15, 23, 42, 0.1), 0 1px 4px rgba(15, 23, 42, 0.04);
}

.hub-bento-card:hover {
    transform: translateY(-4px);
    border-color: var(--card-color);
    box-shadow: 0 18px 40px -12px var(--card-glow-shadow), 0 0 0 1.5px var(--card-color);
}

.hub-bento-ambient-glow {
    position: absolute;
    top: -24px;
    right: -24px;
    width: 150px;
    height: 150px;
    border-radius: 50%;
    background: radial-gradient(circle, var(--card-glow-soft) 0%, transparent 72%);
    opacity: 0.75;
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
}

.hub-bento-card:hover .hub-bento-ambient-glow {
    opacity: 1;
    transform: scale(1.15);
}

.hub-bento-shine {
    position: absolute;
    top: 0;
    left: -120%;
    width: 55%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.55), transparent);
    transform: skewX(-20deg);
    pointer-events: none;
    transition: left 0.7s ease;
}

.hub-bento-card:hover .hub-bento-shine {
    left: 140%;
}

/* Header: colorful icon + module meta */
.hub-bento-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    position: relative;
    z-index: 2;
    margin-bottom: 8px;
    flex-shrink: 0;
}

.hub-bento-icon-medallion {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    background: var(--card-gradient);
    color: #ffffff;
    box-shadow: 0 6px 14px -4px var(--card-glow);
    transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.28s ease;
    flex-shrink: 0;
}

.hub-bento-card:hover .hub-bento-icon-medallion {
    transform: scale(1.07) rotate(2deg);
    box-shadow: 0 10px 22px -4px var(--card-glow);
}

.hub-bento-head-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
    text-align: right;
}

.hub-bento-kicker {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.07em;
    color: #94a3b8;
    text-transform: uppercase;
}

.hub-bento-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--card-color);
    animation: hubDotPulse 2s infinite;
}

@keyframes hubDotPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.45; transform: scale(0.85); }
}

.hub-bento-tag {
    font-size: 8.5px;
    font-weight: 800;
    letter-spacing: 0.05em;
    padding: 2px 8px;
    border-radius: 999px;
    text-transform: uppercase;
    background: var(--card-tag-bg);
    color: var(--card-tag-color);
    border: 1px solid var(--card-tag-border);
    line-height: 1.2;
}

.hub-bento-body {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 2;
    margin: 0 0 8px;
    min-width: 0;
}

.hub-bento-title {
    font-family: Outfit, sans-serif;
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 4px;
    letter-spacing: -0.02em;
    line-height: 1.25;
    transition: color 0.2s ease;
}

.hub-bento-card:hover .hub-bento-title {
    color: var(--card-color);
}

.hub-bento-desc {
    font-size: 10.5px;
    color: #64748b;
    line-height: 1.4;
    margin: 0 0 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.hub-bento-chips {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
    margin-top: auto;
    padding-top: 0;
}

.hub-bento-chip {
    font-size: 9px;
    font-weight: 600;
    color: #475569;
    background: #f8fafc;
    padding: 3px 7px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.hub-bento-chip i {
    font-size: 8px;
    opacity: 0.9;
    margin-right: 0;
    color: var(--card-color);
}

.hub-bento-card:hover .hub-bento-chip {
    background: #ffffff;
    border-color: var(--card-border-soft);
    color: #0f172a;
    box-shadow: 0 2px 5px rgba(15, 23, 42, 0.04);
}

.hub-bento-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 9px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    margin-top: auto;
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    transition: all 0.25s ease;
}

.hub-bento-card:hover .hub-bento-footer {
    background: var(--card-footer-hover-bg);
    border-color: var(--card-border-soft);
}

.hub-bento-action-label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--card-color);
    letter-spacing: -0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.hub-bento-arrow {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: var(--card-color);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
    flex-shrink: 0;
}

.hub-bento-card:hover .hub-bento-arrow {
    background: var(--card-color);
    border-color: var(--card-color);
    color: #ffffff;
    transform: translateX(3px);
    box-shadow: 0 2px 6px var(--card-glow);
}

/* Card Theming Accents */
.is-util {
    --card-color: #0d9488;
    --card-gradient: linear-gradient(135deg, #0d9488, #14b8a6);
    --card-glow: rgba(13, 148, 136, 0.4);
    --card-glow-soft: rgba(13, 148, 136, 0.16);
    --card-glow-shadow: rgba(13, 148, 136, 0.15);
    --card-border-soft: rgba(13, 148, 136, 0.3);
    --card-tag-bg: #ccfbf1;
    --card-tag-color: #0f766e;
    --card-tag-border: #99f6e4;
    --card-footer-hover-bg: rgba(204, 251, 241, 0.45);
}

.is-fabric {
    --card-color: #0284c7;
    --card-gradient: linear-gradient(135deg, #0284c7, #38bdf8);
    --card-glow: rgba(2, 132, 199, 0.4);
    --card-glow-soft: rgba(2, 132, 199, 0.16);
    --card-glow-shadow: rgba(2, 132, 199, 0.15);
    --card-border-soft: rgba(2, 132, 199, 0.3);
    --card-tag-bg: #e0f2fe;
    --card-tag-color: #0369a1;
    --card-tag-border: #bae6fd;
    --card-footer-hover-bg: rgba(224, 242, 254, 0.45);
}

.is-trims {
    --card-color: #d97706;
    --card-gradient: linear-gradient(135deg, #d97706, #fbbf24);
    --card-glow: rgba(217, 119, 6, 0.4);
    --card-glow-soft: rgba(217, 119, 6, 0.16);
    --card-glow-shadow: rgba(217, 119, 6, 0.15);
    --card-border-soft: rgba(217, 119, 6, 0.3);
    --card-tag-bg: #fef3c7;
    --card-tag-color: #b45309;
    --card-tag-border: #fde68a;
    --card-footer-hover-bg: rgba(254, 243, 199, 0.45);
}

.is-production {
    --card-color: #7c3aed;
    --card-gradient: linear-gradient(135deg, #7c3aed, #a855f7);
    --card-glow: rgba(124, 58, 237, 0.4);
    --card-glow-soft: rgba(124, 58, 237, 0.16);
    --card-glow-shadow: rgba(124, 58, 237, 0.15);
    --card-border-soft: rgba(124, 58, 237, 0.3);
    --card-tag-bg: #f3e8ff;
    --card-tag-color: #6b21a8;
    --card-tag-border: #e9d5ff;
    --card-footer-hover-bg: rgba(243, 232, 255, 0.45);
}

.is-procurement {
    --card-color: #e11d48;
    --card-gradient: linear-gradient(135deg, #e11d48, #fb7185);
    --card-glow: rgba(225, 29, 72, 0.4);
    --card-glow-soft: rgba(225, 29, 72, 0.16);
    --card-glow-shadow: rgba(225, 29, 72, 0.15);
    --card-border-soft: rgba(225, 29, 72, 0.3);
    --card-tag-bg: #ffe4e6;
    --card-tag-color: #be123c;
    --card-tag-border: #fecdd3;
    --card-footer-hover-bg: rgba(255, 228, 230, 0.45);
}

.is-po {
    --card-color: #4f46e5;
    --card-gradient: linear-gradient(135deg, #4f46e5, #818cf8);
    --card-glow: rgba(79, 70, 229, 0.4);
    --card-glow-soft: rgba(79, 70, 229, 0.16);
    --card-glow-shadow: rgba(79, 70, 229, 0.15);
    --card-border-soft: rgba(79, 70, 229, 0.3);
    --card-tag-bg: #e0e7ff;
    --card-tag-color: #3730a3;
    --card-tag-border: #c7d2fe;
    --card-footer-hover-bg: rgba(224, 231, 255, 0.45);
}

.is-po-costing {
    --card-color: #7c3aed;
    --card-gradient: linear-gradient(135deg, #7c3aed, #a78bfa);
    --card-glow: rgba(124, 58, 237, 0.45);
    --card-glow-soft: rgba(124, 58, 237, 0.18);
    --card-glow-shadow: rgba(124, 58, 237, 0.18);
    --card-border-soft: rgba(124, 58, 237, 0.35);
    --card-tag-bg: #f3e8ff;
    --card-tag-color: #6b21a8;
    --card-tag-border: #e9d5ff;
    --card-footer-hover-bg: rgba(243, 232, 255, 0.5);
}

.is-fabric-sale {
    --card-color: #0e7490;
    --card-gradient: linear-gradient(135deg, #0e7490, #22d3ee);
    --card-glow: rgba(14, 116, 144, 0.45);
    --card-glow-soft: rgba(14, 116, 144, 0.18);
    --card-glow-shadow: rgba(14, 116, 144, 0.18);
    --card-border-soft: rgba(14, 116, 144, 0.35);
    --card-tag-bg: #cffafe;
    --card-tag-color: #155e75;
    --card-tag-border: #a5f3fc;
    --card-footer-hover-bg: rgba(207, 250, 254, 0.5);
}

.is-sales {
    --card-color: #059669;
    --card-gradient: linear-gradient(135deg, #059669, #10b981);
    --card-glow: rgba(5, 150, 105, 0.4);
    --card-glow-soft: rgba(5, 150, 105, 0.16);
    --card-glow-shadow: rgba(5, 150, 105, 0.15);
    --card-border-soft: rgba(5, 150, 105, 0.3);
    --card-tag-bg: #d1fae5;
    --card-tag-color: #047857;
    --card-tag-border: #a7f3d0;
    --card-footer-hover-bg: rgba(209, 250, 229, 0.45);
}

.is-audit-log {
    --card-color: #475569;
    --card-gradient: linear-gradient(135deg, #334155, #64748b);
    --card-glow: rgba(71, 85, 105, 0.45);
    --card-glow-soft: rgba(71, 85, 105, 0.18);
    --card-glow-shadow: rgba(71, 85, 105, 0.18);
    --card-border-soft: rgba(71, 85, 105, 0.35);
    --card-tag-bg: #f1f5f9;
    --card-tag-color: #1e293b;
    --card-tag-border: #cbd5e1;
    --card-footer-hover-bg: rgba(241, 245, 249, 0.6);
}

.is-uac {
    --card-color: #e11d48;
    --card-gradient: linear-gradient(135deg, #e11d48, #f43f5e);
    --card-glow: rgba(225, 29, 72, 0.45);
    --card-glow-soft: rgba(225, 29, 72, 0.18);
    --card-glow-shadow: rgba(225, 29, 72, 0.18);
    --card-border-soft: rgba(225, 29, 72, 0.35);
    --card-tag-bg: #ffe4e6;
    --card-tag-color: #9f1239;
    --card-tag-border: #fecdd3;
    --card-footer-hover-bg: rgba(254, 226, 226, 0.5);
    cursor: pointer;
}

.is-zcost {
    --card-color: #ea580c;
    --card-gradient: linear-gradient(135deg, #ea580c, #f97316);
    --card-glow: rgba(234, 88, 12, 0.45);
    --card-glow-soft: rgba(234, 88, 12, 0.18);
    --card-glow-shadow: rgba(234, 88, 12, 0.18);
    --card-border-soft: rgba(234, 88, 12, 0.35);
    --card-tag-bg: #ffedd5;
    --card-tag-color: #9a3412;
    --card-tag-border: #fed7aa;
    --card-footer-hover-bg: rgba(254, 243, 199, 0.5);
}

/* Staggered entrance animations */
@keyframes cardEntrance {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-card {
    animation: cardEntrance 0.32s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    animation-delay: calc(var(--stagger, 1) * 40ms);
}

/* Empty Search State */
.hub-empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 30px;
    background: #ffffff;
    border-radius: 16px;
    border: 1px dashed #cbd5e1;
}

.hub-empty-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #f1f5f9;
    color: #94a3b8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 10px;
}

.hub-empty-title {
    font-family: Outfit, sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 4px;
}

.hub-empty-desc {
    font-size: 11.5px;
    color: #64748b;
    max-width: 400px;
    line-height: 1.4;
    margin: 0 0 14px;
}

.hub-btn-primary {
    background: #0f172a;
    color: #ffffff;
    padding: 7px 16px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.hub-btn-primary:hover {
    background: #0284c7;
    transform: translateY(-1px);
}

/* ==========================================================================
   DEFAULT EXECUTIVE FOOTER (COPYRIGHT & VERSION ONLY)
   ========================================================================== */
.hub-footer {
    height: 28px;
    min-height: 28px;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-top: 1px solid rgba(226, 232, 240, 0.85);
    position: relative;
    z-index: 20;
    flex-shrink: 0;
}

.hub-footer-container {
    height: 100%;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 10px;
    color: #64748b;
}

.hub-footer-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.hub-footer-brand {
    font-family: Outfit, sans-serif;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #0f172a;
    font-size: 10.5px;
}

.hub-footer-copy {
    color: #64748b;
}

.hub-footer-center {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    color: #94a3b8;
}

.hub-footer-heartbeat {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    line-height: 0;
}

.hub-heartbeat-svg {
    display: block;
    overflow: visible;
}

.hub-heartbeat-track {
    fill: none;
    stroke: rgba(239, 68, 68, 0.18);
    stroke-width: 1.6;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.hub-heartbeat-pulse {
    fill: none;
    stroke: #ef4444;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 56 120;
    stroke-dashoffset: 0;
    filter: drop-shadow(0 0 3px rgba(239, 68, 68, 0.65));
    animation: hub-heartbeat-draw 1.35s ease-in-out infinite;
}

@keyframes hub-heartbeat-draw {
    0% { stroke-dashoffset: 176; opacity: 0.35; }
    18% { opacity: 1; }
    55% { stroke-dashoffset: 0; opacity: 1; }
    100% { stroke-dashoffset: -176; opacity: 0.25; }
}

@media (prefers-reduced-motion: reduce) {
    .hub-heartbeat-pulse {
        animation: hub-heartbeat-pulse-soft 1.8s ease-in-out infinite;
        stroke-dasharray: none;
        stroke-dashoffset: 0;
    }
    @keyframes hub-heartbeat-pulse-soft {
        0%, 100% { opacity: 0.45; }
        50% { opacity: 1; }
    }
}

.hub-footer-right {
    display: flex;
    align-items: center;
    gap: 7px;
}

.hub-footer-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #059669;
    font-weight: 600;
}

.hub-status-heartbeat-mini {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #10b981;
    animation: hubCorePulse 1.8s ease-in-out infinite;
}

.hub-footer-divider {
    color: #cbd5e1;
}

.hub-footer-ver {
    font-weight: 600;
    color: #475569;
}

.hub-footer-ver i {
    font-size: 9px;
    margin-right: 2px;
}

/* ==========================================================================
   UTILIZATION MODAL (scoped — do not override Access Control modal in app.css)
   ========================================================================== */
#utilModal.hub-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 9999;
    animation: hubFadeIn 0.2s ease-out;
}

#utilModal.hub-modal-backdrop.is-open,
#utilModal.hub-modal-backdrop[style*="display: flex"],
#utilModal.hub-modal-backdrop[style*="display:flex"] {
    display: flex !important;
}

@keyframes hubFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

#utilModal .hub-modal-card {
    width: 100%;
    max-width: 600px;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.9);
    border-radius: 16px;
    box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.35);
    overflow: hidden;
    animation: hubSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes hubSlideUp {
    from { opacity: 0; transform: translateY(14px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

#utilModal .hub-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 18px 22px 12px;
    border-bottom: 1px solid #f1f5f9;
}

#utilModal .hub-modal-kicker {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #0284c7;
    text-transform: uppercase;
}

#utilModal .hub-modal-header h3 {
    font-family: Outfit, sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 2px 0;
}

#utilModal .hub-modal-header p {
    font-size: 11.5px;
    color: #64748b;
    margin: 0;
}

#utilModal .hub-modal-close {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #f1f5f9;
    border: none;
    color: #64748b;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

#utilModal .hub-modal-close:hover {
    background: #e2e8f0;
    color: #0f172a;
    transform: rotate(90deg);
}

#utilModal .hub-modal-body {
    padding: 18px 22px 22px;
}

#utilModal .hub-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.hub-modal-tile {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 16px;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    text-decoration: none;
    color: inherit;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
}

.hub-modal-tile:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 24px -6px rgba(15, 23, 42, 0.12);
}

.is-fabric-tile:hover {
    border-color: #0284c7;
}

.is-trims-tile:hover {
    border-color: #d97706;
}

.hub-modal-tile-icon {
    width: 36px;
    height: 36px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    margin-bottom: 8px;
    color: #ffffff;
}

.is-fabric-tile .hub-modal-tile-icon {
    background: linear-gradient(135deg, #0284c7, #38bdf8);
    box-shadow: 0 6px 12px -3px rgba(2, 132, 199, 0.4);
}

.is-trims-tile .hub-modal-tile-icon {
    background: linear-gradient(135deg, #d97706, #fbbf24);
    box-shadow: 0 6px 12px -3px rgba(217, 119, 6, 0.4);
}

.hub-modal-pill {
    font-size: 8.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 2px 6px;
    border-radius: 999px;
    text-transform: uppercase;
    display: inline-block;
    margin-bottom: 3px;
}

.pill-fabric {
    background: #e0f2fe;
    color: #0369a1;
}

.pill-trims {
    background: #fef3c7;
    color: #b45309;
}

.hub-modal-tile-content h4 {
    font-family: Outfit, sans-serif;
    font-size: 14.5px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 2px;
}

.hub-modal-tile-content p {
    font-size: 11px;
    color: #64748b;
    line-height: 1.35;
    margin: 0 0 10px;
}

.hub-modal-tile-action {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11.5px;
    font-weight: 700;
    padding-top: 7px;
    border-top: 1px solid #f1f5f9;
}

.is-fabric-tile .hub-modal-tile-action {
    color: #0284c7;
}

.is-trims-tile .hub-modal-tile-action {
    color: #d97706;
}

/* ==========================================================================
   RESPONSIVE DESIGN (DESKTOP, LAPTOP, TABLET & MOBILE)
   ========================================================================== */
@media (max-width: 1100px) {
    .hub-cards-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }
}

@media (max-width: 820px) {
    .hub-cards-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .hub-main-container {
        padding: 14px 14px 20px;
    }
}

@media (max-width: 768px) {
    .hub-header {
        height: auto;
        min-height: 52px;
        padding: 6px 0;
    }
    .hub-header-container {
        padding: 0 12px;
        gap: 8px;
        flex-wrap: wrap;
    }
    .hub-brand-subtitle {
        display: none !important;
    }
    .hub-status-pill {
        padding: 3px 6px;
    }
    .hub-status-pill .hub-status-label,
    .hub-status-pill .hub-ecg-icon {
        display: none !important;
    }
    .hub-header-center {
        order: 3;
        flex: 1 1 100%;
        max-width: 100%;
        margin-top: 4px;
    }
    .hub-search-box {
        width: 100%;
        height: 38px;
    }
    .hub-search-kbd {
        display: none;
    }
    .hub-header-right .hub-btn span,
    .hub-user-meta {
        display: none !important;
    }
}

@media (max-width: 560px) {
    .hub-cards-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    .hub-modal-grid {
        grid-template-columns: 1fr;
    }
    .hub-main-container {
        padding: 12px 10px 24px;
    }
    .hub-bento-card {
        padding: 16px 14px;
    }
    .hub-bento-title {
        font-size: 18px;
    }
    .hub-footer-center {
        display: none;
    }
}

@media (max-width: 440px) {
    .hub-quick-nav {
        padding: 0 2px;
        gap: 2px;
    }
    .hub-status-pill {
        display: none !important;
    }
}

@media (max-width: 420px) {
    .hub-brand-subtitle,
    .hub-footer-center {
        display: none !important;
    }
    .hub-brand-logo {
        height: 22px !important;
        max-width: 96px;
    }
    .hub-header-container {
        padding: 0 10px;
    }
    .hub-main-container {
        padding: 10px 8px 20px;
    }
    .hub-bento-card {
        padding: 14px 12px;
    }
    .hub-footer-container {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
        padding: 10px 12px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Modal controls
    var modal = document.getElementById('utilModal');
    var openBtn = document.getElementById('openUtilModalBtn');
    var closeBtn = document.getElementById('closeUtilModalBtn');

    function openBackdrop(el) {
        if (!el) return;
        el.style.display = 'flex';
        el.classList.add('is-open');
        document.body.classList.add('hub-modal-open');
    }

    function closeBackdrop(el) {
        if (!el) return;
        el.style.display = 'none';
        el.classList.remove('is-open');
        if (!document.querySelector('.hub-modal-backdrop.is-open')) {
            document.body.classList.remove('hub-modal-open');
        }
    }

    function openModal() { openBackdrop(modal); }
    function closeModal() { closeBackdrop(modal); }

    if (openBtn) {
        openBtn.addEventListener('click', openModal);
        openBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openModal();
            }
        });
    }

    var uacCard = document.getElementById('openUacCardBtn');
    if (uacCard) {
        uacCard.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                uacCard.click();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Keyboard shortcut to focus search: '/' or 'Ctrl+K'
    var searchInput = document.getElementById('reportSearchInput');
    var clearBtn = document.getElementById('clearSearchBtn');
    var cards = Array.from(document.querySelectorAll('#reportCardsGrid .hub-bento-card'));
    var emptyState = document.getElementById('noReportsFound');
    var emptyQueryText = document.getElementById('emptyQueryText');
    var resetSearchBtn = document.getElementById('resetSearchBtn');

    document.addEventListener('keydown', function (e) {
        if ((e.key === '/' || (e.ctrlKey && e.key === 'k')) && document.activeElement !== searchInput) {
            e.preventDefault();
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    function filterReports() {
        var query = (searchInput ? searchInput.value : '').trim().toLowerCase();
        if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

        var visibleCount = 0;

        cards.forEach(function (card) {
            var category = (card.getAttribute('data-category') || '').toLowerCase();
            var keywords = (card.getAttribute('data-keywords') || '').toLowerCase();
            var title = (card.querySelector('.hub-bento-title') ? card.querySelector('.hub-bento-title').textContent : '').toLowerCase();
            var desc = (card.querySelector('.hub-bento-desc') ? card.querySelector('.hub-bento-desc').textContent : '').toLowerCase();
            var tag = (card.querySelector('.hub-bento-tag') ? card.querySelector('.hub-bento-tag').textContent : '').toLowerCase();

            // Query match
            var matchesQuery = !query || title.includes(query) || desc.includes(query) || tag.includes(query) || keywords.includes(query) || category.includes(query);

            card.style.display = matchesQuery ? 'flex' : 'none';
            if (matchesQuery) visibleCount++;
        });

        // Show/hide empty state
        if (emptyState) {
            emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
            if (emptyQueryText) {
                emptyQueryText.textContent = query;
            }
        }
    }

    // Search input handlers
    if (searchInput) {
        searchInput.addEventListener('input', filterReports);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            filterReports();
        });
    }

    if (resetSearchBtn) {
        resetSearchBtn.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            filterReports();
        });
    }
});
</script>
