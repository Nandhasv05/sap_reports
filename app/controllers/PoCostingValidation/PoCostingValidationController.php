<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Costing / Validation page — page routes only.
 *               Shared PO APIs live on PurchaseOrderReportController.
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class PoCostingValidationController extends ReportsController
{
    public function page(): void
    {
        $this->showPoCosting();
    }

    public function passLogPage(): void
    {
        $this->showPoPassLog();
    }

    public function validationHistoryPage(): void
    {
        $this->showPoValidationHistory();
    }
}
?>
