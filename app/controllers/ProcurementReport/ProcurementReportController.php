<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Procurement report — page-specific controller
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class ProcurementReportController extends ReportsController
{
    public function page(): void
    {
        $this->show('procurement');
    }

    public function reportData(): void
    {
        parent::data('procurement');
    }
}
?>
