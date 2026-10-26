<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Sales Controller for the sales dashboard
*/
require_once base_path('app/models/SalesModel.php');
require_once base_path('app/models/DashboardModel.php');
require_once base_path('app/services/SapSalesService.php');

/*
 * Sales controller
 */
class SalesController extends Controller
{
    private SalesModel $model;
    private SapSalesService $sapSalesService;

    /*
     * Constructor
     */
    public function __construct()
    {
        $this->model = new SalesModel();
        $this->sapSalesService = new SapSalesService();
    }

    /*
     * Index method
     */
    public function index(): void
    {
        $dashModel = new DashboardModel();
        $year = $dashModel->year();
        $live = $this->model->salesPage($year);
        $cfg = $live['cfg'];

        if ($cfg === []) {
            $this->notFound('Sales dashboard not found.');
            return;
        }

        $now = new DateTime();
        $dayOfWeek = (int) $now->format('N');
        $thisWeekFrom = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days')->format('Y-m-d');
        $thisWeekTo = (clone $now)->modify('+' . (7 - $dayOfWeek) . ' days')->format('Y-m-d');

        $this->view('sales/index', [
            'pageTitle'        => $cfg['title'],
            'pageSubtitle'     => 'Sample data',
            'activeNav'        => 'sales',
            'primaryColor'     => $cfg['primary'],
            'primaryDark'      => $cfg['primary_dark'],
            'showPeriodFilter' => true,
            'year'      => $year,
            'defaultFrom'      => $thisWeekFrom,
            'defaultTo'        => $thisWeekTo,
            'periodLabel'      => 'This Week',
            'navDashboards'    => $dashModel->kapis(),
            'dataSource'       => $live['data_source'],
            'sapError'         => $live['sap_error'],
            'sapRows'          => $live['sap_rows'],
            'currencySymbol'   => $live['currency_symbol'] ?? '$',
            'appShell'         => true,
            'layoutWide'       => true,
        ]);
    }

    /*
     * Alias for API endpoint: getSales
     */
    public function getSales(): void
    {
        $this->data();
    }

    /*
     * Data method (fetches fresh data directly from SAP on every request)
     */
    public function data(): void
    {
        $dashModel = new DashboardModel();
        $year = $dashModel->year();
        $export = strtolower((string) ($_GET['export'] ?? '')) === 'csv';

        $filters = [
            'from_date' => trim((string) ($_GET['from_date'] ?? $_GET['from'] ?? '')),
            'to_date'   => trim((string) ($_GET['to_date'] ?? $_GET['to'] ?? '')),
            'search'    => trim((string) ($_GET['search'] ?? $_GET['q'] ?? '')),
            'quotation' => trim((string) ($_GET['quotation'] ?? '')),
            'sales_order' => trim((string) ($_GET['sales_order'] ?? $_GET['so'] ?? '')),
            'page'      => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page'  => min(8000, max(1, (int) ($_GET['per_page'] ?? ($export ? 8000 : 25)))),
            'year'      => $year,
        ];

        try {
            $result = $this->sapSalesService->getSalesData($filters);

            if ($export) {
                $this->sendCsv($result['records'] ?? $result['data']['records'] ?? []);
                return;
            }

            if (!empty($result['error']) || (isset($result['success']) && $result['success'] === false)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message'] ?? 'Unable to fetch sales data from SAP',
                    'error'   => $result['error'] ?? 'SAP request failed',
                    'data'    => [],
                    'meta'    => [
                        'source' => 'SAP',
                        'count'  => 0,
                    ],
                ], 200);
                return;
            }

