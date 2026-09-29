<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Runs the idempotent SQL migrations in database/migrations (CLI only)
 *               Usage: php database/migrate.php [--dry-run]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/core/helpers.php';
require dirname(__DIR__) . '/app/core/Database.php';

$dryRun = in_array('--dry-run', $argv, true);
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDOUT, "No migrations found.\n");
    exit(0);
}

try {
    $pdo = $dryRun ? null : Database::connection();
    foreach ($files as $file) {
        $name = basename($file);
        if ($pdo === null) {
            fwrite(STDOUT, "[dry-run] {$name}\n");
            continue;
        }
        $batches = preg_split('/^\s*GO\s*$/mi', (string) file_get_contents($file)) ?: [];
        foreach ($batches as $sql) {
            if (trim($sql) !== '') {
                $pdo->exec($sql);
            }
        }
        fwrite(STDOUT, "Applied {$name}\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
