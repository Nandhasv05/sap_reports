<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : SAP ZCOST_SRV costing sheet (ZCOSTHSet + Fabric/Trim/Special/Packing expand)
*/
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapCostService
{
    use SapValueFormat;

    private const DEFAULT_SERVICE = '/sap/opu/odata/sap/ZCOST_SRV/ZCOSTHSet';
    private const EXPAND = 'to_Fabric,to_Trim,to_Special,to_Packing';

    private const NAV_SECTIONS = [
        'to_Fabric'  => 'Fabric',
        'to_Trim'    => 'Trim',
        'to_Special' => 'Special',
        'to_Packing' => 'Packing',
    ];

    private SapODataClient $client;
    private array $cfg;

    public function __construct(?SapODataClient $client = null)
    {
        $this->client = $client ?? new SapODataClient();
        $this->cfg = config('sap');
    }

    /*
     * Columns for PO Costing Validation detail table
     */
    public static function costingColumns(): array
    {
        return [
            ['key' => 'sno',          'label' => 'S.No',         'type' => 'num'],
            ['key' => 'section',      'label' => 'Section',      'type' => 'text'],
            ['key' => 'sales_order',  'label' => 'Sales Order',  'type' => 'text'],
            ['key' => 'so_item',      'label' => 'SO Item',      'type' => 'text'],
            ['key' => 'item',         'label' => 'Item',         'type' => 'text'],
            ['key' => 'description',  'label' => 'Description',  'type' => 'text'],
            ['key' => 'wid_siz',      'label' => 'Wid/Size',     'type' => 'text'],
            ['key' => 'net',          'label' => 'Net',          'type' => 'num'],
            ['key' => 'waste',        'label' => 'Waste %',      'type' => 'num'],
            ['key' => 'gross',        'label' => 'Gross',        'type' => 'num'],
            ['key' => 'currency',     'label' => 'Curr',         'type' => 'text'],
            ['key' => 'purp',         'label' => 'Purchase Price','type' => 'num'],
            ['key' => 'convr',        'label' => 'Conv Rate',    'type' => 'num'],
            ['key' => 'freight',      'label' => 'Freight',      'type' => 'num'],
            ['key' => 'duty',         'label' => 'Duty',         'type' => 'num'],
            ['key' => 'fin_cost',     'label' => 'Fin Cost',     'type' => 'num'],
            ['key' => 'rate',         'label' => 'Rate',         'type' => 'num'],
            ['key' => 'total_inr',    'label' => 'Total INR',    'type' => 'num'],
            ['key' => 'total_fr',     'label' => 'Total FR',     'type' => 'num'],
            ['key' => 'sell_price',   'label' => 'Sell Price',   'type' => 'num'],
            ['key' => 'making',       'label' => 'Making',       'type' => 'num'],
            ['key' => 'overhead',     'label' => 'Overhead',     'type' => 'num'],
            ['key' => 'markup',       'label' => 'Markup',       'type' => 'num'],
            ['key' => 'subtot',       'label' => 'Subtotal',     'type' => 'num'],
        ];
    }

    /*
     * Attach ZCOST expanded lines to a PO detail payload (uses linked sales orders).
     *
     * @param array{purchase_order?:string,header?:array,records?:array,totals?:array,error?:?string} $poReport
     * @param array<int, string> $extraSalesOrders Optional SO numbers (e.g. ?so=) when PO lines have none linked
     * @return array{purchase_order:string,header:array,records:array,totals:array,costing:array,error:?string}
     */
    public function attachToPurchaseOrder(array $poReport, array $extraSalesOrders = []): array
    {
        $records = is_array($poReport['records'] ?? null) ? $poReport['records'] : [];
        $salesOrders = [];
        foreach ($records as $row) {
            foreach ((array) ($row['sales_orders'] ?? []) as $so) {
                $so = $this->displayNumber((string) $so);
                if ($so !== '' && !in_array($so, $salesOrders, true)) {
                    $salesOrders[] = $so;
                }
            }
        }
        foreach ((array) ($poReport['header']['sales_orders'] ?? []) as $so) {
            $so = $this->displayNumber((string) $so);
            if ($so !== '' && !in_array($so, $salesOrders, true)) {
                $salesOrders[] = $so;
            }
        }
        foreach ($extraSalesOrders as $so) {
            $so = $this->displayNumber((string) $so);
            if ($so !== '' && !in_array($so, $salesOrders, true)) {
                $salesOrders[] = $so;
            }
        }

        $costing = $this->fetchForSalesOrders($salesOrders);
        $lines = $costing['records'];
        $po = (string) ($poReport['purchase_order'] ?? ($poReport['header']['purchase_order'] ?? ''));
        foreach ($lines as &$line) {
            $line['purchase_order'] = $po;
        }
        unset($line);

        $poReport['costing'] = [
            'sales_orders' => $salesOrders,
            'sheets'       => $costing['sheets'],
            'error'        => $costing['error'],
            'source'       => 'ZCOST_SRV/ZCOSTHSet',
        ];

        if ($lines !== []) {
            $poReport['records'] = $lines;
            $poReport['totals'] = $this->lineTotals($lines);
            $poReport['costing_mode'] = true;
            if (!isset($poReport['header']) || !is_array($poReport['header'])) {
                $poReport['header'] = [];
            }
            $poReport['header']['cost_sheets'] = count($costing['sheets']);
            $poReport['header']['cost_sell_price'] = $costing['summary']['sell_price'] ?? 0;
            $poReport['header']['cost_making'] = $costing['summary']['making'] ?? 0;
            $poReport['header']['cost_subtot'] = $costing['summary']['subtot'] ?? 0;
        } elseif ($salesOrders === []) {
            $poReport['costing']['error'] = $poReport['costing']['error']
                ?: 'No linked sales orders on this PO — cannot load ZCOST sheet.';
        } elseif ($costing['error']) {
            // keep PO item rows; surface costing error to UI
        } else {
            $poReport['costing']['error'] = 'No ZCOST fabric/trim/special/packing lines found for linked sales orders.';
        }

        return $poReport;
    }

    /*
     * Fetch and flatten cost sheets for one or more sales orders.
     *
     * @param array<int, string> $salesOrders
     * @return array{records:array,sheets:array,summary:array,error:?string}
     */
    public function fetchForSalesOrders(array $salesOrders): array
    {
        $out = ['records' => [], 'sheets' => [], 'summary' => [], 'error' => null];
        if ($salesOrders === []) {
            return $out;
        }

        $errors = [];
        $sell = 0.0;
        $making = 0.0;
        $subtot = 0.0;
        $sheetCount = 0;

        foreach ($salesOrders as $so) {
            $sheetResult = $this->fetchSheetsForVbeln((string) $so);
            if ($sheetResult['error'] && $sheetResult['sheets'] === []) {
                $errors[] = $so . ': ' . $sheetResult['error'];
                continue;
            }
            foreach ($sheetResult['sheets'] as $sheet) {
                $sheetCount++;
                $out['sheets'][] = [
                    'sales_order' => $this->displayNumber((string) ($sheet['Vbeln'] ?? $so)),
                    'so_item'     => $this->displayNumber((string) ($sheet['Posnr'] ?? '')),
                    'sell_price'  => $this->sapNumber($sheet['Sellprice'] ?? 0),
                    'making'      => $this->sapNumber($sheet['Making'] ?? 0),
                    'overhead'    => $this->sapNumber($sheet['Overhead'] ?? 0),
                    'markup'      => $this->sapNumber($sheet['Markup'] ?? 0),
                    'subtot'      => $this->sapNumber($sheet['Subtot'] ?? 0),
                ];
                $sell += $this->sapNumber($sheet['Sellprice'] ?? 0);
                $making += $this->sapNumber($sheet['Making'] ?? 0);
                $subtot += $this->sapNumber($sheet['Subtot'] ?? 0);
                foreach ($this->flattenSheet($sheet) as $line) {
                    $out['records'][] = $line;
                }
            }
        }

        $out['summary'] = [
            'sell_price' => round($sell, 4),
            'making'     => round($making, 4),
            'subtot'     => round($subtot, 4),
            'sheets'     => $sheetCount,
            'lines'      => count($out['records']),
        ];
        if ($out['records'] === [] && $errors !== []) {
            $out['error'] = implode(' | ', array_slice($errors, 0, 3));
        }

        return $out;
    }

    /*
     * @return array{sheets:array<int,array>,error:?string}
     */
    public function fetchSheetsForVbeln(string $vbeln, string $posnr = '000010'): array
    {
        $vbeln = trim($vbeln);
        $variants = $this->vbelnVariants($vbeln);
        $posnr = $this->padPosnr($posnr);
        $errors = [];

        // 1) Entity-set filter (all items for SO)
        foreach ($variants as $vb) {
            $filtered = $this->fetchByFilter($vb);
            if ($filtered['sheets'] !== []) {
                return $filtered;
            }
            if ($filtered['error']) {
                $errors[] = $filtered['error'];
            }
        }

        // 2) Keyed entity read (default item)
        foreach ($variants as $vb) {
            $keyed = $this->fetchByKey($vb, $posnr);
            if ($keyed['sheets'] !== []) {
                return $keyed;
            }
            if ($keyed['error']) {
                $errors[] = $keyed['error'];
            }
        }

        return [
            'sheets' => [],
            'error'  => $errors[0] ?? 'ZCOST sheet not found.',
        ];
    }

    private function fetchByFilter(string $vbeln): array
    {
        $service = (string) ($this->cfg['cost_service'] ?? self::DEFAULT_SERVICE);
        $result = $this->client->fetchResults($service, [
            '$filter' => "Vbeln eq '{$vbeln}'",
            '$expand' => self::EXPAND,
        ], 40);

        if (($result['error'] ?? null) !== null) {
            return ['sheets' => [], 'error' => $result['error']];
        }

        $sheets = [];
        foreach ($result['rows'] as $row) {
            if (is_array($row)) {
                $sheets[] = $row;
            }
        }
        return ['sheets' => $sheets, 'error' => null];
    }

    private function fetchByKey(string $vbeln, string $posnr): array
    {
        $base = rtrim((string) ($this->cfg['base_url'] ?? ''), '/');
        $service = (string) ($this->cfg['cost_service'] ?? self::DEFAULT_SERVICE);
        $url = sprintf(
            "%s%s(Vbeln='%s',Posnr='%s')?\$expand=%s&\$format=json",
            $base,
            $service,
            rawurlencode($vbeln),
            rawurlencode($posnr),
            self::EXPAND
        );

        $payload = $this->client->fetchUrl($url, (int) ($this->cfg['timeout'] ?? 45));
        if ($payload['error'] !== null) {
            return ['sheets' => [], 'error' => $payload['error']];
        }

        $body = $payload['body'];
        if (isset($body['d']) && is_array($body['d']) && !isset($body['d']['results'])) {
            return ['sheets' => [$body['d']], 'error' => null];
        }
        if (isset($body['d']['results']) && is_array($body['d']['results'])) {
            return ['sheets' => array_values(array_filter($body['d']['results'], 'is_array')), 'error' => null];
        }

        return ['sheets' => [], 'error' => 'Unexpected ZCOST response format.'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flattenSheet(array $sheet): array
    {
        $vbeln = $this->displayNumber((string) ($sheet['Vbeln'] ?? ''));
        $posnr = $this->displayNumber((string) ($sheet['Posnr'] ?? ''));
        $header = [
            'sell_price' => $this->sapNumber($sheet['Sellprice'] ?? 0),
            'making'     => $this->sapNumber($sheet['Making'] ?? 0),
            'overhead'   => $this->sapNumber($sheet['Overhead'] ?? 0),
            'markup'     => $this->sapNumber($sheet['Markup'] ?? 0),
            'subtot'     => $this->sapNumber($sheet['Subtot'] ?? 0),
            'cnv'        => trim((string) ($sheet['Cnv'] ?? '')),
            'cnvf'       => $this->sapNumber($sheet['Cnvf'] ?? 0),
        ];

        $lines = [];
        foreach (self::NAV_SECTIONS as $nav => $section) {
            $rows = $sheet[$nav]['results'] ?? null;
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines[] = $this->mapLine($row, $section, $vbeln, $posnr, $header);
            }
        }
        return $lines;
    }

    private function mapLine(array $row, string $section, string $vbeln, string $posnr, array $header): array
    {
        $sectionLower = strtolower($section);
        $matCategory = match ($sectionLower) {
            'fabric' => 'fabric',
            'trim', 'packing' => 'trims',
            default => 'other',
        };

        return [
            'section'      => $section,
            'sales_order'  => $vbeln,
            'so_item'      => $posnr !== '' ? $posnr : $this->displayNumber((string) ($row['Posnr'] ?? '')),
            'item'         => trim((string) ($row['Item'] ?? '')),
            'description'  => trim((string) ($row['Maktx'] ?? '')),
            'material'     => trim((string) ($row['Item'] ?? '')),
            'wid_siz'      => trim((string) ($row['WidSiz'] ?? '')),
            'net'          => $this->sapNumber($row['Net'] ?? 0),
            'waste'        => $this->sapNumber($row['Wste'] ?? 0),
            'gross'        => $this->sapNumber($row['Gross'] ?? 0),
            'order_qty'    => $this->sapNumber($row['Gross'] ?? 0),
            'currency'     => trim((string) ($row['Curunit'] ?? '')),
            'purp'         => $this->sapNumber($row['Purp'] ?? 0),
            'convr'        => $this->sapNumber($row['Convr'] ?? 0),
            'freight'      => $this->sapNumber($row['Fright'] ?? 0),
            'duty'         => $this->sapNumber($row['Duty'] ?? 0),
            'fin_cost'     => $this->sapNumber($row['Fincost'] ?? 0),
            'rate'         => $this->sapNumber($row['Rate'] ?? 0),
            'net_price'    => $this->sapNumber($row['Rate'] ?? 0),
            'total_inr'    => $this->sapNumber($row['Totalinr'] ?? 0),
            'net_value'    => $this->sapNumber($row['Totalinr'] ?? 0),
            'total_fr'     => $this->sapNumber($row['Totalfr'] ?? 0),
            'total_per'    => $this->sapNumber($row['Totalper'] ?? 0),
            'sell_price'   => $header['sell_price'],
            'making'       => $header['making'],
            'overhead'     => $header['overhead'],
            'markup'       => $header['markup'],
            'subtot'       => $header['subtot'],
            'mat_category' => $matCategory,
            'material_type'=> $section,
            'mat_type_desc'=> $section . ' costing',
            'material_group'=> $section,
            'unit'         => trim((string) ($row['Curunit'] ?? '')),
            'plant'        => '',
            'storage_location' => '',
            'sales_orders' => $vbeln !== '' ? [$vbeln] : [],
        ];
    }

    private function lineTotals(array $lines): array
    {
        $totalInr = 0.0;
        $gross = 0.0;
        foreach ($lines as $line) {
            $totalInr += (float) ($line['total_inr'] ?? 0);
            $gross += (float) ($line['gross'] ?? 0);
        }
        $currencies = array_values(array_unique(array_filter(array_map(
            static fn ($r) => trim((string) ($r['currency'] ?? '')),
            $lines
        ))));

        return [
            'items'     => count($lines),
            'materials' => count(array_unique(array_filter(array_map(
                static fn ($r) => trim((string) ($r['item'] ?? '')),
                $lines
            )))),
            'order_qty' => round($gross, 4),
            'net_value' => round($totalInr, 4),
            'currency'  => count($currencies) === 1 ? $currencies[0] : 'INR',
            'unit'      => '',
        ];
    }

    /** @return array<int, string> */
    private function vbelnVariants(string $vbeln): array
    {
        $digits = preg_replace('/\D/', '', $vbeln) ?? '';
        if ($digits === '') {
            return [];
        }
        $variants = [$digits];
        $padded10 = str_pad($digits, 10, '0', STR_PAD_LEFT);
        if ($padded10 !== $digits) {
            $variants[] = $padded10;
        }
        $trimmed = ltrim($digits, '0');
        if ($trimmed !== '' && $trimmed !== $digits) {
            $variants[] = $trimmed;
        }
        return array_values(array_unique($variants));
    }

    private function padPosnr(string $posnr): string
    {
        $p = preg_replace('/\D/', '', $posnr) ?? '';
        if ($p === '') {
            return '000010';
        }
        return str_pad($p, 6, '0', STR_PAD_LEFT);
    }
}
