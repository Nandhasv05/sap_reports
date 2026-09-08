<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Main layout
 */
$pageTitle = $pageTitle ?? 'SAP Reports';
$dashboardUrl = sap_reports_evol_url('portal_dashboard.php');
$iconHref = sap_reports_logo_url();
$layoutWide = !empty($layoutWide);
$appShell = !empty($appShell);
$bodyClass = $appShell ? 'rpt-body' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EVOLV | <?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e($iconHref) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="<?= e($bodyClass) ?>">
    <?php if ($appShell): ?>
        <?= $content ?>
    <?php else: ?>
        <div class="wrap <?= $layoutWide ? 'wrap-wide' : '' ?>">
            <?= $content ?>
        </div>
    <?php endif; ?>
</body>
</html>
