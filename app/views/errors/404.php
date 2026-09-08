<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : 404 error view
 */
$message = $message ?? 'The page you are looking for does not exist or has been moved.';
?>
<a class="back" href="<?= e(url()) ?>">
    <i class="fas fa-arrow-left"></i>
    SAP Reports
</a>
<p class="error-code">404</p>
<h1>Page not found</h1>
<p class="lede"><?= e($message) ?></p>
