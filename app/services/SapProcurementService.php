<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : SAP procurement (requirement / PR / PO / stock per component) from ZBUSINESS_API_SRV/ProcurementDashboardSet
 */
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapProcurementService
{
    use SapValueFormat;

    private const DEFAULT_SERVICE = '/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet';

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
     * Table columns: key, label, type (text|num), stage (header colour group)
     */
    public static function columns(): array
    {
        return [
            ['key' => 'sno',                   'label' => 'S.No',                  'type' => 'num',  'stage' => ''],
            ['key' => 'sales_doc',             'label' => 'Sales Order',           'type' => 'text', 'stage' => ''],
            ['key' => 'material',              'label' => 'Style',                 'type' => 'text', 'stage' => ''],
            ['key' => 'material_description',  'label' => 'Style Description',     'type' => 'text', 'stage' => ''],
            ['key' => 'component_material',    'label' => 'Component',             'type' => 'text', 'stage' => ''],
            ['key' => 'component_description', 'label' => 'Component Description', 'type' => 'text', 'stage' => ''],
            ['key' => 'material_group',        'label' => 'Material Group',        'type' => 'text', 'stage' => ''],
            ['key' => 'material_type',         'label' => 'Material Type',         'type' => 'text', 'stage' => ''],
            ['key' => 'shade_text1',           'label' => 'Shade 1',               'type' => 'text', 'stage' => ''],
            ['key' => 'shade_text2',           'label' => 'Shade 2',               'type' => 'text', 'stage' => ''],
            ['key' => 'requirement_qty',       'label' => 'Requirement Qty',       'type' => 'num',  'stage' => 'req'],
            ['key' => 'total_pr_qty',          'label' => 'Total PR Qty',          'type' => 'num',  'stage' => 'pr'],
            ['key' => 'total_po_qty',          'label' => 'Total PO Qty',          'type' => 'num',  'stage' => 'po'],
            ['key' => 'balance_pr_qty',        'label' => 'Balance PR Qty',        'type' => 'num',  'stage' => 'bal'],
            ['key' => 'stock_qty',             'label' => 'Stock Qty',             'type' => 'num',  'stage' => 'stock'],
            ['key' => 'pr_count',              'label' => 'PR Lines',              'type' => 'num',  'stage' => 'lines'],
        ];
    }

    /*
     * Columns that repeat the same value on every line of one sales order; hidden until the user shows them.
     */
    public static function defaultHiddenColumns(): array
    {
        return ['sales_doc', 'material', 'material_description', 'shade_text2'];
    }

    /*
     * PR line columns shown in the expanded row and in the CSV
     */
    public static function prColumns(): array
    {
        return [
            ['key' => 'pr_number',       'label' => 'PR Number',       'type' => 'text'],
            ['key' => 'pr_item',         'label' => 'PR Item',         'type' => 'text'],
            ['key' => 'requirement_qty', 'label' => 'Requirement Qty', 'type' => 'num'],
            ['key' => 'pr_qty',          'label' => 'PR Qty',          'type' => 'num'],
            ['key' => 'po_qty',          'label' => 'PO Qty',          'type' => 'num'],
            ['key' => 'balance_pr_qty',  'label' => 'Balance PR Qty',  'type' => 'num'],
            ['key' => 'delivery_date',   'label' => 'Delivery Date',   'type' => 'date'],
        ];
    }

    /*
     * @return array{records: array, total: int, page: int, pages: int, per_page: int, sales_order: string, info: array, pr_total: int, error: ?string}
     */
    public function report(string $salesOrder): array
    {
        $so = $this->padSalesOrder($salesOrder);
        $out = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => 0,
            'sales_order' => $this->displayNumber($so),
            'info'        => [],
            'pr_total'    => 0,
            'error'       => null,
        ];
        if ($so === '') {
            return $out;
        }
        if (!preg_match('/^\d{1,10}$/', $so)) {
            $out['error'] = 'Sales order must be a number.';
            return $out;
        }

        $service = (string) ($this->cfg['procurement_service'] ?? self::DEFAULT_SERVICE);
        // ProcurementPRSet cannot be filtered on its own; it only returns rows when expanded from the dashboard set.
        $result = $this->client->fetchResults($service, [
            '$filter' => "SalesDoc eq '{$so}'",
            '$expand' => 'ProcurementPRSet',
        ]);
        if (($result['error'] ?? null) !== null) {
            $out['error'] = $result['error'];
            return $out;
        }

        $rows = [];
        $prTotal = 0;
        foreach ($result['rows'] as $row) {
            if (is_array($row)) {
                $mapped = $this->mapRow($row);
                $prTotal += $mapped['pr_count'];
                $rows[] = $mapped;
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['material_group'], $a['component_material']] <=> [$b['material_group'], $b['component_material']]);

        $first = $rows[0] ?? [];
        $out['records'] = $rows;
        $out['total'] = count($rows);
        $out['per_page'] = count($rows);
        $out['pr_total'] = $prTotal;
        $out['info'] = [
            'style'             => (string) ($first['material'] ?? ''),
            'style_description' => (string) ($first['material_description'] ?? ''),
            'material_types'    => array_values(array_unique(array_filter(array_column($rows, 'material_type')))),
        ];
        return $out;
    }

    private function mapRow(array $row): array
    {
        $q = fn (string $field): float => $this->sapNumber($row[$field] ?? '');
        $prLines = [];
        foreach ((array) ($row['ProcurementPRSet']['results'] ?? []) as $pr) {
            if (is_array($pr)) {
                $prLines[] = $this->mapPrLine($pr);
            }
        }
        usort($prLines, static fn (array $a, array $b): int => [$a['delivery_date'], $a['pr_number'], (int) $a['pr_item']] <=> [$b['delivery_date'], $b['pr_number'], (int) $b['pr_item']]);

        return [
            'sales_doc'             => $this->displayNumber((string) ($row['SalesDoc'] ?? '')),
            'material'              => trim((string) ($row['Material'] ?? '')),
            'material_description'  => trim((string) ($row['MaterialDescription'] ?? '')),
            'component_material'    => trim((string) ($row['ComponentMaterial'] ?? '')),
            'component_description' => trim((string) ($row['ComponentDescription'] ?? '')),
            'material_group'        => trim((string) ($row['MaterialGroup'] ?? '')),
            'material_type'         => trim((string) ($row['MaterialType'] ?? '')),
            'shade_text1'           => trim((string) ($row['ShadeText1'] ?? '')),
            'shade_text2'           => trim((string) ($row['ShadeText2'] ?? '')),
            'requirement_qty'       => $q('RequirementQty'),
            'total_pr_qty'          => $q('TotalPRQty'),
            'total_po_qty'          => $q('TotalPOQty'),
            'balance_pr_qty'        => $q('BalancePRQty'),
            'stock_qty'             => $q('StockQty'),
            'pr_count'              => count($prLines),
            'pr_lines'              => $prLines,
        ];
    }

    private function mapPrLine(array $pr): array
    {
        return [
            'pr_number'       => trim((string) ($pr['PRNumber'] ?? '')),
            'pr_item'         => $this->displayNumber((string) ($pr['PRItem'] ?? '')),
            'requirement_qty' => $this->sapNumber($pr['RequirementQty'] ?? ''),
            'pr_qty'          => $this->sapNumber($pr['PRQty'] ?? ''),
            'po_qty'          => $this->sapNumber($pr['POQty'] ?? ''),
            'balance_pr_qty'  => $this->sapNumber($pr['BalancePRQty'] ?? ''),
            'delivery_date'   => $this->sapDate((string) ($pr['DeliveryDate'] ?? '')),
        ];
    }
}
