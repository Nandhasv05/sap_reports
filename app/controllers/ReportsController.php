<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports controller
*/
require_once base_path('app/models/ReportsModel.php');

class ReportsController extends Controller
{
    /*
     * Show the index page
     */ 
    public function index(): void
    {
        $model = new ReportsModel();
        $this->view('reports/index', [
            'pageTitle'  => 'SAP Reports',
            'catalog'    => $model->catalog(),
            'appShell'   => true,
        ]);
    }

    /*
     * Show the report page
     */
    public function show(string $report = ''): void
    {
        $model = new ReportsModel();
        $catalog = $model->catalog();
        $report = strtolower(trim($report !== '' ? $report : (string) ($_GET['r'] ?? '')));
        if ($report === '' || !isset($catalog[$report])) {
            $this->index();
            return;
        }

        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access($report)) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view the ' . ($catalog[$report]['title'] ?? $report) . '.';
            $this->redirect(url('/'));
            return;
        }
        if ($report === 'production') {
            $this->showProduction($model, $catalog[$report]);
            return;
        }
        if ($report === 'procurement') {
            $this->showProcurement($model, $catalog[$report]);
            return;
        }

        $year = (int) date('Y');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
        $search = trim((string) ($_GET['q'] ?? ''));
        $salesOrder = trim((string) ($_GET['so'] ?? ''));
        if ($salesOrder === '' && preg_match('/^\d+$/', $search)) {
            $salesOrder = $search;
            $search = '';
        }
        $from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
        $to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
        $export = strtolower((string) ($_GET['export'] ?? '')) === 'csv';

        $isMaterialReport = in_array($report, ['fabric', 'trims'], true);

        $payload = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => $perPage,
            'sales_order' => $salesOrder,
        ];
        $loadError = '';
        $liveClient = $isMaterialReport && !$export;
        if (!$liveClient) {
            try {
                $payload = $model->fetch($report, $year, $page, $perPage, $search, $from, $to, $export, $salesOrder);
            } catch (Throwable $e) {
                $loadError = $e->getMessage();
            }
        }

        if ($export) {
            $this->sendCsv($report, $payload['records'] ?? []);
            return;
        }

        $total = (int) ($payload['total'] ?? 0);
        $page = (int) ($payload['page'] ?? $page);
        $pages = (int) ($payload['pages'] ?? 1);
        $perPage = (int) ($payload['per_page'] ?? $perPage);
        $start = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $end = min($page * $perPage, $total);

        $this->view($isMaterialReport ? 'reports/material' : 'reports/show', [
            'pageTitle'   => $catalog[$report]['title'],
            'layoutWide'  => $isMaterialReport,
            'appShell'    => $isMaterialReport,
            'catalog'     => $catalog,
            'report'      => $report,
            'item'        => $catalog[$report],
            'records'     => $payload['records'] ?? [],
            'total'       => $total,
            'page'        => $page,
            'pages'       => $pages,
            'perPage'     => $perPage,
            'start'       => $start,
            'end'         => $end,
            'search'      => $search,
            'salesOrder'  => $salesOrder !== '' ? $salesOrder : (string) ($payload['sales_order'] ?? ''),
            'from'        => $from,
            'to'          => $to,
            'loadError'   => $loadError,
            'model'       => $model,
            'summary'     => $payload['summary'] ?? [],
            'chart'       => $payload['chart'] ?? [],
            'liveClient'  => $liveClient,
            'extraHead'   => $isMaterialReport
                ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>'
                : '',
        ]);
    }

    /*
     * Production report page (rows are loaded by production.js from production/data)
    */
    private function showProduction(ReportsModel $model, array $item): void
    {
        $salesOrder = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';
        $service = new SapProductionService();
        $plant = $this->productionPlant($service);
        $range = $this->productionRange();
        $submitted = $salesOrder !== '' || $range !== null || isset($_GET['plant']);
        $filterError = $plant['error'] ?? ($range['error'] ?? null);
        if ($filterError === null && $submitted && $salesOrder === '' && $range === null) {
            $filterError = 'Choose an SO created date (From / To) or enter a sales order.';
        }
        if (!$submitted) {
            $filterError = null;
        }
        $ready = $submitted && $filterError === null;
        $activeRange = $ready ? $range : null;

        if (strtolower((string) ($_GET['export'] ?? '')) === 'csv') {
            $records = [];
            if ($ready) {
                try {
                    $records = $activeRange !== null
                        ? $service->rangeReport($activeRange['from'], $activeRange['to'], $salesOrder, $plant['plant'])['records']
                        : ($model->fetch('production', (int) date('Y'), 1, 10000, '', '', '', true, $salesOrder, $plant['plant'])['records'] ?? []);
                } catch (Throwable $e) {
                    $records = [];
                }
            }
            $tag = $plant['plant'] . ($activeRange !== null ? '-' . $activeRange['from'] . '_to_' . $activeRange['to'] : '') . ($salesOrder !== '' ? '-SO' . $salesOrder : '');
            $this->sendProductionCsv($tag, $records);
            return;
        }

        $this->view('reports/production', [
            'pageTitle'  => $item['title'],
            'layoutWide' => true,
            'appShell'   => true,
            'item'       => $item,
            'report'     => 'production',
            'salesOrder' => $ready ? $salesOrder : '',
            'soInputValue' => $salesOrder,
            'plant'      => $plant['plant'],
            'plants'     => $service->plants(),
            'range'      => $activeRange,
            'rangeError' => $filterError,
            'rangeInput' => $range,
            'presets'    => SapProductionService::rangePresets(),
            'presetDates' => SapProductionService::presetDates(),
            'columns'    => SapProductionService::columns(),
            'extraHead'  => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>',
        ]);
    }

    /*
     * Required plant (?plant=P002); must be one of the configured production plants
     *
     * @return array{plant: string, error: ?string}
     */
    private function productionPlant(SapProductionService $service): array
    {
        $plant = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['plant'] ?? '')) ?? ''), 0, 4);
        if ($plant === '') {
            return ['plant' => '', 'error' => 'Plant is required.'];
        }
        if (!in_array($plant, $service->plants(), true)) {
            return ['plant' => $plant, 'error' => 'Please select a valid plant (' . implode(', ', $service->plants()) . ').'];
        }
        return ['plant' => $plant, 'error' => null];
    }

    /*
     * SO creation date range (From / To) from ?range=preset[&from=&to=]; null when no date was chosen.
     * The sales order field then narrows the range.
     */
    private function productionRange(): ?array
    {
        $preset = strtolower(trim((string) ($_GET['range'] ?? '')));
        if ($preset === '') {
            return null;
        }
        return SapProductionService::resolveRange($preset, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
    }

    /*
     * Date-range mode: ?range=… returns production records directly from ZPROD_NEWSet
     */
    private function productionRangeData(): void
    {
        $service = new SapProductionService();
        $salesOrder = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';
        $plant = $this->productionPlant($service);
        $range = $this->productionRange();
        if ($plant['error'] !== null || $range === null || $range['error'] !== null) {
            $message = $plant['error'] ?? ($range['error'] ?? 'Choose a date range.');
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 422);
            return;
        }

        $data = $service->rangeReport($range['from'], $range['to'], $salesOrder, $plant['plant']);
        if ($data['error'] !== null) {
            $this->jsonResponse(['success' => false, 'message' => 'Unable to fetch SAP data: ' . $data['error'], 'error' => $data['error'], 'data' => []], 500);
            return;
        }

        $this->jsonResponse(['success' => true, 'message' => 'Live SAP data', 'data' => $data]);
    }

    private function sendProductionCsv(string $fileTag, array $records): void
    {
        $columns = SapProductionService::columns();
        $filename = 'sap-production-' . ($fileTag !== '' ? $fileTag . '-' : '') . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, array_column($columns, 'label'));
        foreach ($records as $i => $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $col['key'] === 'sno' ? $i + 1 : ($row[$col['key']] ?? '');
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    /*
     * Procurement report page (rows are loaded by procurement.js from procurement/data)
     */
    private function showProcurement(ReportsModel $model, array $item): void
    {
        $soParam = trim((string) ($_GET['so'] ?? ''));
        $rawSos = array_filter(
            array_map('trim', preg_split('/[,\s]+/', $soParam)),
            static fn (string $s): bool => $s !== ''
        );
        $salesOrder = implode(', ', $rawSos);

        if (strtolower((string) ($_GET['export'] ?? '')) === 'csv') {
            $records = [];
            try {
                $records = $model->fetch('procurement', (int) date('Y'), 1, 10000, '', '', '', true, $salesOrder)['records'] ?? [];
            } catch (Throwable $e) {
                $records = [];
            }
            $this->sendProcurementCsv($salesOrder, $records);
            return;
        }

        $this->view('reports/procurement', [
            'pageTitle'  => $item['title'],
            'layoutWide' => true,
            'appShell'   => true,
            'item'       => $item,
            'report'     => 'procurement',
            'salesOrder' => $salesOrder,
            'columns'    => SapProcurementService::columns(),
        ]);
    }

    /*
     * One CSV line per PR line; components without PR lines get a single line with empty PR fields.
     */
    private function sendProcurementCsv(string $salesOrder, array $records): void
    {
        $columns = array_values(array_filter(SapProcurementService::columns(), static fn (array $c): bool => $c['key'] !== 'pr_count'));
        $prColumns = SapProcurementService::prColumns();
        $safeSo = preg_replace('/[^\w\-]+/', '-', $salesOrder);
        $filename = 'sap-procurement-' . ($safeSo !== '' ? $safeSo . '-' : '') . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, array_merge(
            array_column($columns, 'label'),
            array_map(static fn (array $c): string => 'PR: ' . $c['label'], $prColumns)
        ));
        foreach ($records as $i => $row) {
            $base = [];
            foreach ($columns as $col) {
                $base[] = $col['key'] === 'sno' ? $i + 1 : ($row[$col['key']] ?? '');
            }
            $lines = is_array($row['pr_lines'] ?? null) && $row['pr_lines'] !== [] ? $row['pr_lines'] : [[]];
            foreach ($lines as $pr) {
                $line = $base;
                foreach ($prColumns as $col) {
                    $line[] = $pr[$col['key']] ?? '';
                }
                fputcsv($out, $line);
            }
        }
        fclose($out);
    }

    /*
     * PR Details endpoint: Linked sales order requirements, purchase history, and vendor info
     */
    public function prDetails(): void
    {
        $prNumber = trim((string) ($_GET['pr'] ?? ''));
        $material = trim((string) ($_GET['material'] ?? ''));
        $materialDesc = trim((string) ($_GET['material_desc'] ?? ''));
        $salesOrder = trim((string) ($_GET['so'] ?? ''));
        $customerName = trim((string) ($_GET['customer'] ?? '')) ?: 'ABC Garments';

        if ($prNumber === '') {
            $this->jsonResponse([
                'success' => false,
                'message' => 'PR number is required.',
                'error'   => 'PR number is required.',
                'data'    => null,
            ], 400);
            return;
        }

        $rawSos = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,\s]+/', $salesOrder)),
            static fn (string $s): bool => $s !== ''
        )));

        if (empty($rawSos)) {
            $rawSos = ['500001'];
        }

        $now = time();
        $salesOrders = [];
        $totalSos = count($rawSos);

        foreach ($rawSos as $idx => $soNum) {
            $date = date('d-M-Y', strtotime('+' . (($idx + 1) * 2) . ' days', $now));
            $req = $totalSos === 1 ? 200 : ($idx === 0 ? 200 : 150);
            $order = $totalSos === 1 ? 1000 : ($idx === 0 ? 1000 : ($idx === 1 ? 2000 : 1500));
            $cust = $customerName;
            if ($totalSos > 1 && $idx === 1) {
                $cust = 'XYZ Garments';
            } elseif ($totalSos > 1 && $idx >= 2) {
                $cust = 'PQR Garments';
            }

            $salesOrders[] = [
                'so_number'     => $soNum,
                'so_item'       => (string) (10 * ($idx + 1)),
                'customer'      => $cust,
                'order_qty'     => $order,
                'required_qty'  => $req,
                'allocated_qty' => 0,
                'pending_qty'   => $req,
                'delivery_date' => $date,
                'remarks'       => 'Production',
            ];
        }

        $purchaseHistory = [
            [
                'po_number'     => '4000006524',
                'po_item'       => '10',
                'po_date'       => date('d-M-Y', strtotime('-25 days', $now)),
                'vendor_name'   => 'Reliable Trims & Tapes Ltd',
                'order_qty'     => 1200,
                'grn_qty'       => 1200,
                'pending_qty'   => 0,
                'net_price'     => 74.36,
                'currency'      => 'INR',
                'delivery_date' => date('d-M-Y', strtotime('-10 days', $now)),
                'status'        => 'In Stock',
            ],
            [
                'po_number'     => '4000006412',
                'po_item'       => '10',
                'po_date'       => date('d-M-Y', strtotime('-55 days', $now)),
                'vendor_name'   => 'Supreme Accessories Corp',
                'order_qty'     => 2500,
                'grn_qty'       => 2500,
                'pending_qty'   => 0,
                'net_price'     => 72.80,
                'currency'      => 'INR',
                'delivery_date' => date('d-M-Y', strtotime('-40 days', $now)),
                'status'        => 'In Stock',
            ],
            [
                'po_number'     => '4000006305',
                'po_item'       => '20',
                'po_date'       => date('d-M-Y', strtotime('-90 days', $now)),
                'vendor_name'   => 'Apex Garment Solutions',
                'order_qty'     => 1800,
                'grn_qty'       => 1800,
                'pending_qty'   => 0,
                'net_price'     => 75.10,
                'currency'      => 'INR',
                'delivery_date' => date('d-M-Y', strtotime('-75 days', $now)),
                'status'        => 'In Stock',
            ],
        ];

        $vendors = [
            [
                'vendor_code'    => 'V-10024',
                'vendor_name'    => 'Reliable Trims & Tapes Ltd',
                'city'           => 'Bengaluru',
                'country'        => 'India',
                'payment_terms'  => 'Net 30 Days',
                'contact_person' => 'Ramesh Kumar',
                'email'          => 'ramesh@reliabletrims.in',
                'status'         => 'Preferred',
            ],
            [
                'vendor_code'    => 'V-10089',
                'vendor_name'    => 'Supreme Accessories Corp',
                'city'           => 'Chennai',
                'country'        => 'India',
                'payment_terms'  => 'Net 45 Days',
                'contact_person' => 'Senthil Nathan',
                'email'          => 'senthil@supremeaccessories.com',
                'status'         => 'Approved',
            ],
            [
                'vendor_code'    => 'V-10142',
                'vendor_name'    => 'Apex Garment Solutions',
                'city'           => 'Tirupur',
                'country'        => 'India',
                'payment_terms'  => 'Net 30 Days',
                'contact_person' => 'K. Anand',
                'email'          => 'anand@apexgarments.com',
                'status'         => 'Approved',
            ],
        ];

        $this->jsonResponse([
            'success' => true,
            'data'    => [
                'pr_number'            => $prNumber,
                'material'             => $material,
                'material_description' => $materialDesc,
                'sales_orders'         => $salesOrders,
                'purchase_history'     => $purchaseHistory,
                'vendors'              => $vendors,
            ],
        ]);
    }

    /*
     * Send the CSV file
     */
    private function sendCsv(string $report, array $records): void
    {
        $filename = 'sap-reports-' . $report . '-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        if ($report === 'division') {
            fputcsv($out, ['Division', 'Lines', 'Qty', 'Net Amount']);
            foreach ($records as $row) {
                fputcsv($out, [$row['division'] ?? '', $row['lines'] ?? 0, $row['qty'] ?? 0, $row['net_amount'] ?? 0]);
            }
        } elseif ($report === 'trims') {
            fputcsv($out, [
                'S.No', 'Category', 'Sales Order', 'Material', 'MatType', 'MatTypeDesc', 'MatGroup', 'MatGroupDesc', 'Season', 'Season Year', 'Purchase Order', 'PO Item',
                'SO Qty', 'BOM Qty', 'Total Add SO BOM Qty', 'Planned Qty', 'Production Qty',
                'PO Qty', 'GRN Qty', 'Issue Qty', 'Additional Sale Order', 'COLOR', 'SIZE1', 'SIZE2',
            ]);
            foreach ($records as $i => $row) {
                fputcsv($out, [
                    $i + 1,
                    $row['category'] ?? '',
                    $row['sales_order'] ?? '',
                    $row['material'] ?? '',
                    $row['mat_type'] ?? '',
                    $row['mat_type_desc'] ?? '',
                    $row['mat_group'] ?? '',
                    $row['mat_group_desc'] ?? '',
                    $row['season'] ?? '',
                    $row['season_year'] ?? '',
                    $row['purchase_order'] ?? '',
                    $row['po_item'] ?? '',
                    $row['so_qty'] ?? 0,
                    $row['bom_qty'] ?? 0,
                    $row['total_bom_qty'] ?? 0,
                    $row['planned_qty'] ?? 0,
                    $row['production_qty'] ?? 0,
                    $row['po_qty'] ?? 0,
                    $row['grn_qty'] ?? 0,
                    $row['issue_qty'] ?? 0,
                    $row['grn_sales_orders'] ?? '',
                    $row['colour'] ?? $row['color'] ?? '',
                    $row['size1'] ?? '',
                    $row['size2'] ?? '',
                ]);
            }
        } elseif ($report === 'fabric') {
            fputcsv($out, [
                'S.No', 'Sales Order', 'Material', 'Description', 'Season', 'Season Year', 'Purchase Order', 'PO Item',
                'SO Qty', 'BOM Qty', 'Total Add SO BOM Qty', 'Planned Qty', 'Production Qty',
                'PO Qty', 'GRN Qty', 'Issue Qty', 'Additional Sale Order',
                'Attribute1_text', 'Attribute2_text', 'Attribute3_text', 'Colour'
            ]);
            foreach ($records as $i => $row) {
                fputcsv($out, [
                    $i + 1,
                    $row['sales_order'] ?? '',
                    $row['material'] ?? '',
                    $row['description'] ?? '',
                    $row['season'] ?? '',
                    $row['season_year'] ?? '',
                    $row['purchase_order'] ?? '',
                    $row['po_item'] ?? '',
                    $row['so_qty'] ?? 0,
                    $row['bom_qty'] ?? 0,
                    $row['total_bom_qty'] ?? 0,
                    $row['planned_qty'] ?? 0,
                    $row['production_qty'] ?? 0,
                    $row['po_qty'] ?? 0,
                    $row['grn_qty'] ?? 0,
                    $row['issue_qty'] ?? 0,
                    $row['grn_sales_orders'] ?? '',
                    $row['attribute1_text'] ?? '',
                    $row['attribute2_text'] ?? '',
                    $row['attribute3_text'] ?? '',
                    $row['colour'] ?? '',
                ]);
            }
        } else {
            fputcsv($out, ['Sales Order', 'Line', 'Style / Product', 'Material', 'Item Type', 'Division', 'Date', 'Qty', 'Net Amount', 'Status']);
            foreach ($records as $row) {
                fputcsv($out, [
                    $row['sales_order'] ?? '',
                    $row['line_item'] ?? '',
                    $row['style'] ?? '',
                    $row['material'] ?? '',
                    $row['item_type'] ?? ($row['item_category'] ?? ''),
                    $row['division'] ?? '',
                    $row['date'] ?? '',
                    $row['qty'] ?? '',
                    $row['net_amount'] ?? '',
                    $row['status'] ?? '',
                ]);
            }
        }
        fclose($out);
    }

    /*
     * Fabric Sale Order:
     * Landing → search modal (date presets) → full SO dashboard → 90% fabric offcanvas
     */
    public function showFabricSale(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('fabric-sale')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view Fabric Sale Order.';
            $this->redirect(url('/'));
            return;
        }
        require_once base_path('app/services/SapPurchaseOrderService.php');
        $soQuery = trim((string) ($_GET['so'] ?? ''));
        // Mutually exclusive: SO search OR date range — never both
        $autoSearch = $soQuery !== '' || isset($_GET['from']) || isset($_GET['to']) || isset($_GET['range']);
        $presets = SapPurchaseOrderService::rangePresets();
        $presetIn = '';
        $range = ['preset' => '', 'from' => '', 'to' => '', 'label' => ''];
        if ($soQuery === '') {
            $presetIn = strtolower(trim((string) ($_GET['range'] ?? '')));
            if ($autoSearch && $presetIn === '') {
                $presetIn = 'today';
            }
            if ($presetIn !== '' && !array_key_exists($presetIn, $presets)) {
                $presetIn = 'today';
            }
            if ($presetIn !== '') {
                $range = SapPurchaseOrderService::resolveRange(
                    $presetIn,
                    (string) ($_GET['from'] ?? ''),
                    (string) ($_GET['to'] ?? '')
                );
            }
        }
        $preset = (string) ($range['preset'] ?? $presetIn);

        $this->view('reports/fabric_sale', [
            'pageTitle'    => 'Fabric Sale Order',
            'appShell'     => true,
            'layoutWide'   => true,
            'from'         => (string) ($range['from'] ?? ''),
            'to'           => (string) ($range['to'] ?? ''),
            'rangePreset'  => $preset,
            'presets'      => $presets,
            'presetDates'  => SapPurchaseOrderService::presetDates(),
            'soQuery'      => $soQuery,
            'autoSearch'   => $autoSearch,
            'home'         => sap_reports_evol_url('portal_dashboard.php'),
            'catalogHome'  => url('/'),
            'logoUrl'      => sap_reports_logo_url(),
            'extraScripts' => '',
        ]);
    }

    /*
     * PO Costing Validation lookup:
     * Full-page landing (header + footer). PO → workbench; date range → bulk validation.
     */
    public function showPoCosting(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view PO Costing Validation.';
            $this->redirect(url('/'));
            return;
        }
        require_once base_path('app/services/SapPurchaseOrderService.php');
        $this->view('reports/po_costing', [
            'pageTitle'   => 'PO Costing Validation',
            'appShell'    => true,
            'layoutWide'  => true,
            'presets'     => SapPurchaseOrderService::rangePresets(),
            'poBaseUrl'             => url('purchase-order'),
            'passLogPageUrl'        => url('po-costing/pass-log'),
            'validationHistoryUrl'  => url('po-costing/validation-history'),
            'home'                  => sap_reports_evol_url('portal_dashboard.php'),
            'catalogHome'           => url('/'),
            'logoUrl'               => sap_reports_logo_url(),
            'extraScripts'          => '',
        ]);
    }

    /*
     * PO Validation History — Status & Remarks / validate activity by PO
     */
    public function showPoValidationHistory(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view PO Costing Validation.';
            $this->redirect(url('/'));
            return;
        }
        $this->view('reports/po_validation_history', [
            'pageTitle'         => 'PO Validation History',
            'appShell'          => true,
            'layoutWide'        => true,
            'poBaseUrl'         => url('purchase-order'),
            'validationLogUrl'  => url('purchase-order/validation-log'),
            'costingUrl'        => url('po-costing'),
            'home'              => sap_reports_evol_url('portal_dashboard.php'),
            'catalogHome'       => url('/'),
            'logoUrl'           => sap_reports_logo_url(),
        ]);
    }

    /*
     * Purchase Order report page:
     * - Without ?po: condition-wise PO list view (date range, plant, supplier, quick search)
     * - With ?po=4000006524: single PO details view (Hero, KPI cards, charts, items table)
     */
    public function showPurchaseOrder(): void
    {
        $isCosting = strtolower((string) ($_GET['view'] ?? '')) === 'costing';
        $perm = $isCosting ? 'po-costing' : 'purchase-order';
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access($perm)) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view ' . ($isCosting ? 'PO Costing Validation' : 'Purchase Orders') . '.';
            $this->redirect(url('/'));
            return;
        }
        $input = preg_replace('/[\s,]/', '', (string) ($_GET['po'] ?? '')) ?? '';
        $error = $input !== '' && !preg_match('/^\d{1,10}$/', $input) ? 'Purchase order must be a number of up to 10 digits.' : null;
        $mode = ($input !== '' && $error === null) ? 'detail' : 'list';

        $service = new SapPurchaseOrderService();
        $rangePreset = strtolower(trim((string) ($_GET['range'] ?? 'today')));
        $range = SapPurchaseOrderService::resolveRange($rangePreset, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
        $rangePreset = (string) ($range['preset'] ?? $rangePreset);
        $plant = strtoupper(trim((string) ($_GET['plant'] ?? '')));
        $rawSupplier = $_GET['supplier'] ?? $_GET['vendor'] ?? '';
        if (is_array($rawSupplier)) {
            $suppliers = array_values(array_unique(array_filter(array_map('trim', $rawSupplier))));
        } else {
            $suppliers = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $rawSupplier)))));
        }
        $supplier = implode(',', $suppliers);
        $salesOrder = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';
        $rawPoType = $_GET['po_type'] ?? $_GET['type'] ?? '';
        if (is_array($rawPoType)) {
            $poTypes = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $rawPoType)))));
        } else {
            $poTypes = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', explode(',', (string) $rawPoType))))));
        }
        $poType = implode(',', $poTypes);
        $matCat = strtolower(trim((string) ($_GET['mat_cat'] ?? 'all')));
        if (!array_key_exists($matCat, SapPurchaseOrderService::materialCategories())) {
            $matCat = 'all';
        }
        $approvedStatus = trim((string) ($_GET['approved_status'] ?? $_GET['APPROVEDSTATUS'] ?? ''));
        if (!in_array($approvedStatus, ['Approved', 'NotApproved'], true)) {
            $approvedStatus = '';
        }

        $listColumns = SapPurchaseOrderService::listColumns();
        $detailColumns = SapPurchaseOrderService::columns();
        $viewMode = strtolower(trim((string) ($_GET['view'] ?? '')));
        $isCostingView = $viewMode === 'costing';
        $hasCostService = is_file(base_path('app/services/SapCostService.php'));
        $hasValidationStatus = is_file(base_path('app/services/PoValidationStatusService.php'));
        if ($isCostingView && !$hasCostService) {
            $isCostingView = false;
        }

        $costingColumns = [];
        $costingListColumns = [];
        if ($hasCostService) {
            require_once base_path('app/services/SapCostService.php');
            $costingColumns = SapCostService::costingColumns();
        }
        if ($hasValidationStatus) {
            require_once base_path('app/services/PoValidationStatusService.php');
            $costingListColumns = PoValidationStatusService::costingListColumns();
        }

        if (strtolower((string) ($_GET['export'] ?? '')) === 'csv') {
            if ($mode === 'detail') {
                $detail = $service->report($input);
                $exportView = strtolower(trim((string) ($_GET['view'] ?? '')));
                if ($exportView === 'costing' && $hasCostService) {
                    $soOverride = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';
                    $detail = (new SapCostService())->attachToPurchaseOrder(
                        $detail,
                        $soOverride !== '' ? [$soOverride] : []
                    );
                    $records = $detail['records'] ?? [];
                    if ($matCat !== 'all') {
                        $records = array_values(array_filter(
                            $records,
                            static fn (array $r): bool => ($r['mat_category'] ?? '') === $matCat
                        ));
                    }
                    $this->sendPurchaseOrderCsv($input, $records, $costingColumns);
                } else {
                    $records = $detail['records'] ?? [];
                    if ($matCat !== 'all') {
                        $records = array_values(array_filter(
                            $records,
                            static fn (array $r): bool => ($r['mat_category'] ?? '') === $matCat
                        ));
                    }
                    $this->sendPurchaseOrderCsv($input, $records);
                }
            } else {
                $listData = $service->listReport([
                    'range'    => $rangePreset,
                    'from'     => $range['from'] ?? '',
                    'to'       => $range['to'] ?? '',
                    'plant'    => $plant,
                    'supplier' => $suppliers,
                    'so'       => $salesOrder,
                    'po_type'  => $poTypes,
                    'mat_cat'  => $matCat,
                ]);
                $this->sendPurchaseOrderListCsv($listData['records'] ?? []);
            }
            return;
        }

        $listTitle = $isCostingView ? 'PO Costing Validation' : 'Purchase Order Report';
        $detailTitle = $isCostingView
            ? ('PO Costing ' . $input)
            : ('Purchase Order ' . $input);
        $activeColumns = $mode === 'detail'
            ? ($isCostingView && $costingColumns !== [] ? $costingColumns : $detailColumns)
            : ($isCostingView && $costingListColumns !== [] ? $costingListColumns : $listColumns);

        $this->view('reports/purchase_order', [
            'pageTitle'     => $mode === 'detail' ? $detailTitle : $listTitle,
            'layoutWide'    => true,
            'appShell'      => true,
            'item'          => ['title' => $listTitle],
            'report'        => 'purchase-order',
            'isCostingView' => $isCostingView,
            'mode'          => $mode,
            'purchaseOrder' => $error === null ? $input : '',
            'poInput'       => $input,
            'poError'       => $error,
            'range'         => $range,
            'rangePreset'   => $rangePreset,
            'rangeError'    => $range['error'] ?? null,
            'presets'       => SapPurchaseOrderService::rangePresets(),
            'presetDates'   => SapPurchaseOrderService::presetDates(),
            'plant'         => $plant,
            'plants'        => $service->plants(),
            'supplier'      => $supplier,
            'suppliers'     => $suppliers,
            'salesOrder'    => $salesOrder,
            'poType'        => $poType,
            'poTypes'       => $poTypes,
            'matCat'        => $matCat,
            'approvedStatus'=> $approvedStatus,
            'matCategories' => SapPurchaseOrderService::materialCategories(),
            'docTypes'      => SapPurchaseOrderService::documentTypes(),
            'columns'       => $activeColumns,
            'detailColumns' => $detailColumns,
            'costingColumns'=> $costingColumns,
            'costingListColumns' => $costingListColumns,
            'listColumns'   => $listColumns,
            'extraHead'     => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>',
        ]);
    }

    private function sendPurchaseOrderListCsv(array $records): void
    {
        $filename = 'sap-purchase-orders-list-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['S.No', 'Purchase Order', 'PO Date', 'Plant', 'Supplier', 'Material', 'MaterialType', 'MatTypeDesc', 'MaterialGroup', 'Items', 'Order Qty', 'Unit', 'Net Value', 'Currency', 'Linked Sales Orders', 'Created By']);
        foreach ($records as $i => $row) {
            fputcsv($out, [
                $i + 1,
                $row['purchase_order'] ?? '',
                $row['date'] ?? '',
                $row['plant'] ?? '',
                $row['supplier'] ?? '',
                $row['material'] ?? implode(', ', (array) ($row['materials'] ?? [])),
                $row['material_type'] ?? implode(', ', (array) ($row['material_types'] ?? [])),
                $row['mat_type_desc'] ?? implode(', ', (array) ($row['mat_type_descs'] ?? [])),
                $row['material_group'] ?? implode(', ', (array) ($row['material_groups'] ?? [])),
                $row['items_count'] ?? 0,
                $row['total_qty'] ?? 0,
                $row['unit'] ?? '',
                $row['total_value'] ?? 0,
                $row['currency'] ?? '',
                implode(', ', (array) ($row['sales_orders'] ?? [])),
                $row['created_by'] ?? '',
            ]);
        }
        fclose($out);
    }

    private function sendPurchaseOrderCsv(string $po, array $records, ?array $columns = null): void
    {
        $filename = 'sap-purchase-order-' . ($po !== '' ? $po . '-' : '') . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        $columns = is_array($columns) && $columns !== [] ? $columns : SapPurchaseOrderService::columns();
        fputcsv($out, array_column($columns, 'label'));
        foreach ($records as $i => $row) {
            $line = [];
            foreach ($columns as $col) {
                if ($col['key'] === 'sno') {
                    $line[] = $i + 1;
                } elseif ($col['key'] === 'sales_orders') {
                    $line[] = implode(', ', (array) ($row['sales_orders'] ?? []));
                } else {
                    $line[] = $row[$col['key']] ?? '';
                }
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    /*
     * PASS PO: POST POApprovalSet then fetch POPDFSet $value and save under upload/PoZcostValidation/Po-{po}/
     */
    public function purchaseOrderPass(): void
    {
        require_once base_path('app/services/PoPassApprovalService.php');
        $raw = function_exists('sap_reports_request_raw_body') ? sap_reports_request_raw_body() : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        $po = preg_replace('/\D/', '', (string) ($input['po'] ?? $input['purchase_order'] ?? $_GET['po'] ?? '')) ?? '';
        $requestId = trim((string) ($input['request_id'] ?? $input['RequestId'] ?? ''));
        // Value: "X" = PASS / approve, " " = REVERT
        $rawValue = (string) ($input['value'] ?? $input['Value'] ?? $input['action'] ?? 'X');
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        if ($action === 'revert' || $action === 'unapprove') {
            $valueFlag = ' ';
        } elseif (strtoupper(trim($rawValue)) === 'X' || $action === 'approve' || $action === 'pass') {
            $valueFlag = 'X';
        } else {
            $valueFlag = ' ';
        }
        $svc = new PoPassApprovalService();

        // Approved button: SAP POApprovalSet only. Local DB updated only after SAP success.
        $approveOnly = !empty($input['approve_only'])
            || $action === 'approve'
            || $action === 'approve_only';
        if ($approveOnly && $valueFlag === 'X') {
            $res = $svc->approveOnly($po, $requestId !== '' ? $requestId : null, 'X');
            if (empty($res['success'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => (string) ($res['error'] ?? 'SAP Approve API failed'),
                    'error'   => (string) ($res['error'] ?? 'SAP Approve API failed'),
                    'data'    => $res,
                ], 502);
            }
            $this->jsonResponse([
                'success' => true,
                'message' => 'SAP approved PO (POApprovalSet Value = X). Local status updated for tracking only.',
                'data'    => [
                    'request_id'   => $res['request_id'],
                    'po'           => $res['po'],
                    'value'        => 'X',
                    'action'       => 'approve',
                    'approve_only' => true,
                    'sap_approved' => true,
                    'local_status' => 'Approved Completed',
                ],
            ]);
        }

        $res = $svc->approveAndFetchPdf($po, $requestId !== '' ? $requestId : null, $valueFlag);
        if (empty($res['success'])) {
            $failMsg = $valueFlag === 'X' ? 'PASS PO failed' : 'REVERT PO failed';
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? $failMsg),
                'error'   => (string) ($res['error'] ?? $failMsg),
                'data'    => $res,
            ], 502);
        }

        if ($valueFlag !== 'X') {
            $this->jsonResponse([
                'success' => true,
                'message' => 'PO reverted (Value = blank)',
                'data'    => [
                    'request_id' => $res['request_id'],
                    'po'         => $res['po'],
                    'value'      => ' ',
                    'action'     => 'revert',
                    'folder'     => 'upload/PoZcostValidation/Po-' . $res['po'],
                ],
            ]);
        }

        // Browser preview must use the routed PDF proxy (static /upload/... hits the app router → 404)
        $previewUrl = url('purchase-order/pdf') . '?po=' . rawurlencode((string) $res['po']);
        $this->jsonResponse([
            'success' => true,
            'message' => 'PO approved (Value = X) and PDF ready for e-sign',
            'data'    => [
                'request_id'  => $res['request_id'],
                'po'          => $res['po'],
                'value'       => 'X',
                'action'      => 'pass',
                'pdf_url'     => $previewUrl,
                'preview_url' => $previewUrl,
                'saved_path'  => $res['pdf_url'],
                'folder'      => 'upload/PoZcostValidation/Po-' . $res['po'],
            ],
        ]);
    }

    /*
     * Load / save the signed-in user's e-signature (dbo.user_esignatures)
     * GET  → load
     * POST → upsert signature image after upload/draw
     */
    public function purchaseOrderEsignSignature(): void
    {
        require_once base_path('app/services/UserEsignService.php');
        $current = function_exists('portal_user') ? portal_user() : null;
        $username = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? '')));
        $userId = (string) ($current['id'] ?? ($_SESSION['user_id'] ?? ''));
        if ($username === '') {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Not signed in',
                'error'   => 'Not signed in',
                'data'    => ['found' => false],
            ], 401);
        }
        $svc = new UserEsignService();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' || $method === 'PUT') {
            $raw = function_exists('sap_reports_request_raw_body') ? sap_reports_request_raw_body() : ((string) (file_get_contents('php://input') ?: ''));
            $input = json_decode($raw, true);
            if (!is_array($input)) {
                $input = $_POST;
            }
            $sig = trim((string) ($input['signature_data'] ?? $input['signature'] ?? ''));
            $save = $svc->saveForUser($userId !== '' ? $userId : null, $username, $sig);
            if (empty($save['success'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => (string) ($save['error'] ?? 'Could not save signature'),
                    'error'   => (string) ($save['error'] ?? 'Could not save signature'),
                    'data'    => ['found' => false],
                ], 422);
            }
            $this->jsonResponse([
                'success' => true,
                'message' => 'Signature saved to database',
                'data'    => [
                    'found' => true,
                    'username' => $username,
                    'signature_data' => $sig,
                    'saved' => true,
                ],
            ]);
        }

        $res = $svc->getForUsername($username);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Could not load signature'),
                'error'   => (string) ($res['error'] ?? 'Could not load signature'),
                'data'    => $res,
            ], 500);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => !empty($res['found']) ? 'Signature found' : 'No saved signature',
            'data'    => [
                'found' => !empty($res['found']),
                'username' => $res['username'],
                'signature_data' => $res['signature_data'],
            ],
        ]);
    }

    /*
     * Save signed PO: write signed PDF to EVOL path only (no POApprovalSet).
     * Approve API is triggered by the Approved button separately.
     */
    public function purchaseOrderEsignSave(): void
    {
        try {
            @set_time_limit(120);
            require_once base_path('app/services/PoPassApprovalService.php');
            $raw = function_exists('sap_reports_request_raw_body')
                ? sap_reports_request_raw_body()
                : ((string) (file_get_contents('php://input') ?: ''));
            if ($raw === '' && empty($_POST)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Empty request body. Increase PHP post_max_size / memory_limit and try again.',
                    'error'   => 'Empty request body',
                    'data'    => null,
                ], 413);
            }
            $input = json_decode($raw, true);
            if (!is_array($input)) {
                $input = is_array($_POST) ? $_POST : [];
            }
            if ($input === []) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid JSON body for e-sign save.',
                    'error'   => 'Invalid JSON body',
                    'data'    => null,
                ], 422);
            }
            $po = preg_replace('/\D/', '', (string) ($input['po'] ?? $input['purchase_order'] ?? '')) ?? '';
            $current = function_exists('portal_user') ? portal_user() : null;
            $input['signed_by'] = (string) ($current['username'] ?? ($_SESSION['username'] ?? 'system'));
            $svc = new PoPassApprovalService();
            $res = $svc->saveEsign($po, $input);

            $displayPo = ltrim(preg_replace('/\D/', '', $po) ?? '', '0');
            if ($displayPo === '') {
                $displayPo = $po;
            }
            $fileUrl = (string) ($res['file_url'] ?? '');
            if ($fileUrl === '' && !empty($res['signed_pdf'])) {
                $fileUrl = url('purchase-order/signed-file?po=' . rawurlencode($displayPo));
            }
            // Slim payload — never return SAP raw/body (breaks json_encode / empty response)
            $data = [
                'success' => !empty($res['success']),
                'db_id' => $res['db_id'] ?? null,
                'request_id' => $res['request_id'] ?? null,
                'pdf_url' => $res['pdf_url'] ?? null,
                'file_url' => $fileUrl !== '' ? $fileUrl : null,
                'pdf_file' => $res['pdf_file'] ?? null,
                'folder' => $res['folder'] ?? null,
                'network_path' => $res['network_path'] ?? null,
                'network_folder' => $res['network_folder'] ?? null,
                'signed_pdf' => !empty($res['signed_pdf']),
                'approval_http' => $res['approval']['http_code'] ?? ($res['approval_http'] ?? null),
                'approval_payload' => $res['approval_payload'] ?? null,
                'error' => $res['error'] ?? null,
            ];

            if (empty($res['success'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => (string) ($res['error'] ?? 'E-sign save failed'),
                    'error'   => (string) ($res['error'] ?? 'E-sign save failed'),
                    'data'    => $data,
                ], 422);
            }
            $this->jsonResponse([
                'success' => true,
                'message' => 'Signed PDF saved to path',
                'data'    => $data,
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'E-sign save crashed: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }

    /*
     * Full-screen Past PO Log dashboard (charts + filters + table)
     */
    public function showPoPassLog(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view PO Costing Validation.';
            $this->redirect(url('/'));
            return;
        }
        $this->view('reports/po_pass_log', [
            'pageTitle'  => 'PO E-Sign Log',
            'appShell'   => true,
            'layoutWide' => true,
            'poBaseUrl'  => url('purchase-order'),
            'passLogUrl' => url('purchase-order/pass-log'),
            'costingUrl' => url('po-costing'),
            'home'       => sap_reports_evol_url('portal_dashboard.php'),
            'catalogHome'=> url('/'),
            'logoUrl'    => sap_reports_logo_url(),
        ]);
    }

    /*
     * GET purchase-order/pass-log — list successfully passed POs (Past PO Log API)
     */
    public function purchaseOrderPassLog(): void
    {
        require_once base_path('app/services/PassPoValidationLogService.php');
        $svc = new PassPoValidationLogService();
        $res = $svc->listPassed([
            'limit' => (int) ($_GET['limit'] ?? 200),
            'po' => (string) ($_GET['po'] ?? ''),
            'username' => (string) ($_GET['username'] ?? $_GET['user'] ?? ''),
            'action' => (string) ($_GET['action'] ?? ''),
            'from' => (string) ($_GET['from'] ?? ''),
            'to' => (string) ($_GET['to'] ?? ''),
        ]);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Could not load Past PO Log'),
                'error'   => (string) ($res['error'] ?? 'Could not load Past PO Log'),
                'data'    => ['rows' => [], 'stats' => []],
            ], 500);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => 'Past PO Log',
            'data'    => [
                'rows' => $res['rows'],
                'stats' => $res['stats'] ?? [],
                'count' => count($res['rows']),
            ],
        ]);
    }

    /*
     * Stream locally saved signed PO PDF (Past PO Log → File)
     * GET purchase-order/signed-file?po=1000027972
     */
    public function purchaseOrderSignedFile(): void
    {
        $po = preg_replace('/\D/', '', (string) ($_GET['po'] ?? '')) ?? '';
        $display = ltrim($po, '0');
        if ($display === '') {
            $display = $po;
        }
        if ($display === '' || !preg_match('/^\d{1,10}$/', $po)) {
            $message = 'Purchase order must be a number of up to 10 digits.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message], 422);
        }
        require_once base_path('app/services/PassPoValidationLogService.php');
        $file = (new PassPoValidationLogService())->resolveSignedPdf($display);
        $path = (string) ($file['path'] ?? $file['network_path'] ?? '');
        if ($path === '' || empty($file['has_file'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Signed PDF file not found for this PO.',
                'error'   => 'Signed PDF file not found for this PO.',
                'po'      => $display,
            ], 404);
        }
        if (!@is_file($path)) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Signed PDF file not found on network share.',
                'error'   => 'Signed PDF file not found on network share.',
                'po'      => $display,
            ], 404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $name = basename((string) ($file['pdf_file'] ?? $path));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        $size = @filesize($path);
        if ($size !== false) {
            header('Content-Length: ' . (string) $size);
        }
        header('Cache-Control: private, max-age=60');
        readfile($path);
        exit;
    }

    /*
     * Stream PO PDF (local-first):
     *  1) Signed PDF under EVOL/uploads/po_esign/PO_New_Module/PO-{po}_signed.pdf
     *  2) Cached original PO-{po}.pdf
     *  3) Else SAP POPDFSet → save as PO-{po}.pdf (never overwrites signed)
     * GET purchase-order/pdf?po=1000000543
     * GET purchase-order/pdf?po=…&ensure=1  → JSON { success, source, fetched_sap } (warm cache)
     */
    public function purchaseOrderPdf(): void
    {
        $po = preg_replace('/\D/', '', (string) ($_GET['po'] ?? '')) ?? '';
        if ($po === '' || !preg_match('/^\d{1,10}$/', $po)) {
            $message = 'Purchase order must be a number of up to 10 digits.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message], 422);
        }
        require_once base_path('app/services/PoPassApprovalService.php');
        $svc = new PoPassApprovalService();
        $res = $svc->ensureLocalPoPdf($po);
        $ensureOnly = isset($_GET['ensure']) && (string) $_GET['ensure'] !== '' && (string) $_GET['ensure'] !== '0';

        if (empty($res['success']) || empty($res['path']) || !@is_file((string) $res['path'])) {
            $message = (string) ($res['error'] ?? 'SAP did not return a PDF for this purchase order.');
            $this->jsonResponse([
                'success' => false,
                'message' => $message,
                'error'   => $message,
                'po'      => $svc->displayPoNumber($po),
                'source'  => $res['source'] ?? null,
                'fetched_sap' => !empty($res['fetched_sap']),
            ], 502);
        }

        if ($ensureOnly) {
            $this->jsonResponse([
                'success' => true,
                'message' => !empty($res['is_signed'])
                    ? 'Using signed PDF'
                    : (!empty($res['fetched_sap']) ? 'PDF fetched from SAP and cached' : 'Using cached PDF'),
                'data' => [
                    'po' => $svc->displayPoNumber($po),
                    'pdf_file' => $res['pdf_file'] ?? null,
                    'folder' => $res['folder'] ?? null,
                    'is_signed' => !empty($res['is_signed']),
                    'source' => $res['source'] ?? null,
                    'fetched_sap' => !empty($res['fetched_sap']),
                ],
            ]);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $path = (string) $res['path'];
        $name = (string) ($res['pdf_file'] ?? basename($path));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        $size = @filesize($path);
        if ($size !== false) {
            header('Content-Length: ' . (string) $size);
        }
        header('Cache-Control: private, max-age=60');
        header('X-PO-PDF-Source: ' . (string) ($res['source'] ?? 'local'));
        readfile($path);
        exit;
    }

    /*
     * PO Costing Validation workbench — PO lines vs ZCOST expected rates
     * GET purchase-order/validate?po=1000027905&mat_cat=trims&so=22000914
     */
    public function purchaseOrderValidate(): void
    {
        if (!is_file(base_path('app/services/PoCostValidationService.php'))) {
            $message = 'PO costing validation is not deployed on this server.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 503);
        }
        require_once base_path('app/services/PoCostValidationService.php');
        $po = preg_replace('/\D/', '', (string) ($_GET['po'] ?? '')) ?? '';
        if ($po === '' || !preg_match('/^\d{1,10}$/', $po)) {
            $message = 'Purchase order must be a number of up to 10 digits.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 422);
        }
        $category = strtolower(trim((string) ($_GET['mat_cat'] ?? $_GET['category'] ?? 'all')));
        $so = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';
        $item = preg_replace('/\D/', '', (string) ($_GET['item'] ?? $_GET['item_no'] ?? '')) ?? '';
        $quotation = preg_replace('/\D/', '', (string) ($_GET['quotation'] ?? $_GET['quote_no'] ?? '')) ?? '';
        $mode = strtolower(trim((string) ($_GET['mode'] ?? 'validated')));
        if (!in_array($mode, ['validated', 'non_validated'], true)) {
            $mode = 'validated';
        }
        $customer = preg_replace('/\D/', '', (string) ($_GET['customer'] ?? $_GET['customer_quote'] ?? '')) ?? '';
        $endUser = preg_replace('/\D/', '', (string) ($_GET['end_user'] ?? $_GET['end_user_quote'] ?? '')) ?? '';
        try {
            $payload = (new PoCostValidationService())->validate(
                $po,
                $category,
                $so,
                $item !== '' ? $item : null,
                $quotation !== '' ? $quotation : null,
                $mode,
                $customer,
                $endUser
            );
            // Re-apply User1 manual Map Costing so User2 sees the same mapping
            if (!empty($payload['records']) && is_array($payload['records'])) {
                require_once base_path('app/services/PoManualCostingMapService.php');
                $mapSvc = new PoManualCostingMapService();
                $payload['records'] = $mapSvc->applyToRecords($po, $payload['records']);
                $payload['summary'] = $this->recountPoCostSummary($payload['records'], $payload['summary'] ?? []);
                $payload['manual_mappings'] = $mapSvc->listForPo($po);
            }
            // APPROVEDSTATUS: prefer SAP Z_QuotationZCostCom.ApprovedStatus; also honor local Pass log
            require_once base_path('app/services/SapPurchaseOrderService.php');
            $sapApproved = trim((string) ($payload['approved_status'] ?? $payload['header']['approved_status'] ?? ''));
            if ($sapApproved !== 'Approved' && $sapApproved !== 'NotApproved') {
                $sapApproved = 'NotApproved';
            }
            $localApproved = (new SapPurchaseOrderService())->approvedStatusFor($po);
            $payload['approved_status'] = ($sapApproved === 'Approved' || $localApproved === 'Approved')
                ? 'Approved'
                : 'NotApproved';
            if (isset($payload['header']) && is_array($payload['header'])) {
                $payload['header']['approved_status'] = $payload['approved_status'];
            }
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Validation failed: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => [],
            ], 502);
        }
        $exceptions = (int) ($payload['summary']['exceptions'] ?? 0);
        $needsReview = (int) ($payload['summary']['needs_review'] ?? 0);
        $message = $payload['error'] ?? null;
        if ($message === null) {
            if ($exceptions > 0 && $needsReview > 0) {
                $message = $exceptions . ' exceptions, ' . $needsReview . ' need review';
            } elseif ($exceptions > 0) {
                $message = $exceptions . ' exceptions require review';
            } elseif ($needsReview > 0) {
                $message = $needsReview . ' items need review';
            } else {
                $message = 'Validation complete';
            }
        }
        $this->jsonResponse([
            'success' => !empty($payload['success']),
            'message' => $message,
            'error'   => $payload['error'] ?? null,
            'data'    => $payload,
        ], !empty($payload['success']) ? 200 : 502);
    }

    /**
     * Recount KPI summary after applying shared manual mappings.
     *
     * @param list<array<string,mixed>> $records
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function recountPoCostSummary(array $records, array $base): array
    {
        $passed = 0;
        $exceptions = 0;
        $needsReview = 0;
        $matched = 0;
        $detail = 0;
        foreach ($records as $r) {
            $kind = (string) ($r['row_kind'] ?? 'detail');
            if (in_array($kind, ['footer', 'charge', 'tax'], true)) {
                continue;
            }
            $st = strtoupper(str_replace(' ', '_', (string) ($r['status'] ?? '')));
            if ($st === 'FOOTER') {
                continue;
            }
            $detail++;
            if ($st === 'PASS') {
                $passed++;
            } elseif ($st === 'NEEDS_REVIEW') {
                $needsReview++;
            } elseif ($st === 'NOT_MATCHED' || !empty($r['needs_manual_match'])) {
                $exceptions++;
            } else {
                $exceptions++;
            }
            if (($r['maktx'] ?? '') !== '' || ($r['cost_item'] ?? '') !== '' || !empty($r['manual_match'])) {
                $matched++;
            }
        }
        $base['passed'] = $passed;
        $base['exceptions'] = $exceptions;
        $base['needs_review'] = $needsReview;
        $base['zcost_matched'] = $matched;
        $base['can_pass_po'] = $detail > 0 && $exceptions === 0 && $needsReview === 0 && $passed > 0;
        return $base;
    }

    /*
     * Save Manual Map Costing (shared for all users on this PO)
     */
    public function purchaseOrderManualMapSave(): void
    {
        require_once base_path('app/services/PoManualCostingMapService.php');
        $raw = function_exists('sap_reports_request_raw_body')
            ? sap_reports_request_raw_body()
            : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = is_array($_POST) ? $_POST : [];
        }
        $current = function_exists('portal_user') ? portal_user() : null;
        $input['updated_by'] = (string) ($current['username'] ?? ($_SESSION['username'] ?? 'system'));
        $input['created_by'] = $input['updated_by'];
        $res = (new PoManualCostingMapService())->upsert($input);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Could not save mapping'),
                'error'   => (string) ($res['error'] ?? 'Could not save mapping'),
                'data'    => $res,
            ], 422);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => 'Mapping saved for all users',
            'data'    => $res,
        ]);
    }

    /*
     * Active portal users for PO costing assign / validate dropdown
     */
    public function purchaseOrderUsers(): void
    {
        require_once base_path('app/services/PoValidationLogService.php');
        $q = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 40);
        try {
            $users = (new PoValidationLogService())->searchUsers($q, $limit);
            $this->jsonResponse(['success' => true, 'data' => ['users' => $users]]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Could not load users: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => ['users' => []],
            ], 502);
        }
    }

    /*
     * Save Validate PO / Not Validate decision into po_validation_log
     */
    public function purchaseOrderValidationLogSave(): void
    {
        try {
            require_once base_path('app/services/PoValidationLogService.php');
            $raw = function_exists('sap_reports_request_raw_body') ? sap_reports_request_raw_body() : ((string) (file_get_contents('php://input') ?: ''));
            $input = json_decode($raw, true);
            if (!is_array($input)) {
                $input = $_POST;
            }
            $current = function_exists('portal_user') ? portal_user() : null;
            $createdBy = (string) ($current['username'] ?? ($_SESSION['username'] ?? 'system'));
            $input['created_by'] = $createdBy;

            $svc = new PoValidationLogService();
            // Always one PO-level validation log (ignore legacy per-line rows payload)
            unset($input['rows']);
            $input['po_item'] = '';
            $input['material'] = $input['material'] ?? '';
            $input['mat_desc'] = $input['mat_desc'] ?? '';
            $input['matched_zcost_item'] = '';
            $input['matched_maktx'] = '';
            $input['row_status'] = '';

            $res = $svc->save($input);
            if (empty($res['success'])) {
                $err = (string) ($res['error'] ?? 'Save failed');
                $this->jsonResponse([
                    'success' => false,
                    'message' => $err,
                    'error'   => $err,
                    'data'    => ['ids' => []],
                ], 422);
            }

            $status = strtoupper(trim((string) ($input['validation_status'] ?? '')));
            $isComment = $status === PoValidationLogService::STATUS_COMMENT;

            // Respond first so SMTP hang/timeout cannot blank the browser JSON body
            $payload = [
                'success' => true,
                'message' => $isComment ? 'Comment saved' : 'PO validation saved',
                'data'    => [
                    'ids' => [$res['id']],
                    'errors' => [],
                    'mail' => [
                        'sent' => 0,
                        'failed' => 0,
                        'errors' => $isComment ? ['Mail queued after response'] : [],
                        'mentioned' => [],
                        'from' => '',
                        'recipients' => [],
                        'deferred' => $isComment,
                    ],
                ],
            ];
            $this->flushJsonThenContinue($payload, 200);

            if ($isComment) {
                try {
                    @set_time_limit(60);
                    $this->notifyCommentMentions($svc, $input, $createdBy);
                } catch (Throwable $ignored) {
                    // Comment already saved + response already sent
                }
            }
            exit;
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Could not save comment: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => ['ids' => []],
            ], 500);
        }
    }

    /**
     * Send JSON to the client, then keep PHP running (for post-response mail).
     *
     * @param array<string,mixed> $payload
     */
    private function flushJsonThenContinue(array $payload, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (function_exists('sap_reports_audit_api_response')) {
            try {
                sap_reports_audit_api_response($payload, $status);
            } catch (Throwable $ignored) {
            }
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Connection: close');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        $json = json_encode($payload, $flags);
        if ($json === false || $json === '') {
            $json = '{"success":true,"message":"Comment saved","data":null}';
        }
        header('Content-Length: ' . strlen($json));
        echo $json;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @ob_flush();
            @flush();
        }
    }

    /**
     * Email users tagged with @username in a PO comment.
     *
     * @param array<string,mixed> $input
     * @return array{sent:int,failed:int,errors:list<string>,mentioned:list<string>}
     */
    private function notifyCommentMentions(PoValidationLogService $svc, array $input, string $createdBy): array
    {
        $description = trim((string) ($input['description'] ?? ''));
        $po = preg_replace('/\D/', '', (string) ($input['purchase_order'] ?? '')) ?? '';
        $mentioned = [];

        if (!empty($input['mentions']) && is_array($input['mentions'])) {
            foreach ($input['mentions'] as $m) {
                if (is_array($m)) {
                    $name = trim((string) ($m['username'] ?? $m['user'] ?? ''));
                } else {
                    $name = trim((string) $m);
                }
                if ($name !== '') {
                    $mentioned[strtolower($name)] = $name;
                }
            }
        }
        foreach ($svc->extractMentions($description) as $name) {
            $mentioned[strtolower($name)] = $name;
        }

        if (!$mentioned) {
            return [
                'sent' => 0,
                'failed' => 0,
                'errors' => ['No @mention found in comment'],
                'mentioned' => [],
                'from' => '',
            ];
        }

        $mailCfg = function_exists('config') ? config('mail') : [];
        $smtpPass = trim((string) (($mailCfg['smtp']['password'] ?? '') ?: ''));
        if ($smtpPass === '' && strtolower((string) ($mailCfg['driver'] ?? 'smtp')) === 'smtp') {
            return [
                'sent' => 0,
                'failed' => count($mentioned),
                'errors' => ['SMTP password is empty — set smtp.password in config/mail.php (or EVOLV_SMTP_PASS)'],
                'mentioned' => array_values($mentioned),
                'from' => (string) ($mailCfg['from']['address'] ?? ''),
                'recipients' => [],
            ];
        }

        $fallbackDomain = trim((string) ($mailCfg['fallback_domain'] ?? 'evolvclothing.com'));
        $users = $svc->findUsersByUsernames(array_values($mentioned));
        $byName = [];
        foreach ($users as $u) {
            $byName[strtolower((string) ($u['username'] ?? ''))] = $u;
        }

        $emails = [];
        $resolved = [];
        foreach ($mentioned as $key => $name) {
            $u = $byName[$key] ?? null;
            $email = trim((string) ($u['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Prefer company mailbox for known portal users
                $email = strtolower($name) . '@' . $fallbackDomain;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
                $resolved[] = (string) ($u['username'] ?? $name);
            }
        }
        $emails = array_values(array_unique($emails));

        if (!$emails) {
            return [
                'sent' => 0,
                'failed' => 0,
                'errors' => ['No email found for mentioned users'],
                'mentioned' => array_values($mentioned),
                'from' => (string) ($mailCfg['from']['address'] ?? ''),
            ];
        }

        require_once base_path('app/services/SimpleMailService.php');
        $fromEmail = (string) ($mailCfg['from']['address'] ?? 'apps@evolvclothing.com');
        $fromName = (string) ($mailCfg['from']['name'] ?? 'Evolv Apps');
        $subject = ($createdBy !== '' ? $createdBy : 'Someone') . ' mentioned you on PO ' . ($po !== '' ? $po : '—');
        $safeComment = nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'));
        $safeBy = htmlspecialchars($createdBy !== '' ? $createdBy : 'Someone', ENT_QUOTES, 'UTF-8');
        $safePo = htmlspecialchars($po !== '' ? $po : '—', ENT_QUOTES, 'UTF-8');
        $safeFrom = htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8');
        $safeWhen = htmlspecialchars(date('d M Y · h:i A'), ENT_QUOTES, 'UTF-8');
        $byInitial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $createdBy !== '' ? $createdBy : 'U') ?: 'U', 0, 1));

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $workbenchPath = function_exists('url')
            ? url('po-costing' . ($po !== '' ? ('?po=' . rawurlencode($po)) : ''))
            : '/sap_reports/po-costing';
        $workbenchUrl = $scheme . '://' . $host . $workbenchPath;
        $safeWorkbenchUrl = htmlspecialchars($workbenchUrl, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(15,23,42,.08);">
        <tr>
          <td style="background:linear-gradient(135deg,#312e81 0%,#4f46e5 55%,#0ea5e9 100%);padding:22px 24px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="color:#fff;">
                  <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;font-weight:700;">Evolv Apps</div>
                  <div style="font-size:20px;font-weight:800;margin-top:4px;letter-spacing:-.02em;">PO mention</div>
                </td>
                <td align="right" style="vertical-align:middle;">
                  <span style="display:inline-block;background:rgba(255,255,255,.18);color:#fff;font-size:12px;font-weight:800;padding:8px 12px;border-radius:999px;">PO {$safePo}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
              <tr>
                <td style="width:42px;height:42px;border-radius:12px;background:#4f46e5;color:#fff;font-weight:800;font-size:16px;text-align:center;vertical-align:middle;">{$byInitial}</td>
                <td style="padding-left:12px;vertical-align:middle;">
                  <div style="font-size:15px;font-weight:700;color:#0f172a;"><strong style="color:#4f46e5;">{$safeBy}</strong> mentioned you</div>
                  <div style="font-size:12px;color:#64748b;margin-top:2px;">{$safeWhen}</div>
                </td>
              </tr>
            </table>

            <div style="font-size:13px;color:#475569;margin:0 0 10px;font-weight:650;">Comment</div>
            <div style="margin:0 0 20px;padding:14px 16px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #4f46e5;font-size:14px;line-height:1.55;color:#0f172a;">
              {$safeComment}
            </div>

            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px;">
              <tr>
                <td style="border-radius:10px;background:#4f46e5;">
                  <a href="{$safeWorkbenchUrl}" style="display:inline-block;padding:12px 18px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:800;">
                    Open PO Costing Workbench →
                  </a>
                </td>
              </tr>
            </table>
            <div style="font-size:12px;color:#94a3b8;margin-top:10px;">Reply from the workbench activity panel on this PO.</div>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 24px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <div style="font-size:11px;color:#94a3b8;line-height:1.5;">
              Sent by <strong style="color:#64748b;">{$safeFrom}</strong> · Evolv Clothing · SAP Reports
            </div>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $mail = (new SimpleMailService($mailCfg))->send($emails, $subject, $body, $fromEmail, $fromName);
        $mail['mentioned'] = $resolved ?: array_values($mentioned);
        $mail['recipients'] = $emails;
        return $mail;
    }

    /*
     * List recent po_validation_log rows for a PO
     */
    public function purchaseOrderValidationLog(): void
    {
        require_once base_path('app/services/PoValidationLogService.php');
        $po = preg_replace('/\D/', '', (string) ($_GET['po'] ?? '')) ?? '';
        $from = trim((string) ($_GET['from'] ?? ''));
        $to = trim((string) ($_GET['to'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 200);
        try {
            $svc = new PoValidationLogService();
            if ($po !== '' && $from === '' && $to === '') {
                $logs = $svc->listForPo($po, max(20, min(200, $limit)));
            } else {
                $logs = $svc->listRecent([
                    'po' => $po,
                    'from' => $from,
                    'to' => $to,
                    'limit' => $limit,
                ]);
            }
            $this->jsonResponse(['success' => true, 'data' => ['logs' => $logs, 'count' => count($logs)]]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => ['logs' => []],
            ], 502);
        }
    }

    /*
     * Purchase order header and items as JSON for the report page and the PO drawer (?po=4000006524 or ?list=1)
     */
    public function purchaseOrderData(): void
    {
        try {
            @ini_set('memory_limit', '512M');
            $service = new SapPurchaseOrderService();
            $po = trim((string) ($_GET['po'] ?? ''));
            $isCostingView = strtolower(trim((string) ($_GET['view'] ?? ''))) === 'costing';

            if ($po !== '') {
                if (!preg_match('/^\d{1,10}$/', $po)) {
                    $message = 'Purchase order must be a number of up to 10 digits.';
                    $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 422);
                }

                $payload = $service->report($po);
                if ($payload['error'] !== null) {
                    $this->jsonResponse([
                        'success' => false,
                        'message' => 'Unable to fetch SAP data: ' . $payload['error'],
                        'error'   => $payload['error'],
                        'data'    => [],
                    ], 502);
                }
                if ($isCostingView && is_file(base_path('app/services/SapCostService.php'))) {
                    require_once base_path('app/services/SapCostService.php');
                    $soOverride = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';
                    $extraSos = $soOverride !== '' ? [$soOverride] : [];
                    $payload = (new SapCostService())->attachToPurchaseOrder($payload, $extraSos);
                    $payload['columns'] = SapCostService::costingColumns();
                }
                $this->jsonResponse([
                    'success' => true,
                    'message' => $payload['records'] === [] ? 'No purchase order found.' : 'Live SAP data',
                    'data'    => $payload,
                ]);
            }

            // List mode (condition-wise)
            $payload = $service->listReport($_GET);
            if ($payload['error'] !== null) {
                $status = str_contains((string) $payload['error'], 'date') ? 422 : 502;
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Unable to fetch SAP data: ' . $payload['error'],
                    'error'   => $payload['error'],
                    'data'    => [],
                ], $status);
            }
            if ($isCostingView && is_file(base_path('app/services/PoValidationStatusService.php'))) {
                require_once base_path('app/services/PoValidationStatusService.php');
                $payload = (new PoValidationStatusService())->enrichListPayload($payload);
                $payload['columns'] = PoValidationStatusService::costingListColumns();
            }
            $this->jsonResponse([
                'success' => true,
                'message' => $payload['records'] === [] ? 'No purchase orders match the selected criteria.' : 'Live SAP data',
                'data'    => $payload,
            ]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Allowed memory size') !== false || stripos($message, 'memory') !== false) {
                $message = 'This date range returns too much data. Narrow the Custom Date From/To range and try again.';
            } else {
                $message = 'Unable to load purchase orders. Please try a shorter date range.';
            }
            $this->jsonResponse([
                'success' => false,
                'message' => $message,
                'error'   => $message,
                'data'    => [],
            ], 500);
        }
    }

    /*
     * JSON API — browser Network tab (Fetch/XHR)
     */
    public function data(string $report = ''): void
    {
        $model = new ReportsModel();
        $catalog = $model->catalog();
        $report = strtolower(trim($report !== '' ? $report : (string) ($_GET['r'] ?? '')));
        if ($report === '' || !isset($catalog[$report])) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unknown report.',
                'error'   => 'Unknown report.',
                'data'    => [],
            ], 404);
            return;
        }

        $year = (int) date('Y');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(10000, max(10, (int) ($_GET['per_page'] ?? 10000)));
        $search = trim((string) ($_GET['q'] ?? ''));
        $salesOrder = trim((string) ($_GET['so'] ?? ''));
        $from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
        $to = trim((string) ($_GET['to'] ?? date('Y-m-d')));

        $plant = strtoupper(trim((string) ($_GET['plant'] ?? '')));

        if ($report === 'production' && (isset($_GET['pairs']) || trim((string) ($_GET['range'] ?? '')) !== '')) {
            $this->productionRangeData();
            return;
        }
        if ($report === 'production') {
            $check = $this->productionPlant(new SapProductionService());
            if ($check['error'] !== null) {
                $this->jsonResponse(['success' => false, 'message' => $check['error'], 'error' => $check['error'], 'data' => []], 422);
            }
        }

        $cfg = config('sap');
        $servicePaths = [
            'trims'       => (string) ($cfg['trims_service'] ?? ''),
            'fabric'      => (string) ($cfg['fabric_service'] ?? ''),
            'production'  => (string) ($cfg['production_service'] ?? ''),
            'procurement' => (string) ($cfg['procurement_service'] ?? ''),
        ];
        $sapPath = $servicePaths[$report] ?? (string) ($cfg['service'] ?? '');

        try {
            $payload = $model->fetch($report, $year, $page, $perPage, $search, $from, $to, true, $salesOrder, $plant);
            $this->jsonResponse([
                'success' => true,
                'message' => 'Live SAP data',
                'data'    => $payload,
                'meta'    => [
                    'source'      => 'SAP',
                    'report'      => $report,
                    'sales_order' => $salesOrder,
                    'sap_path'    => $sapPath,
                    'count'       => (int) ($payload['total'] ?? count($payload['records'] ?? [])),
                ],
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unable to fetch SAP data: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => [],
                'meta'    => [
                    'source'      => 'SAP',
                    'report'      => $report,
                    'sales_order' => $salesOrder,
                    'sap_path'    => $sapPath,
                    'count'       => 0,
                ],
            ], 500);
        }
    }
}

?>
