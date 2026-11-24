<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Reports hub (home catalog) — page-specific controller
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class HubController extends ReportsController
{
    public function page(): void
    {
        $this->index();
    }
}
?>
