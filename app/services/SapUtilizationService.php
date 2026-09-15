<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP FABRIC / trims utilization from ZBUSINESS_API_SRV
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
                    $row['sales_order'] ?? '',
                    $row['material'] ?? '',
                    $row['purchase_order'] ?? '',
                    $row['po_item'] ?? '',
                    $row['grn_sales_orders'] ?? '',
                    implode(' ', $row['grn_so_list'] ?? []),
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
                $rows[] = $this->mapRow($row, $kind);
            }
        }

        return ['rows' => $rows, 'error' => null];
    }

    /*
     * Categorize trim material
     */
    public function categorizeTrim(string $material, string $desc = ''): string
    {
        $mat = strtoupper(trim($material));
        $d = strtoupper(trim($desc));

        // 1. Button
        if (str_starts_with($mat, '60') || str_contains($mat, 'BTN') || str_contains($mat, 'BUTTON') || str_contains($mat, 'LSB') || str_contains($d, 'BUTTON') || str_contains($d, 'BTN')) {
            return 'Button';
        }
        // 2. Zipper
        if (str_starts_with($mat, '50') || str_contains($mat, 'ZIP') || str_contains($mat, 'FASTENER') || str_contains($mat, 'SLIDER') || str_contains($d, 'ZIPPER') || str_contains($d, 'ZIP')) {
            return 'Zipper';
        }
        // 3. Thread
        if (str_starts_with($mat, '70') || str_contains($mat, 'THR') || str_contains($mat, 'THREAD') || str_contains($d, 'THREAD')) {
            return 'Thread';
        }
        // 4. Labels
        if (str_starts_with($mat, '30') || str_contains($mat, 'LBL') || str_contains($mat, 'LABEL') || str_contains($d, 'LABEL') || str_contains($mat, 'MAL') || str_contains($mat, 'CNL') || str_contains($mat, 'SIZ') || str_contains($mat, 'WCA') || str_contains($mat, 'WRL') || str_contains($mat, 'SML')) {
            return 'Labels';
        }
        // 5. Packing
        if (str_starts_with($mat, '40') || str_contains($mat, 'PLB') || str_contains($mat, 'MTG') || str_contains($mat, 'CBD') || str_contains($mat, 'BKS') || str_contains($mat, 'BFY') || str_contains($mat, 'CRP') || str_contains($mat, 'HNT') || str_contains($mat, 'SLG') || str_contains($mat, 'TIP') || str_contains($d, 'PACKING') || str_contains($d, 'POLY') || str_contains($d, 'CARTON') || str_contains($d, 'HANGTAG')) {
            return 'Packing';
        }
        // 6. Lining / Interlining
        if (str_starts_with($mat, '80') || str_contains($mat, 'FUS') || str_contains($mat, 'NFU') || str_contains($d, 'FUSIBLE') || str_contains($d, 'LINING') || str_contains($d, 'INTERLINING')) {
            return 'Lining';
        }
        // 7. Consumables / Tape
        if (str_starts_with($mat, '90') || str_contains($mat, 'GTP') || str_contains($mat, 'TAPE') || str_contains($d, 'TAPE') || str_contains($d, 'CONSUM')) {
            return 'Consumables';
        }

        return 'Other';
    }

    /*
     * Map row method
     */
    private function mapRow(array $row, string $kind = ''): array
    {
        $soList = $this->parseSoList((string) ($row['GRN_SalesOrders'] ?? ''));
        $material = (string) ($row['Material'] ?? '');
        return [
            'sales_order'      => $this->displaySo((string) ($row['SalesOrder'] ?? '')),
            'category'         => $kind === 'trims' ? $this->categorizeTrim($material) : '',
            'material'         => $material,
            'purchase_order'   => (string) ($row['PurchaseOrder'] ?? ''),
            'po_item'          => $this->displaySo((string) ($row['PO_Item'] ?? '')),
            'so_qty'           => (float) ($row['SO_QTY'] ?? 0),
            'bom_qty'          => (float) ($row['BOM_QTY'] ?? 0),
            'planned_qty'      => (float) ($row['Planned_Qty'] ?? 0),
            'production_qty'   => (float) ($row['Production_Qty'] ?? 0),
            'po_qty'           => (float) ($row['PO_QTY'] ?? 0),
            'grn_qty'          => (float) ($row['GRN_QTY'] ?? 0),
            'issue_qty'        => (float) ($row['Issue_QTY'] ?? 0),
            'grn_sales_orders' => implode(', ', $soList),
            'grn_so_list'      => $soList,
        ];
    }

    /*
     * Display sales order method
     */
    public function displaySo(string $raw): string
    {
        $raw = str_replace(',', '', trim($raw));
        if ($raw === '') {
            return '';
        }
        $trimmed = ltrim($raw, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    /*
     * Parse sales order list method
     */
    public function parseSoList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $cleaned = $this->displaySo($part);
            if ($cleaned !== '' && !in_array($cleaned, $out, true)) {
                $out[] = $cleaned;
            }
        }
        return $out;
    }

    /*
     * Format sales order list method
     */
    public function formatSoList(string $raw): string
    {
        return implode(', ', $this->parseSoList($raw));
    }

    /*
     * Empty summary method
     */
    private function emptySummary(): array
    {
        return [
            'lines'            => 0,
            'so_qty'           => 0,
            'bom_qty'          => 0,
            'planned_qty'      => 0,
            'production_qty'   => 0,
            'po_qty'           => 0,
            'grn_qty'          => 0,
            'issue_qty'        => 0,
            'category_counts'  => [],
        ];
    }

    private function emptyChart(): array
    {
        return [
            'totals_labels' => ['BOM', 'Planned', 'Production', 'PO', 'GRN', 'Issue'],
            'totals'        => [0, 0, 0, 0, 0, 0],
            'mix_labels'    => [],
            'mix_values'    => [],
            'cat_labels'    => [],
            'cat_values'    => [],
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
            $cat = (string) ($row['category'] ?? '');
            if ($cat !== '') {
                $sum['category_counts'][$cat] = ($sum['category_counts'][$cat] ?? 0) + 1;
            }
        }
        return $sum;
    }

    /*
     * Chart payload method
     */
    private function chartPayload(array $rows): array
    {
        $summary = $this->summarize($rows);

        // By-material BOM breakdown (top 7 + Other)
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

        // By-category BOM breakdown (trims only)
        $byCategory = [];
        foreach ($rows as $row) {
            $cat = (string) ($row['category'] ?? '');
            if ($cat === '') {
                continue;
            }
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = 0.0;
            }
            $byCategory[$cat] += (float) ($row['bom_qty'] ?? 0);
        }
        arsort($byCategory);
        $catLabels = array_keys($byCategory);
        $catValues = array_values(array_map(static fn ($v) => round($v, 3), $byCategory));

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
            'cat_labels'    => $catLabels,
            'cat_values'    => $catValues,
        ];
    }
}
