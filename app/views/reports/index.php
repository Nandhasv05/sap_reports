<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports index view - Executive Bento Studio UI (Single-Viewport No-Scroll)
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
                <a href="<?= e(url('/')) ?>" class="hub-brand" title="SAP Reports Analytics Hub">
                    <img src="<?= e($logoUrl) ?>" alt="EVOLV Logo" class="hub-brand-logo">
                    <div class="hub-brand-text">
                        <span class="hub-brand-title">SAP Reports</span>
                        <span class="hub-brand-subtitle">Analytics Hub</span>
                    </div>
                </a>

                <!-- Quick Navigation Shortcut Buttons (matches report view header) -->
                <div class="hub-quick-nav">
                    <a href="<?= e(url('/')) ?>" class="hub-quick-icon-btn is-active" title="SAP Reports Catalog" aria-label="Reports Catalog">
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

    <!-- 6 Executive Bento Cards Grid (3 Columns × 2 Rows, Fitted in Single Viewport, No Subheader) -->
    <main class="hub-main-container">
        <div class="hub-cards-grid" id="reportCardsGrid">
            <!-- Card 1: Utilization Report (All Materials - Modal) -->
            <div class="hub-bento-card is-util animate-card" style="--stagger: 1;" id="openUtilModalBtn" role="button" tabindex="0" aria-haspopup="dialog" data-category="utilization" data-keywords="utilization all materials fabric trims bom po grn issue combined">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header: Medallion Icon + Tag & Status -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 01 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">ALL MATERIALS</span>
                    </div>
                </div>

                <!-- Card Body: Title, Description & Chips -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Utilization Report</h2>
                    <p class="hub-bento-desc">Combined SAP material analytics for BOM, PO, GRN &amp; issue quantities across Fabric and Trims.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-cubes"></i> Combined BOM</span>
                        <span class="hub-bento-chip"><i class="fas fa-arrow-right-arrow-left"></i> PO vs GRN</span>
                        <span class="hub-bento-chip"><i class="fas fa-chart-line"></i> Issue Tracking</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Select Material Category</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </div>

            <!-- Card 2: Fabric Utilization Report (Fabric Unit Only) -->
            <a class="hub-bento-card is-fabric animate-card" style="--stagger: 2;" href="<?= e(url('fabric') . '?mode=unit') ?>" data-category="utilization" data-keywords="fabric utilization unit material sales order bom cutting roll issue live">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-scroll"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 02 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">FABRIC UNIT</span>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Fabric Utilization</h2>
                    <p class="hub-bento-desc">Dedicated SAP fabric report by sales order with live BOM, PO, GRN &amp; issue data for Fabric team.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-receipt"></i> Sales Order BOM</span>
                        <span class="hub-bento-chip"><i class="fas fa-scroll"></i> Fabric Rolls</span>
                        <span class="hub-bento-chip"><i class="fas fa-boxes-stacked"></i> Unit Issue Data</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Fabric Unit</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>

            <!-- Card 3: Trims Utilization Report (Trims Unit Only) -->
            <a class="hub-bento-card is-trims animate-card" style="--stagger: 3;" href="<?= e(url('trims') . '?mode=unit') ?>" data-category="utilization" data-keywords="trims utilization unit buttons zippers thread labels consumables accessories">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 03 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">TRIMS UNIT</span>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Trims Utilization</h2>
                    <p class="hub-bento-desc">Dedicated SAP trims report with categorical breakdown (Buttons, Zippers, Thread, Labels &amp; Consumables).</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-circle-dot"></i> Buttons &amp; Zippers</span>
                        <span class="hub-bento-chip"><i class="fas fa-tag"></i> Thread &amp; Labels</span>
                        <span class="hub-bento-chip"><i class="fas fa-box-open"></i> Consumables</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Trims Unit</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>

            <!-- Card 4: Production Report -->
            <a class="hub-bento-card is-production animate-card" style="--stagger: 4;" href="<?= e(url('production')) ?>" data-category="operations" data-keywords="production cut sew wash finish packing shipment stage tracking orders operations">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-industry"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 04 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">PRODUCTION</span>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Production Report</h2>
                    <p class="hub-bento-desc">Live SAP production status by sales order: cutting, sewing, washing, finishing &amp; shipment quantities.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-scissors"></i> Cutting &amp; Sewing</span>
                        <span class="hub-bento-chip"><i class="fas fa-soap"></i> Wash &amp; Finish</span>
                        <span class="hub-bento-chip"><i class="fas fa-truck-fast"></i> Shipment Stages</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Production</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>

            <!-- Card 5: Procurement Report -->
            <a class="hub-bento-card is-procurement animate-card" style="--stagger: 5;" href="<?= e(url('procurement')) ?>" data-category="operations" data-keywords="procurement pr purchase requisition po balance stock component requirement allocation">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-cart-shopping"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 05 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">PROCUREMENT</span>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Procurement Report</h2>
                    <p class="hub-bento-desc">Live SAP component requirement, PR, PO &amp; stock quantities by sales order, with PR line allocation.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-list-check"></i> PR Drilldown</span>
                        <span class="hub-bento-chip"><i class="fas fa-warehouse"></i> Stock vs Req</span>
                        <span class="hub-bento-chip"><i class="fas fa-scale-balanced"></i> Running Balance</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Procurement</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>

            <!-- Card 6: Purchase Order Report -->
            <a class="hub-bento-card is-po animate-card" style="--stagger: 6;" href="<?= e(url('purchase-order')) ?>" data-category="orders" data-keywords="purchase order po vendor supplier item pricing net price grn pending currency orders">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 06 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">PURCHASE ORDER</span>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Purchase Order Report</h2>
                    <p class="hub-bento-desc">Live SAP purchase order details: supplier, items, quantities, pricing and linked sales orders.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-building"></i> Vendor Intel</span>
                        <span class="hub-bento-chip"><i class="fas fa-clock-rotate-left"></i> Order vs Pending</span>
                        <span class="hub-bento-chip"><i class="fas fa-barcode"></i> Item Breakdown</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Purchase Orders</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>

            <!-- Card 7: Sales Dashboard -->
            <a class="hub-bento-card is-sales animate-card" style="--stagger: 7;" href="<?= e(url('sales')) ?>" data-category="sales" data-keywords="sales dashboard quotations orders sap revenue net value order qty buyers customers pipeline">
                <div class="hub-bento-ambient-glow"></div>
                <div class="hub-bento-shine"></div>
                
                <!-- Card Header -->
                <div class="hub-bento-head">
                    <div class="hub-bento-icon-medallion">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="hub-bento-head-meta">
                        <div class="hub-bento-kicker">
                            <span class="hub-bento-dot"></span>
                            <span>MODULE 07 • LIVE SAP</span>
                        </div>
                        <span class="hub-bento-tag">SALES &amp; QUOTATIONS</span>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="hub-bento-body">
                    <h2 class="hub-bento-title">Sales Dashboard</h2>
                    <p class="hub-bento-desc">Live SAP sales quotations &amp; orders: quotation value, order qty, buyer intel, conversion &amp; procurement status.</p>
                    <div class="hub-bento-chips">
                        <span class="hub-bento-chip"><i class="fas fa-file-invoice-dollar"></i> Quotations</span>
                        <span class="hub-bento-chip"><i class="fas fa-chart-pie"></i> KPI Analytics</span>
                        <span class="hub-bento-chip"><i class="fas fa-boxes-packing"></i> Order Drilldown</span>
                    </div>
                </div>

                <!-- Card Footer Action -->
                <div class="hub-bento-footer">
                    <span class="hub-bento-action-label">Launch Sales Dashboard</span>
                    <span class="hub-bento-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>
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
    height: 50px;
    min-height: 50px;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(226, 232, 240, 0.85);
    position: relative;
    z-index: 20;
    flex-shrink: 0;
}

