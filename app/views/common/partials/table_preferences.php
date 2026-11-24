<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Column definitions + preference API URL for table-columns.js (expects $prefTableKey)
 */
require_once base_path('app/services/TableColumnRegistry.php');

$prefTable = TableColumnRegistry::get($prefTableKey);
if ($prefTable !== null): ?>
<script type="application/json" id="rptTablePrefs"><?= json_encode([
    'tableKey'      => $prefTableKey,
    'apiUrl'        => url('api/user/table-preferences/' . $prefTableKey),
    'userId'        => (string) ($_SESSION['user_id'] ?? ''),
    'columns'       => $prefTable['columns'],
    'defaultHidden' => $prefTable['defaultHidden'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
