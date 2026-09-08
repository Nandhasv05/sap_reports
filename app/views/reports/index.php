<?php   
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports index view
 */ 
/*
 * Get the dashboard URL
 */
$dashboardUrl = sap_reports_evol_url('portal_dashboard.php');
$sales = [];
$material = [];
foreach ($catalog as $key => $item) {
    if (in_array($key, ['fabric', 'trims'], true)) {
        $material[$key] = $item;
    } else {
        $sales[$key] = $item;
    }
}
?>
<a class="back" href="<?= e($dashboardUrl) ?>">
    <i class="fas fa-arrow-left"></i>
    Dashboard
</a>
<h1>SAP Reports</h1>
<p class="lede">PHP reports from SAP sales, fabric, and trims data.</p>

<div class="section-label">Sales</div>
<div class="grid">
    <?php foreach ($sales as $key => $item): ?>
        <a class="tile" href="<?= e(url($key)) ?>">
            <div class="tile-icon" style="background: <?= e($item['bg']) ?>; color: <?= e($item['color']) ?>;">
                <i class="fas <?= e($item['icon']) ?>"></i>
            </div>
            <div>
                <h3><?= e($item['title']) ?></h3>
                <p><?= e($item['blurb']) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<div class="section-label">Fabric &amp; Trims</div>
<div class="mat-switch">
    <?php foreach ($material as $key => $item): ?>
        <a class="mat-tab <?= $key === 'fabric' ? 'is-fabric' : 'is-trims' ?>" href="<?= e(url($key)) ?>">
            <i class="fas <?= e($item['icon']) ?>"></i>
            <?= e($item['title']) ?>
        </a>
    <?php endforeach; ?>
</div>
