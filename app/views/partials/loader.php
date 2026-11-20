<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : App-wide colorful evolv loading spinner (logo + rings + message)
 * Optional: $loaderVisible, $loaderMessage, $loaderSteps, $pageTitle
 */
$loaderVisible = (bool) ($loaderVisible ?? true);
$loaderAutoHide = (bool) ($loaderAutoHide ?? false);
$loaderSteps = is_array($loaderSteps ?? null) ? array_values(array_filter(array_map('strval', $loaderSteps))) : [];
$loaderMessage = trim((string) ($loaderMessage ?? ''));
if ($loaderMessage === '' && $loaderSteps !== []) {
    $loaderMessage = (string) $loaderSteps[0];
}
if ($loaderMessage === '') {
    $title = trim((string) ($pageTitle ?? ''));
    $loaderMessage = $title !== '' && strcasecmp($title, 'SAP Insight') !== 0 && strcasecmp($title, 'SAP Reports') !== 0
        ? ('Preparing ' . $title . '…')
        : 'Loading…';
}
$loaderLogo = function_exists('sap_reports_logo_url')
    ? sap_reports_logo_url()
    : (function_exists('asset') ? asset('css/zcost/logo.png') : '/sap_reports/public/css/zcost/logo.png');
$loaderStepsJson = htmlspecialchars(json_encode($loaderSteps, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<div class="rpt-spinner is-boot is-colorful<?= $loaderVisible ? ' is-solid' : '' ?><?= !empty($loaderContentScoped) ? ' is-content-scoped' : '' ?>" id="rptSpinner"<?= $loaderVisible ? '' : ' hidden' ?><?= $loaderAutoHide ? ' data-autohide="1"' : '' ?><?= !empty($loaderContentScoped) ? ' data-scope="content"' : '' ?> role="status" aria-live="polite" aria-label="<?= e($loaderMessage) ?>">
    <div class="rpt-spinner-glow" aria-hidden="true">
        <span class="rpt-spinner-orb orb-a"></span>
        <span class="rpt-spinner-orb orb-b"></span>
        <span class="rpt-spinner-orb orb-c"></span>
    </div>
    <div class="rpt-boot-card rpt-spinner-card">
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
                <img src="<?= e($loaderLogo) ?>" alt="evolv" id="rptLoaderLogo">
            </span>
        </div>
        <p id="rptLoaderMsg"><?= e($loaderMessage) ?></p>
        <span class="rpt-loader-pct" id="rptLoaderPct">0%</span>
        <span class="rpt-boot-bar rpt-loader-bar" id="rptLoaderBarTrack"><i id="rptLoaderBar"></i></span>
    </div>
    <span id="rptLoaderStep" hidden data-steps="<?= $loaderStepsJson ?>"></span>
    <span id="rptLoaderCount" hidden></span>
    <span id="rptLoaderTime" hidden></span>
</div>
