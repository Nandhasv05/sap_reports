<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP fabric / trims utilization from ZBUSINESS_API_SRV
 */
require_once base_path('app/core/SapODataClient.php');

class SapUtilizationService
{
    private SapODataClient $client;
    private array $cfg;

    /*
     * Constructor
     */
    public function __construct(?SapODataClient $client = null)
    {
        $this->client = $client ?? new SapODataClient();
        $this->cfg = config('sap');
    }

    /*
     * Pad sales order method
     */
    public function padSalesOrder(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d+$/', $raw)) {
            return str_pad(substr($raw, -10), 10, '0', STR_PAD_LEFT);
        }
        return $raw;
    }

    /*
     * @return array{records: array<int, array>, total: int, page: int, pages: int, per_page: int, sales_order: string, error: ?string}
     */
    public function paginated(string $kind, string $salesOrder, string $search, int $page, int $perPage, bool $export): array
    {
        $so = $this->padSalesOrder($salesOrder);
        $empty = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => $perPage,
            'sales_order' => $so,
            'error'       => null,
        ];
        if ($so === '') {
            return $empty + ['summary' => $this->emptySummary(), 'chart' => $this->emptyChart()];
        }

        $fetched = $this->loadRows($kind, $so);
        if (($fetched['error'] ?? null) !== null) {
            $empty['error'] = $fetched['error'];
            $empty['summary'] = $this->emptySummary();
            $empty['chart'] = $this->emptyChart();
            return $empty;
        }

        $rows = $fetched['rows'];
        $search = strtolower(trim($search));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                $hay = strtolower(implode(' ', [
                    $row['material'] ?? '',
                    $row['purchase_order'] ?? '',
                    $row['po_item'] ?? '',
                    $row['grn_sales_orders'] ?? '',
                ]));
                return str_contains($hay, $search);
            }));
        }

        $total = count($rows);
        $summary = $this->summarize($rows);
        $chart = $this->chartPayload($rows);

        return [
            'records'     => $rows,
            'total'       => $total,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => max($perPage, $total),
            'sales_order' => $so,
            'error'       => null,
            'summary'     => $summary,
            'chart'       => $chart,
        ];
    }

    /*
     * @return array{rows: array<int, array>, error: ?string}
     */
    private function loadRows(string $kind, string $so): array
    {
        $cacheFile = base_path('storage/cache/util_' . $kind . '_' . $so . '.json');
        $ttl = max(0, (int) ($this->cfg['cache_ttl'] ?? 300));
        if ($ttl > 0 && is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['rows']) && is_array($cached['rows'])) {
                return ['rows' => $cached['rows'], 'error' => null];
            }
        }

        $service = $kind === 'trims'
            ? (string) ($this->cfg['trims_service'] ?? '')
            : (string) ($this->cfg['fabric_service'] ?? '');
        if ($service === '') {
            return ['rows' => [], 'error' => 'Utilization service path is not configured.'];
        }

        $soEsc = str_replace("'", "''", $so);
        $result = $this->client->fetchResults($service, [
            '$filter' => "SalesOrder eq '{$soEsc}'",
        ]);
        if (($result['error'] ?? null) !== null) {
            return ['rows' => [], 'error' => $result['error']];
        }

        $rows = [];
        foreach ($result['rows'] as $row) {
            if (is_array($row)) {
                $rows[] = $this->mapRow($row);
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($cacheFile, json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE));

        return ['rows' => $rows, 'error' => null];
    }

    /*
     * Map row method
     */
    private function mapRow(array $row): array
    {
        return [
            'sales_order'      => $this->displaySo((string) ($row['SalesOrder'] ?? '')),
            'material'         => (string) ($row['Material'] ?? ''),
            'purchase_order'   => (string) ($row['PurchaseOrder'] ?? ''),
            'po_item'          => $this->displaySo((string) ($row['PO_Item'] ?? '')),
            'so_qty'           => (float) ($row['SO_QTY'] ?? 0),
            'bom_qty'          => (float) ($row['BOM_QTY'] ?? 0),
            'planned_qty'      => (float) ($row['Planned_Qty'] ?? 0),
            'production_qty'   => (float) ($row['Production_Qty'] ?? 0),
            'po_qty'           => (float) ($row['PO_QTY'] ?? 0),
            'grn_qty'          => (float) ($row['GRN_QTY'] ?? 0),
            'issue_qty'        => (float) ($row['Issue_QTY'] ?? 0),
            'grn_sales_orders' => $this->formatSoList((string) ($row['GRN_SalesOrders'] ?? '')),
        ];
    }

    /*
     * Display sales order method
     */
    private function displaySo(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $trimmed = ltrim($raw, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    /*
     * Format sales order list method
     */
    private function formatSoList(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out[] = $this->displaySo($part);
        }
        return implode(', ', $out);
    }

    /*
     * Empty summary method
     */
    private function emptySummary(): array
    {
        return [
            'lines'           => 0,
            'so_qty'          => 0,
            'bom_qty'         => 0,
            'planned_qty'     => 0,
            'production_qty'  => 0,
            'po_qty'          => 0,
            'grn_qty'         => 0,
            'issue_qty'       => 0,
        ];
    }

    private function emptyChart(): array
    {
        return [
            'totals_labels' => ['BOM', 'Planned', 'Production', 'PO', 'GRN', 'Issue'],
            'totals'        => [0, 0, 0, 0, 0, 0],
            'mix_labels'    => [],
            'mix_values'    => [],
        ];
    }

    private function summarize(array $rows): array
    {
        $sum = $this->emptySummary();
        $sum['lines'] = count($rows);
        foreach ($rows as $row) {
            $sum['so_qty'] += (float) ($row['so_qty'] ?? 0);
            $sum['bom_qty'] += (float) ($row['bom_qty'] ?? 0);
            $sum['planned_qty'] += (float) ($row['planned_qty'] ?? 0);
            $sum['production_qty'] += (float) ($row['production_qty'] ?? 0);
            $sum['po_qty'] += (float) ($row['po_qty'] ?? 0);
            $sum['grn_qty'] += (float) ($row['grn_qty'] ?? 0);
            $sum['issue_qty'] += (float) ($row['issue_qty'] ?? 0);
        }
        return $sum;
    }

    /*
     * Chart payload method
     */
    private function chartPayload(array $rows): array
    {
        $summary = $this->summarize($rows);
        $byMaterial = [];
        foreach ($rows as $row) {
            $key = (string) ($row['material'] ?? '—');
            if (!isset($byMaterial[$key])) {
                $byMaterial[$key] = 0.0;
            }
            $byMaterial[$key] += (float) ($row['bom_qty'] ?? 0);
        }
        arsort($byMaterial);
        $mixLabels = [];
        $mixValues = [];
        $i = 0;
        $other = 0.0;
        foreach ($byMaterial as $label => $value) {
            if ($i < 7) {
                $mixLabels[] = $label;
                $mixValues[] = round($value, 3);
            } else {
                $other += $value;
            }
            $i++;
        }
        if ($other > 0) {
            $mixLabels[] = 'Other';
            $mixValues[] = round($other, 3);
        }

        return [
            'totals_labels' => ['BOM', 'Planned', 'Production', 'PO', 'GRN', 'Issue'],
            'totals'        => [
                round($summary['bom_qty'], 3),
                round($summary['planned_qty'], 3),
                round($summary['production_qty'], 3),
                round($summary['po_qty'], 3),
                round($summary['grn_qty'], 3),
                round($summary['issue_qty'], 3),
            ],
            'mix_labels'    => $mixLabels,
            'mix_values'    => $mixValues,
        ];
    }
}
