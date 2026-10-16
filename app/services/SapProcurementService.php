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
            ['key' => 'component_material',    'label' => 'Component',             'type' => 'text', 'stage' => ''],
            ['key' => 'material_group',        'label' => 'Material Group',        'type' => 'text', 'stage' => ''],
            ['key' => 'shade_text1',           'label' => 'Shade',                 'type' => 'text', 'stage' => ''],
            ['key' => 'requirement_qty',       'label' => 'Requirement Qty',       'type' => 'num',  'stage' => 'req'],
            ['key' => 'stock_qty',             'label' => 'Stock Qty',             'type' => 'num',  'stage' => 'stock'],
            ['key' => 'stock_status',          'label' => 'Status',                'type' => 'text', 'stage' => ''],
            ['key' => 'total_pr_qty',          'label' => 'Total PR Qty',          'type' => 'num',  'stage' => 'pr'],
            ['key' => 'total_po_qty',          'label' => 'Total PO Qty',          'type' => 'num',  'stage' => 'po'],
            ['key' => 'balance_pr_qty',        'label' => 'Balance PR Qty',        'type' => 'num',  'stage' => 'bal'],
            ['key' => 'pr_count',              'label' => 'PR Lines',              'type' => 'num',  'stage' => 'lines'],
            ['key' => 'sales_doc',             'label' => 'Sales Order',           'type' => 'text', 'stage' => ''],
            ['key' => 'material',              'label' => 'Style',                 'type' => 'text', 'stage' => ''],
            ['key' => 'material_description',  'label' => 'Style Description',     'type' => 'text', 'stage' => ''],
            ['key' => 'component_description', 'label' => 'Component Description', 'type' => 'text', 'stage' => ''],
            ['key' => 'mat_group_desc',        'label' => 'MatGroupDsc',           'type' => 'text', 'stage' => ''],
            ['key' => 'material_type',         'label' => 'Material Type',         'type' => 'text', 'stage' => ''],
            ['key' => 'shade_text2',           'label' => 'Shade 2',               'type' => 'text', 'stage' => ''],
        ];
    }

    /*
     * Columns that repeat the same value on every line of one sales order; hidden until the user shows them.
     */
    public static function defaultHiddenColumns(): array
    {
        return [
            'sales_doc',
            'material',
            'material_description',
            'component_description',
            'mat_group_desc',
            'material_type',
            'shade_text2',
        ];
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
        $rawSos = array_filter(
            array_map('trim', preg_split('/[,\s]+/', $salesOrder)),
            static fn (string $s): bool => $s !== ''
        );
        $displaySos = array_values(array_unique(array_map([$this, 'displayNumber'], $rawSos)));
        $soJoined = implode(', ', $displaySos);

        $out = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => 0,
            'sales_order' => $soJoined,
            'info'        => [],
            'pr_total'    => 0,
            'error'       => null,
        ];
        if ($displaySos === []) {
            return $out;
        }

        foreach ($displaySos as $so) {
            if (!preg_match('/^\d{1,10}$/', $so)) {
                $out['error'] = 'Sales order(s) must be numeric.';
                return $out;
            }
        }

        $service = (string) ($this->cfg['procurement_service'] ?? self::DEFAULT_SERVICE);
        // ProcurementPRSet cannot be filtered on its own; it only returns rows when expanded from the dashboard set.
        $filterParts = array_map(static fn (string $s): string => "SalesDoc eq '{$s}'", $displaySos);
        $filter = implode(' or ', $filterParts);

        $result = $this->client->fetchResults($service, [
            '$filter' => $filter,
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
        usort($rows, static fn (array $a, array $b): int => [$a['sales_doc'], $a['material_group'], $a['component_material']] <=> [$b['sales_doc'], $b['material_group'], $b['component_material']]);

        $styles = array_values(array_unique(array_filter(array_column($rows, 'material'))));
        $styleDescs = array_values(array_unique(array_filter(array_column($rows, 'material_description'))));
        $out['records'] = $rows;
        $out['total'] = count($rows);
        $out['per_page'] = count($rows);
        $out['pr_total'] = $prTotal;
        $out['info'] = [
            'sales_orders'      => $displaySos,
            'style'             => count($styles) > 1 ? implode(', ', $styles) : (string) ($styles[0] ?? ''),
            'style_description' => count($styleDescs) > 1 ? implode(', ', $styleDescs) : (string) ($styleDescs[0] ?? ''),
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
        usort($prLines, static function (array $a, array $b): int {
            $dateA = (string) ($a['delivery_date'] ?? '');
            $dateB = (string) ($b['delivery_date'] ?? '');

            // Empty delivery dates placed at the end
            if ($dateA === '' && $dateB !== '') {
                return 1;
            }
            if ($dateA !== '' && $dateB === '') {
                return -1;
            }

            // 1. Current / latest delivery date first (descending)
            if ($dateA !== $dateB) {
                return strcmp($dateB, $dateA);
            }

            // 2. Quantity high first (PR Qty descending)
            $qtyA = (float) ($a['pr_qty'] ?? 0);
            $qtyB = (float) ($b['pr_qty'] ?? 0);
            if ($qtyA !== $qtyB) {
                return $qtyB <=> $qtyA;
            }

            // 3. Balance Qty descending
            $balA = (float) ($a['balance_pr_qty'] ?? 0);
            $balB = (float) ($b['balance_pr_qty'] ?? 0);
            if ($balA !== $balB) {
                return $balB <=> $balA;
            }

            // 4. Stable tie-breaker: PR number desc, PR item asc
            $prCmp = strcmp((string) ($b['pr_number'] ?? ''), (string) ($a['pr_number'] ?? ''));
            if ($prCmp !== 0) {
                return $prCmp;
            }

            return (int) ($a['pr_item'] ?? 0) <=> (int) ($b['pr_item'] ?? 0);
        });

        return [
            'sales_doc'             => $this->displayNumber((string) ($row['SalesDoc'] ?? '')),
            'material'              => trim((string) ($row['Material'] ?? '')),
            'material_description'  => trim((string) ($row['MaterialDescription'] ?? '')),
            'component_material'    => trim((string) ($row['ComponentMaterial'] ?? '')),
            'component_description' => trim((string) ($row['ComponentDescription'] ?? '')),
            'material_group'        => trim((string) ($row['MaterialGroup'] ?? '')),
            'mat_group_desc'        => trim((string) ($row['MatGroupDsc'] ?? '')),
            'material_type'         => trim((string) ($row['MaterialType'] ?? '')),
            'shade_text1'           => trim((string) ($row['ShadeText1'] ?? '')),
            'shade_text2'           => trim((string) ($row['ShadeText2'] ?? '')),
            'requirement_qty'       => $q('RequirementQty'),
            'total_pr_qty'          => $q('TotalPRQty'),
            'total_po_qty'          => $q('TotalPOQty'),
            'balance_pr_qty'        => $q('BalancePRQty'),
            'stock_qty'             => $q('StockQty'),
            'stock_status'          => ($q('StockQty') >= $q('RequirementQty') && $q('RequirementQty') > 0)
                ? 'In Stock'
                : ((($q('StockQty') + $q('TotalPOQty')) >= $q('RequirementQty') && $q('RequirementQty') > 0)
                    ? 'PO Pipeline'
                    : (($q('BalancePRQty') > 0 || ($q('StockQty') + $q('TotalPOQty')) < $q('RequirementQty')) ? 'Shortage' : 'Available')),
            'unit'                  => trim((string) ($row['BaseUnit'] ?? $row['Unit'] ?? $row['Meins'] ?? '')),
            'customer'              => trim((string) ($row['CustomerName'] ?? $row['Customer'] ?? $row['Name1'] ?? $row['Kunnr'] ?? '')),
            'plant'                 => trim((string) ($row['Plant'] ?? $row['Werks'] ?? '')),
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
            'uom'             => trim((string) ($pr['Unit'] ?? $pr['Uom'] ?? $pr['BaseUnit'] ?? $pr['Meins'] ?? '')),
            'department'      => trim((string) ($pr['Department'] ?? $pr['PlanDept'] ?? '')),
            'created_date'    => $this->sapDate((string) ($pr['CreatedDate'] ?? $pr['PRDate'] ?? $pr['DocDate'] ?? $pr['Erdat'] ?? '')),
        ];
    }
}
?>
