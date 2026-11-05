<?php
/**
 * Redirected: use create_sap_report_tables.php (keeps ONE access table, drops duplicates).
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo "Use: /sap_reports/public/create_sap_report_tables.php\n";
echo "That script keeps only dbo.sap_report_userascesscontrol and drops duplicate access tables.\n";
