<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Sales Model for the sales dashboard
 */

/*
 * Sales Model class
 */
require_once base_path('app/services/SapSalesService.php');

class SalesModel
{
    private DashboardModel $dashboards;
    private SapSalesService $sap;
    private ?array $staticRecords = null;

    /*
     * Constructor
     */
    public function __construct()
    {
        $this->dashboards = new DashboardModel();
        $this->sap = new SapSalesService();
    }

    /*
     * Dashboard method
     */
    public function dashboard(): ?array
    {
        return $this->dashboards->find('sales');
    }

    /*
     * Lightweight page context — does not load all records into memory.
     */
    public function salesPage(int $year): array
    {
        $cfg = $this->dashboard();
        if (!$cfg) {
            return [
                'cfg'             => [],
                'sap_error'       => 'Sales dashboard not found.',
                'sap_rows'        => 0,
                'data_source'     => 'static',
                'currency_symbol' => '$',
            ];
        }

        $sap = ['payload' => [], 'error' => null, 'row_count' => 0];
        try {
            $sap = $this->sap->buildDashboardPayload($year);
        } catch (Throwable $e) {
            $sap['error'] = 'SAP processing failed: ' . $e->getMessage();
        }

        if ($sap['payload'] !== []) {
            $payload = $sap['payload'];

            return [
                'cfg'             => array_merge($cfg, [
                    'title' => $cfg['title'],
                    'primary' => $cfg['primary'],
                    'primary_dark' => $cfg['primary_dark'],
                ]),
                'sap_error'       => null,
                'sap_rows'        => $sap['row_count'],
                'data_source'     => 'sap',
                'currency_symbol' => $payload['currency_symbol'] ?? '$',
            ];
        }

        return [
            'cfg'             => $cfg,
            'sap_error'       => $sap['error'],
            'sap_rows'        => 0,
            'data_source'     => !empty(config('sap')['enabled']) ? 'sap' : 'static',
            'currency_symbol' => (string) (config('sap')['currency_symbol'] ?? '$'),
        ];
    }

    /*
     * Get Sales Data directly from SAP
     */
    public function getSalesData(array $filters = []): array
    {
        return $this->sap->getSalesData($filters);
    }

    /*
     * Paginated records method
     */
    public function paginatedRecords(int $year, int $page, int $perPage, string $search, string $from, string $to): array
    {
        return $this->sap->getSalesData([
            'year'     => $year,
            'page'     => $page,
            'per_page' => $perPage,
            'search'   => $search,
            'from'     => $from,
            'to'       => $to,
        ]);
    }

    /*
     * Paginate static method
     */
    private function paginateStatic(int $page, int $perPage, string $search, string $from, string $to): array
    {
        if ($this->staticRecords === null) {
            $cfg = $this->dashboard();
            $this->staticRecords = $this->buildStaticRecords(
                $this->dashboards->prepareItems($cfg ?? []),
                '$'
            );
        }

        $search = strtolower(trim($search));
        $filtered = array_values(array_filter($this->staticRecords, function (array $r) use ($search, $from, $to) {
            if ($from !== '' && $to !== '' && !empty($r['date_iso'])) {
                if ($r['date_iso'] < $from || $r['date_iso'] > $to) {
                    return false;
                }
            }
            if ($search === '') {
                return true;
            }
            $hay = strtolower(implode(' ', [
                $r['sales_order'], $r['style'], $r['material'], $r['division'], $r['status'],
            ]));

            return str_contains($hay, $search);
        }));

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $net = array_sum(array_column($filtered, 'net_amount'));
        $cost = array_sum(array_column($filtered, 'cost'));
        $qty = array_sum(array_column($filtered, 'qty'));
        $returnQty = array_sum(array_map(fn($r) => !empty($r['is_return']) ? ($r['qty'] ?? 0) : 0, $filtered));

        return [
            'records'  => array_slice($filtered, $offset, $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'summary'  => [
                'net_sales'    => $net,
                'lines'        => $total,
                'orders'       => count(array_unique(array_column($filtered, 'sales_order'))),
                'avg_line'     => $total > 0 ? $net / $total : 0,
                'total_qty'    => $qty,
                'return_rate'  => $qty > 0 ? ($returnQty / $qty) * 100 : 0,
                'gross_margin' => $net > 0 ? (($net - $cost) / $net) * 100 : 0,
            ],
            'charts'   => $this->sap->buildChartsFromRecords($filtered),
        ];
    }

    /*
     * Build static records method
     */
    private function buildStaticRecords(array $items, string $symbol): array
    {
        $records = [];

        foreach ($items as $item) {
            $qty = array_sum($item['monthly_units'] ?? []);
            if ($qty <= 0) {
                $qty = 10;
            }
            $amount = $qty * ($item['price'] ?? 0);
            $orderNo = 'DEMO-' . str_pad((string) $item['id'], 4, '0', STR_PAD_LEFT);

            $records[] = [
                'id'            => 'demo-' . $item['id'],
                'sales_order'   => $orderNo,
                'line_item'     => '10',
                'style'         => $item['name'],
                'material'      => 'MAT-' . $item['id'],
                'division'      => $item['category'],
                'division_code' => '',
                'qty'           => $qty,
                'unit_price'    => $item['price'],
                'net_amount'    => $amount,
                'cost'          => $amount * 0.6,
                'currency'      => 'USD',
                'customer_ref'  => 'Sample Customer',
                'channel'       => 'Retail',
                'status'        => 'Active',
                'is_return'     => false,
                'date'          => date('d M Y'),
                'date_iso'      => date('Y-m-d'),
                'created_by'    => 'Demo',
            ];
        }

        return $records;
    }
}
