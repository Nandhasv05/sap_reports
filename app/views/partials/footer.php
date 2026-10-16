<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Default executive footer (Copyright & Version)
 */
$currentYear = date('Y');
?>
<footer class="hub-footer">
    <div class="hub-footer-container">
        <div class="hub-footer-left">
            <span class="hub-footer-brand">EVOLV</span>
            <span class="hub-footer-copy">© <?= e($currentYear) ?> EVOLV Clothing. All rights reserved.</span>
        </div>
        <div class="hub-footer-center">
            <span class="hub-footer-text">SAP ERP Operational Analytics Hub</span>
        </div>
        <div class="hub-footer-right">
            <span class="hub-footer-status">
                <span class="hub-status-heartbeat-mini"></span>
                <span>RFC Online</span>
            </span>
            <span class="hub-footer-divider">•</span>
            <span class="hub-footer-ver"><i class="fas fa-code-branch"></i> v2.4.0</span>
        </div>
    </div>
</footer>
