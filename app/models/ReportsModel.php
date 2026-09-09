<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Reports model
 */
require_once base_path('app/services/SapSalesService.php');
require_once base_path('app/services/SapUtilizationService.php');

class ReportsModel
{
    /*
     * Get the catalog
     */
    public function catalog(): array
    {
        return [
            'sales' => [
                'title' => 'Sales Order Lines',
                'blurb' => 'Live SAP order lines with search, paging, and CSV export.',
                'icon'  => 'fa-receipt',
                'color' => '#1d4ed8',
                'bg'    => '#eff6ff',
                'group' => 'Sales',
            ],
            'open' => [
                'title' => 'Active Orders',
                'blurb' => 'Order lines that are still active in SAP.',
                'icon'  => 'fa-circle-check',
                'color' => '#047857',
                'bg'    => '#ecfdf5',
                'group' => 'Sales',
            ],
            'division' => [
                'title' => 'Division Summary',
                'blurb' => 'Net amount and quantity grouped by division.',
                'icon'  => 'fa-layer-group',
                'color' => '#7c3aed',
                'bg'    => '#f5f3ff',
                'group' => 'Sales',
            ],
            'fabric' => [
                'title' => 'Fabric Utilization',
                'blurb' => 'SAP fabric utilization by sales order.',
                'icon'  => 'fa-scroll',
                'color' => '#0f766e',
                'bg'    => '#ccfbf1',
                'group' => 'Utilization Reports',
            ],
            'trims' => [
                'title' => 'Trims Utilization',
                'blurb' => 'SAP trims utilization by sales order.',
                'icon'  => 'fa-tags',
                'color' => '#c2410c',
                'bg'    => '#ffedd5',
                'group' => 'Utilization Reports',
            ],
        ];
    }

    /*
     * Format the money
     */
    public function money($n, string $symbol = '₹'): string
    {
        return $symbol . number_format((float) $n, 2);
    }

    /*
     * Format the number
     */
    public function num($n): string
    {
        return number_format((float) $n, 0);
    }

    public function qty($n): string
    {
        $f = (float) $n;
        if (abs($f - round($f)) < 0.0005) {
            return number_format($f, 0);
        }
        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.');
    }

    public function dash($n): string
    {
        if ($n === null || $n === '') {
            return '-';
        }
        if (is_numeric($n) && abs((float) $n) < 0.0000001) {
            return '-';
        }
        if (is_numeric($n)) {
            return $this->qty($n);
        }
        $text = trim((string) $n);
        return $text === '' ? '-' : $text;
    }

    /*
     * Fetch the records
     */
    public function fetch(string $report, int $year, int $page, int $perPage, string $search, string $from, string $to, bool $export, string $salesOrder = ''): array
    {
        if ($report === 'fabric' || $report === 'trims') {
            $util = new SapUtilizationService();
            $payload = $util->paginated($report, $salesOrder, $search, $page, $perPage, $export);
            if (($payload['error'] ?? null) !== null) {
                throw new RuntimeException((string) $payload['error']);
            }
            return $payload;
        }

        $sap = new SapSalesService();
        $needAll = $export || in_array($report, ['open', 'division'], true);
        $payload = $sap->paginatedRecords(
            $year,
            $needAll ? 1 : $page,
            $needAll ? 8000 : $perPage,
            $search,
            $from,
            $to
        );
        $rows = $payload['records'] ?? [];

        if ($report === 'open') {
            $rows = array_values(array_filter(
                $rows,
                static fn($row) => stripos((string) ($row['status'] ?? ''), 'active') !== false
            ));
        }

        if ($report === 'division') {
            $groups = [];
            foreach ($rows as $row) {
                $key = (string) ($row['division'] ?? '—');
                if (!isset($groups[$key])) {
                    $groups[$key] = ['division' => $key, 'qty' => 0, 'net_amount' => 0, 'lines' => 0];
                }
                $groups[$key]['qty'] += (float) ($row['qty'] ?? 0);
                $groups[$key]['net_amount'] += (float) ($row['net_amount'] ?? 0);
                $groups[$key]['lines']++;
            }
            $rows = array_values($groups);
        }

        $totalAll = count($rows);
        if (!$export && $report !== 'division') {
            $pagesAll = max(1, (int) ceil($totalAll / $perPage));
            $page = min($page, $pagesAll);
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $payload['pages'] = $pagesAll;
            $payload['page'] = $page;
            $payload['per_page'] = $perPage;
        } else {
            $payload['pages'] = 1;
            $payload['page'] = 1;
            $payload['per_page'] = max($perPage, $totalAll);
        }
        $payload['records'] = $rows;
        $payload['total'] = $totalAll;
        return $payload;
    }
}
