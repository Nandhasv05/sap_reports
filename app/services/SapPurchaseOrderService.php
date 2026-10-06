<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : SAP purchase order list (condition-wise) and details from ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB
 */
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapPurchaseOrderService
{
    use SapValueFormat;

    public const MAX_RANGE_DAYS = 186;
    private const DEFAULT_SERVICE = '/sap/opu/odata/sap/ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB';
    private const SALES_ORDER_FIELDS = 20;
    private const RANGE_TIMEZONE = 'Asia/Kolkata';

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
     * Date presets for PO filtering (exact order requested: Today, Yesterday, This Week, Previous Week, This Month, Previous Month, Custom Date)
     */
    public static function rangePresets(): array
    {
        return [
            'today'      => 'Today',
            'yesterday'  => 'Yesterday',
            'this_week'  => 'This Week',
            'last_week'  => 'Previous Week',
            'this_month' => 'This Month',
            'last_month' => 'Previous Month',
            'custom'     => 'Custom Date',
        ];
    }

    /*
     * Resolve date range preset or custom From/To into Y-m-d format (max 6 months range)
     *
     * @return array{preset: string, from: string, to: string, label: string, error: ?string}
     */
    public static function resolveRange(string $preset, string $from = '', string $to = ''): array
    {
        $tz = new DateTimeZone(self::RANGE_TIMEZONE);
        $today = new DateTimeImmutable('today', $tz);
        $preset = array_key_exists($preset, self::rangePresets()) ? $preset : 'this_month';
        $out = ['preset' => $preset, 'from' => '', 'to' => '', 'label' => self::rangePresets()[$preset], 'error' => null];

        [$start, $end] = match ($preset) {
            'today'      => [$today, $today],
            'yesterday'  => [$today->modify('-1 day'), $today->modify('-1 day')],
            'this_week'  => [$today->modify('monday this week'), $today],
            'last_week'  => [$today->modify('monday last week'), $today->modify('monday last week')->modify('+6 days')],
            'this_month' => [$today->modify('first day of this month'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            default      => [self::parseDate($from, $tz), self::parseDate($to, $tz)],
        };

        if ($start === null || $end === null) {
            $out['error'] = 'Choose both a From and a To date.';
            return $out;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $today) {
            $out['error'] = 'The date range is in the future.';
            return $out;
        }
        $sixMonthsAgo = $today->modify('-186 days');
        if ($start < $sixMonthsAgo) {
            $out['error'] = 'Select dates within the last 6 months.';
            return $out;
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_RANGE_DAYS) {
            $out['error'] = 'Choose a range of 6 months (186 days) or less.';
            return $out;
        }

        $out['from'] = $start->format('Y-m-d');
        $out['to'] = $end->format('Y-m-d');
        if ($preset === 'custom') {
            $out['label'] = $out['from'] === $out['to'] ? $start->format('d M Y') : $start->format('d M Y') . ' – ' . $end->format('d M Y');
        }
        return $out;
    }

    /*
     * From / To (Y-m-d) of presets for client-side date population
     */
    public static function presetDates(): array
    {
        $out = [];
        foreach (array_keys(self::rangePresets()) as $key) {
            if ($key !== 'custom') {
                $r = self::resolveRange($key);
                $out[$key] = ['from' => $r['from'], 'to' => $r['to']];
            }
        }
        return $out;
    }

    private static function parseDate(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $tz);
        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    /*
     * Configured production plants
     */
    public function plants(): array
    {
        $plants = $this->cfg['production_plants'] ?? [];
        return is_array($plants) ? array_values(array_filter(array_map('strval', $plants), [$this, 'validPlant'])) : [];
    }

    public function validPlant(string $plant): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{4}$/', $plant);
    }

    /*
     * Columns for the condition-wise Purchase Order list view (action column removed)
     */
    public static function listColumns(): array
    {
        return [
            ['key' => 'sno',            'label' => 'S.No',         'type' => 'num'],
            ['key' => 'purchase_order', 'label' => 'PO Number',    'type' => 'text'],
            ['key' => 'date',           'label' => 'PO Date',      'type' => 'date'],
            ['key' => 'plant',          'label' => 'Plant',        'type' => 'text'],
            ['key' => 'supplier',       'label' => 'Supplier',     'type' => 'text'],
            ['key' => 'items_count',    'label' => 'Items',        'type' => 'num'],
            ['key' => 'total_qty',      'label' => 'Order Qty',    'type' => 'num'],
            ['key' => 'unit',           'label' => 'Unit',         'type' => 'text'],
            ['key' => 'total_value',    'label' => 'Net Value',    'type' => 'num'],
            ['key' => 'currency',       'label' => 'Currency',     'type' => 'text'],
            ['key' => 'sales_orders',   'label' => 'Sales Orders', 'type' => 'text'],
            ['key' => 'created_by',     'label' => 'Created By',   'type' => 'text'],
        ];
    }

    /*
     * Columns for the single Purchase Order item table
     */
    public static function columns(): array
    {
        return [
            ['key' => 'sno',              'label' => 'S.No',         'type' => 'num'],
            ['key' => 'item',             'label' => 'Item',         'type' => 'num'],
            ['key' => 'material',         'label' => 'Material',     'type' => 'text'],
            ['key' => 'description',      'label' => 'Description',  'type' => 'text'],
            ['key' => 'plant',            'label' => 'Plant',        'type' => 'text'],
            ['key' => 'storage_location', 'label' => 'Storage Loc.', 'type' => 'text'],
            ['key' => 'order_qty',        'label' => 'Order Qty',    'type' => 'num'],
            ['key' => 'unit',             'label' => 'Unit',         'type' => 'text'],
            ['key' => 'net_price',        'label' => 'Net Price',    'type' => 'num'],
            ['key' => 'net_value',        'label' => 'Net Value',    'type' => 'num'],
            ['key' => 'currency',         'label' => 'Currency',     'type' => 'text'],
            ['key' => 'value_share',      'label' => 'Value Share',  'type' => 'num'],
            ['key' => 'sales_orders',     'label' => 'Sales Orders', 'type' => 'text'],
        ];
    }

    /*
     * Condition-wise PO list from SAP ZI_PurchaseOrderAPI_HUB
     *
     * @param array<string, mixed> $filters
     * @return array{records: array, total: int, totals: array, range: array, error: ?string}
     */
    public function listReport(array $filters = []): array
    {
        $preset = strtolower(trim((string) ($filters['range'] ?? 'this_month')));
        $range = self::resolveRange($preset, (string) ($filters['from'] ?? ''), (string) ($filters['to'] ?? ''));
        $plant = strtoupper(trim((string) ($filters['plant'] ?? '')));
        $supplier = trim((string) ($filters['supplier'] ?? ''));
        $so = preg_replace('/\D/', '', (string) ($filters['so'] ?? '')) ?? '';

        $cacheKey = 'po_list_' . md5(json_encode([$range['preset'], $range['from'], $range['to'], $plant, $supplier, $so]));
        $cached = $this->cacheRead($cacheKey, (int) ($this->cfg['cache_ttl'] ?? 300));
        if ($cached !== null) {
            return $cached;
        }

        $filterParts = [];
        if ($range['preset'] !== 'all' && $range['from'] !== '' && $range['to'] !== '') {
            $filterParts[] = "PurchaseOrderDate ge datetime'{$range['from']}T00:00:00' and PurchaseOrderDate le datetime'{$range['to']}T23:59:59'";
        }
        if ($plant !== '') {
            $filterParts[] = "Plant eq '{$plant}'";
        }
        if ($supplier !== '') {
            $supPadded = $this->padSalesOrder($supplier);
            $filterParts[] = "(Supplier eq '{$supplier}' or Supplier eq '{$supPadded}')";
        }

        $query = [
            '$top' => '1000',
            '$orderby' => 'PurchaseOrderDate desc, PurchaseOrder desc',
        ];
        if (!empty($filterParts)) {
            $query['$filter'] = implode(' and ', $filterParts);
        }

        $service = (string) ($this->cfg['purchase_order_service'] ?? self::DEFAULT_SERVICE);
        $result = $this->client->fetchResults($service, $query);
        if (($result['error'] ?? null) !== null) {
            return [
                'records' => [],
                'total'   => 0,
                'totals'  => [
                    'pos_count'       => 0,
                    'items_count'     => 0,
                    'total_qty'       => 0,
                    'total_value'     => 0,
                    'currency'        => 'INR',
                    'suppliers_count' => 0,
                ],
                'range'   => $range,
                'error'   => $result['error'],
            ];
        }

        $pos = [];
        $currencies = [];
        foreach ($result['rows'] as $row) {
            if (!is_array($row)) continue;
            $poNum = $this->displayNumber((string) ($row['PurchaseOrder'] ?? ''));
            if ($poNum === '') continue;

            $soList = [];
            for ($i = 1; $i <= self::SALES_ORDER_FIELDS; $i++) {
                $v = $this->displayNumber((string) ($row['zzvbeln' . $i] ?? ''));
                if ($v !== '') $soList[] = $v;
            }
            if ($so !== '' && !in_array($so, $soList, true)) {
                continue;
            }

            if (!isset($pos[$poNum])) {
                $pos[$poNum] = [
                    'purchase_order'   => $poNum,
                    'date'             => $this->sapDate((string) ($row['PurchaseOrderDate'] ?? '')),
                    'supplier'         => $this->displayNumber((string) ($row['Supplier'] ?? '')),
                    'company_code'     => trim((string) ($row['CompanyCode'] ?? '')),
                    'purchasing_org'   => trim((string) ($row['PurchasingOrganization'] ?? '')),
                    'purchasing_group' => trim((string) ($row['PurchasingGroup'] ?? '')),
                    'created_by'       => trim((string) ($row['CreatedByUser'] ?? '')),
                    'plant'            => trim((string) ($row['Plant'] ?? '')),
                    'plants'           => [],
                    'items_count'      => 0,
                    'materials'        => [],
                    'total_qty'        => 0.0,
                    'unit'             => trim((string) ($row['PurchaseOrderQuantityUnit'] ?? '')),
                    'total_value'      => 0.0,
                    'currency'         => trim((string) ($row['DocumentCurrency'] ?? '')),
                    'sales_orders'     => [],
                ];
            }

            $p = &$pos[$poNum];
            $p['items_count']++;
            $plantVal = trim((string) ($row['Plant'] ?? ''));
            if ($plantVal !== '' && !in_array($plantVal, $p['plants'], true)) {
                $p['plants'][] = $plantVal;
            }
            $mat = trim((string) ($row['Material'] ?? ''));
            if ($mat !== '' && !in_array($mat, $p['materials'], true)) {
                $p['materials'][] = $mat;
            }
            $qty = $this->sapNumber($row['OrderQuantity'] ?? '');
            $price = $this->sapNumber($row['NetPriceAmount'] ?? '');
            $p['total_qty'] += $qty;
            $p['total_value'] += round($qty * $price, 2);
            foreach ($soList as $s) {
                if (!in_array($s, $p['sales_orders'], true)) {
                    $p['sales_orders'][] = $s;
                }
            }
            if ($p['currency'] !== '') {
                $currencies[$p['currency']] = ($currencies[$p['currency']] ?? 0) + 1;
            }
        }
        unset($p);

        $records = array_values($pos);
        foreach ($records as &$r) {
            $r['plant'] = implode(', ', $r['plants']);
            $r['total_qty'] = round($r['total_qty'], 3);
            $r['total_value'] = round($r['total_value'], 2);
            $r['materials_count'] = count($r['materials']);
        }
        unset($r);

        arsort($currencies);
        $primaryCurrency = key($currencies) ?: 'INR';

        $totalQty = 0.0;
        $totalVal = 0.0;
        $totalItems = 0;
        $uniqueSuppliers = [];
        foreach ($records as $r) {
            $totalQty += $r['total_qty'];
            $totalVal += $r['total_value'];
            $totalItems += $r['items_count'];
            if ($r['supplier'] !== '') {
                $uniqueSuppliers[$r['supplier']] = true;
            }
        }

        $out = [
            'records' => $records,
            'total'   => count($records),
            'totals'  => [
                'pos_count'       => count($records),
                'items_count'     => $totalItems,
                'total_qty'       => round($totalQty, 3),
                'total_value'     => round($totalVal, 2),
                'currency'        => $primaryCurrency,
                'suppliers_count' => count($uniqueSuppliers),
            ],
            'range'   => $range,
            'error'   => null,
        ];

        if ($range['error'] === null) {
            $this->cacheWrite($cacheKey, $out);
        }

        return $out;
    }

    /*
     * Detailed single PO report
     *
     * @return array{purchase_order: string, header: array, records: array, totals: array, error: ?string}
     */
    public function report(string $purchaseOrder): array
    {
        $po = $this->padSalesOrder($purchaseOrder);
        $out = [
            'purchase_order' => $this->displayNumber($po),
            'header'         => [],
            'records'        => [],
            'totals'         => [],
            'error'          => null,
        ];
        if (!preg_match('/^\d{1,10}$/', $po)) {
            $out['error'] = 'Purchase order must be a number of up to 10 digits.';
            return $out;
        }

        $cacheKey = 'po_detail_' . $po;
        $cached = $this->cacheRead($cacheKey, (int) ($this->cfg['cache_ttl'] ?? 300));
        if ($cached !== null) {
            return $cached;
        }

        $service = (string) ($this->cfg['purchase_order_service'] ?? self::DEFAULT_SERVICE);
        $result = $this->client->fetchResults($service, ['$filter' => "PurchaseOrder eq '{$po}'"]);
        if (($result['error'] ?? null) !== null) {
            $out['error'] = $result['error'];
            return $out;
        }

        $rows = [];
        foreach ($result['rows'] as $row) {
            if (is_array($row)) {
                $rows[] = $this->mapItem($row);
            }
        }
        usort($rows, static fn (array $a, array $b): int => (int) $a['item'] <=> (int) $b['item']);
        if ($rows === []) {
            return $out;
        }

        $first = $result['rows'][0];
        $out['header'] = [
            'purchase_order'   => $this->displayNumber((string) ($first['PurchaseOrder'] ?? $po)),
            'date'             => $this->sapDate((string) ($first['PurchaseOrderDate'] ?? '')),
            'supplier'         => $this->displayNumber((string) ($first['Supplier'] ?? '')),
            'company_code'     => trim((string) ($first['CompanyCode'] ?? '')),
            'purchasing_org'   => trim((string) ($first['PurchasingOrganization'] ?? '')),
            'purchasing_group' => trim((string) ($first['PurchasingGroup'] ?? '')),
            'created_by'       => trim((string) ($first['CreatedByUser'] ?? '')),
            'plants'           => $this->distinct($rows, 'plant'),
            'sales_orders'     => array_values(array_unique(array_merge(...array_column($rows, 'sales_orders')))),
        ];
        $out['records'] = $rows;
        $out['totals'] = $this->totals($rows);

        $this->cacheWrite($cacheKey, $out);
        return $out;
    }

    private function mapItem(array $row): array
    {
        $qty = $this->sapNumber($row['OrderQuantity'] ?? '');
        $price = $this->sapNumber($row['NetPriceAmount'] ?? '');
        $salesOrders = [];
        for ($i = 1; $i <= self::SALES_ORDER_FIELDS; $i++) {
            $so = $this->displayNumber((string) ($row['zzvbeln' . $i] ?? ''));
            if ($so !== '' && !in_array($so, $salesOrders, true)) {
                $salesOrders[] = $so;
            }
        }

        return [
            'item'             => $this->displayNumber((string) ($row['PurchaseOrderItem'] ?? '')),
            'material'         => trim((string) ($row['Material'] ?? '')),
            'description'      => trim((string) ($row['PurchaseOrderItemText'] ?? '')),
            'plant'            => trim((string) ($row['Plant'] ?? '')),
            'storage_location' => trim((string) ($row['StorageLocation'] ?? '')),
            'order_qty'        => $qty,
            'unit'             => trim((string) ($row['PurchaseOrderQuantityUnit'] ?? '')),
            'net_price'        => $price,
            'net_value'        => round($qty * $price, 2),
            'currency'         => trim((string) ($row['DocumentCurrency'] ?? '')),
            'sales_orders'     => $salesOrders,
        ];
    }

    /*
     * Quantity and value are only summed when every item shares the same unit / currency.
     */
    private function totals(array $rows): array
    {
        $units = $this->distinct($rows, 'unit');
        $currencies = $this->distinct($rows, 'currency');
        return [
            'items'      => count($rows),
            'materials'  => count($this->distinct($rows, 'material')),
            'unit'       => count($units) === 1 ? $units[0] : '',
            'order_qty'  => count($units) === 1 ? round(array_sum(array_column($rows, 'order_qty')), 3) : null,
            'currency'   => count($currencies) === 1 ? $currencies[0] : '',
            'net_value'  => count($currencies) === 1 ? round(array_sum(array_column($rows, 'net_value')), 2) : null,
        ];
    }

    private function distinct(array $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_column($rows, $key), static fn ($v): bool => $v !== '')));
    }

    /*
     * Cache utilities
     */
    private function cacheRead(string $name, int $ttl): ?array
    {
        if ($ttl <= 0) return null;
        $file = $this->cacheFile($name);
        if ($file === null || !is_file($file)) return null;
        $mtime = filemtime($file);
        if ($mtime === false || (time() - $mtime) > $ttl) return null;
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function cacheWrite(string $name, array $payload): void
    {
        $file = (int) ($this->cfg['cache_ttl'] ?? 0) > 0 ? $this->cacheFile($name) : null;
        if ($file !== null) {
            @file_put_contents($file, json_encode($payload), LOCK_EX);
        }
    }

    private function cacheFile(string $name): ?string
    {
        static $dir = false;
        if ($dir === false) {
            $dir = null;
            foreach ([base_path('storage/cache'), rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'sap_reports'] as $candidate) {
                if ((is_dir($candidate) || @mkdir($candidate, 0775, true)) && is_writable($candidate)) {
                    $dir = $candidate;
                    break;
                }
            }
        }
        return $dir === null ? null : $dir . DIRECTORY_SEPARATOR . 'sap_po_v' . (int) ($this->cfg['cache_version'] ?? 1) . '_' . $name . '.json';
    }
}