.hub-header-container {
    height: 100%;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
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
    padding: 24px 28px 36px;
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
    gap: 18px;
    width: 100%;
}

/* ==========================================================================
   EXECUTIVE BENTO STUDIO CARD DESIGN (NEW)
   ========================================================================== */
.hub-bento-card {
    position: relative;
    border-radius: 16px;
    background: #ffffff;
    border: 1.5px solid rgba(226, 232, 240, 0.95);
    padding: 20px 20px 18px;
    display: flex;
    flex-direction: column;
    min-height: 236px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    overflow: hidden;
    transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.28s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.28s ease;
    box-shadow: 0 4px 14px -3px rgba(15, 23, 42, 0.05);
}

/* 3D Elevation & Ambient Color Glow on Hover */
.hub-bento-card:hover {
    transform: translateY(-4.5px);
    border-color: var(--card-color);
    box-shadow: 0 16px 36px -8px var(--card-glow-shadow), 0 0 0 1.5px var(--card-color);
}

/* Ambient Radial Glow in Top Right */
.hub-bento-ambient-glow {
    position: absolute;
    top: -20px;
    right: -20px;
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: radial-gradient(circle, var(--card-glow-soft) 0%, transparent 70%);
    opacity: 0.6;
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
}

.hub-bento-card:hover .hub-bento-ambient-glow {
    opacity: 1;
    transform: scale(1.2);
}

/* Shimmer Light Beam */
.hub-bento-shine {
    position: absolute;
    top: 0;
    left: -120%;
    width: 60%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.65), transparent);
    transform: skewX(-20deg);
    pointer-events: none;
    transition: left 0.75s ease;
}

