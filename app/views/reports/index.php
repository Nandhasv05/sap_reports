<?php   
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports index view - Utilization card only with Fabric/Trims modal
 */ 
$dashboardUrl = sap_reports_evol_url('portal_dashboard.php');
?>
<a class="back" href="<?= e($dashboardUrl) ?>">
    <i class="fas fa-arrow-left"></i>
    Dashboard
</a>
<h1>SAP Reports</h1>
<p class="lede">Select a report module to view live SAP utilization and order analytics.</p>

<!-- Utilization Report Card (Only Card) -->
<div class="util-only-wrap">
    <div class="tile tile-hero" id="openUtilModalBtn" role="button" tabindex="0" aria-haspopup="dialog">
        <div class="tile-icon tile-hero-icon" style="background: #ccfbf1; color: #0f766e;">
            <i class="fas fa-layer-group"></i>
        </div>
        <div class="tile-body">
            <span class="tile-badge">MATERIAL ANALYTICS</span>
            <h2>Utilization Report</h2>
            <p>Pull BOM, production, PO, GRN, and issue quantities from SAP for Fabric &amp; Trims.</p>
        </div>
        <div class="tile-action">
            <span>Select Material</span>
            <div class="tile-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>
        </div>
    </div>
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
                <a class="modal-tile is-fabric" href="<?= e(url('fabric')) ?>">
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
                <a class="modal-tile is-trims" href="<?= e(url('trims')) ?>">
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
