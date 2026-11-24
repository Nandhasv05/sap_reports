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