.hub-bento-card:hover .hub-bento-shine {
    left: 140%;
}

/* Card Header */
.hub-bento-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    position: relative;
    z-index: 2;
    margin-bottom: 14px;
    flex-shrink: 0;
}

/* Medallion Icon Box */
.hub-bento-icon-medallion {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    background: var(--card-gradient);
    color: #ffffff;
    box-shadow: 0 6px 16px -3px var(--card-glow);
    transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.28s ease;
    flex-shrink: 0;
}

.hub-bento-card:hover .hub-bento-icon-medallion {
    transform: scale(1.08) rotate(3deg);
    box-shadow: 0 8px 20px -3px var(--card-glow);
}

.hub-bento-head-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.hub-bento-kicker {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
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
    50% { opacity: 0.4; transform: scale(0.85); }
}

.hub-bento-tag {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 2.5px 8.5px;
    border-radius: 999px;
    text-transform: uppercase;
    background: var(--card-tag-bg);
    color: var(--card-tag-color);
    border: 1px solid var(--card-tag-border);
}

/* Card Body */
.hub-bento-body {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 2;
    margin: 0 0 14px;
}

.hub-bento-title {
    font-family: Outfit, sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px;
    letter-spacing: -0.018em;
    line-height: 1.3;
    transition: color 0.2s ease;
}

.hub-bento-card:hover .hub-bento-title {
    color: var(--card-color);
}

.hub-bento-desc {
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
    margin: 0 0 12px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Bento Chips */
.hub-bento-chips {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
    margin-top: auto;
    padding-top: 2px;
}

.hub-bento-chip {
    font-size: 9.5px;
    font-weight: 600;
    color: #475569;
    background: #f8fafc;
    padding: 2.5px 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.hub-bento-chip i {
    font-size: 8.5px;
    opacity: 0.75;
    margin-right: 3px;
    color: var(--card-color);
}

.hub-bento-card:hover .hub-bento-chip {
    background: #ffffff;
    border-color: var(--card-border-soft);
    color: #0f172a;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.03);
}

/* Bento Footer Action Bar */
.hub-bento-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 12px;
    border-radius: 10px;
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
    font-size: 11.5px;
    font-weight: 700;
    color: var(--card-color);
    letter-spacing: -0.01em;
}

.hub-bento-arrow {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 9.5px;
    color: var(--card-color);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.hub-bento-card:hover .hub-bento-arrow {
    background: var(--card-color);
    border-color: var(--card-color);
    color: #ffffff;
    transform: translateX(4px);
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
    font-weight: 500;
    color: #94a3b8;
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
   UTILIZATION MODAL
   ========================================================================== */
.hub-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 9999;
    animation: hubFadeIn 0.2s ease-out;
}

@keyframes hubFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.hub-modal-card {
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

.hub-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 18px 22px 12px;
    border-bottom: 1px solid #f1f5f9;
}

.hub-modal-kicker {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #0284c7;
    text-transform: uppercase;
}

.hub-modal-header h3 {
    font-family: Outfit, sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 2px 0;
}

.hub-modal-header p {
    font-size: 11.5px;
    color: #64748b;
    margin: 0;
}

.hub-modal-close {
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

.hub-modal-close:hover {
    background: #e2e8f0;
    color: #0f172a;
    transform: rotate(90deg);
}

.hub-modal-body {
    padding: 18px 22px 22px;
}

.hub-modal-grid {
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
@media (max-width: 1400px) {
    .hub-cards-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }
}

@media (max-width: 1040px) {
    .hub-cards-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }
    .hub-main-container {
        padding: 20px 18px 28px;
    }
}

@media (max-width: 680px) {
    .hub-cards-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }
    .hub-modal-grid {
        grid-template-columns: 1fr;
    }
    .hub-main-container {
        padding: 16px 14px 24px;
    }
    .hub-header-container {
        padding: 0 12px;
        gap: 8px;
    }
    .hub-header-center {
        max-width: 180px;
    }
    .hub-search-kbd {
        display: none;
    }
    .hub-header-right .hub-btn span,
    .hub-user-meta {
        display: none;
    }
    .hub-footer-center {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Modal controls
    var modal = document.getElementById('utilModal');
    var openBtn = document.getElementById('openUtilModalBtn');
    var closeBtn = document.getElementById('closeUtilModalBtn');

    function openModal() {
        if (!modal) return;
        modal.style.display = 'flex';
    }

    function closeModal() {
        if (!modal) return;
        modal.style.display = 'none';
    }

    if (openBtn) {
        openBtn.addEventListener('click', openModal);
        openBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openModal();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
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
