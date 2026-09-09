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
            'records'  => [],
            'total'    => 0,
            'page'     => 1,
            'pages'    => 1,
            'per_page' => $perPage,
        ];
        $loadError = '';
        try {
            $payload = $model->fetch($report, $year, $page, $perPage, $search, $from, $to, $export, $salesOrder);
        } catch (Throwable $e) {
            $loadError = $e->getMessage();
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
            'extraHead'   => in_array($report, ['fabric', 'trims'], true)
                ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>'
                : '',
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
        } elseif ($report === 'fabric' || $report === 'trims') {
            fputcsv($out, [
                'S.No', 'Sales Order', 'Material', 'Purchase Order', 'PO Item',
                'SO Qty', 'BOM Qty', 'Planned Qty', 'Production Qty',
                'PO Qty', 'GRN Qty', 'Issue Qty', 'GRN Sales Orders',
            ]);
            foreach ($records as $i => $row) {
                fputcsv($out, [
                    $i + 1,
                    $row['sales_order'] ?? '',
                    $row['material'] ?? '',
                    $row['purchase_order'] ?? '',
                    $row['po_item'] ?? '',
                    $row['so_qty'] ?? 0,
                    $row['bom_qty'] ?? 0,
                    $row['planned_qty'] ?? 0,
                    $row['production_qty'] ?? 0,
                    $row['po_qty'] ?? 0,
                    $row['grn_qty'] ?? 0,
                    $row['issue_qty'] ?? 0,
                    $row['grn_sales_orders'] ?? '',
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
}
