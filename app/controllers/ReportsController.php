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
            'pageTitle'  => 'SAP Insight',
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
     * Full-page landing (header + footer). PO → workbench; list → bulk validation.
     */
    public function showPoCosting(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view PO Costing Validation.';
            $this->redirect(url('/'));
            return;
        }
        // Ensure PO Costing aside bits (incl. can_search_po) exist before permission checks
        try {
            require_once base_path('app/controllers/UserAccessControlController.php');
            UserAccessControlController::ensureAccessBitColumns();
        } catch (Throwable $e) {
            error_log('showPoCosting ensureAccessBitColumns: ' . $e->getMessage());
        }
        require_once base_path('app/services/SapPurchaseOrderService.php');
        $this->view('reports/po_costing', [
            'pageTitle'   => 'PO eSign',
            'appShell'    => true,
            'layoutWide'  => true,
            'presets'     => SapPurchaseOrderService::rangePresets(),
            'brands'      => SapPurchaseOrderService::brands(),
            'poBaseUrl'             => url('purchase-order'),
            'poEsignUrl'            => sap_reports_evol_url('po_e_signature.php'),
            'passLogUrl'            => url('purchase-order/pass-log'),
            'passLogPageUrl'        => url('po-costing/pass-log'),
            'sentBoxUrl'            => url('purchase-order/sent-box'),
            'pdfUrl'                => url('purchase-order/pdf'),
            'validationHistoryUrl'  => url('po-costing/validation-history'),
            'sendMailUrl'           => url('purchase-order/send-mail'),
            'usersUrl'              => url('purchase-order/users'),
            'workflowUrl'           => url('purchase-order/workflow'),
            'workflowSaveUrl'       => url('purchase-order/workflow/save'),
            'workflowNotifyUrl'     => url('purchase-order/workflow/notify'),
            'workflowDecideUrl'     => url('purchase-order/workflow/decide'),
            'workflowAckUrl'        => url('purchase-order/workflow/ack'),
            'home'                  => sap_reports_evol_url('portal_dashboard.php'),
            'catalogHome'           => url('/'),
            'logoUrl'               => sap_reports_logo_url(),
            'canView'               => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'view')
                : true,
            'canPassPo'             => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'approve')
                : true,
            'canAssign'             => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'assign')
                : true,
            'canValidate'           => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'validate')
                : true,
            'canEsign'              => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'esign')
                : true,
            'canWorkbench'          => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'workbench')
                : true,
            'canReceivePage'        => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'receive')
                : true,
            'canSentPage'           => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'sent')
                : true,
            'canWorkflowPage'       => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'workflow')
                : true,
            'canApprovedPage'       => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'approved')
                : true,
            'canRelatedPage'        => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'related')
                : true,
            'canFindPoPage'         => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'find_po')
                : true,
            'canSearchPoPage'       => function_exists('sap_reports_user_can')
                ? sap_reports_user_can('po-costing', 'search_po')
                : true,
            'relatedPoDataUrl'      => url('po-costing/related-po'),
            'relatedPoStatusUrl'    => url('po-costing/related-po/status'),
            'relatedPoSentLogUrl'   => url('po-costing/related-po/sent-log'),
            'searchPoDataUrl'       => url('po-costing/search-po'),
            'extraScripts'          => '',
        ]);
    }

    /*
     * Search PO — Z_QuotationZCostCom by PurchaseOrder across NOTAPPROVED / DRAFT / APPROVED
     * GET po-costing/search-po?po=4000008618
     */
    public function searchPoData(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing')) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted.',
                'error'   => 'Access restricted.',
                'data'    => [],
            ], 403);
            return;
        }
        if (function_exists('sap_reports_user_can') && !sap_reports_user_can('po-costing', 'search_po')) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted: Search PO permission required.',
                'error'   => 'Access restricted: Search PO permission required.',
                'data'    => [],
            ], 403);
            return;
        }
        if (!is_file(base_path('app/services/PoCostValidationService.php'))) {
            $message = 'PO costing validation is not deployed on this server.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 503);
            return;
        }
        require_once base_path('app/services/PoCostValidationService.php');
        try {
            $payload = (new PoCostValidationService())->searchPurchaseOrder($_GET);
            if (!empty($payload['error']) && ($payload['records'] ?? []) === []) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => (string) $payload['error'],
                    'error'   => (string) $payload['error'],
                    'data'    => $payload,
                ], str_contains((string) $payload['error'], 'Enter a valid') ? 422 : 502);
                return;
            }
            $this->jsonResponse([
                'success' => true,
                'message' => ($payload['records'] ?? []) === []
                    ? 'No purchase order found for this number.'
                    : 'Search PO data loaded',
                'data'    => $payload,
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unable to search purchase order. Please try again.',
                'error'   => $e->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /*
     * Related PO — Z_QuotationZCostCom NotApproved list for PO Costing sidebar panel
     * GET po-costing/related-po
     */
    public function relatedPoData(): void
    {
        if (
            (function_exists('sap_reports_user_can') && !sap_reports_user_can('po-costing', 'related'))
            || (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing'))
        ) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted.',
                'error'   => 'Access restricted.',
                'data'    => [],
            ], 403);
            return;
        }
        if (!is_file(base_path('app/services/PoCostValidationService.php'))) {
            $message = 'PO costing validation is not deployed on this server.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 503);
            return;
        }
        require_once base_path('app/services/PoCostValidationService.php');
        try {
            $payload = (new PoCostValidationService())->listRelatedPurchaseOrders($_GET);
            if (!empty($payload['error'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Unable to fetch Related POs: ' . $payload['error'],
                    'error'   => $payload['error'],
                    'data'    => $payload,
                ], 502);
                return;
            }

            $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];
            $poNumbers = [];
            foreach ($records as $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $poNumbers[] = (string) ($rec['purchase_order'] ?? '');
            }
            if ($records !== [] && is_file(base_path('app/services/PoReleaseLogService.php'))) {
                require_once base_path('app/services/PoReleaseLogService.php');
                $latest = (new PoReleaseLogService())->latestStatusesForPos($poNumbers);
                foreach ($records as &$rec) {
                    if (!is_array($rec)) {
                        continue;
                    }
                    $poKey = ltrim(preg_replace('/\D/', '', (string) ($rec['purchase_order'] ?? '')) ?? '', '0');
                    $info = $latest[$poKey] ?? null;
                    $rec['status_update'] = is_array($info) ? (string) ($info['status'] ?? '') : '';
                    $rec['status_updated_by'] = is_array($info) ? (string) ($info['created_by'] ?? '') : '';
                    $rec['status_updated_on'] = is_array($info) ? (string) ($info['created_on'] ?? '') : '';
                    $rec['status_remarks'] = is_array($info) ? (string) ($info['remarks'] ?? '') : '';
                }
                unset($rec);
            }
            if ($records !== [] && is_file(base_path('app/services/PoSentLogService.php'))) {
                require_once base_path('app/services/PoSentLogService.php');
                $sentMap = (new PoSentLogService())->latestForPurchaseOrders($poNumbers);
                foreach ($records as &$rec) {
                    if (!is_array($rec)) {
                        continue;
                    }
                    $poKey = ltrim(preg_replace('/\D/', '', (string) ($rec['purchase_order'] ?? '')) ?? '', '0');
                    $mail = $sentMap[$poKey] ?? null;
                    $rec['sent_receive_status'] = is_array($mail) ? (string) ($mail['sent_receive_status'] ?? '') : '';
                    $rec['mail_from'] = is_array($mail) ? (string) ($mail['from_username'] ?? '') : '';
                    $rec['mail_to'] = is_array($mail) ? (string) ($mail['to_username'] ?? '') : '';
                    $rec['mail_email'] = is_array($mail) ? (string) ($mail['to_email'] ?? '') : '';
                    $rec['mail_status'] = is_array($mail) ? (string) ($mail['status'] ?? '') : '';
                    $rec['mail_at'] = is_array($mail) ? (string) ($mail['created_at'] ?? '') : '';
                    $rec['mail_remarks'] = is_array($mail) ? (string) ($mail['remarks'] ?? '') : '';
                    $rec['mail_note'] = $rec['mail_remarks'];
                }
                unset($rec);
            }
            $payload['records'] = $records;

            $this->jsonResponse([
                'success' => true,
                'message' => ($payload['records'] ?? []) === []
                    ? 'No related purchase orders found.'
                    : 'Related PO data loaded',
                'data'    => $payload,
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unable to load Related POs. Please try again.',
                'error'   => $e->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /*
     * POST po-costing/related-po/status — save Related / Passed / Moved into dbo.po_release_log
     */
    public function relatedPoStatusSave(): void
    {
        $canStatus = !function_exists('sap_reports_user_can')
            || sap_reports_user_can('po-costing', 'related')
            || sap_reports_user_can('po-costing', 'esign');
        if (
            !$canStatus
            || (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing'))
        ) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted.',
                'error'   => 'Access restricted.',
                'data'    => [],
            ], 403);
            return;
        }
        if (!is_file(base_path('app/services/PoReleaseLogService.php'))) {
            $message = 'PO release log service is not deployed on this server.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 503);
            return;
        }
        require_once base_path('app/services/PoReleaseLogService.php');

        $raw = function_exists('sap_reports_request_raw_body')
            ? sap_reports_request_raw_body()
            : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = is_array($_POST) ? $_POST : [];
        }

        $po = (string) ($input['po'] ?? $input['purchase_order'] ?? $input['po_number'] ?? '');
        $status = (string) ($input['status'] ?? $input['status_update'] ?? '');
        $remarks = (string) ($input['remarks'] ?? '');
        $current = function_exists('portal_user') ? portal_user() : null;
        $createdBy = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? 'system')));

        $res = (new PoReleaseLogService())->save($po, $status, $createdBy, $remarks);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Could not save status update'),
                'error'   => (string) ($res['error'] ?? 'Could not save status update'),
                'data'    => $res,
            ], 422);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Status update saved',
            'data'    => [
                'id'             => $res['id'],
                'purchase_order' => preg_replace('/\D/', '', $po),
                'status'         => $res['status'] ?? PoReleaseLogService::normalizeStatus($status),
                'remarks'        => substr(trim($remarks), 0, 500),
                'created_by'     => $createdBy,
            ],
        ]);
    }

    /*
     * GET po-costing/related-po/status?po=… — latest Draft/Review/Released + remarks
     */
    public function relatedPoStatusGet(): void
    {
        $canStatus = !function_exists('sap_reports_user_can')
            || sap_reports_user_can('po-costing', 'related')
            || sap_reports_user_can('po-costing', 'esign');
        if (
            !$canStatus
            || (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing'))
        ) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted.',
                'error'   => 'Access restricted.',
                'data'    => [],
            ], 403);
            return;
        }
        if (!is_file(base_path('app/services/PoReleaseLogService.php'))) {
            $message = 'PO release log service is not deployed on this server.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 503);
            return;
        }
        require_once base_path('app/services/PoReleaseLogService.php');

        $po = (string) ($_GET['po'] ?? $_GET['purchase_order'] ?? $_GET['po_number'] ?? '');
        $digits = preg_replace('/\D/', '', $po) ?? '';
        if ($digits === '' || !preg_match('/^\d{1,10}$/', ltrim($digits, '0') ?: '0')) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid purchase order.',
                'error'   => 'Invalid purchase order.',
                'data'    => [],
            ], 422);
            return;
        }

        $latest = (new PoReleaseLogService())->latestForPo($digits);
        $this->jsonResponse([
            'success' => true,
            'message' => $latest ? 'Status loaded' : 'No status update yet',
            'data'    => [
                'purchase_order' => ltrim($digits, '0') ?: $digits,
                'status'         => is_array($latest) ? (string) ($latest['status'] ?? '') : '',
                'remarks'        => is_array($latest) ? (string) ($latest['remarks'] ?? '') : '',
                'created_by'     => is_array($latest) ? (string) ($latest['created_by'] ?? '') : '',
                'created_on'     => is_array($latest) ? ($latest['created_on'] ?? null) : null,
            ],
        ]);
    }

    /*
     * GET po-costing/related-po/sent-log?po=… — Sent / Receive log history for one PO
     */
    public function relatedPoSentLogGet(): void
    {
        $canLog = !function_exists('sap_reports_user_can')
            || sap_reports_user_can('po-costing', 'related')
            || sap_reports_user_can('po-costing', 'sent')
            || sap_reports_user_can('po-costing', 'receive');
        if (
            !$canLog
            || (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('po-costing'))
        ) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Access restricted.',
                'error'   => 'Access restricted.',
                'data'    => ['rows' => []],
            ], 403);
            return;
        }
        if (!is_file(base_path('app/services/PoSentLogService.php'))) {
            $message = 'Sent log service is not deployed on this server.';
            $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => ['rows' => []]], 503);
            return;
        }
        require_once base_path('app/services/PoSentLogService.php');

        $po = (string) ($_GET['po'] ?? $_GET['purchase_order'] ?? '');
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $norm = ltrim($digits, '0');
        if ($norm === '' || !preg_match('/^\d{1,10}$/', $norm)) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid purchase order.',
                'error'   => 'Invalid purchase order.',
                'data'    => ['rows' => []],
            ], 422);
            return;
        }

        $rows = (new PoSentLogService())->historyForPurchaseOrder($norm, (int) ($_GET['limit'] ?? 40));
        $this->jsonResponse([
            'success' => true,
            'message' => $rows === [] ? 'No sent / receive log yet' : 'Sent / receive log loaded',
            'data'    => [
                'purchase_order' => $norm,
                'rows'           => $rows,
                'count'          => count($rows),
            ],
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
        // Workbench (PO detail costing view):
        // - Workbench right, or
        // - Search PO right (Search PO page opens a PO by number), or
        // - eSign-only when ?open=esign
        $openMode = strtolower(trim((string) ($_GET['open'] ?? '')));
        $fromPanel = strtolower(trim((string) ($_GET['from'] ?? '')));
        $canOpenWorkbench = !function_exists('sap_reports_user_can')
            || sap_reports_user_can('po-costing', 'workbench')
            || sap_reports_user_can('po-costing', 'search_po')
            || (
                $openMode === 'esign'
                && sap_reports_user_can('po-costing', 'esign')
            );
        if ($isCosting && $mode === 'detail' && !$canOpenWorkbench) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have Workbench / Search PO permission for PO Costing Validation.';
            // Prefer returning to Search PO when that is where the user came from
            if ($fromPanel === 'search') {
                $this->redirect(url('po-costing') . '?panel=search');
            } else {
                $this->redirect(url('purchase-order') . '?view=costing');
            }
            return;
        }

        $service = new SapPurchaseOrderService();
        $viewMode = strtolower(trim((string) ($_GET['view'] ?? '')));
        $isCostingView = $viewMode === 'costing';
        $rangePreset = $isCostingView
            ? 'all'
            : strtolower(trim((string) ($_GET['range'] ?? 'today')));
        $range = SapPurchaseOrderService::resolveRange(
            $rangePreset,
            $isCostingView ? '' : (string) ($_GET['from'] ?? ''),
            $isCostingView ? '' : (string) ($_GET['to'] ?? '')
        );
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
        $brands = SapPurchaseOrderService::normalizeBrands($_GET['brand'] ?? $_GET['brand_name'] ?? '');
        $brand = implode(',', $brands);
        $approvedStatus = trim((string) ($_GET['approved_status'] ?? $_GET['APPROVEDSTATUS'] ?? ''));
        if (!in_array($approvedStatus, ['Approved', 'NotApproved'], true)) {
            // Costing list defaults to Z_QuotationZCostCom ApprovedStatus eq 'NotApproved'
            $approvedStatus = $isCostingView ? 'NotApproved' : '';
        }

        $listColumns = SapPurchaseOrderService::listColumns();
        $detailColumns = SapPurchaseOrderService::columns();
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
                if ($isCostingView) {
                    require_once base_path('app/services/PoCostValidationService.php');
                    $listData = (new PoCostValidationService())->listNotApprovedPurchaseOrders([
                        'mat_cat' => $matCat,
                        'brand'   => $brand,
                        'po'      => $input,
                        'approved_status' => 'NotApproved',
                        'page_size' => 5000,
                        'page' => 1,
                    ]);
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
                        'brand'    => $brand,
                    ]);
                }
                $this->sendPurchaseOrderListCsv($listData['records'] ?? []);
            }
            return;
        }

        $listTitle = $isCostingView ? 'PO eSign' : 'Purchase Order Report';
        $detailTitle = $isCostingView
            ? ('PO eSign ' . $input)
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
            'brand'         => $brand,
            'brands'        => SapPurchaseOrderService::brands(),
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
            $sapMsg = trim((string) ($res['message'] ?? $res['error'] ?? ''));
            $sapType = strtoupper(substr(trim((string) ($res['message_type'] ?? '')), 0, 1));
            if (empty($res['success'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => $sapMsg !== '' ? $sapMsg : 'SAP Approve API failed',
                    'error'   => $sapMsg !== '' ? $sapMsg : 'SAP Approve API failed',
                    'status'  => 'NotApproved',
                    'message_type' => $sapType !== '' ? $sapType : 'E',
                    'data'    => array_merge(is_array($res) ? $res : [], [
                        'message' => $sapMsg,
                        'message_type' => $sapType !== '' ? $sapType : 'E',
                        'sap_approved' => false,
                        'local_status' => 'NotApproved',
                    ]),
                ], 502);
            }

            // MessageType S → mail Workflow Approver Assignment users with PDF + Approved status
            $current = function_exists('portal_user') ? portal_user() : null;
            $wfUser = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? 'system')));
            $approverMail = $this->notifyWorkflowApproversOnSapApprove((string) ($res['po'] ?? $po), $wfUser);

            $mailSent = (int) ($approverMail['sent'] ?? 0);
            $mailFailed = (int) ($approverMail['failed'] ?? 0);
            $msg = $sapMsg !== '' ? $sapMsg : 'SAP approved PO (MessageType = S).';
            if ($mailSent > 0) {
                $msg .= ' PDF mailed to ' . $mailSent . ' Approver' . ($mailSent === 1 ? '' : 's') . '.';
            } elseif (!empty($approverMail['error'])) {
                $msg .= ' Approver mail skipped: ' . (string) $approverMail['error'];
            }

            $this->jsonResponse([
                'success' => true,
                'message' => $msg,
                'message_type' => $sapType !== '' ? $sapType : 'S',
                'status'  => 'Approved',
                'warning' => $mailFailed > 0
                    ? ('Approver mail failures: ' . implode('; ', $approverMail['errors'] ?? []))
                    : null,
                'data'    => [
                    'request_id'   => $res['request_id'],
                    'po'           => $res['po'],
                    'value'        => 'X',
                    'action'       => 'approve',
                    'approve_only' => true,
                    'sap_approved' => true,
                    'message'      => $sapMsg !== '' ? $sapMsg : $msg,
                    'message_type' => $sapType !== '' ? $sapType : 'S',
                    'local_status' => 'Approved',
                    'payload'      => $res['payload'] ?? null,
                    'approval'     => $res['approval'] ?? null,
                    'approver_mail'=> $approverMail,
                ],
            ]);
        }

        $res = $svc->approveAndFetchPdf($po, $requestId !== '' ? $requestId : null, $valueFlag);
        $sapMsg = trim((string) ($res['message'] ?? $res['error'] ?? ''));
        $sapType = strtoupper(substr(trim((string) ($res['message_type'] ?? '')), 0, 1));
        if (empty($res['success'])) {
            $failMsg = $sapMsg !== ''
                ? $sapMsg
                : ($valueFlag === 'X' ? 'PASS PO failed' : 'REVERT PO failed');
            $this->jsonResponse([
                'success' => false,
                'message' => $failMsg,
                'error'   => $failMsg,
                'message_type' => $sapType !== '' ? $sapType : 'E',
                'status'  => $valueFlag === 'X' ? 'NotApproved' : 'NotApproved',
                'data'    => array_merge(is_array($res) ? $res : [], [
                    'message' => $failMsg,
                    'message_type' => $sapType !== '' ? $sapType : 'E',
                ]),
            ], 502);
        }

        if ($valueFlag !== 'X') {
            $this->jsonResponse([
                'success' => true,
                'message' => $sapMsg !== '' ? $sapMsg : 'PO reverted (Value = blank)',
                'message_type' => $sapType !== '' ? $sapType : 'S',
                'status'  => 'NotApproved',
                'data'    => [
                    'request_id' => $res['request_id'],
                    'po'         => $res['po'],
                    'value'      => ' ',
                    'action'     => 'revert',
                    'message'    => $sapMsg !== '' ? $sapMsg : 'PO reverted (Value = blank)',
                    'message_type' => $sapType !== '' ? $sapType : 'S',
                    'folder'     => null,
                ],
            ]);
        }

        // Browser preview must use the routed PDF proxy (static /upload/... hits the app router → 404)
        $previewUrl = url('purchase-order/pdf') . '?po=' . rawurlencode((string) $res['po']);
        $current = function_exists('portal_user') ? portal_user() : null;
        $wfUser = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? 'system')));
        $wfApply = $this->applyGlobalWorkflowOnPoEnter((string) $res['po'], $wfUser);
        $this->jsonResponse([
            'success' => true,
            'message' => $sapMsg !== '' ? $sapMsg : 'PO approved (MessageType = S).',
            'message_type' => $sapType !== '' ? $sapType : 'S',
            'status'  => 'Approved',
            'data'    => [
                'request_id'  => $res['request_id'],
                'po'          => $res['po'],
                'value'       => 'X',
                'action'      => 'pass',
                'message'     => $sapMsg !== '' ? $sapMsg : 'PO approved (MessageType = S).',
                'message_type'=> $sapType !== '' ? $sapType : 'S',
                'pdf_url'     => $previewUrl,
                'preview_url' => $previewUrl,
                'saved_path'  => null,
                'folder'      => null,
                'workflow'    => $wfApply,
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
            'message' => !empty($res['found'])
                ? (!empty($res['file'])
                    ? ('Signature loaded from Esign/' . $res['file'])
                    : ('Signature found' . (!empty($res['source']) ? ' (' . $res['source'] . ')' : '')))
                : 'No saved signature',
            'data'    => [
                'found' => !empty($res['found']),
                'username' => $res['username'],
                'signature_data' => $res['signature_data'],
                'source' => $res['source'] ?? null,
                'file' => $res['file'] ?? null,
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
                'signed_at_mumbai' => $res['signed_at_mumbai'] ?? null,
                'status' => $res['status'] ?? 'E-Sign Completed',
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
            'success' => (string) ($_GET['success'] ?? 'all'),
            // Approved PO page passes approved_only=1 → success only + status = Approved
            'approved_only' => (string) ($_GET['approved_only'] ?? ''),
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
        require_once base_path('app/services/PoPassApprovalService.php');
        $passSvc = new PoPassApprovalService();
        $file = (new PassPoValidationLogService())->resolveSignedPdf($display);
        $path = (string) ($file['path'] ?? $file['network_path'] ?? '');
        // File alone is not enough — require DB e-sign completed (or legacy .signed / *_signed.pdf)
        $dbOk = $passSvc->isEsignCompletedInDb($display);
        $marker = $path !== ''
            ? (dirname($path) . DIRECTORY_SEPARATOR . ('PO-' . $display . '.signed'))
            : '';
        $nameLooksSigned = str_contains(strtolower((string) ($file['pdf_file'] ?? basename($path))), 'signed');
        $markerOk = ($marker !== '' && @is_file($marker)) || $nameLooksSigned;
        if ($path === '' || empty($file['has_file']) || !($dbOk || $markerOk)) {
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
     * Stream PO PDF (no unsigned auto-save):
     *  1) Verified signed PDF under EVOL/uploads/po_esign/PO_New_Module/PO-{po}.pdf (DB + file)
     *  2) Else SAP POPDFSet → stream in memory (never write unsigned PDF to disk)
     * GET purchase-order/pdf?po=1000000543
     * GET purchase-order/pdf?po=…&ensure=1  → JSON status only (does not cache)
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
        $res = $svc->resolvePoPdfForView($po);
        $ensureOnly = isset($_GET['ensure']) && (string) $_GET['ensure'] !== '' && (string) $_GET['ensure'] !== '0';

        if (empty($res['success'])) {
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
                    : 'SAP PDF available (not saved to server)',
                'data' => [
                    'po' => $svc->displayPoNumber($po),
                    'pdf_file' => $res['pdf_file'] ?? null,
                    'is_signed' => !empty($res['is_signed']),
                    'source' => $res['source'] ?? null,
                    'fetched_sap' => !empty($res['fetched_sap']),
                    'saved' => false,
                ],
            ]);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $name = (string) ($res['pdf_file'] ?? ('PO-' . $svc->displayPoNumber($po) . '.pdf'));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Cache-Control: private, max-age=60');
        header('X-PO-PDF-Source: ' . (string) ($res['source'] ?? 'sap'));
        header('X-PO-PDF-Signed: ' . (!empty($res['is_signed']) ? '1' : '0'));

        $path = (string) ($res['path'] ?? '');
        if ($path !== '' && @is_file($path)) {
            $size = @filesize($path);
            if ($size !== false) {
                header('Content-Length: ' . (string) $size);
            }
            readfile($path);
            exit;
        }

        $body = (string) ($res['body'] ?? '');
        header('Content-Length: ' . (string) strlen($body));
        echo $body;
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
            // Re-apply saved Manual Map Costing so refresh / other users keep the mapping
            if (!empty($payload['records']) && is_array($payload['records'])) {
                require_once base_path('app/services/PoManualCostingMapService.php');
                $mapSvc = new PoManualCostingMapService();
                $payload['records'] = $mapSvc->applyToRecords($po, $payload['records']);
                $payload['summary'] = $this->recountPoCostSummary($payload['records'], $payload['summary'] ?? []);
                $payload['manual_mappings'] = $mapSvc->listForPo($po);
            } else {
                $payload['manual_mappings'] = [];
            }
            // APPROVEDSTATUS from Z_QuotationZCostCom only (no local Pass log override)
            $sapApproved = trim((string) ($payload['approved_status'] ?? $payload['header']['approved_status'] ?? ''));
            if ($sapApproved !== 'Approved' && $sapApproved !== 'NotApproved') {
                $sapApproved = 'NotApproved';
            }
            $payload['approved_status'] = $sapApproved;
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
     * POST purchase-order/send-mail — Sent button: remarks + username → auto email → send mail
     */
    public function purchaseOrderSendMail(): void
    {
        try {
            require_once base_path('app/services/PoValidationLogService.php');
            require_once base_path('app/services/SimpleMailService.php');

            $raw = function_exists('sap_reports_request_raw_body')
                ? sap_reports_request_raw_body()
                : ((string) (file_get_contents('php://input') ?: ''));
            $input = json_decode($raw, true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $po = preg_replace('/\D/', '', (string) ($input['po'] ?? $input['purchase_order'] ?? '')) ?? '';
            $displayPo = ltrim($po, '0');
            if ($displayPo === '') {
                $displayPo = $po;
            }
            $username = trim((string) ($input['username'] ?? $input['assigned_username'] ?? ''));
            $email = trim((string) ($input['email'] ?? $input['assigned_email'] ?? ''));
            $remarks = trim((string) ($input['note'] ?? $input['remarks'] ?? $input['description'] ?? ''));
            $sendStatus = trim((string) ($input['status'] ?? $input['send_status'] ?? 'Sent'));
            $userId = trim((string) ($input['user_id'] ?? $input['assigned_user_id'] ?? ''));
            if ($sendStatus === '') {
                $sendStatus = 'Sent';
            }

            if ($displayPo === '' || !preg_match('/^\d{1,10}$/', $po)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Purchase order must be a number of up to 10 digits.',
                    'error'   => 'Invalid PO',
                    'data'    => null,
                ], 422);
            }
            if ($username === '') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Select a username.',
                    'error'   => 'Username required',
                    'data'    => null,
                ], 422);
            }
            if ($sendStatus === '') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Enter a status.',
                    'error'   => 'Status required',
                    'data'    => null,
                ], 422);
            }
            if ($remarks === '') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Note is required.',
                    'error'   => 'Note required',
                    'data'    => null,
                ], 422);
            }

            $current = function_exists('portal_user') ? portal_user() : null;
            $createdBy = (string) ($current['username'] ?? ($_SESSION['username'] ?? 'system'));

            $svc = new PoValidationLogService();
            $mailCfg = function_exists('config') ? config('mail') : [];
            $fallbackDomain = trim((string) ($mailCfg['fallback_domain'] ?? 'evolvclothing.com'));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $found = $svc->findUsersByUsernames([$username]);
                $u = $found[0] ?? null;
                $email = trim((string) ($u['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower($username) . '@' . $fallbackDomain;
                }
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'No valid email for user ' . $username,
                    'error'   => 'Invalid email',
                    'data'    => ['username' => $username],
                ], 422);
            }

            $desc = 'Sent to ' . $username . ' [' . $sendStatus . ']: ' . $remarks;
            $logRes = $svc->save([
                'purchase_order' => $displayPo,
                'po_item' => '',
                'material' => '',
                'mat_desc' => '',
                'matched_zcost_item' => '',
                'matched_maktx' => '',
                'row_status' => $sendStatus,
                'assigned_user_id' => $userId,
                'assigned_username' => $username,
                'assigned_email' => $email,
                'validation_status' => PoValidationLogService::STATUS_COMMENT,
                'description' => $desc,
                'created_by' => $createdBy,
            ]);

            // Inbox / outbox for Receive PO + Sent PO portals
            require_once base_path('app/services/PoSentLogService.php');
            $sentLog = (new PoSentLogService())->save([
                'purchase_order' => $displayPo,
                'from_username' => $createdBy,
                'to_username' => $username,
                'to_email' => $email,
                'remarks' => $remarks,
                'status' => $sendStatus,
            ]);

            $fromEmail = (string) ($mailCfg['from']['address'] ?? 'apps@evolvclothing.com');
            $fromName = (string) ($mailCfg['from']['name'] ?? 'Evolv Apps');
            $subject = ($createdBy !== '' ? $createdBy : 'Someone') . ' sent PO ' . $displayPo . ' to you';

            $safeBy = htmlspecialchars($createdBy !== '' ? $createdBy : 'Someone', ENT_QUOTES, 'UTF-8');
            $safePo = htmlspecialchars($displayPo, ENT_QUOTES, 'UTF-8');
            $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $safeRemarks = nl2br(htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8'));
            $safeStatus = htmlspecialchars($sendStatus, ENT_QUOTES, 'UTF-8');
            $safeFrom = htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8');
            $safeWhen = htmlspecialchars(date('d M Y · h:i A'), ENT_QUOTES, 'UTF-8');
            $byInitial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $createdBy !== '' ? $createdBy : 'U') ?: 'U', 0, 1));

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $workbenchPath = function_exists('url')
                ? url('purchase-order?view=costing&po=' . rawurlencode($displayPo))
                : '/sap_reports/purchase-order?view=costing&po=' . rawurlencode($displayPo);
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
          <td style="background:linear-gradient(135deg,#0f766e 0%,#0e7490 55%,#1d4ed8 100%);padding:22px 24px;">
            <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;font-weight:700;color:#fff;">Evolv Apps</div>
            <div style="font-size:20px;font-weight:800;margin-top:4px;letter-spacing:-.02em;color:#fff;">PO sent to you</div>
            <div style="margin-top:10px;display:inline-block;background:rgba(255,255,255,.18);color:#fff;font-size:12px;font-weight:800;padding:8px 12px;border-radius:999px;">PO {$safePo}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:6px;">
              <strong style="color:#0f766e;">{$safeBy}</strong> sent PO {$safePo} to <strong>{$safeUser}</strong>
            </div>
            <div style="font-size:12px;color:#64748b;margin-bottom:16px;">{$safeWhen}</div>
            <div style="font-size:13px;color:#475569;margin:0 0 8px;font-weight:650;">Status</div>
            <div style="margin:0 0 14px;display:inline-block;padding:6px 12px;border-radius:999px;background:#ccfbf1;color:#0f766e;font-size:12px;font-weight:800;">{$safeStatus}</div>
            <div style="font-size:13px;color:#475569;margin:0 0 8px;font-weight:650;">Note</div>
            <div style="margin:0 0 20px;padding:14px 16px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0f766e;font-size:14px;line-height:1.55;">
              {$safeRemarks}
            </div>
            <a href="{$safeWorkbenchUrl}" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#0f766e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:800;">
              Open PO Costing Workbench →
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 24px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <div style="font-size:11px;color:#94a3b8;">Sent by <strong style="color:#64748b;">{$safeFrom}</strong> · Evolv Clothing · SAP Insight</div>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

            $mail = (new SimpleMailService($mailCfg))->send([$email], $subject, $body, $fromEmail, $fromName);
            $sent = (int) ($mail['sent'] ?? 0);
            $failed = (int) ($mail['failed'] ?? 0);
            $errors = is_array($mail['errors'] ?? null) ? $mail['errors'] : [];
            $inboxOk = !empty($sentLog['id']) || !empty($logRes['id']);

            // PO enters costing workflow → apply global Viewers/Approvers + auto-notify
            $wfApply = $this->applyGlobalWorkflowOnPoEnter($displayPo, $createdBy);

            if ($sent < 1) {
                $mailError = (string) ($errors[0] ?? 'Mail could not be sent');
                // Inbox / Sent PO already saved — don't fail the whole Send PO action when
                // Office 365 TLS is blocked on the local network (common on Wi‑Fi / MAMP).
                if ($inboxOk) {
                    $this->jsonResponse([
                        'success' => true,
                        'message' => 'Saved to Receive PO for ' . $username
                            . ', but email was not delivered: ' . $mailError,
                        'warning' => $mailError,
                        'email_sent' => false,
                        'data'    => [
                            'po' => $displayPo,
                            'username' => $username,
                            'email' => $email,
                            'log_id' => $logRes['id'] ?? null,
                            'sent_log_id' => $sentLog['id'] ?? null,
                            'inbox' => [
                                'from' => $createdBy,
                                'to' => $username,
                                'received_for' => $username,
                                'sent_for' => $createdBy,
                            ],
                            'mail' => $mail,
                            'workflow' => $wfApply,
                        ],
                    ]);
                }
                $this->jsonResponse([
                    'success' => false,
                    'message' => $mailError,
                    'error'   => $mailError,
                    'data'    => [
                        'po' => $displayPo,
                        'username' => $username,
                        'email' => $email,
                        'log_id' => $logRes['id'] ?? null,
                        'mail' => $mail,
                        'workflow' => $wfApply,
                    ],
                ], 502);
            }

            $this->jsonResponse([
                'success' => true,
                'message' => 'Mail sent to ' . $username . ' (' . $email . ')',
                'email_sent' => true,
                'data'    => [
                    'po' => $displayPo,
                    'username' => $username,
                    'email' => $email,
                    'log_id' => $logRes['id'] ?? null,
                    'sent_log_id' => $sentLog['id'] ?? null,
                    'inbox' => [
                        'from' => $createdBy,
                        'to' => $username,
                        'received_for' => $username,
                        'sent_for' => $createdBy,
                    ],
                    'mail' => [
                        'sent' => $sent,
                        'failed' => $failed,
                        'errors' => $errors,
                        'from' => $mail['from'] ?? $fromEmail,
                        'recipients' => [$email],
                    ],
                    'workflow' => $wfApply,
                ],
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Send mail failed: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }

    /*
     * GET purchase-order/workflow
     * - No po → global (common) Viewer / Approver configuration
     * - ?po= → per-PO eSign / approval status rows
     */
    public function purchaseOrderWorkflow(): void
    {
        require_once base_path('app/services/PoWorkflowAssignmentService.php');
        $po = preg_replace('/\D/', '', (string) ($_GET['po'] ?? $_GET['purchase_order'] ?? '')) ?? '';
        $current = function_exists('portal_user') ? portal_user() : null;
        $username = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? '')));
        $svc = new PoWorkflowAssignmentService();

        if ($po === '') {
            $res = $svc->getGlobalConfig();
            if (empty($res['success'])) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => (string) ($res['error'] ?? 'Could not load workflow configuration'),
                    'error'   => (string) ($res['error'] ?? 'Could not load workflow configuration'),
                    'data'    => [
                        'is_global' => true,
                        'viewers' => [],
                        'approvers' => [],
                        'summary' => [],
                    ],
                ], 500);
            }
            $this->jsonResponse([
                'success' => true,
                'message' => 'Global workflow configuration',
                'data'    => [
                    'is_global' => true,
                    'viewers' => $res['viewers'],
                    'approvers' => $res['approvers'],
                    'summary' => $res['summary'],
                    'current_user' => $username,
                ],
            ]);
        }

        $res = $svc->listForPo($po, $username);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Could not load workflow'),
                'error'   => (string) ($res['error'] ?? 'Could not load workflow'),
                'data'    => [
                    'viewers' => [],
                    'approvers' => [],
                    'summary' => [],
                ],
            ], 500);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => 'Workflow assignment',
            'data'    => [
                'is_global' => false,
                'po' => PoWorkflowAssignmentService::displayPo($po),
                'viewers' => $res['viewers'],
                'approvers' => $res['approvers'],
                'summary' => $res['summary'],
                'current_user_approver' => $res['current_user_approver'],
                'current_user' => $username,
            ],
        ]);
    }

    /*
     * POST purchase-order/workflow/save
     * Save global Viewer + Approver configuration (no emails).
     */
    public function purchaseOrderWorkflowSave(): void
    {
        require_once base_path('app/services/PoWorkflowAssignmentService.php');
        $raw = function_exists('sap_reports_request_raw_body')
            ? sap_reports_request_raw_body()
            : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        $viewers = is_array($input['viewers'] ?? null) ? $input['viewers'] : [];
        $approvers = is_array($input['approvers'] ?? null) ? $input['approvers'] : [];
        $current = function_exists('portal_user') ? portal_user() : null;
        $assignedBy = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? 'system')));

        if (function_exists('sap_reports_user_can') && !sap_reports_user_can('po-costing', 'workflow')) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'You do not have Workflow page access for PO Costing.',
                'error'   => 'Forbidden',
                'data'    => null,
            ], 403);
        }

        $svc = new PoWorkflowAssignmentService();
        $res = $svc->saveGlobalConfig($viewers, $approvers, $assignedBy);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Save failed'),
                'error'   => (string) ($res['error'] ?? 'Save failed'),
                'data'    => $res,
            ], 422);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => 'Workflow configuration saved. Emails will send automatically when a PO enters the flow.',
            'data'    => [
                'is_global' => true,
                'viewers' => $res['viewers'],
                'approvers' => $res['approvers'],
                'summary' => $res['summary'],
            ],
        ]);
    }

    /*
     * POST purchase-order/workflow/notify
     * Email assigned Viewers / Approvers (skip already emailed unless force=1)
     */
    public function purchaseOrderWorkflowNotify(): void
    {
        require_once base_path('app/services/PoWorkflowAssignmentService.php');
        $raw = function_exists('sap_reports_request_raw_body')
            ? sap_reports_request_raw_body()
            : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        $po = preg_replace('/\D/', '', (string) ($input['po'] ?? $input['purchase_order'] ?? '')) ?? '';
        $roles = $input['roles'] ?? null;
        if (!is_array($roles)) {
            $roles = null;
        }
        $force = !empty($input['force']);
        $current = function_exists('portal_user') ? portal_user() : null;
        $fromUser = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? 'system')));

        if (function_exists('sap_reports_user_can') && !sap_reports_user_can('po-costing', 'assign')) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'You do not have assign access for PO Costing.',
                'error'   => 'Forbidden',
                'data'    => null,
            ], 403);
        }

        $svc = new PoWorkflowAssignmentService();
        $res = $svc->notify($po, $roles, $fromUser, $force);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Notify failed'),
                'error'   => (string) ($res['error'] ?? 'Notify failed'),
                'data'    => $res,
            ], 502);
        }

        $sent = (int) ($res['sent'] ?? 0);
        $failed = (int) ($res['failed'] ?? 0);
        $skipped = (int) ($res['skipped'] ?? 0);
        $msg = 'Notifications: ' . $sent . ' sent';
        if ($skipped > 0) {
            $msg .= ', ' . $skipped . ' skipped (already emailed)';
        }
        if ($failed > 0) {
            $msg .= ', ' . $failed . ' failed';
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $msg,
            'email_sent' => $sent > 0,
            'warning' => $failed > 0 ? implode('; ', $res['errors'] ?? []) : null,
            'data'    => [
                'po' => PoWorkflowAssignmentService::displayPo($po),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'errors' => $res['errors'] ?? [],
                'viewers' => $res['viewers'],
                'approvers' => $res['approvers'],
                'summary' => $res['summary'],
            ],
        ]);
    }

    /*
     * POST purchase-order/workflow/decide
     * Assigned Approver Approve / Reject with remarks (local workflow only — not SAP)
     */
    public function purchaseOrderWorkflowDecide(): void
    {
        require_once base_path('app/services/PoWorkflowAssignmentService.php');
        $raw = function_exists('sap_reports_request_raw_body')
            ? sap_reports_request_raw_body()
            : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        $po = preg_replace('/\D/', '', (string) ($input['po'] ?? $input['purchase_order'] ?? '')) ?? '';
        $decision = trim((string) ($input['decision'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));
        $current = function_exists('portal_user') ? portal_user() : null;
        $username = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? '')));
        if ($username === '') {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Not logged in',
                'error'   => 'Not logged in',
                'data'    => null,
            ], 401);
        }

        $svc = new PoWorkflowAssignmentService();
        $res = $svc->decide($po, $username, $decision, $remarks);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Decision failed'),
                'error'   => (string) ($res['error'] ?? 'Decision failed'),
                'data'    => null,
            ], 422);
        }
        $list = $svc->listForPo($po, $username);
        $this->jsonResponse([
            'success' => true,
            'message' => 'Decision saved: ' . (string) (($res['row']['status'] ?? $decision)),
            'data'    => [
                'row' => $res['row'],
                'viewers' => $list['viewers'] ?? [],
                'approvers' => $list['approvers'] ?? [],
                'summary' => $list['summary'] ?? [],
                'current_user_approver' => $list['current_user_approver'] ?? null,
            ],
        ]);
    }

    /*
     * POST purchase-order/workflow/ack
     * Assigned Viewer opened the PO (Viewed / E-Sign Pending) or marked Signed
     */
    public function purchaseOrderWorkflowAck(): void
    {
        require_once base_path('app/services/PoWorkflowAssignmentService.php');
        $raw = function_exists('sap_reports_request_raw_body')
            ? sap_reports_request_raw_body()
            : ((string) (file_get_contents('php://input') ?: ''));
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        $po = preg_replace('/\D/', '', (string) ($input['po'] ?? $input['purchase_order'] ?? '')) ?? '';
        $action = strtolower(trim((string) ($input['action'] ?? 'view')));
        $current = function_exists('portal_user') ? portal_user() : null;
        $username = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? '')));
        if ($username === '') {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Not logged in',
                'error'   => 'Not logged in',
                'data'    => null,
            ], 401);
        }

        $svc = new PoWorkflowAssignmentService();
        if ($action === 'signed' || $action === 'sign') {
            $res = $svc->markViewerSigned($po, $username);
        } else {
            $esignPending = in_array($action, ['esign', 'esign_pending', 'e-sign'], true)
                || !empty($input['esign_pending']);
            $res = $svc->ackViewer($po, $username, $esignPending);
        }
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Ack failed'),
                'error'   => (string) ($res['error'] ?? 'Ack failed'),
                'data'    => null,
            ], 422);
        }
        $list = $svc->listForPo($po, $username);
        $this->jsonResponse([
            'success' => true,
            'message' => 'Workflow status updated',
            'data'    => [
                'row' => $res['row'],
                'viewers' => $list['viewers'] ?? [],
                'approvers' => $list['approvers'] ?? [],
                'summary' => $list['summary'] ?? [],
            ],
        ]);
    }

    /*
     * GET purchase-order/sent-box?box=sent|received
     * Admin → Sent PO list · nandhakumar → Receive PO list
     */
    public function purchaseOrderSentBox(): void
    {
        require_once base_path('app/services/PoSentLogService.php');
        $current = function_exists('portal_user') ? portal_user() : null;
        $username = trim((string) ($current['username'] ?? ($_SESSION['username'] ?? '')));
        $box = strtolower(trim((string) ($_GET['box'] ?? 'sent')));
        if (!in_array($box, ['sent', 'received'], true)) {
            $box = 'sent';
        }
        if ($username === '') {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Not logged in',
                'error' => 'Not logged in',
                'data' => ['rows' => [], 'box' => $box],
            ], 401);
        }
        $svc = new PoSentLogService();
        $res = $svc->listBox([
            'box' => $box,
            'username' => $username,
            'limit' => (int) ($_GET['limit'] ?? 80),
            'po' => (string) ($_GET['po'] ?? ''),
        ]);
        if (empty($res['success'])) {
            $this->jsonResponse([
                'success' => false,
                'message' => (string) ($res['error'] ?? 'Could not load box'),
                'error' => (string) ($res['error'] ?? 'Could not load box'),
                'data' => ['rows' => [], 'box' => $box, 'username' => $username],
            ], 500);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => $box === 'received' ? 'Receive PO' : 'Sent PO',
            'data' => [
                'box' => $box,
                'username' => $username,
                'rows' => $res['rows'],
                'count' => count($res['rows']),
            ],
        ]);
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
    /**
     * Apply global Viewer/Approver config to a PO and email them (idempotent).
     * Called when a PO enters the costing workflow (Send / Pass).
     *
     * @return array<string,mixed>
     */
    private function applyGlobalWorkflowOnPoEnter(string $po, string $fromUsername): array
    {
        try {
            require_once base_path('app/services/PoWorkflowAssignmentService.php');
            $svc = new PoWorkflowAssignmentService();
            return $svc->applyGlobalToPoAndNotify($po, $fromUsername !== '' ? $fromUsername : 'system');
        } catch (Throwable $e) {
            error_log('applyGlobalWorkflowOnPoEnter: ' . $e->getMessage());
            return [
                'success' => false,
                'applied' => false,
                'notified' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * After SAP Approve API: email Workflow Approver Assignment users with PDF + set Approved status.
     *
     * @return array<string,mixed>
     */
    private function notifyWorkflowApproversOnSapApprove(string $po, string $fromUsername): array
    {
        try {
            require_once base_path('app/services/PoWorkflowAssignmentService.php');
            $svc = new PoWorkflowAssignmentService();
            return $svc->notifyApproversOnSapApproved($po, $fromUsername !== '' ? $fromUsername : 'system');
        } catch (Throwable $e) {
            error_log('notifyWorkflowApproversOnSapApprove: ' . $e->getMessage());
            return [
                'success' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => [$e->getMessage()],
                'error' => $e->getMessage(),
            ];
        }
    }

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
              Sent by <strong style="color:#64748b;">{$safeFrom}</strong> · Evolv Clothing · SAP Insight
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
        $jsonSent = false;
        register_shutdown_function(static function () use (&$jsonSent): void {
            if ($jsonSent) {
                return;
            }
            $err = error_get_last();
            if (!is_array($err) || !in_array((int) ($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
            }
            $msg = 'Purchase order list failed on the server (timeout or memory). Try Approved/Unapproved or material filters again.';
            echo json_encode([
                'success' => false,
                'message' => $msg,
                'error'   => $msg,
                'data'    => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        try {
            @ini_set('memory_limit', '1024M');
            @ini_set('max_execution_time', '180');
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }
            $service = new SapPurchaseOrderService();
            $po = trim((string) ($_GET['po'] ?? ''));
            $isCostingView = strtolower(trim((string) ($_GET['view'] ?? ''))) === 'costing';

            if ($po !== '') {
                if (!preg_match('/^\d{1,10}$/', $po)) {
                    $message = 'Purchase order must be a number of up to 10 digits.';
                    $jsonSent = true;
                    $this->jsonResponse(['success' => false, 'message' => $message, 'error' => $message, 'data' => []], 422);
                }

                $payload = $service->report($po);
                if ($payload['error'] !== null) {
                    $jsonSent = true;
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
                $jsonSent = true;
                $this->jsonResponse([
                    'success' => true,
                    'message' => $payload['records'] === [] ? 'No purchase order found.' : 'Live SAP data',
                    'data'    => $payload,
                ]);
            }

            // List mode. Costing bulk = NotApproved API rows that are Released in po_release_log.
            $listFilters = $_GET;
            if ($isCostingView) {
                $listFilters['range'] = 'all';
                $listFilters['from'] = '';
                $listFilters['to'] = '';
                $listFilters['approved_status'] = 'NotApproved';
                require_once base_path('app/services/PoCostValidationService.php');
                $payload = (new PoCostValidationService())->listNotApprovedPurchaseOrders($listFilters);
            } else {
                $payload = $service->listReport($listFilters);
            }
            if ($payload['error'] !== null) {
                $status = str_contains((string) $payload['error'], 'date') ? 422 : 502;
                $jsonSent = true;
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
            if ($isCostingView) {
                $payload['released_only'] = true;
            }
            $jsonSent = true;
            $this->jsonResponse([
                'success' => true,
                'message' => $payload['records'] === []
                    ? ($isCostingView
                        ? 'No released purchase orders match the selected criteria.'
                        : 'No purchase orders match the selected criteria.')
                    : ($isCostingView ? 'Released PO Validation data' : 'Live SAP data'),
                'data'    => $payload,
            ]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Allowed memory size') !== false || stripos($message, 'memory') !== false) {
                $message = 'Too many purchase orders to load at once. Narrow filters (Approved / Material) and try again.';
            } else {
                $message = 'Unable to load purchase orders. Please try again.';
            }
            $jsonSent = true;
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