            $this->jsonResponse($result);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unable to fetch sales data from SAP: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => [],
                'meta'    => [
                    'source' => 'SAP',
                    'count'  => 0,
                ],
            ], 500);
        }
    }

    /*
     * Send CSV method
     */
    private function sendCsv(array $records): void
    {
        $filename = 'kapis-order-lines-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, [
            'Quotation', 'Item', 'Material', 'Description', 'Plant',
            'Date', 'Qty', 'Net Value', 'Sales Order', 'Category', 'Customer',
        ]);
        foreach ($records as $row) {
            fputcsv($out, [
                $row['quotation'] ?? '',
                $row['line_item'] ?? '',
                $row['material'] ?? '',
                $row['style'] ?? '',
                $row['plant'] ?? '',
                $row['date'] ?? '',
                $row['qty'] ?? '',
                $row['net_amount'] ?? '',
                $row['sales_order'] ?? '',
                $row['item_category'] ?? '',
                $row['customer'] ?? '',
            ]);
        }
        fclose($out);
    }

    /*
     * Procurement data endpoint (calls SAP ZBUSINESS_API_SRV ProcurementDashboardSet)
     */
    public function procurementData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $salesDoc = trim((string) ($_GET['sales_doc'] ?? ''));
        if ($salesDoc === '' || $salesDoc === '—') {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Sales order is required for procurement.',
                'data'   => [],
            ]);
            return;
        }
        $rawDoc = ltrim($salesDoc, '0');
        if ($rawDoc === '') {
            $rawDoc = $salesDoc;
        }
        $paddedDoc = str_pad($rawDoc, 10, '0', STR_PAD_LEFT);

        require_once base_path('app/core/SapODataClient.php');
        $client = new SapODataClient();

        $cfg = config('sap');
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'http://APP-PROD.evolvclothing.com:8000'), '/');
        $targetUrl = $baseUrl . '/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet?$filter=' . urlencode("SalesDoc eq '{$rawDoc}'") . '&$format=json';

        $sapResponse = $client->fetchUrl($targetUrl, 25);

        if (!empty($sapResponse['body']['d']['results'])) {
            echo json_encode([
                'status'     => 'ok',
                'source'     => 'sap_live',
                'sales_doc'  => $rawDoc,
                'target_url' => $targetUrl,
                'data'       => $sapResponse['body']['d']['results'],
            ]);
            return;
        }

        if ($rawDoc !== $paddedDoc) {
            $targetUrlPadded = $baseUrl . '/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet?$filter=' . urlencode("SalesDoc eq '{$paddedDoc}'") . '&$format=json';
            $sapResponsePadded = $client->fetchUrl($targetUrlPadded, 25);
            if (!empty($sapResponsePadded['body']['d']['results'])) {
                echo json_encode([
                    'status'     => 'ok',
                    'source'     => 'sap_live',
                    'sales_doc'  => $paddedDoc,
                    'target_url' => $targetUrlPadded,
                    'data'       => $sapResponsePadded['body']['d']['results'],
                ]);
                return;
            }
        }

        echo json_encode([
            'status'     => 'error',
            'error'      => $sapResponse['error'] ?? 'No procurement records found from SAP for Sales Doc ' . $salesDoc,
            'sales_doc'  => $salesDoc,
            'target_url' => $targetUrl,
            'data'       => [],
        ]);
    }

    /*
     * Procurement PR set endpoint (calls deferred ProcurementPRSet uri live from SAP)
     */
    public function procurementPrData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $uri = trim((string) ($_GET['uri'] ?? ''));
        $salesDoc = trim((string) ($_GET['sales_doc']));
        $compMat = trim((string) ($_GET['component_material']));

        require_once base_path('app/core/SapODataClient.php');
        $client = new SapODataClient();

        if ($uri === '' && $compMat !== '') {
            $cfg = config('sap');
            $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'http://APP-PROD.evolvclothing.com:8000'), '/');
            $paddedDoc = str_pad(ltrim($salesDoc, '0'), 10, '0', STR_PAD_LEFT);
            $encMat = rawurlencode($compMat);
            $uri = "{$baseUrl}/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet(SalesDoc='{$paddedDoc}',ComponentMaterial='{$encMat}')/ProcurementPRSet?\$format=json";
        }

        $sapResponse = $uri !== '' ? $client->fetchUrl($uri, 25) : ['body' => null, 'error' => 'No URI provided.'];

        if (!empty($sapResponse['body']['d']['results'])) {
            echo json_encode([
                'status' => 'ok',
                'source' => 'sap_live',
                'uri'    => $uri,
                'data'   => $sapResponse['body']['d']['results'],
            ]);
            return;
        }

        if (!empty($sapResponse['body']['d']) && !isset($sapResponse['body']['d']['results'])) {
            echo json_encode([
                'status' => 'ok',
                'source' => 'sap_live',
                'uri'    => $uri,
                'data'   => [$sapResponse['body']['d']],
            ]);
            return;
        }

        echo json_encode([
            'status'    => 'error',
            'error'     => $sapResponse['error'] ?? 'No PR records returned from SAP.',
            'uri'       => $uri,
            'data'      => [],
        ]);
    }

    /**
     * BOM data endpoint (calls SAP ZC_COOISCOMP_HUB_CDS / ZC_COOISComp_Hub)
     */
    public function bomData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $salesOrder = trim((string) ($_GET['sales_order'] ?? ''));
        if ($salesOrder === '' || $salesOrder === '—') {
            echo json_encode([
                'status' => 'error',
                'error'  => 'Sales order is required for BOM.',
                'data'   => [],
            ]);
            return;
        }

        $rawDoc = ltrim($salesOrder, '0');
        $paddedDoc = str_pad($rawDoc, 10, '0', STR_PAD_LEFT);

        require_once base_path('app/core/SapODataClient.php');
        $client = new SapODataClient();

        $cfg = config('sap');
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'http://APP-PROD.evolvclothing.com:8000'), '/');

        // 1. Try with raw order ID (e.g. 4203)
        $targetUrl = $baseUrl . '/sap/opu/odata/sap/ZC_COOISCOMP_HUB_CDS/ZC_COOISComp_Hub?$filter=' . urlencode("SalesOrder eq '{$rawDoc}'") . '&$format=json';
        $sapResponse = $client->fetchUrl($targetUrl, 30);

        if (!empty($sapResponse['body']['d']['results'])) {
            echo json_encode([
                'status'      => 'ok',
                'source'      => 'sap_live',
                'sales_order' => $rawDoc,
                'target_url'  => $targetUrl,
                'count'       => count($sapResponse['body']['d']['results']),
                'data'        => $sapResponse['body']['d']['results'],
            ]);
            return;
        }

        // 2. Try with padded 10-digit doc if rawDoc != paddedDoc
        if ($rawDoc !== $paddedDoc) {
            $targetUrlPadded = $baseUrl . '/sap/opu/odata/sap/ZC_COOISCOMP_HUB_CDS/ZC_COOISComp_Hub?$filter=' . urlencode("SalesOrder eq '{$paddedDoc}'") . '&$format=json';
            $sapResponsePadded = $client->fetchUrl($targetUrlPadded, 30);
            if (!empty($sapResponsePadded['body']['d']['results'])) {
                echo json_encode([
                    'status'      => 'ok',
                    'source'      => 'sap_live',
                    'sales_order' => $paddedDoc,
                    'target_url'  => $targetUrlPadded,
                    'count'       => count($sapResponsePadded['body']['d']['results']),
                    'data'        => $sapResponsePadded['body']['d']['results'],
                ]);
                return;
            }
        }

        echo json_encode([
            'status'      => 'error',
            'error'       => $sapResponse['error'] ?? 'No BOM records found from SAP for Sales Order ' . $salesOrder,
            'sales_order' => $salesOrder,
            'target_url'  => $targetUrl,
            'data'        => [],
        ]);
    }

    /**
     * Material master live data endpoint (calls SAP ZI_MATERIALAPI_HUB_CDS / ZI_MaterialAPI_HUB)
     */
    public function materialApiData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $forceDateFilter = (!empty($_GET['date_filter']) || !empty($_GET['date_range']) || ($_GET['mode'] ?? '') === 'date_range');
        $material = $forceDateFilter ? '' : trim((string) ($_GET['material'] ?? $_GET['product'] ?? ''));

        require_once base_path('app/core/SapODataClient.php');
        $client = new SapODataClient();

        $cfg = config('sap');
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'http://APP-PROD.evolvclothing.com:8000'), '/');

        // If specific material provided and not forcing date range, query by Product
        if ($material !== '') {
            $targetUrl = $baseUrl . '/sap/opu/odata/sap/ZI_MATERIALAPI_HUB_CDS/ZI_MaterialAPI_HUB?$filter=' . urlencode("Product eq '{$material}'") . '&$format=json';
            $sapResponse = $client->fetchUrl($targetUrl, 25);
            if (!empty($sapResponse['body']['d']['results'])) {
                echo json_encode([
                    'status'     => 'ok',
                    'source'     => 'sap_live',
                    'mode'       => 'product',
                    'material'   => $material,
                    'target_url' => $targetUrl,
                    'count'      => count($sapResponse['body']['d']['results']),
                    'data'       => $sapResponse['body']['d']['results'],
                ]);
                return;
            }

            // Fallback: try prefix without trailing size/item digits (e.g. 3H001262 from 3H001262005)
            $prefix = preg_replace('/[0-9]{3}$/', '', $material);
            if ($prefix !== '' && $prefix !== $material) {
                $targetUrl2 = $baseUrl . '/sap/opu/odata/sap/ZI_MATERIALAPI_HUB_CDS/ZI_MaterialAPI_HUB?$filter=' . urlencode("Product eq '{$prefix}'") . '&$format=json';
                $sapResponse2 = $client->fetchUrl($targetUrl2, 25);
                if (!empty($sapResponse2['body']['d']['results'])) {
                    echo json_encode([
                        'status'     => 'ok',
                        'source'     => 'sap_live',
                        'mode'       => 'product_prefix',
                        'material'   => $material,
                        'matched'    => $prefix,
                        'target_url' => $targetUrl2,
                        'count'      => count($sapResponse2['body']['d']['results']),
                        'data'       => $sapResponse2['body']['d']['results'],
                    ]);
                    return;
                }
            }

            // Return clean empty result for requested material instead of falling back to unrelated date range
            echo json_encode([
                'status'     => 'ok',
                'source'     => 'sap_live',
                'mode'       => 'product',
                'material'   => $material,
                'target_url' => $targetUrl,
                'count'      => 0,
                'data'       => [],
                'message'    => "No SAP Material Master records found for Product '{$material}'",
            ]);
            return;
        }

        // Live CreationDate range query when explicitly requested or no material specified
        $dateFilter = "CreationDate ge datetime'2026-09-01T00:00:00' and CreationDate le datetime'2026-09-07T23:59:59'";
        $targetUrl = $baseUrl . '/sap/opu/odata/sap/ZI_MATERIALAPI_HUB_CDS/ZI_MaterialAPI_HUB?$filter=' . urlencode($dateFilter) . '&$top=150&$format=json';

        $sapResponse = $client->fetchUrl($targetUrl, 30);

        if (!empty($sapResponse['body']['d']['results'])) {
            echo json_encode([
                'status'     => 'ok',
                'source'     => 'sap_live',
                'mode'       => 'date_range',
                'filter'     => $dateFilter,
                'target_url' => $targetUrl,
                'count'      => count($sapResponse['body']['d']['results']),
                'data'       => $sapResponse['body']['d']['results'],
            ]);
            return;
        }

        echo json_encode([
            'status'     => 'error',
            'error'      => $sapResponse['error'] ?? 'No material records returned from SAP.',
            'target_url' => $targetUrl,
            'data'       => [],
        ]);
    }

    /**
     * Live ZI_SalesApi_HUB lines for one sales order (dashboard charts + table).
     */
    public function salesApiHubData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $salesOrder = trim((string) ($_GET['sales_order'] ?? $_GET['so'] ?? ''));
        if ($salesOrder === '' || $salesOrder === '—') {
            echo json_encode([
                'success' => false,
                'error'   => 'Sales order is required',
                'records' => [],
            ]);
            return;
        }

        $result = $this->sapSalesService->getSalesApiHubByOrder($salesOrder);
        echo json_encode($result);
    }
}

?>
