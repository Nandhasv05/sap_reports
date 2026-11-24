<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Purchase Order report + shared PO APIs (pass, esign, PDF, workflow)
 *               Shared by Purchase Order page and PO Costing Validation.
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class PurchaseOrderReportController extends ReportsController
{
    public function page(): void
    {
        $this->showPurchaseOrder();
    }

    public function reportData(): void
    {
        $this->purchaseOrderData();
    }
}
?>
