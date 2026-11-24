<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Fabric Utilization report — page-specific controller
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class FabricReportController extends ReportsController
{
    public function page(): void
    {
        $this->show('fabric');
    }

    public function reportData(): void
    {
        parent::data('fabric');
    }
}
?>
