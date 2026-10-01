<?php   
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports index view - Compact card non-scrolling layout
 */ 
$dashboardUrl = sap_reports_evol_url('portal_dashboard.php');
?>
<div class="rpt-index-header">
    <a class="back" href="<?= e($dashboardUrl) ?>">
        <i class="fas fa-arrow-left"></i>
        Dashboard
    </a>
    <h1 class="index-title">SAP Reports</h1>
    <p class="lede">Select a report module to view live SAP utilization and order analytics.</p>
</div>

<!-- Compact Non-Scrolling Card Row -->
<div class="rpt-cards-row">
    <!-- Card 1: Utilization Report (All Materials - Modal) -->
    <div class="rpt-card card-all" id="openUtilModalBtn" role="button" tabindex="0" aria-haspopup="dialog">
        <div class="card-accent-line accent-teal"></div>
        <div class="card-top">
            <div class="card-icon icon-teal">
                <i class="fas fa-layer-group"></i>
            </div>
            <span class="card-badge badge-teal">ALL MATERIALS</span>
        </div>
        <h3>Utilization Report</h3>
        <p>Combined SAP material analytics for BOM, PO, GRN &amp; issue quantities across Fabric &amp; Trims.</p>
        <div class="card-action action-teal">
            <span>Select Material</span>
            <i class="fas fa-arrow-right"></i>
        </div>
    </div>

    <!-- Card 2: Fabric Utilization Report (Fabric Unit Only) -->
    <a class="rpt-card card-fabric" href="<?= e(url('fabric') . '?mode=unit') ?>">
        <div class="card-accent-line accent-sky"></div>
        <div class="card-top">
            <div class="card-icon icon-sky">
                <i class="fas fa-scroll"></i>
            </div>
            <span class="card-badge badge-sky">FABRIC UNIT</span>
        </div>
        <h3>Fabric Utilization</h3>
        <p>Dedicated SAP fabric report by sales order with live BOM, PO, GRN &amp; issue data for Fabric team.</p>
        <div class="card-action action-sky">
            <span>Open Fabric Unit</span>
            <i class="fas fa-arrow-right"></i>
        </div>
    </a>

    <!-- Card 3: Trims Utilization Report (Trims Unit Only) -->
    <a class="rpt-card card-trims" href="<?= e(url('trims') . '?mode=unit') ?>">
        <div class="card-accent-line accent-amber"></div>
        <div class="card-top">
            <div class="card-icon icon-amber">
                <i class="fas fa-tags"></i>
            </div>
            <span class="card-badge badge-amber">TRIMS UNIT</span>
        </div>
        <h3>Trims Utilization</h3>
        <p>Dedicated SAP trims report with categorical breakdown (Buttons, Zippers, Thread, Labels &amp; Consumables).</p>
        <div class="card-action action-amber">
            <span>Open Trims Unit</span>
            <i class="fas fa-arrow-right"></i>
        </div>
    </a>

    <!-- Card 4: Production Report -->
    <a class="rpt-card card-production" href="<?= e(url('production')) ?>">
        <div class="card-accent-line accent-violet"></div>
        <div class="card-top">
            <div class="card-icon icon-violet">
                <i class="fas fa-industry"></i>
            </div>
            <span class="card-badge badge-violet">PRODUCTION</span>
        </div>
        <h3>Production Report</h3>
        <p>Live SAP production status by sales order: cutting, sewing, washing, finishing &amp; shipment quantities.</p>
        <div class="card-action action-violet">
            <span>Open Production</span>
            <i class="fas fa-arrow-right"></i>
        </div>
    </a>

    <!-- Card 5: Procurement Report -->
    <a class="rpt-card card-procurement" href="<?= e(url('procurement')) ?>">
        <div class="card-accent-line accent-rose"></div>
        <div class="card-top">
            <div class="card-icon icon-rose">
                <i class="fas fa-cart-shopping"></i>
            </div>
            <span class="card-badge badge-rose">PROCUREMENT</span>
        </div>
        <h3>Procurement Report</h3>
        <p>Live SAP component requirement, PR, PO &amp; stock quantities by sales order, with PR line details.</p>
        <div class="card-action action-rose">
            <span>Open Procurement</span>
            <i class="fas fa-arrow-right"></i>
        </div>
    </a>

    <!-- Card 6: Purchase Order Report -->
    <a class="rpt-card card-po" href="<?= e(url('purchase-order')) ?>">
        <div class="card-accent-line accent-indigo"></div>
        <div class="card-top">
            <div class="card-icon icon-indigo">
                <i class="fas fa-file-invoice"></i>
            </div>
            <span class="card-badge badge-indigo">PURCHASE ORDER</span>
        </div>
        <h3>Purchase Order Report</h3>
        <p>Live SAP purchase order details: supplier, items, quantities, prices and linked sales orders.</p>
        <div class="card-action action-indigo">
            <span>Open Purchase Order</span>
            <i class="fas fa-arrow-right"></i>
        </div>
    </a>
</div>

<!-- Fabric / Trims Modal -->
<div class="modal-backdrop" id="utilModal" style="display: none;" role="dialog" aria-modal="true">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <span class="modal-kicker">SELECT REPORT</span>
                <h3>Utilization Reports</h3>
                <p>Choose material category to pull utilization from SAP.</p>
            </div>
            <button type="button" class="modal-close" id="closeUtilModalBtn" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-grid">
                <!-- Fabric Option -->
                <a class="modal-tile is-fabric" href="<?= e(url('fabric') . '?mode=all') ?>">
                    <div class="modal-tile-icon">
                        <i class="fas fa-scroll"></i>
                    </div>
                    <div class="modal-tile-content">
                        <span class="modal-pill modal-pill-fabric">Fabric</span>
                        <h4>Fabric Reports</h4>
                        <p>SAP fabric utilization by sales order with live BOM, PO, GRN &amp; issue data.</p>
                    </div>
                    <div class="modal-tile-footer">
                        <span>Open report</span>
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </a>

                <!-- Trims Option -->
                <a class="modal-tile is-trims" href="<?= e(url('trims') . '?mode=all') ?>">
                    <div class="modal-tile-icon">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="modal-tile-content">
                        <span class="modal-pill modal-pill-trims">Trims</span>
                        <h4>Trims Reports</h4>
                        <p>SAP trims utilization by sales order with live BOM, PO, GRN &amp; issue data.</p>
                    </div>
                    <div class="modal-tile-footer">
                        <span>Open report</span>
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('utilModal');
    var openBtn = document.getElementById('openUtilModalBtn');
    var closeBtn = document.getElementById('closeUtilModalBtn');

    function openModal() {
        if (!modal) return;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        if (!modal) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
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
});
</script>
