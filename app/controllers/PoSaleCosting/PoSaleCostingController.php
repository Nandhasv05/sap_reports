<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Sale Costing Validation — standalone list → detail page
 */
declare(strict_types=1);

sap_reports_require_controller('ReportsController');

class PoSaleCostingController extends ReportsController
{
    public function page(): void
    {
        if (function_exists('sap_reports_user_can_access')
            && !sap_reports_user_can_access('posalecosting')
            && !sap_reports_user_can_access('po-costing')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view PO Sale Costing.';
            $this->redirect(url('/'));
            return;
        }

        require_once base_path('app/services/SapPurchaseOrderService.php');
        $canPassPo = function_exists('sap_reports_user_can')
            ? (sap_reports_user_can('posalecosting', 'approve') || sap_reports_user_can('po-costing', 'approve'))
            : true;
        $canValidate = function_exists('sap_reports_user_can')
            ? (sap_reports_user_can('posalecosting', 'validate') || sap_reports_user_can('po-costing', 'validate'))
            : true;
        $canSapRevert = function_exists('sap_reports_user_can')
            ? (sap_reports_user_can('posalecosting', 'sap_revert') || sap_reports_user_can('po-costing', 'sap_revert'))
            : false;
        $canPortalRevert = function_exists('sap_reports_user_can')
            ? (sap_reports_user_can('posalecosting', 'portal_revert') || sap_reports_user_can('po-costing', 'portal_revert'))
            : false;
        $canEsign = function_exists('sap_reports_user_can')
            ? (sap_reports_user_can('posalecosting', 'esign') || sap_reports_user_can('po-costing', 'esign'))
            : true;

        $this->view('poSaleCosting/po_sale_costing', [
            'pageTitle'     => 'PO Wise Costing Validation',
            'appShell'      => true,
            'layoutWide'    => true,
            'brands'        => SapPurchaseOrderService::brands(),
            'home'          => sap_reports_evol_url('portal_dashboard.php'),
            'catalogHome'   => url('/'),
            'logoUrl'       => sap_reports_logo_url(),
            'validateUrl'   => url('purchase-order/validate'),
            'passUrl'       => url('purchase-order/pass'),
            'manualMapUrl'      => url('purchase-order/manual-map'),
            'chargesUrl'        => url('purchase-order/charges'),
            'portalRevertUrl'   => url('purchase-order/portal-revert'),
            'pdfUrl'            => url('purchase-order/pdf'),
            'poCostingUrl'      => url('po-costing'),
            'purchaseOrderUrl'  => url('purchase-order'),
            'canPassPo'         => $canPassPo,
            'canValidate'       => $canValidate,
            'canSapRevert'      => $canSapRevert,
            'canPortalRevert'   => $canPortalRevert,
            'canEsign'          => $canEsign,
            'extraHead'     => '<link rel="stylesheet" href="' . e(asset('css/po-sale-costing.css')) . '">',
            'extraScripts'  => '',
        ]);
    }

    /*
     * GET posalecosting/data — NotApproved / released costing PO list
     */
    public function reportData(): void
    {
        if (function_exists('sap_reports_user_can_access')
            && !sap_reports_user_can_access('posalecosting')
            && !sap_reports_user_can_access('po-costing')) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted.',
                'error'   => 'Access restricted.',
                'data'    => [],
            ], 403);
            return;
        }

        require_once base_path('app/services/PoCostValidationService.php');
        $listFilters = $_GET;
        $listFilters['range'] = 'all';
        $listFilters['from'] = '';
        $listFilters['to'] = '';
        if (!isset($listFilters['approved_status']) || trim((string) $listFilters['approved_status']) === '') {
            $listFilters['approved_status'] = 'NotApproved';
        }
        $listFilters['mat_cat'] = strtolower(trim((string) ($listFilters['mat_cat'] ?? $listFilters['category'] ?? 'all')));
        $listFilters['page'] = max(1, (int) ($listFilters['page'] ?? 1));
        $listFilters['page_size'] = min(100, max(10, (int) ($listFilters['page_size'] ?? $listFilters['per_page'] ?? 25)));

        try {
            $payload = (new PoCostValidationService())->listNotApprovedPurchaseOrders($listFilters);
            if (($payload['error'] ?? null) !== null) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Unable to fetch SAP data: ' . $payload['error'],
                    'error'   => $payload['error'],
                    'data'    => [],
                ], 502);
                return;
            }
            if (is_file(base_path('app/services/PoValidationStatusService.php'))) {
                require_once base_path('app/services/PoValidationStatusService.php');
                $payload = (new PoValidationStatusService())->enrichListPayload($payload);
            }
            $payload['released_only'] = true;
            $this->jsonResponse([
                'success' => true,
                'message' => empty($payload['records'])
                    ? 'No released purchase orders match the selected criteria.'
                    : 'PO Sale Costing list data',
                'data'    => $payload,
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unable to load purchase orders. Please try again.',
                'error'   => $e->getMessage(),
                'data'    => [],
            ], 500);
        }
    }
}
?>
