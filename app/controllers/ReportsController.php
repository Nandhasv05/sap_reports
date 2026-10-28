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
     * Purchase Order report page:
     * - Without ?po: condition-wise PO list view (date range, plant, supplier, quick search)
     * - With ?po=4000006524: single PO details view (Hero, KPI cards, charts, items table)
     */
    public function showPurchaseOrder(): void
    {
        $input = preg_replace('/[\s,]/', '', (string) ($_GET['po'] ?? '')) ?? '';
        $error = $input !== '' && !preg_match('/^\d{1,10}$/', $input) ? 'Purchase order must be a number of up to 10 digits.' : null;
        $mode = ($input !== '' && $error === null) ? 'detail' : 'list';

        $service = new SapPurchaseOrderService();
        $rangePreset = strtolower(trim((string) ($_GET['range'] ?? 'this_month')));
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
        try {
            $payload = (new PoCostValidationService())->validate($po, $category, $so);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Validation failed: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
                'data'    => [],
            ], 502);
        }
        $this->jsonResponse([
            'success' => !empty($payload['success']),
            'message' => $payload['error']
                ?? (($payload['summary']['exceptions'] ?? 0) > 0
                    ? (($payload['summary']['exceptions'] ?? 0) . ' exceptions require review')
                    : 'Validation complete'),
            'error'   => $payload['error'] ?? null,
            'data'    => $payload,
        ], !empty($payload['success']) ? 200 : 502);
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
