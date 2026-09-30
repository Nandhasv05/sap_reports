<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : SAP production (cut / sew / wash / finish / ship) status from ZBUSINESS_API_SRV/ZPROD_NEWSet
 */
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapProductionService
{
    use SapValueFormat;

    /** Longest date range (days) that may be loaded at once; each sales order in it is a separate SAP call */
    public const MAX_RANGE_DAYS = 62;
    private const RANGE_TIMEZONE = 'Asia/Kolkata';
    private const RANGE_CHUNK_DAYS = 7;

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
     * Table columns: key, label, type (text|num|date), stage (header colour group)
     */
    public static function columns(): array
    {
        return [
            ['key' => 'sno',               'label' => 'S.No',                 'type' => 'num',  'stage' => ''],
            ['key' => 'sales_order',       'label' => 'Sales Order',          'type' => 'text', 'stage' => ''],
            ['key' => 'so_item',           'label' => 'SO Item',              'type' => 'num',  'stage' => ''],
            ['key' => 'plant',             'label' => 'Plant',                'type' => 'text', 'stage' => ''],
            ['key' => 'customer',          'label' => 'Customer',             'type' => 'text', 'stage' => ''],
            ['key' => 'customer_name',     'label' => 'Customer Name',        'type' => 'text', 'stage' => ''],
            ['key' => 'header_material',   'label' => 'Header Material',      'type' => 'text', 'stage' => ''],
            ['key' => 'style',             'label' => 'Style',                'type' => 'text', 'stage' => ''],
            ['key' => 'style_description', 'label' => 'Style Description',    'type' => 'text', 'stage' => ''],
            ['key' => 'material_group',    'label' => 'Material Group',       'type' => 'text', 'stage' => ''],
            ['key' => 'colour',            'label' => 'Colour',               'type' => 'text', 'stage' => ''],
            ['key' => 'size',              'label' => 'Size',                 'type' => 'text', 'stage' => ''],
            ['key' => 'fit',               'label' => 'Fit',                  'type' => 'text', 'stage' => ''],
            ['key' => 'delivery_date',     'label' => 'Req. Delivery',        'type' => 'date', 'stage' => ''],
            ['key' => 'so_qty',            'label' => 'SO Qty',               'type' => 'num',  'stage' => 'order'],
            ['key' => 'tolerance',         'label' => 'Over Del. Tol.',       'type' => 'text', 'stage' => 'order'],
            ['key' => 'total_so_qty',      'label' => 'Total SO Qty',         'type' => 'num',  'stage' => 'order'],
            ['key' => 'cut_order',         'label' => 'Cut Order',            'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_made',          'label' => 'Cut Made',             'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_reject',        'label' => 'Cut Reject',           'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_pending_so',    'label' => 'Cut Pending (SO)',     'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_pending_co',    'label' => 'Cut Pending (CO)',     'type' => 'num',  'stage' => 'cut'],
            ['key' => 'ready_sewing',      'label' => 'Ready for Sewing',     'type' => 'num',  'stage' => 'cut'],
            ['key' => 'sew_order',         'label' => 'Sew Order',            'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_made',          'label' => 'Sew Made',             'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_reject',        'label' => 'Sew Reject',           'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_pending_so',    'label' => 'Sew Pending (SO)',     'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_pending_fo',    'label' => 'Sew Pending (FO)',     'type' => 'num',  'stage' => 'sew'],
            ['key' => 'ready_wash',        'label' => 'Ready for Wash',       'type' => 'num',  'stage' => 'sew'],
            ['key' => 'wash_sent',         'label' => 'Wash Sent',            'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_received',     'label' => 'Wash Received',        'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_reject',       'label' => 'Wash Reject',          'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_pending_so',   'label' => 'Wash Pending (SO)',    'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_pending_spo',  'label' => 'Wash Pending (SPO)',   'type' => 'num',  'stage' => 'wash'],
            ['key' => 'ready_finishing',   'label' => 'Ready for Finishing',  'type' => 'num',  'stage' => 'wash'],
            ['key' => 'fin_order',         'label' => 'Finish Order',         'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_made',          'label' => 'Finish Made',          'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_reject',        'label' => 'Finish Reject',        'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_pending_so',    'label' => 'Finish Pending (SO)',  'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_pending_po',    'label' => 'Finish Pending (PO)',  'type' => 'num',  'stage' => 'fin'],
            ['key' => 'ready_delivery',    'label' => 'Ready for Delivery',   'type' => 'num',  'stage' => 'fin'],
            ['key' => 'good_garment',      'label' => 'Good Garment Qty',     'type' => 'num',  'stage' => 'ship'],
            ['key' => 'shipment',          'label' => 'Shipment Qty',         'type' => 'num',  'stage' => 'ship'],
            ['key' => 'pending_shipment',  'label' => 'Pending Shipment',     'type' => 'num',  'stage' => 'ship'],
            ['key' => 'delivery_transfer', 'label' => 'Delivery / Transfer',  'type' => 'num',  'stage' => 'ship'],
            ['key' => 'pending_delivery',  'label' => 'Pending Delivery',     'type' => 'num',  'stage' => 'ship'],
        ];
    }

    /*
     * Columns that repeat the same value on every line of one sales order; hidden until the user shows them.
     */
    public static function defaultHiddenColumns(): array
    {
        return ['sales_order', 'plant', 'customer', 'customer_name', 'header_material', 'material_group'];
    }

    /*
     * Date-range mode lists many sales orders, so the order / customer columns stay visible there.
     */
    public static function defaultHiddenRangeColumns(): array
    {
        return ['customer', 'material_group'];
    }

    /*
     * Date presets for the SO creation date filter (key => label)
     */
    public static function rangePresets(): array
    {
        return [
            'today'      => 'Today',
            'yesterday'  => 'Yesterday',
            'this_week'  => 'This Week',
            'this_month' => 'This Month',
            'last_week'  => 'Previous Week',
            'last_month' => 'Previous Month',
            'custom'     => 'Custom Date',
        ];
    }

    /*
     * Turn a preset (weeks start on Monday) or a custom from / to into a validated Y-m-d range.
     *
     * @return array{preset: string, from: string, to: string, label: string, error: ?string}
     */
    public static function resolveRange(string $preset, string $from = '', string $to = ''): array
    {
        $tz = new DateTimeZone(self::RANGE_TIMEZONE);
        $today = new DateTimeImmutable('today', $tz);
        $preset = array_key_exists($preset, self::rangePresets()) ? $preset : 'today';
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
        if ((int) $start->diff($end)->days + 1 > self::MAX_RANGE_DAYS) {
            $out['error'] = 'Choose a range of ' . self::MAX_RANGE_DAYS . ' days or less.';
            return $out;
        }

        $out['from'] = $start->format('Y-m-d');
        $out['to'] = $end->format('Y-m-d');
        if ($preset === 'custom') {
            $out['label'] = $out['from'] === $out['to'] ? $start->format('d M Y') : $start->format('d M Y') . ' – ' . $end->format('d M Y');
        }
        return $out;
    }

    private static function parseDate(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $tz);
        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    /*
     * Configured plant list for the plant selector
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
     * @return array{records: array, total: int, page: int, pages: int, per_page: int, sales_order: string, plants: array, info: array, error: ?string}
     */
    public function report(string $salesOrder, string $plant = ''): array
    {
        $so = $this->padSalesOrder($salesOrder);
        $plant = strtoupper(trim($plant));
        $out = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => 0,
            'sales_order' => $this->displayNumber($so),
            'plants'      => [],
            'info'        => [],
            'warning'     => '',
            'error'       => null,
        ];
        if ($so === '') {
            return $out;
        }
        if (!preg_match('/^\d{1,10}$/', $so)) {
            $out['error'] = 'Sales order must be a number.';
            return $out;
        }
        if ($plant !== '' && !$this->validPlant($plant)) {
            $out['error'] = 'Plant must be a 4 character code, for example P002. Leave it empty to find the plant automatically.';
            return $out;
        }

        // A wrong plant makes ZPROD_NEWSet scan for ~20 s and return nothing, so check the order's real plant first.
        $detected = $this->detectPlants($so);
        $soText = $this->displayNumber($so);
        if ($plant === '') {
            if ($detected['error'] !== null) {
                $out['error'] = $detected['error'];
                return $out;
            }
            $plants = $detected['plants'];
            if ($plants === []) {
                $out['error'] = 'Sales order ' . $soText . ' was not found in SAP. Check the number or enter the plant.';
                return $out;
            }
        } elseif ($detected['plants'] !== [] && !in_array($plant, $detected['plants'], true)) {
            $plants = $detected['plants'];
            $out['warning'] = 'Sales order ' . $soText . ' belongs to plant ' . implode(', ', $plants) . ', not ' . $plant . '. Showing plant ' . implode(', ', $plants) . '.';
        } else {
            $plants = [$plant];
        }

        $service = (string) ($this->cfg['production_service'] ?? '');
        if ($service === '') {
            $out['error'] = 'Production service path is not configured.';
            return $out;
        }

        $rows = [];
        foreach ($plants as $p) {
            $result = $this->client->fetchResults($service, [
                '$filter' => "SalesOrder eq '{$so}' and Plant eq '{$p}'",
            ]);
            if (($result['error'] ?? null) !== null) {
                $out['error'] = $result['error'];
                return $out;
            }
            foreach ($result['rows'] as $row) {
                if (is_array($row)) {
                    $rows[] = $this->mapRow($row);
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['plant'], (int) $a['so_item']] <=> [$b['plant'], (int) $b['so_item']]);

        $first = $rows[0] ?? [];
        $out['records'] = $rows;
        $out['total'] = count($rows);
        $out['per_page'] = count($rows);
        $out['plants'] = $plants;
        $out['info'] = [
            'customer'        => (string) ($first['customer'] ?? ''),
            'customer_name'   => (string) ($first['customer_name'] ?? ''),
            'header_material' => (string) ($first['header_material'] ?? ''),
            'delivery_date'   => (string) ($first['delivery_date'] ?? ''),
            'tolerance'       => (string) ($first['tolerance'] ?? ''),
        ];
        return $out;
    }

    /*
     * Sales orders (+ production plant) created between $from and $to (Y-m-d, already validated by resolveRange).
     * ZPROD_NEWSet cannot filter by date, so the page loads this list first and then the production in batches (pairsReport).
     *
     * Optional $salesOrder / $plant narrow the list (both must match).
     *
     * @return array{orders: array<int, array{so: string, plant: string}>, orders_found: int, range: array, warning: string, error: ?string}
     */
    public function rangeOrders(string $from, string $to, string $salesOrder = '', string $plant = ''): array
    {
        $out = $this->allRangeOrders($from, $to);
        $so = $this->displayNumber(preg_replace('/\D/', '', $salesOrder) ?? '');
        $plant = strtoupper(trim($plant));
        if ($out['error'] === null && ($so !== '' || $plant !== '')) {
            $out['orders'] = array_values(array_filter(
                $out['orders'],
                static fn (array $o): bool => ($so === '' || $o['so'] === $so) && ($plant === '' || $o['plant'] === $plant)
            ));
            $out['orders_found'] = count(array_unique(array_column($out['orders'], 'so')));
        }
        return $out;
    }

    private function allRangeOrders(string $from, string $to): array
    {
        $cacheName = 'orders_' . $from . '_' . $to;
        $cached = $this->cacheRead($cacheName, (int) ($this->cfg['cache_ttl'] ?? 0));
        if ($cached !== null) {
            return $cached;
        }

        $found = $this->ordersCreatedBetween($from, $to);
        $orders = array_map(fn (array $p): array => ['so' => $this->displayNumber($p['so']), 'plant' => $p['plant']], array_values($found['pairs']));
        $out = [
            'orders'       => $orders,
            'orders_found' => count(array_unique(array_column($orders, 'so'))),
            'range'        => ['from' => $from, 'to' => $to],
            'warning'      => $found['truncated'] ? 'SAP returned the maximum number of order lines for part of this range, so some sales orders may be missing. Choose a shorter range.' : '',
            'error'        => $found['error'],
        ];
        if ($found['error'] === null && !$found['truncated']) {
            $this->cacheWrite($cacheName, $out);
        }
        return $out;
    }

    /*
     * Production rows for a batch of sales order + plant pairs, fetched in parallel. Each pair is cached on its own
     * (orders without production for longer, because SAP needs ~20 s to answer them with nothing).
     *
     * @param array<int, array{so: string, plant: string}> $pairs
     * @return array{records: array, failed: array<int, string>, error: ?string}
     */
    public function pairsReport(array $pairs): array
    {
        $service = (string) ($this->cfg['production_service'] ?? '');
        if ($service === '') {
            return ['records' => [], 'failed' => [], 'error' => 'Production service path is not configured.'];
        }
        $ttl = (int) ($this->cfg['cache_ttl'] ?? 0);
        $emptyTtl = max($ttl, (int) ($this->cfg['production_empty_ttl'] ?? 0));

        $rowsByPair = [];
        $queries = [];
        foreach ($pairs as $pair) {
            $so = $this->padSalesOrder($pair['so']);
            $key = $so . '|' . $pair['plant'];
            $cacheName = 'pair_' . $so . '_' . $pair['plant'];
            $cached = $this->cacheRead($cacheName, $emptyTtl);
            if ($cached !== null && ($cached === [] || $this->cacheAge($cacheName) <= $ttl)) {
                $rowsByPair[$key] = $cached;
                continue;
            }
            $queries[$key] = ['$filter' => "SalesOrder eq '{$so}' and Plant eq '{$pair['plant']}'"];
        }

        $failed = [];
        if ($queries !== []) {
            @set_time_limit(300);
            $results = $this->client->fetchMany($service, $queries, max(1, (int) ($this->cfg['production_concurrency'] ?? 8)));
            foreach ($results as $key => $result) {
                [$so, $plant] = explode('|', (string) $key);
                if ($result['error'] !== null) {
                    $failed[] = $this->displayNumber($so);
                    continue;
                }
                $rowsByPair[$key] = array_map(fn (array $row): array => $this->mapRow($row), $result['rows']);
                $this->cacheWrite('pair_' . $so . '_' . $plant, $rowsByPair[$key]);
            }
        }

        $records = array_merge([], ...array_values($rowsByPair));
        usort($records, static fn (array $a, array $b): int => [(int) $a['sales_order'], $a['plant'], (int) $a['so_item']] <=> [(int) $b['sales_order'], $b['plant'], (int) $b['so_item']]);
        return ['records' => $records, 'failed' => array_values(array_unique($failed)), 'error' => null];
    }

    /*
     * The whole range in one call (CSV export); uses the same per-pair cache the page filled while loading.
     */
    public function rangeReport(string $from, string $to, string $salesOrder = '', string $plant = ''): array
    {
        $orders = $this->rangeOrders($from, $to, $salesOrder, $plant);
        if ($orders['error'] !== null) {
            return ['records' => [], 'total' => 0, 'error' => $orders['error']];
        }
        $report = $this->pairsReport($orders['orders']);
        return ['records' => $report['records'], 'total' => count($report['records']), 'error' => null];
    }

    /*
     * "4489-P002,4543-P003" from the page -> validated pairs (at most $max)
     *
     * @return array<int, array{so: string, plant: string}>
     */
    public function parsePairs(string $raw, int $max): array
    {
        $plants = $this->plants();
        $pairs = [];
        foreach (explode(',', $raw) as $item) {
            if (preg_match('/^(\d{1,10})-([A-Z0-9]{4})$/', strtoupper(trim($item)), $m) && in_array($m[2], $plants, true)) {
                $pairs[$m[1] . '-' . $m[2]] = ['so' => $m[1], 'plant' => $m[2]];
            }
        }
        return array_slice(array_values($pairs), 0, $max);
    }

    /*
     * Distinct sales order + production plant pairs created in the range, read in weekly chunks so no chunk hits max_rows.
     *
     * @return array{pairs: array<string, array{so: string, plant: string}>, truncated: bool, error: ?string}
     */
    private function ordersCreatedBetween(string $from, string $to): array
    {
        $service = (string) ($this->cfg['service'] ?? '');
        $maxRows = (int) ($this->cfg['max_rows'] ?? 10000);
        $queries = [];
        $cursor = new DateTimeImmutable($from);
        $last = new DateTimeImmutable($to);
        while ($cursor <= $last) {
            $chunkEnd = min($cursor->modify('+' . (self::RANGE_CHUNK_DAYS - 1) . ' days'), $last);
            $queries[] = [
                '$filter' => "Creationdate ge datetime'{$cursor->format('Y-m-d')}T00:00:00' and Creationdate le datetime'{$chunkEnd->format('Y-m-d')}T23:59:59'",
                '$select' => 'Salesorder,Plant',
            ];
            $cursor = $chunkEnd->modify('+1 day');
        }

        $plants = $this->plants();
        $soPattern = (string) ($this->cfg['production_so_pattern'] ?? '');
        $pairs = [];
        $truncated = false;
        foreach ($this->client->fetchMany($service, $queries, 4) as $result) {
            if ($result['error'] !== null) {
                return ['pairs' => [], 'truncated' => false, 'error' => 'Could not read sales orders for this date range: ' . $result['error']];
            }
            $truncated = $truncated || count($result['rows']) >= $maxRows;
            foreach ($result['rows'] as $row) {
                $so = $this->padSalesOrder((string) ($row['Salesorder'] ?? ''));
                $plant = strtoupper(trim((string) ($row['Plant'] ?? '')));
                if (preg_match('/^\d{10}$/', $so) && in_array($plant, $plants, true)
                    && ($soPattern === '' || preg_match($soPattern, $this->displayNumber($so)) === 1)) {
                    $pairs[$so . '|' . $plant] = ['so' => $so, 'plant' => $plant];
                }
            }
        }
        ksort($pairs);
        return ['pairs' => $pairs, 'truncated' => $truncated, 'error' => null];
    }

    private function cacheRead(string $name, int $ttl): ?array
    {
        $age = $ttl > 0 ? $this->cacheAge($name) : null;
        if ($age === null || $age > $ttl) {
            return null;
        }
        $data = json_decode((string) @file_get_contents((string) $this->cacheFile($name)), true);
        return is_array($data) ? $data : null;
    }

    private function cacheAge(string $name): ?int
    {
        $file = $this->cacheFile($name);
        $mtime = $file !== null && is_file($file) ? filemtime($file) : false;
        return $mtime === false ? null : time() - $mtime;
    }

    private function cacheWrite(string $name, array $payload): void
    {
        $file = (int) ($this->cfg['cache_ttl'] ?? 0) > 0 ? $this->cacheFile($name) : null;
        if ($file !== null) {
            @file_put_contents($file, json_encode($payload), LOCK_EX);
        }
    }

    /*
     * storage/cache when the web server can write there, otherwise the system temp directory
     */
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
        return $dir === null ? null : $dir . DIRECTORY_SEPARATOR . 'sap_production_v' . (int) ($this->cfg['cache_version'] ?? 1) . '_' . $name . '.json';
    }

    /*
     * Find the plant(s) of a sales order from the sales order items CDS view.
     *
     * @return array{plants: array<int, string>, error: ?string}
     */
    private function detectPlants(string $so): array
    {
        $service = (string) ($this->cfg['service'] ?? '');
        $result = $this->client->fetchResults($service, [
            '$filter' => "Salesorder eq '{$so}'",
            '$select' => 'Salesorder,Plant',
        ]);
        if (($result['error'] ?? null) !== null) {
            return ['plants' => [], 'error' => 'Could not find the plant for this sales order: ' . $result['error']];
        }
        $plants = [];
        foreach ($result['rows'] as $row) {
            $p = strtoupper(trim((string) ($row['Plant'] ?? '')));
            if ($this->validPlant($p) && !in_array($p, $plants, true)) {
                $plants[] = $p;
            }
        }
        sort($plants);
        return ['plants' => $plants, 'error' => null];
    }

    private function mapRow(array $row): array
    {
        $q = fn (string $field): float => $this->sapNumber($row[$field] ?? '');
        return [
            'sales_order'       => $this->displayNumber((string) ($row['SalesOrder'] ?? '')),
            'so_item'           => $this->displayNumber((string) ($row['SalesOrderItem'] ?? '')),
            'plant'             => trim((string) ($row['Plant'] ?? '')),
            'customer'          => $this->displayNumber((string) ($row['Customer'] ?? '')),
            'customer_name'     => trim((string) ($row['CustomerName'] ?? '')),
            'header_material'   => trim((string) ($row['HeaderMaterial'] ?? '')),
            'style'             => trim((string) ($row['Style'] ?? '')),
            'style_description' => trim((string) ($row['StyleDescription'] ?? '')),
            'material_group'    => trim((string) ($row['MaterialGroup'] ?? '')),
            'colour'            => trim((string) ($row['Characteristic1'] ?? '')),
            'size'              => trim((string) ($row['Characteristic2'] ?? '')),
            'fit'               => trim((string) ($row['Characteristic3'] ?? '')),
            'delivery_date'     => $this->sapDate((string) ($row['RequestedDeliveryDate'] ?? '')),
            'so_qty'            => $q('SalesOrderQuantity'),
            'tolerance'         => trim((string) ($row['OverDeliveryTolerance'] ?? '')),
            'total_so_qty'      => $q('TotalSalesOrderQuantity'),
            'cut_order'         => $q('CutOrderQty'),
            'cut_made'          => $q('CutMadeQty'),
            'cut_reject'        => $q('CutRejectQty'),
            'cut_pending_so'    => $q('CutPendingQtySO'),
            'cut_pending_co'    => $q('CutPendingQtyCO'),
            'ready_sewing'      => $q('CutReadyForSewing'),
            'sew_order'         => $q('SewOrderQty'),
            'sew_made'          => $q('SewMadeQty'),
            'sew_reject'        => $q('SewRejectQty'),
            'sew_pending_so'    => $q('SewPendingQtySO'),
            'sew_pending_fo'    => $q('SewPendingQtyFO'),
            'ready_wash'        => $q('GarmentReadyForWash'),
            'wash_sent'         => $q('WashSentQty'),
            'wash_received'     => $q('WashReceivedQty'),
            'wash_reject'       => $q('WashRejectQty'),
            'wash_pending_so'   => $q('WashPendingQtySO'),
            'wash_pending_spo'  => $q('WashPendingQtySPO'),
            'ready_finishing'   => $q('GarmentReadyForFinishing'),
            'fin_order'         => $q('FinOrderQty'),
            'fin_made'          => $q('FinMadeQty'),
            'fin_reject'        => $q('FinRejectQty'),
            'fin_pending_so'    => $q('FinPendingQtySO'),
            'fin_pending_po'    => $q('FinPendingQtyPO'),
            'ready_delivery'    => $q('GarmentReadyForDelivery'),
            'good_garment'      => $q('GoodGarmentQty'),
            'shipment'          => $q('ShipmentQty'),
            'pending_shipment'  => $q('PendingShipmentQty'),
            'delivery_transfer' => $q('DeliveryTransferQty'),
            'pending_delivery'  => $q('PendingDeliveryQty'),
        ];
    }
}
