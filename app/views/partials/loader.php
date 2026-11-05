<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : Minimal colorful loading spinner with project logo + %
 * Optional: $loaderVisible (show on page load)
 */
$loaderVisible = (bool) ($loaderVisible ?? false);
$loaderLogo = function_exists('sap_reports_logo_url')
    ? sap_reports_logo_url()
    : (function_exists('asset') ? asset('css/zcost/logo.png') : '/sap_reports/public/css/zcost/logo.png');
?>
<div class="rpt-spinner is-minimal<?= $loaderVisible ? ' is-solid' : '' ?>" id="rptSpinner"<?= $loaderVisible ? '' : ' hidden' ?> role="status" aria-live="polite" aria-label="Loading">
    <div class="rpt-loader-wrap">
        <div class="rpt-loader" aria-hidden="true">
            <span class="rpt-loader-ring ring-1"></span>
            <span class="rpt-loader-ring ring-2"></span>
            <span class="rpt-loader-ring ring-3"></span>
            <span class="rpt-loader-orbit">
                <i class="dot-1"></i>
                <i class="dot-2"></i>
                <i class="dot-3"></i>
                <i class="dot-4"></i>
            </span>
            <span class="rpt-loader-logo">
                <img src="<?= e($loaderLogo) ?>" alt="EVOLV" id="rptLoaderLogo">
            </span>
        </div>
        <span class="rpt-loader-pct" id="rptLoaderPct">0%</span>
    </div>
    <!-- Kept for API compatibility with older progress callers -->
    <span id="rptLoaderStep" hidden data-steps="[]"></span>
    <span id="rptLoaderBar" hidden></span>
    <span id="rptLoaderCount" hidden></span>
    <span id="rptLoaderTime" hidden></span>
</div>
