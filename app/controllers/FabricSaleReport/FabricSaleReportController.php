<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Fabric Sale Order report — page-specific controller
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class FabricSaleReportController extends ReportsController
{
    public function page(): void
    {
        $this->showFabricSale();
    }
}
?>
