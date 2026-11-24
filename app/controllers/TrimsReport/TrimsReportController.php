<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Trims Utilization report — page-specific controller
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class TrimsReportController extends ReportsController
{
    public function page(): void
    {
        $this->show('trims');
    }

    public function reportData(): void
    {
        parent::data('trims');
    }
}
?>
