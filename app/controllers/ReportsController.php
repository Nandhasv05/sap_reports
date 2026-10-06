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
            'pageTitle' => 'SAP Reports',
            'catalog'   => $model->catalog(),
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

        $payload = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => $perPage,
            'sales_order' => $salesOrder,
        ];
        $loadError = '';
        $liveClient = in_array($report, ['fabric', 'trims'], true) && !$export;
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

        $this->view(in_array($report, ['fabric', 'trims'], true) ? 'reports/material' : 'reports/show', [
            'pageTitle'   => $catalog[$report]['title'],
            'layoutWide'  => in_array($report, ['fabric', 'trims'], true),
            'appShell'    => in_array($report, ['fabric', 'trims'], true),
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
            'extraHead'   => in_array($report, ['fabric', 'trims'], true)
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
        if (!$service->validPlant($plant) && !in_array($plant, $service->plants(), true)) {
            return ['plant' => $plant, 'error' => 'Plant must be a valid 4-character code (e.g. P002).'];
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
        $salesOrder = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';

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
            'extraHead'  => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>',
        ]);
    }

    /*
     * One CSV line per PR line; components without PR lines get a single line with empty PR fields.
     */
    private function sendProcurementCsv(string $salesOrder, array $records): void
    {
        $columns = array_values(array_filter(SapProcurementService::columns(), static fn (array $c): bool => $c['key'] !== 'pr_count'));
        $prColumns = SapProcurementService::prColumns();
        $filename = 'sap-procurement-' . ($salesOrder !== '' ? $salesOrder . '-' : '') . date('Ymd-His') . '.csv';
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
        $plant = strtoupper(trim((string) ($_GET['plant'] ?? '')));
        $supplier = trim((string) ($_GET['supplier'] ?? ''));
        $salesOrder = preg_replace('/\D/', '', (string) ($_GET['so'] ?? '')) ?? '';

        $listColumns = SapPurchaseOrderService::listColumns();
        $detailColumns = SapPurchaseOrderService::columns();

        if (strtolower((string) ($_GET['export'] ?? '')) === 'csv') {
            if ($mode === 'detail') {
                $detail = $service->report($input);
                $this->sendPurchaseOrderCsv($input, $detail['records'] ?? []);
            } else {
                $listData = $service->listReport([
                    'range'    => $rangePreset,
                    'from'     => $range['from'] ?? '',
                    'to'       => $range['to'] ?? '',
                    'plant'    => $plant,
                    'supplier' => $supplier,
                    'so'       => $salesOrder,
                ]);
                $this->sendPurchaseOrderListCsv($listData['records'] ?? []);
            }
            return;
        }

        $this->view('reports/purchase_order', [
            'pageTitle'     => $mode === 'detail' ? ('Purchase Order ' . $input) : 'Purchase Order Report',
            'layoutWide'    => true,
            'appShell'      => true,
            'item'          => ['title' => 'Purchase Order Report'],
            'report'        => 'purchase-order',
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
            'salesOrder'    => $salesOrder,
            'columns'       => $mode === 'detail' ? $detailColumns : $listColumns,
            'detailColumns' => $detailColumns,
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
        fputcsv($out, ['S.No', 'Purchase Order', 'PO Date', 'Plant', 'Supplier', 'Items', 'Order Qty', 'Unit', 'Net Value', 'Currency', 'Linked Sales Orders', 'Created By']);
        foreach ($records as $i => $row) {
            fputcsv($out, [
                $i + 1,
                $row['purchase_order'] ?? '',
                $row['date'] ?? '',
                $row['plant'] ?? '',
                $row['supplier'] ?? '',
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

    private function sendPurchaseOrderCsv(string $po, array $records): void
    {
        $filename = 'sap-purchase-order-' . ($po !== '' ? $po . '-' : '') . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        $columns = SapPurchaseOrderService::columns();
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
     * Purchase order header and items as JSON for the report page and the PO drawer (?po=4000006524 or ?list=1)
     */
    public function purchaseOrderData(): void
    {
        $service = new SapPurchaseOrderService();
        $po = trim((string) ($_GET['po'] ?? ''));

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
            $this->jsonResponse([
                'success' => true,
                'message' => $payload['records'] === [] ? 'No purchase order found.' : 'Live SAP data',
                'data'    => $payload,
            ]);
        }

        // List mode (condition-wise)
        $payload = $service->listReport($_GET);
        if ($payload['error'] !== null) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Unable to fetch SAP data: ' . $payload['error'],
                'error'   => $payload['error'],
                'data'    => [],
            ], 502);
        }
        $this->jsonResponse([
            'success' => true,
            'message' => $payload['records'] === [] ? 'No purchase orders match the selected criteria.' : 'Live SAP data',
            'data'    => $payload,
        ]);
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
