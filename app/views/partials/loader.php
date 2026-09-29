<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Animated SAP loading overlay (steps are cycled by public/js/loader.js while it is visible)
 * Expects: $loaderIcon (material icon name), $loaderSteps (list of status messages)
 */
$loaderIcon = (string) ($loaderIcon ?? 'cloud_sync');
$loaderSteps = array_values(array_filter(array_map('strval', (array) ($loaderSteps ?? []))));
if ($loaderSteps === []) {
    $loaderSteps = ['Connecting to SAP…', 'Fetching live data…', 'Preparing report…'];
}
?>
<div class="rpt-spinner" id="rptSpinner" hidden role="status" aria-live="polite">
    <div class="rpt-spinner-card">
        <div class="rpt-loader" aria-hidden="true">
            <span class="rpt-loader-ring"></span>
            <span class="rpt-loader-ring is-inner"></span>
            <span class="rpt-loader-dot"></span>
            <span class="material-icons-round rpt-loader-icon"><?= e($loaderIcon) ?></span>
        </div>
        <p class="rpt-loader-title">Loading from SAP</p>
        <p class="rpt-loader-step" id="rptLoaderStep" data-steps="<?= e(json_encode($loaderSteps, JSON_UNESCAPED_UNICODE)) ?>"><?= e($loaderSteps[0]) ?></p>
        <span class="rpt-loader-bar"><i></i></span>
        <div class="rpt-loader-meta">
            <span class="rpt-loader-dots"><i></i><i></i><i></i></span>
            <span id="rptLoaderTime">0s</span>
        </div>
    </div>
</div>
