<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Sap Sales Service for the sales dashboard
*/

/*
 * Sap Sales Service class
*/
require_once base_path('app/core/SapODataClient.php');

class SapSalesService
{
    private SapODataClient $client;
    private array $cfg;

    /*
    /*
     * Constructor
     */
    public function __construct(?SapODataClient $client = null)
    {
        $this->client = $client ?? new SapODataClient();
        $this->cfg = config('sap');
    }

    /*
     * Build dashboard payload method (lightweight view context)
     */
    public function buildDashboardPayload(int $year): array
    {
        return [
            'payload'   => [
                'currency_symbol' => (string) ($this->cfg['currency_symbol'] ?? '$'),
            ],
            'error'     => null,
            'row_count' => 0,
        ];
    }

    /*
     * Paginated records method (delegates directly to getSalesData)
     */
    public function paginatedRecords(int $year, int $page, int $perPage, string $search = '', string $from = '', string $to = ''): array
    {
        return $this->getSalesData([
            'year'     => $year,
            'page'     => $page,
            'per_page' => $perPage,
            'search'   => $search,
            'from'     => $from,
            'to'       => $to,
        ]);
    }

    /**
     * Get Sales Data directly from SAP on demand without file caching or storage.
     *
     * @param array $filters [from_date|from, to_date|to, search|q, page, per_page, year]
     * @return array
     */
    public function getSalesData(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(8000, max(1, (int) ($filters['per_page'] ?? 25)));
        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        $from = trim((string) ($filters['from_date'] ?? $filters['from'] ?? ''));
        $to = trim((string) ($filters['to_date'] ?? $filters['to'] ?? ''));
        $year = (int) ($filters['year'] ?? (int) date('Y'));

        @ini_set('memory_limit', '512M');
        @set_time_limit(45);

        $idList = [];
        $rawSearch = trim((string) ($filters['quotation'] ?? $filters['sales_order'] ?? $filters['so'] ?? $search));
        $cleanedSearch = preg_replace('/(?:so|qt|quo(?:tation)?)\s*#?/i', '', $rawSearch);
        $tokens = preg_split('/[,;\s|\/]+/', trim($cleanedSearch), -1, PREG_SPLIT_NO_EMPTY);

        if (!empty($tokens) && count(array_filter($tokens, fn($t) => preg_match('/^\d{1,10}$/', $t))) === count($tokens)) {
            foreach ($tokens as $token) {
                $rawId = ltrim($token, '0');
                $idList[] = [
                    'raw'     => $rawId === '' ? '0' : $rawId,
                    'padded'  => str_pad($token, 10, '0', STR_PAD_LEFT),
                    'padded8' => str_pad($rawId, 8, '0', STR_PAD_LEFT),
                ];
            }
        }
        $idSearch = !empty($idList) ? $idList[0] : null;

        if (!empty($idList)) {
            $from = '';
            $to = '';
        } elseif ($from === '' && $to === '') {
            $now = new DateTime();
            $dayOfWeek = (int) $now->format('N');
            $from = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days')->format('Y-m-d');
            $to = (clone $now)->modify('+' . (7 - $dayOfWeek) . ' days')->format('Y-m-d');
        } elseif ($from === '' && $to !== '') {
            $from = date('Y-m-01', strtotime($to));
        } elseif ($from !== '' && $to === '') {
            $to = date('Y-m-d', strtotime($from . ' +6 days'));
        }

        $sapError = null;
        $quoteRows = [];
        $quoteMeta = [
            'count'        => 0,
            'sales_orders' => [],
            'quotation'    => implode(', ', array_unique(array_column($idList, 'raw'))),
        ];

        try {
            $quoteResult = $this->fetchQuotationHub($idSearch, $from, $to, $idList);
            $quoteRows = $quoteResult['rows'] ?? [];
            $sapError = $quoteResult['error'] ?? null;
            $salesOrders = [];
            foreach ($quoteRows as $row) {
                foreach (['SalesOrder', 'FollowOnDocument'] as $key) {
                    $val = ltrim(trim((string) ($row[$key] ?? '')), '0');
                    if ($val !== '') {
                        $salesOrders[$val] = true;
                    }
                }
            }
            $quoteMeta = [
                'count'        => count($quoteRows),
                'sales_orders' => array_keys($salesOrders),
                'quotation'    => implode(', ', array_unique(array_column($idList, 'raw'))),
            ];
        } catch (Throwable $e) {
            $sapError = $e->getMessage();
        }

        $records = [];
        foreach ($quoteRows as $row) {
            $mapped = $this->mapQuotationHubRow($row, $year);
            if ($mapped !== null) {
                $records[] = $mapped;
            }
        }

        if (!empty($idList)) {
            $idSet = [];
            foreach ($idList as $it) {
                $idSet[(string) $it['raw']] = true;
                $idSet[(string) $it['padded']] = true;
                if (isset($it['padded8'])) {
                    $idSet[(string) $it['padded8']] = true;
                }
            }
            $records = array_values(array_filter($records, function ($r) use ($idSet) {
                $q = ltrim(trim((string) ($r['quotation'] ?? '')), '0');
                $so = ltrim(trim((string) ($r['sales_order'] ?? '')), '0');
                $qRaw = trim((string) ($r['quotation'] ?? ''));
                $soRaw = trim((string) ($r['sales_order'] ?? ''));
                return isset($idSet[$q]) || isset($idSet[$so]) || isset($idSet[$qRaw]) || isset($idSet[$soRaw]);
            }));
        }

        $textSearch = !empty($idList) ? '' : $search;
        $filtered = $this->filterRecords($records, $textSearch, $from, $to);
        unset($records);

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($filtered, $offset, $perPage);

        $pageDetails = [];
        foreach ($filtered as $r) {
            if (!empty($r['is_header'])) {
                continue;
            }
            $qKey = trim((string) ($r['quotation'] ?? ''));
            $soKey = trim((string) ($r['sales_order'] ?? ''));
            if ($qKey !== '' && $qKey !== '—') {
                if (!isset($pageDetails[$qKey])) {
                    $pageDetails[$qKey] = [];
                }
                $pageDetails[$qKey][] = $r;
            }
            if ($soKey !== '' && $soKey !== '—') {
                if (!isset($pageDetails[$soKey])) {
                    $pageDetails[$soKey] = [];
                }
                $pageDetails[$soKey][] = $r;
            }
        }
        if ($pageDetails === []) {
            $pageDetails = new \stdClass();
        }

        $net = 0.0;
        $cost = 0.0;
        $qty = 0.0;
        $returnQty = 0.0;
        foreach ($filtered as $r) {
            $net += (float) ($r['net_amount'] ?? 0);
            $cost += (float) ($r['cost'] ?? 0);
            $lineQty = (float) ($r['qty'] ?? 0);
            $qty += $lineQty;
            if (!empty($r['is_return'])) {
                $returnQty += $lineQty;
            }
        }
        $uniqueQuotations = count(array_unique(array_filter(array_map(
            static fn($r) => trim((string) ($r['quotation'] ?? '')),
            $filtered
        ), static fn($q) => $q !== '' && $q !== '—')));

        $uniqueOrders = count(array_unique(array_filter(array_map(
            static fn($r) => trim((string) ($r['sales_order'] ?? '')),
            $filtered
        ), static fn($s) => $s !== '' && $s !== '—')));

        $quotations = $uniqueQuotations > 0 ? $uniqueQuotations : ($total > 0 ? 1 : 0);
        $orders = $uniqueOrders > 0 ? $uniqueOrders : ($total > 0 ? 1 : 0);
        $materials = count(array_unique(array_filter(array_map(
            static fn($r) => (string) ($r['customer'] ?? ''),
            $filtered
        ), static fn($m) => $m !== '' && $m !== '—')));

        $convertedLines = 0;
        $convertedQuotations = [];
        $allQuotations = [];
        foreach ($filtered as $r) {
            $hasSo = !empty($r['sales_order']) && $r['sales_order'] !== '—';
            $quoteId = trim((string) ($r['quotation'] ?? ''));
            if ($quoteId !== '' && $quoteId !== '—') {
                $allQuotations[$quoteId] = true;
                if ($hasSo) {
                    $convertedQuotations[$quoteId] = true;
                }
            }
            if ($hasSo) {
                $convertedLines++;
            }
        }
        $convertedQuotesCount = count($convertedQuotations);
        $totalQuotesCount = count($allQuotations) ?: $quotations;
        $pendingQuotesCount = max(0, $totalQuotesCount - $convertedQuotesCount);
        $conversionRate = $totalQuotesCount > 0 ? round(($convertedQuotesCount / $totalQuotesCount) * 100, 1) : 0;
        $lineConversionRate = $total > 0 ? round(($convertedLines / $total) * 100, 1) : 0;

        $summary = [
            'net_sales'            => round($net, 2),
            'cost_amount'          => round($cost, 2),
            'lines'                => $total,
            'orders'               => $orders,
            'quotations'           => $quotations,
            'converted_quotations' => $convertedQuotesCount,
            'pending_quotations'   => $pendingQuotesCount,
            'converted_lines'      => $convertedLines,
            'conversion_rate'      => $conversionRate,
            'line_conversion_rate' => $lineConversionRate,
            'avg_line'             => $total > 0 ? round($net / $total, 2) : 0,
            'avg_order'            => $orders > 0 ? round($net / $orders, 2) : 0,
            'total_qty'            => round($qty, 3),
            'return_rate'          => $qty > 0 ? round(($returnQty / $qty) * 100, 2) : 0,
            'gross_margin'         => $net > 0 ? round((($net - $cost) / $net) * 100, 2) : 0,
            'materials'            => $materials,
        ];

        $charts = $this->buildChartsFromRecords($filtered);

        if ($sapError !== null && $total === 0) {
            $payload = [
                'success' => false,
                'message' => 'Unable to fetch sales data from SAP: ' . $sapError,
                'error'   => $sapError,
                'records' => [],
                'total'   => 0,
                'page'    => 1,
                'pages'   => 1,
                'per_page'=> $perPage,
                'summary' => $summary,
                'charts'  => $charts,
                'meta'    => [
                    'source' => 'SAP',
                    'count'  => 0,
                    'quotation_hub' => $quoteMeta,
                ],
                'data'    => [
                    'records' => [],
                    'detail_lines' => new \stdClass(),
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 1,
                    'per_page'=> $perPage,
                    'summary' => $summary,
                    'charts'  => $charts,
                    'meta'    => [
                        'source' => 'SAP',
                        'count'  => 0,
                        'quotation_hub' => $quoteMeta,
                    ],
                ],
            ];
            return $payload;
        }

        $payload = [
            'success'  => true,
            'message'  => 'Sales data fetched successfully',
            'records'  => $slice,
            'detail_lines' => $pageDetails,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'summary'  => $summary,
            'charts'   => $charts,
            'meta'     => [
                'source' => 'SAP',
                'count'  => $total,
                'quotation_hub' => $quoteMeta,
            ],
            'data'     => [
                'records'  => $slice,
                'detail_lines' => $pageDetails,
                'total'    => $total,
                'page'     => $page,
                'pages'    => $pages,
                'per_page' => $perPage,
                'summary'  => $summary,
                'charts'   => $charts,
                'meta'     => [
                    'source' => 'SAP',
                    'count'  => $total,
                    'quotation_hub' => $quoteMeta,
                ],
            ],
        ];
        return $payload;
    }

    /*
     * Fetch records for range method
     */
    private function fetchRecordsForRange(int $year, string $from, string $to): array
    {
        $state = $this->createState($year);
        $filter = sprintf(
            "CreationDate ge datetime'%sT00:00:00' and CreationDate le datetime'%sT23:59:59'",
            $from,
            $to
        );

        $result = $this->client->eachPage(function (array $batch) use (&$state, $year): void {
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $this->ingestRow($row, $state, $year);
                }
            }
        }, ['$filter' => $filter]);

        return [
            'records' => $state['records'],
            'error'   => $state['row_count'] === 0 ? ($result['error'] ?? null) : null,
        ];
    }

    /*
     * Is narrow range method
     */
    private function isNarrowRange(string $from, string $to): bool
    {
        if ($from === '' || $to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return false;
        }
        try {
            $start = new DateTimeImmutable($from);
            $end = new DateTimeImmutable($to);
        } catch (Exception $e) {
            return false;
        }

        return $end >= $start && $start->diff($end)->days <= 31;
    }

    /*
     * Build charts from records method
     */
    public function buildChartsFromRecords(array $filtered): array
    {
        $byDate = [];
        $byDivision = [];
        $byStyle = [];
        $byChannel = [];
        $byCategory = [];
        $byPlant = [];
        $byStatus = [];
        $byOrder = [];
        $byCustomer = [];
        $byCreator = [];
        $itemsBreakdown = [];
        $costSum = 0.0;
        $profitSum = 0.0;

        foreach ($filtered as $r) {
            $amount = (float) ($r['net_amount'] ?? 0);
            $qtyVal = (float) ($r['qty'] ?? 0);
            $weight = abs($amount) > 0.0000001 ? $amount : $qtyVal;
            $cost = (float) ($r['cost'] ?? 0);

            $line = trim((string) ($r['line_item'] ?? '0'));
            // Skip zero-value empty header line 0 from items breakdown if other lines exist
            if (!($line === '0' && $amount <= 0 && $qtyVal <= 0 && count($filtered) > 1)) {
                $mat = trim((string) ($r['material'] ?? ''));
                $shortMat = ($mat !== '' && $mat !== '—') ? (strlen($mat) > 12 ? substr($mat, 0, 10) . '..' : $mat) : '';
                $lbl = 'Item ' . $line . ($shortMat !== '' ? ' (' . $shortMat . ')' : '');
                $itemsBreakdown[$lbl] = [
                    'item'      => $line,
                    'material'  => $mat !== '' ? $mat : '—',
                    'net_value' => round($amount, 2),
                    'qty'       => round($qtyVal, 2),
                    'unit'      => (string) ($r['unit'] ?? 'EA'),
                ];
            }

            $dateKey = !empty($r['date_iso']) ? $r['date_iso'] : 'N/A';
            $byDate[$dateKey] = ($byDate[$dateKey] ?? 0) + $weight;

            $div = (string) ($r['division'] ?? 'General');
            $byDivision[$div] = ($byDivision[$div] ?? 0) + $weight;

            $style = (string) ($r['material'] ?? $r['style'] ?? 'Unknown');
            if ($style === '' || $style === '—') {
                $style = (string) ($r['style'] ?? 'Unknown');
            }
            if (strlen($style) > 28) {
                $style = substr($style, 0, 26) . '…';
            }
            $byStyle[$style] = ($byStyle[$style] ?? 0) + $weight;

            $ch = (string) ($r['channel'] ?? 'Direct');
            $byChannel[$ch] = ($byChannel[$ch] ?? 0) + $weight;

            $cat = trim((string) ($r['item_category'] ?? ''));
            if ($cat === '' || $cat === '—') {
                $cat = trim((string) ($r['item_type'] ?? ''));
            }
            if ($cat === '' || $cat === '—') {
                $cat = 'Other';
            }
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + $weight;

            $plant = trim((string) ($r['plant'] ?? ''));
            if ($plant !== '' && $plant !== '—') {
                $byPlant[$plant] = ($byPlant[$plant] ?? 0) + $weight;
            }

            $st = trim((string) ($r['status'] ?? 'Open'));
            if ($st === '') {
                $st = 'Open';
            }
            $byStatus[$st] = ($byStatus[$st] ?? 0) + $weight;

            $orderNo = trim((string) ($r['sales_order'] ?? ''));
            if ($orderNo !== '' && $orderNo !== '—') {
                $byOrder['SO ' . $orderNo] = ($byOrder['SO ' . $orderNo] ?? 0) + $weight;
            }

            $cust = trim((string) ($r['customer'] ?? ''));
            if ($cust === '' || $cust === '—') {
                $cust = trim((string) ($r['customer_ref'] ?? ''));
            }
            if ($cust !== '' && $cust !== '—') {
                if (strlen($cust) > 22) {
                    $cust = substr($cust, 0, 20) . '…';
                }
                $byCustomer[$cust] = ($byCustomer[$cust] ?? 0) + $weight;
            }

            $creator = trim((string) ($r['created_by'] ?? ''));
            if ($creator !== '' && $creator !== '—') {
                $byCreator[$creator] = ($byCreator[$creator] ?? 0) + $weight;
            }

            $costSum += max(0, $cost);
            $profitSum += max(0, $amount - $cost);
        }

        ksort($byDate);
        arsort($byStyle);
        arsort($byDivision);
        arsort($byChannel);

        $dateKeys = array_keys($byDate);
        if (count($dateKeys) > 31) {
            $dateKeys = array_slice($dateKeys, -31);
        }

        $trendLabels = [];
        $trendValues = [];
        foreach ($dateKeys as $key) {
            if ($key === 'N/A') {
                $trendLabels[] = 'N/A';
            } else {
                $trendLabels[] = date('d M', strtotime($key));
            }
            $trendValues[] = round($byDate[$key], 2);
        }

        $topStyles = array_slice($byStyle, 0, 6, true);
        $mix = $this->pickMixChart($byPlant, $byDivision, $byCategory, $byStatus, $costSum, $profitSum);
        $bar = $this->pickBarChart($byOrder, $byCustomer, $byCreator, $byChannel);

        return [
            'trend'      => [
                'labels' => $trendLabels,
                'values' => $trendValues,
            ],
            'items'      => [
                'labels'     => array_keys($itemsBreakdown),
                'values'     => array_column(array_values($itemsBreakdown), 'net_value'),
                'quantities' => array_column(array_values($itemsBreakdown), 'qty'),
                'units'      => array_column(array_values($itemsBreakdown), 'unit'),
                'materials'  => array_column(array_values($itemsBreakdown), 'material'),
            ],
            'materials'  => [
                'labels' => array_keys($topStyles),
                'values' => array_map(static fn($v) => round((float) $v, 2), array_values($topStyles)),
            ],
            'division'   => $mix,
            'mix'        => $mix,
            'plants'     => $this->chartSeries($byPlant, 8),
            'divisions'  => $this->chartSeries($byDivision, 8),
            'statuses'   => $this->chartSeries($byStatus, 8),
            'categories' => $this->chartSeries($byCategory, 8),
            'customers'  => $this->chartSeries($byCustomer, 8),
            'top_styles' => [
                'labels' => array_keys($topStyles),
                'values' => array_map(static fn($v) => round((float) $v, 2), array_values($topStyles)),
            ],
            'channels'   => $bar,
            'bar'        => $bar,
        ];
    }

    /**
     * Prefer a mix with multiple slices so the donut is not a single 100% ring.
     */
    /*
     * Pick mix chart method
     */
    private function pickMixChart(array $byPlant, array $byDivision, array $byCategory, array $byStatus, float $costSum, float $profitSum): array
    {
        arsort($byPlant);
        arsort($byDivision);
        arsort($byCategory);
        arsort($byStatus);

        if (count($byPlant) >= 2) {
            $slice = array_slice($byPlant, 0, 6, true);
            return [
                'title'    => 'Plant Mix',
                'subtitle' => 'Net amount by plant',
                'labels'   => array_keys($slice),
                'values'   => array_map(static fn($v) => round((float) $v, 2), array_values($slice)),
            ];
        }

        if (count($byDivision) >= 2) {
            $slice = array_slice($byDivision, 0, 6, true);
            return [
                'title'    => 'Division Mix',
                'subtitle' => 'Net amount by division',
                'labels'   => array_keys($slice),
                'values'   => array_map(static fn($v) => round((float) $v, 2), array_values($slice)),
            ];
        }

        if (count($byCategory) >= 2) {
            $slice = array_slice($byCategory, 0, 6, true);
            return [
                'title'    => 'Item Category Mix',
                'subtitle' => 'Net amount by category',
                'labels'   => array_keys($slice),
                'values'   => array_map(static fn($v) => round((float) $v, 2), array_values($slice)),
            ];
        }

        if (count($byStatus) >= 2) {
            $slice = array_slice($byStatus, 0, 6, true);
            return [
                'title'    => 'Order Status Mix',
                'subtitle' => 'Net amount by status',
                'labels'   => array_keys($slice),
                'values'   => array_map(static fn($v) => round((float) $v, 2), array_values($slice)),
            ];
        }

        if ($costSum > 0 || $profitSum > 0) {
            return [
                'title'    => 'Value Mix',
                'subtitle' => 'Cost vs gross profit',
                'labels'   => ['Cost', 'Gross Profit'],
                'values'   => [round($costSum, 2), round($profitSum, 2)],
            ];
        }

        $slice = array_slice($byCategory, 0, 6, true);
        return [
            'title'    => 'Item Category Mix',
            'subtitle' => 'Net amount by category',
            'labels'   => array_keys($slice) ?: ['No data'],
            'values'   => array_values($slice) ?: [0],
        ];
    }

    /*
     * Chart series method
     */
    private function chartSeries(array $map, int $limit = 8): array
    {
        arsort($map);
        $slice = array_slice($map, 0, $limit, true);
        if ($slice === []) {
            return ['labels' => ['No data'], 'values' => [0]];
        }

        return [
            'labels' => array_keys($slice),
            'values' => array_map(static fn($v) => round((float) $v, 2), array_values($slice)),
        ];
    }

    /**
     * Prefer a bar chart with several columns instead of a single district bar.
     */
    /*
     * Pick bar chart method
     */
    private function pickBarChart(array $byOrder, array $byCustomer, array $byCreator, array $byChannel): array
    {
        $candidates = [
            [$byOrder, 'Top Sales Orders', 'Net amount by sales order'],
            [$byCustomer, 'Top Customers / PO', 'Net amount by customer ref'],
            [$byCreator, 'Sales by Creator', 'Net amount by created-by user'],
            [$byChannel, 'Sales by Plant', 'Net amount by plant'],
        ];

        foreach ($candidates as [$map, $title, $subtitle]) {
            if (count($map) < 2 && $title !== 'Sales by Plant') {
                continue;
            }
            arsort($map);
            $slice = array_slice($map, 0, 6, true);
            if ($slice === []) {
                continue;
            }
            return [
                'title'    => $title,
                'subtitle' => $subtitle,
                'labels'   => array_keys($slice),
                'values'   => array_map(static fn($v) => round((float) $v, 2), array_values($slice)),
            ];
        }

        return [
            'title'    => 'Top Sales Orders',
            'subtitle' => 'Net amount by sales order',
            'labels'   => ['No data'],
            'values'   => [0],
        ];
    }

    /*
     * Filter records method
     */
    private function filterRecords(array $records, string $search, string $from, string $to): array
    {
        $search = strtolower(trim($search));
        $digits = '';
        if (preg_match('/(\d+)/', $search, $m)) {
            $digits = ltrim($m[1], '0');
        }

        return array_values(array_filter($records, function (array $r) use ($search, $digits, $from, $to) {
            if ($from !== '' && $to !== '') {
                $iso = trim((string) ($r['date_iso'] ?? $r['date'] ?? ''));
                if (preg_match('/^\d{2}\s+\w{3}\s+\d{4}$/', $iso)) {
                    $parsed = date_create($iso);
                    $iso = $parsed ? $parsed->format('Y-m-d') : '';
                }
                if ($iso === '' || $iso < $from || $iso > $to) {
                    return false;
                }
            }

            if ($search === '') {
                return true;
            }

            $orderNo = strtolower((string) ($r['sales_order'] ?? ''));
            $cleanOrder = ltrim($orderNo, '0');
            if ($digits !== '' && ($orderNo === $digits || $cleanOrder === $digits || str_contains($cleanOrder, $digits))) {
                return true;
            }

            $hay = strtolower(implode(' ', [
                $r['quotation'] ?? '',
                $r['sales_order'] ?? '',
                $r['line_item'] ?? '',
                $r['style'] ?? '',
                $r['material'] ?? '',
                $r['plant'] ?? '',
                $r['shipping_point'] ?? '',
                $r['material_group'] ?? '',
                $r['division'] ?? '',
                $r['customer'] ?? '',
                $r['customer_ref'] ?? '',
                $r['channel'] ?? '',
                $r['status'] ?? '',
                $r['created_by'] ?? '',
                $r['item_category'] ?? '',
            ]));

            return str_contains($hay, $search) || ($digits !== '' && str_contains($hay, $digits));
        }));
    }

    private function isTagCategory(array $row): bool
    {
        return strtoupper(trim((string) ($row['item_category'] ?? ''))) === 'TAG';
    }

    private function createState(int $year): array
    {
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[$m] = [
                'date'  => sprintf('%04d-%02d-01', $year, $m),
                'month' => date('M', mktime(0, 0, 0, $m, 1, $year)),
                'value' => 0.0,
                'count' => 0,
            ];
        }

        return [
            'year'           => $year,
            'row_count'      => 0,
            'material_count' => 0,
            'items_by_key'   => [],
            'monthly'        => $monthly,
            'monthly_qty'    => [],
            'orders'         => [],
            'channels'       => [],
            'records'        => [],
        ];
    }

    private function ingestRow(array $row, array &$state, int $year): void
    {
        $state['row_count']++;

        $orderNo = (string) $this->field($row, ['Salesorder'], '');
        $lineNo = (string) $this->field($row, ['Salesorderitem'], '');
        $material = (string) $this->field($row, ['Material', 'Originallyrequestedmaterial'], '');

        $name = (string) $this->field($row, [
            'Purchaseorderbycustomer',
            'Salesorderitemtext',
            'Materialbycustomer',
            'Originallyrequestedmaterial',
        ], '');

        if ($name === '' && $material !== '') {
            $name = $material;
        }
        if ($name === '' && $orderNo !== '') {
            $name = 'Order ' . $orderNo . ' / ' . ltrim($lineNo, '0');
        }

        $divisionCode = (string) $this->field($row, ['Division'], '');
        $division = $this->divisionLabel($divisionCode, $row);

        $qty = $this->number($this->field($row, [
            'Orderquantity',
            'Requestedquantity',
            'Confddelivqtyinorderqtyunit',
            'Targetquantity',
        ], 0));

        $amount = $this->number($this->field($row, ['Netamount'], 0));
        if ($amount <= 0) {
            $amount = $this->number($this->field($row, ['Subtotal1amount', 'Subtotal2amount'], 0));
        }

        $unitPrice = $this->number($this->field($row, ['Netpriceamount'], 0));
        if ($amount <= 0 && $qty > 0 && $unitPrice > 0) {
            $priceQty = $this->number($this->field($row, ['Netpricequantity'], 1));
            $amount = ($unitPrice / max(1, $priceQty)) * $qty;
        }

        $price = $qty > 0 ? round($amount / $qty, 2) : $unitPrice;
        $cost = $this->number($this->field($row, ['Costamount'], 0));
        $isReturn = $this->isReturnItem($row);

        $customer = (string) $this->field($row, [
            'SoldToParty',
            'Soldtoparty',
            'Customer',
            'Purchaseorderbycustomer',
            'Materialbycustomer',
        ], '');
        if ($customer === '') {
            $group = (string) $this->field($row, ['Customergroup'], '');
            $customer = $group !== '' ? 'Customer Group ' . $group : ('SO ' . $orderNo);
        }

        $channel = $this->channelLabel($row);
        $status = $this->statusLabel($row);

        $date = $this->parseDate($this->field($row, [
            'Creationdate',
            'Billingdocumentdate',
            'Pricingdate',
            'Servicesrendereddate',
            'Lastchangedate',
        ], null));

        $monthIndex = $date ? (int) $date->format('n') : (int) date('n');
        $rowYear = $date ? (int) $date->format('Y') : $year;

        if ($rowYear === $year) {
            $state['monthly'][$monthIndex]['value'] += $amount;
        }

        if ($orderNo !== '') {
            if (!isset($state['orders'][$orderNo])) {
                $state['orders'][$orderNo] = [
                    'order'    => $orderNo,
                    'customer' => $customer,
                    'channel'  => $channel,
                    'items'    => 0,
                    'amount'   => 0.0,
                    'status'   => $status,
                    'date'     => $date,
                ];
            }
            $state['orders'][$orderNo]['items']++;
            $state['orders'][$orderNo]['amount'] += $amount;
            if ($date && (!$state['orders'][$orderNo]['date'] || $date > $state['orders'][$orderNo]['date'])) {
                $state['orders'][$orderNo]['date'] = $date;
            }
        }

        $itemKey = $material . '|' . $name . '|' . $divisionCode;
        if (!isset($state['items_by_key'][$itemKey])) {
            $state['material_count']++;
            $state['items_by_key'][$itemKey] = [
                'id'           => $state['material_count'],
                'name'         => $name,
                'category'     => $division,
                'price'        => $price,
                'stock'        => 0,
                'material'     => $material !== '' ? $material : ltrim($lineNo, '0'),
                'order_no'     => $orderNo,
                'units'        => 0.0,
                'revenue'      => 0.0,
                'cost'         => 0.0,
                'return_units' => 0.0,
            ];
            $state['monthly_qty'][$state['material_count']] = array_fill(1, 12, 0);
        }

        $id = $state['items_by_key'][$itemKey]['id'];
        $state['items_by_key'][$itemKey]['units'] += $qty;
        $state['items_by_key'][$itemKey]['revenue'] += $amount;
        $state['items_by_key'][$itemKey]['cost'] += $cost;
        if ($isReturn) {
            $state['items_by_key'][$itemKey]['return_units'] += $qty;
        }
        if ($price > 0) {
            $state['items_by_key'][$itemKey]['price'] = $price;
        }
        $state['monthly_qty'][$id][$monthIndex] = ($state['monthly_qty'][$id][$monthIndex] ?? 0) + $qty;

        $state['channels'][$channel] = ($state['channels'][$channel] ?? 0) + max($amount, 0);

        $state['records'][] = $this->buildRecord(
            $row,
            $orderNo,
            $lineNo,
            $material,
            $name,
            $division,
            $divisionCode,
            $qty,
            $price,
            $amount,
            $cost,
            $customer,
            $channel,
            $status,
            $isReturn,
            $date
        );
    }

    private function buildRecord(
        array $row,
        string $orderNo,
        string $lineNo,
        string $material,
        string $name,
        string $division,
        string $divisionCode,
        float $qty,
        float $price,
        float $amount,
        float $cost,
        string $customer,
        string $channel,
        string $status,
        bool $isReturn,
        ?DateTime $date
    ): array {
        $currency = (string) $this->field($row, ['Transactioncurrency'], (string) ($this->cfg['currency'] ?? 'USD'));
        $dateStr = $date ? $date->format('d M Y') : '—';
        $itemCategory = (string) $this->field($row, ['Salesorderitemcategory'], '');
        $itemType = (string) $this->field($row, ['Salesorderitemtype'], '');
        $district = (string) $this->field($row, ['Salesdistrict'], '');
        $customerGroup = (string) $this->field($row, ['Customergroup'], '');
        $createdBy = (string) $this->field($row, ['Createdbyuser'], '');
        $incoterms = (string) $this->field($row, ['Incotermsclassification'], '');
        $paymentTerms = (string) $this->field($row, ['Customerpaymentterms'], '');
        $deliveryStatus = (string) $this->field($row, ['Deliverystatus', 'Totaldeliverystatus'], '');
        $plant = (string) $this->field($row, ['Plant'], '');
        $shippingPoint = (string) $this->field($row, ['Shippingpoint'], '');
        $orderUnit = (string) $this->field($row, ['Orderquantityunit', 'Baseunit'], '');
        $materialGroup = (string) $this->field($row, ['Materialgroup'], '');
        $billingDate = $this->formatDateField($row, 'Billingdocumentdate');
        $pricingDate = $this->formatDateField($row, 'Pricingdate');

        // Extended ZI_SalesApi_HUB entity fields
        $storageLocation = (string) $this->field($row, ['Storagelocation'], '');
        $batch = (string) $this->field($row, ['Batch'], '');
        $matByCust = (string) $this->field($row, ['Materialbycustomer'], '');
        $origMat = (string) $this->field($row, ['Originallyrequestedmaterial'], '');
        $productHierarchy = (string) $this->field($row, ['Producthierarchynode'], '');
        $requestedQty = $this->number($this->field($row, ['Requestedquantity'], 0));
        $requestedUnit = (string) $this->field($row, ['Requestedquantityunit'], '');
        $targetQty = $this->number($this->field($row, ['Targetquantity'], 0));
        $targetUnit = (string) $this->field($row, ['Targetquantityunit'], '');
        $confdDelivQty = $this->number($this->field($row, ['Confddelivqtyinorderqtyunit'], 0));
        $confdBaseQty = $this->number($this->field($row, ['Confddeliveryqtyinbaseunit'], 0));
        $baseUnit = (string) $this->field($row, ['Baseunit'], '');
        $netPriceAmount = $this->number($this->field($row, ['Netpriceamount'], 0));
        $netPriceQty = $this->number($this->field($row, ['Netpricequantity'], 1));
        $netPriceUnit = (string) $this->field($row, ['Netpricequantityunit'], '');
        $taxAmount = $this->number($this->field($row, ['Taxamount'], 0));
        $shippingType = (string) $this->field($row, ['Shippingtype'], '');
        $deliveryPriority = (string) $this->field($row, ['Deliverypriority'], '');
        $route = (string) $this->field($row, ['Route'], '');
        $delivDateQtyFixed = (string) $this->field($row, ['Deliverydatequantityisfixed'], '');
        $partialDelivAllowed = (string) $this->field($row, ['Partialdeliveryisallowed'], '');
        $itemIsDelivRelevant = (string) $this->field($row, ['Itemisdeliveryrelevant'], '');
        $itemIsBillRelevant = (string) $this->field($row, ['Itemisbillingrelevant'], '');
        $billingBlockReason = (string) $this->field($row, ['Itembillingblockreason'], '');
        $billingPlan = (string) $this->field($row, ['Billingplan'], '');
        $sdProcessStatus = (string) $this->field($row, ['Sdprocessstatus'], '');
        $delivConfStatus = (string) $this->field($row, ['Deliveryconfirmationstatus'], '');
        $purchConfStatus = (string) $this->field($row, ['Purchaseconfirmationstatus'], '');
        $totalDelivStatus = (string) $this->field($row, ['Totaldeliverystatus'], '');
        $delivBlockStatus = (string) $this->field($row, ['Deliveryblockstatus'], '');
        $orderRelBillingStatus = (string) $this->field($row, ['Orderrelatedbillingstatus'], '');
        $billingBlockStatus = (string) $this->field($row, ['Billingblockstatus'], '');
        $itemGenIncompStatus = (string) $this->field($row, ['Itemgeneralincompletionstatus'], '');
        $itemBillIncompStatus = (string) $this->field($row, ['Itembillingincompletionstatus'], '');
        $pricingIncompStatus = (string) $this->field($row, ['Pricingincompletionstatus'], '');
        $itemDelivIncompStatus = (string) $this->field($row, ['Itemdeliveryincompletionstatus'], '');
        $sdDocRejectStatus = (string) $this->field($row, ['Sddocumentrejectionstatus'], '');
        $totalSdDocRefStatus = (string) $this->field($row, ['Totalsddocreferencestatus'], '');
        $creationTime = (string) $this->field($row, ['Creationtime'], '');
        $lastChangeDate = $this->formatDateField($row, 'Lastchangedate');

        $id = ($orderNo !== '' ? $orderNo : 'NA') . '-' . ($lineNo !== '' ? $lineNo : count($row));

        return [
            'id'            => $id,
            'sales_order'   => $orderNo !== '' ? $orderNo : '—',
            'line_item'     => $lineNo !== '' ? ltrim($lineNo, '0') : '—',
            'style'         => $name,
            'material'      => $material !== '' ? $material : '—',
            'division'      => $division,
            'division_code' => $divisionCode,
            'qty'           => round($qty, 3),
            'unit_price'    => round($price, 2),
            'net_amount'    => round($amount, 2),
            'cost'          => round($cost, 2),
            'currency'      => $currency,
            'customer_ref'  => $customer,
            'channel'       => $channel,
            'status'        => $status,
            'is_return'     => $isReturn,
            'date'          => $dateStr,
            'date_iso'      => $date ? $date->format('Y-m-d') : '',
            'created_by'    => $createdBy !== '' ? $createdBy : '—',
            'item_category' => $itemCategory !== '' ? $itemCategory : '—',
            'item_type'     => $itemType !== '' ? $itemType : '—',
            'sales_district'=> $district !== '' ? $district : '—',
            'customer_group'=> $customerGroup !== '' ? $customerGroup : '—',
            'incoterms'     => $incoterms !== '' ? $incoterms : '—',
            'payment_terms' => $paymentTerms !== '' ? $paymentTerms : '—',
            'delivery_status' => $deliveryStatus !== '' ? $deliveryStatus : '—',
            'billing_date'  => $billingDate,
            'pricing_date'  => $pricingDate,
            'plant'         => $plant !== '' ? $plant : '—',
            'shipping_point'=> $shippingPoint !== '' ? $shippingPoint : '—',
            'material_group'=> $materialGroup !== '' ? $materialGroup : '—',
            'unit'          => $orderUnit !== '' ? $orderUnit : 'EA',
            'sales_org'     => (string) $this->field($row, ['SalesOrganization', 'Salesorganization'], '—') ?: '—',
            'sales_order_type' => (string) $this->field($row, ['SalesOrderType', 'Salesordertype'], '—') ?: '—',
            'sold_to_party' => (string) $this->field($row, ['SoldToParty', 'Soldtoparty', 'Customer'], '—') ?: '—',
            'organization_division' => (string) $this->field($row, ['OrganizationDivision', 'Organizationdivision'], $divisionCode) ?: '—',

            // Entity ZI_SalesApi_HUB fields
            'storage_location' => $storageLocation !== '' ? $storageLocation : '—',
            'batch'            => $batch !== '' ? $batch : '—',
            'material_by_customer' => $matByCust !== '' ? $matByCust : '—',
            'originally_requested_material' => $origMat !== '' ? $origMat : '—',
            'product_hierarchy_node' => $productHierarchy !== '' ? $productHierarchy : '—',
            'requested_quantity' => round($requestedQty, 3),
            'requested_quantity_unit' => $requestedUnit !== '' ? $requestedUnit : $orderUnit,
            'target_quantity' => round($targetQty, 3),
            'target_quantity_unit' => $targetUnit !== '' ? $targetUnit : $orderUnit,
            'confd_deliv_qty' => round($confdDelivQty, 3),
            'confd_delivery_qty_in_base_unit' => round($confdBaseQty, 3),
            'base_unit'        => $baseUnit !== '' ? $baseUnit : $orderUnit,
            'net_price_amount' => round($netPriceAmount, 2),
            'net_price_quantity' => round($netPriceQty, 2),
            'net_price_quantity_unit' => $netPriceUnit !== '' ? $netPriceUnit : $orderUnit,
            'tax_amount'       => round($taxAmount, 2),
            'shipping_type'    => $shippingType !== '' ? $shippingType : '—',
            'delivery_priority'=> $deliveryPriority !== '' ? $deliveryPriority : '—',
            'route'            => $route !== '' ? $route : '—',
            'delivery_date_quantity_is_fixed' => $delivDateQtyFixed,
            'partial_delivery_is_allowed' => $partialDelivAllowed,
            'item_is_delivery_relevant' => $itemIsDelivRelevant,
            'item_is_billing_relevant' => $itemIsBillRelevant,
            'item_billing_block_reason' => $billingBlockReason !== '' ? $billingBlockReason : '—',
            'billing_plan'     => $billingPlan !== '' ? $billingPlan : '—',
            'sd_process_status'=> $sdProcessStatus !== '' ? $sdProcessStatus : '—',
            'delivery_confirmation_status' => $delivConfStatus !== '' ? $delivConfStatus : '—',
            'purchase_confirmation_status' => $purchConfStatus !== '' ? $purchConfStatus : '—',
            'total_delivery_status' => $totalDelivStatus !== '' ? $totalDelivStatus : '—',
            'delivery_block_status' => $delivBlockStatus !== '' ? $delivBlockStatus : '—',
            'order_related_billing_status' => $orderRelBillingStatus !== '' ? $orderRelBillingStatus : '—',
            'billing_block_status' => $billingBlockStatus !== '' ? $billingBlockStatus : '—',
            'item_general_incompletion_status' => $itemGenIncompStatus !== '' ? $itemGenIncompStatus : '—',
            'item_billing_incompletion_status' => $itemBillIncompStatus !== '' ? $itemBillIncompStatus : '—',
            'pricing_incompletion_status' => $pricingIncompStatus !== '' ? $pricingIncompStatus : '—',
            'item_delivery_incompletion_status' => $itemDelivIncompStatus !== '' ? $itemDelivIncompStatus : '—',
            'sd_document_rejection_status' => $sdDocRejectStatus !== '' ? $sdDocRejectStatus : '—',
            'total_sd_doc_reference_status' => $totalSdDocRefStatus !== '' ? $totalSdDocRefStatus : '—',
            'creation_time'    => $creationTime !== '' ? $creationTime : '—',
            'last_change_date' => $lastChangeDate,
        ];
    }

    private function formatDateField(array $row, string $field): string
    {
        $date = $this->parseDate($this->field($row, [$field], null));

        return $date ? $date->format('d M Y') : '—';
    }

    private function finalizeState(array $state, int $year): array
    {
        $monthly = $state['monthly'];
        $orders = $state['orders'];

        foreach ($orders as $order) {
            if ($order['date'] && (int) $order['date']->format('Y') === $year) {
                $monthly[(int) $order['date']->format('n')]['count']++;
            }
        }

        $items = array_values($state['items_by_key']);
        usort($items, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        $monthlyList = array_values($monthly);
        $netSales = array_sum(array_column($monthlyList, 'value'));
        $channelList = $this->buildChannelShares($state['channels']);
        $recentRows = $this->buildRecentOrders($orders);
        $highlights = $this->buildHighlights($items, $channelList, count($orders), $netSales);
        $alerts = $this->buildAlerts($items, $state['row_count'], count($orders));

        $symbol = (string) ($this->cfg['currency_symbol'] ?? '$');

        return [
            'row_count'       => $state['row_count'],
            'records'         => $state['records'],
            'items'           => $items,
            'monthly'         => $monthlyList,
            'monthly_qty'     => $state['monthly_qty'],
            'channels'        => $channelList,
            'kpis'            => [
                ['key' => 'kpi1', 'label' => 'Net Sales', 'icon' => 'payments', 'color' => 'blue', 'money' => true],
                ['key' => 'kpi2', 'label' => 'Order Quantity', 'icon' => 'checkroom', 'color' => 'green', 'money' => false],
                ['key' => 'kpi3', 'label' => 'Sales Orders', 'icon' => 'receipt_long', 'color' => 'orange', 'money' => false],
                ['key' => 'kpi4', 'label' => 'Avg Order Value', 'icon' => 'account_balance_wallet', 'color' => 'purple', 'money' => true],
            ],
            'charts'          => [
                'main'   => ['icon' => 'show_chart', 'title' => 'Net Sales Trend'],
                'pie'    => ['icon' => 'donut_large', 'title' => 'Plant / Division Mix'],
                'bar'    => ['icon' => 'star', 'title' => 'Top Materials'],
                'orders' => ['icon' => 'shopping_cart', 'title' => 'Sales Orders by Period'],
            ],
            'recent'          => [
                'title'   => 'Recent SAP Sales Orders',
                'icon'    => 'receipt_long',
                'headers' => ['Sales Order', 'Material', 'Plant', 'Lines', 'Net Amount', 'Status'],
                'rows'    => $recentRows,
            ],
            'table'           => [
                'title'   => 'Sales Order Items',
                'icon'    => 'inventory_2',
                'headers' => ['Material', 'Plant / Division', 'Unit Price', 'Qty', 'Net Amount', 'Category'],
            ],
            'highlights'      => $highlights,
            'alerts'          => $alerts,
            'data_source'     => 'SAP ZI_SalesApi_HUB',
            'currency'        => (string) ($this->cfg['currency'] ?? 'USD'),
            'currency_symbol' => $symbol,
        ];
    }

    private function divisionLabel(string $code, array $row): string
    {
        $labels = $this->cfg['division_labels'] ?? [];
        if ($code !== '' && isset($labels[$code])) {
            return $labels[$code];
        }

        $group = (string) $this->field($row, ['Materialgroup', 'Businessarea'], '');
        if ($group !== '') {
            return $group;
        }

        return $code !== '' ? 'Division ' . $code : 'General';
    }

    private function channelLabel(array $row): string
    {
        $plant = (string) $this->field($row, ['Plant'], '');
        if ($plant !== '') {
            return 'Plant ' . $plant;
        }

        $district = (string) $this->field($row, ['Salesdistrict'], '');
        if ($district !== '' && $district !== '000000') {
            return 'District ' . ltrim($district, '0');
        }

        $group = (string) $this->field($row, ['Customergroup'], '');
        if ($group !== '') {
            return 'Customer Group ' . $group;
        }

        $category = (string) $this->field($row, ['Salesorderitemcategory'], '');
        if ($category !== '') {
            return 'Category ' . $category;
        }

        return 'Direct';
    }

    private function statusLabel(array $row): string
    {
        $labels = $this->cfg['status_labels'] ?? [];
        $code = (string) $this->field($row, ['Sdprocessstatus', 'Deliverystatus', 'Totaldeliverystatus'], 'A');

        return $labels[$code] ?? $code;
    }

    private function isReturnItem(array $row): bool
    {
        $value = $this->field($row, ['Isreturnsitem'], null);
        if ($value === null || $value === '') {
            return false;
        }

        return $value === true || $value === 1 || $value === 'X' || $value === 'true';
    }

    private function buildChannelShares(array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        arsort($channels);
        $total = array_sum($channels);
        $list = [];

        foreach (array_slice($channels, 0, 5, true) as $name => $value) {
            $list[] = [
                'name'  => $name,
                'share' => $total > 0 ? (int) round(($value / $total) * 100) : 0,
            ];
        }

        return $list;
    }

    private function buildRecentOrders(array $orders): array
    {
        uasort($orders, function ($a, $b) {
            $da = $a['date'] ?? null;
            $db = $b['date'] ?? null;
            if ($da && $db) {
                return $db <=> $da;
            }

            return strcmp($b['order'], $a['order']);
        });

        $symbol = (string) ($this->cfg['currency_symbol'] ?? '$');
        $rows = [];

        foreach (array_slice($orders, 0, 8, true) as $order) {
            $rows[] = [
                $order['order'],
                $order['customer'],
                $order['channel'],
                (string) $order['items'],
                $symbol . number_format($order['amount'], 2),
                $order['status'],
            ];
        }

        return $rows;
    }

    private function buildHighlights(array $items, array $channels, int $orderCount, float $netSales): array
    {
        $topItem = $items[0] ?? null;
        $topChannel = $channels[0]['name'] ?? 'Direct';
        $topShare = $channels[0]['share'] ?? 0;
        $symbol = (string) ($this->cfg['currency_symbol'] ?? '$');

        return [
            [
                'icon'  => 'cloud_done',
                'title' => 'SAP ZI_SalesApi_HUB',
                'text'  => number_format($orderCount) . ' sales orders in the current extract',
            ],
            [
                'icon'  => 'trending_up',
                'title' => 'Net sales',
                'text'  => $symbol . number_format($netSales, 0) . ' total net amount (USD)',
            ],
            [
                'icon'  => 'workspace_premium',
                'title' => 'Top material',
                'text'  => $topItem
                    ? ($topItem['material'] . ' — ' . $symbol . number_format($topItem['revenue'], 0))
                    : 'No material lines available',
            ],
            [
                'icon'  => 'factory',
                'title' => 'Top plant',
                'text'  => $topChannel . ' contributes ' . $topShare . '% of net amount',
            ],
        ];
    }

    private function buildAlerts(array $items, int $rowCount, int $orderCount): array
    {
        $alerts = [
            [
                'type' => 'ok',
                'text' => 'SAP OData connected — ' . number_format($rowCount) . ' order lines across ' . number_format($orderCount) . ' sales orders',
            ],
        ];

        if (count($items) > 0) {
            $alerts[] = [
                'type' => 'info',
                'text' => count($items) . ' unique materials grouped from Material / OriginallyRequestedMaterial',
            ];
        }

        return $alerts;
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[strtolower($key)] = $value;
            }
        }

        return $normalized;
    }

    private function field(array $row, array $keys, $default = null)
    {
        $normalized = $this->normalizeRow($row);

        foreach ($keys as $key) {
            $lookup = strtolower($key);
            if (!array_key_exists($lookup, $normalized)) {
                continue;
            }

            $value = $normalized[$lookup];
            if ($value === null || $value === '') {
                continue;
            }

            return $value;
        }

        return $default;
    }

    private function number($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = str_replace([',', ' '], '', $value);
            if (is_numeric($clean)) {
                return (float) $clean;
            }
        }

        return 0.0;
    }

    private function parseDate($value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTime) {
            return $value;
        }

        $value = (string) $value;

        if (preg_match('/\/Date\((\-?\d+)\)\//', $value, $matches)) {
            $ms = (int) $matches[1];
            $seconds = (int) floor($ms / 1000);
            $dt = new DateTime('@' . $seconds);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

            return $dt;
        }

        try {
            return new DateTime($value);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Call ZI_QuotationSalesOrder_HUB (quotation first).
     */
    private function fetchQuotationHub(?array $idSearch, string $from, string $to, array $idList = []): array
    {
        $service = (string) ($this->cfg['quotation_service']
            ?? '/sap/opu/odata/sap/ZI_QUOTATIONSALESORDER_HUB_CDS/ZI_QuotationSalesOrder_HUB');

        $activeList = !empty($idList) ? $idList : ($idSearch !== null ? [$idSearch] : []);

        if (!empty($activeList)) {
            $vals = [];
            foreach ($activeList as $item) {
                if (isset($item['raw'])) {
                    $vals[] = (string) $item['raw'];
                }
                if (isset($item['padded'])) {
                    $vals[] = (string) $item['padded'];
                }
                if (isset($item['padded8'])) {
                    $vals[] = (string) $item['padded8'];
                }
            }
            $vals = array_unique(array_filter($vals));
            $parts = [];
            foreach ($vals as $v) {
                $v = str_replace("'", "''", (string) $v);
                $parts[] = "Quotation eq '{$v}'";
                $parts[] = "SalesOrder eq '{$v}'";
                $parts[] = "FollowOnDocument eq '{$v}'";
            }
            $filter = implode(' or ', $parts);
        } else {
            $filter = sprintf(
                "QuotationDate ge datetime'%sT00:00:00' and QuotationDate le datetime'%sT23:59:59'",
                $from,
                $to
            );
        }

        $rows = [];
        $result = $this->client->eachPage(function (array $batch) use (&$rows): void {
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }, [
            '$filter' => $filter,
        ], $service);

        return [
            'rows'  => $rows,
            'error' => $rows === [] ? ($result['error'] ?? null) : null,
        ];
    }

    /**
     * Map quotation hub entity. Header item 000000 is kept for the table.
     */
    private function mapQuotationHubRow(array $row, int $year): ?array
    {
        $item = trim((string) ($row['QuotationItem'] ?? ''));
        $material = trim((string) ($row['QuotationMaterial'] ?? ''));
        $isHeader = ($item === '' || $item === '000000') && $material === '';

        $quotation = ltrim(trim((string) ($row['Quotation'] ?? '')), '0');
        $so = ltrim(trim((string) ($row['FollowOnDocument'] ?? '')), '0');
        if ($so === '') {
            $so = ltrim(trim((string) ($row['SalesOrder'] ?? '')), '0');
        }
        $desc = trim((string) ($row['QuotationMaterialDescription'] ?? ''));
        $quoteDate = $this->parseDate($row['QuotationDate'] ?? null);
        $soDate = $this->parseDate($row['SalesOrderDate'] ?? null);
        $validFrom = $this->parseDate($row['ValidFromDate'] ?? null);
        $validTo = $this->parseDate($row['ValidToDate'] ?? null);
        $reqDate = $this->parseDate($row['RequestedDeliveryDate'] ?? null);
        $createdDate = $this->parseDate($row['CreatedDate'] ?? null);
        $qty = $this->number($row['QuotationQuantity'] ?? 0);
        $net = $this->number($row['QuotationNetValue'] ?? 0);
        $plant = trim((string) ($row['QuotationPlant'] ?? ''));
        $category = trim((string) ($row['QuotationItemCategory'] ?? ''));
        $customerRef = trim((string) ($row['CustomerReference'] ?? ''));
        $customer = trim((string) ($row['Customer'] ?? ''));
        $quoteType = trim((string) ($row['QuotationType'] ?? ''));
        $createdBy = trim((string) ($row['CreatedBy'] ?? ''));

        return [
            'id'               => ($quotation !== '' ? $quotation : 'QT') . '-' . ($item !== '' ? $item : '000000'),
            'quotation'        => $quotation !== '' ? $quotation : '—',
            'sales_order'      => $so !== '' ? $so : '—',
            'line_item'        => ltrim($item, '0') ?: '0',
            'material'         => $material !== '' ? $material : '—',
            'style'            => $desc !== '' ? $desc : ($customerRef !== '' ? $customerRef : '—'),
            'material_group'   => $desc,
            'plant'            => $plant !== '' ? $plant : '—',
            'date'             => $quoteDate ? $quoteDate->format('Y-m-d') : '',
            'date_iso'         => $quoteDate ? $quoteDate->format('Y-m-d') : '',
            'sales_order_date' => $soDate ? $soDate->format('Y-m-d') : '',
            'valid_from'       => $validFrom ? $validFrom->format('Y-m-d') : '',
            'valid_to'         => $validTo ? $validTo->format('Y-m-d') : '',
            'requested_delivery_date' => $reqDate ? $reqDate->format('Y-m-d') : '',
            'created_date'     => $createdDate ? $createdDate->format('Y-m-d') : '',
            'qty'              => $qty,
            'net_amount'       => $net,
            'item_category'    => $category,
            'customer'         => $customer,
            'customer_ref'     => $customerRef,
            'division'         => $customer !== '' ? $customer : trim((string) ($row['Division'] ?? '')),
            'channel'          => $quoteType !== '' ? $quoteType : trim((string) ($row['DistributionChannel'] ?? '')),
            'created_by'       => $createdBy,
            'quotation_type'   => $quoteType,
            'sales_order_type' => trim((string) ($row['SalesOrderType'] ?? '')),
            'sales_org'        => trim((string) ($row['SalesOrganization'] ?? '')),
            'dist_channel'     => trim((string) ($row['DistributionChannel'] ?? '')),
            'unit'             => trim((string) ($row['QuotationSalesUnit'] ?? '')),
            'currency'         => trim((string) ($row['QuotationCurrency'] ?? 'USD')),
            'status'           => 'Active',
            'is_header'        => $isHeader,
            'is_return'        => false,
            'cost'             => 0,
            'year'             => $year,
        ];
    }

    /**
     * One table row per quotation header, with item qty/net rolled up.
     *
     * @return array{0: array<int, array>, 1: array<string, array>}
     */
    private function buildQuotationHeaderRecords(array $mappedRows): array
    {
        $groups = [];
        foreach ($mappedRows as $r) {
            $key = trim((string) ($r['quotation'] ?? ''));
            if ($key === '' || $key === '—') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'header' => null,
                    'items'  => [],
                    'qty'    => 0.0,
                    'net'    => 0.0,
                ];
            }
            if (!empty($r['is_header'])) {
                $groups[$key]['header'] = $r;
                continue;
            }
            $groups[$key]['items'][] = $r;
            $groups[$key]['qty'] += (float) ($r['qty'] ?? 0);
            $groups[$key]['net'] += (float) ($r['net_amount'] ?? 0);
        }

        $headers = [];
        $details = [];
        foreach ($groups as $key => $g) {
            $header = $g['header'];
            if ($header === null && $g['items'] !== []) {
                $header = $g['items'][0];
                $header['is_header'] = true;
            }
            if ($header === null) {
                continue;
            }
            $header['id'] = $key;
            $header['qty'] = $g['qty'];
            $header['net_amount'] = round($g['net'], 2);
            $header['item_count'] = count($g['items']);
            $headers[] = $header;
            $details[$key] = $g['items'];
        }

        return [$headers, $details];
    }

    /**
     * First SAP hop: quotation hub → unique follow-on sales orders.
     */
    private function resolveSalesOrdersFromQuotations(?array $idSearch, string $from, string $to): array
    {
        $service = (string) ($this->cfg['quotation_service']
            ?? '/sap/opu/odata/sap/ZI_QUOTATIONSALESORDER_HUB_CDS/ZI_QuotationSalesOrder_HUB');

        if ($idSearch !== null) {
            $vals = array_unique(array_filter([
                $idSearch['raw'],
                $idSearch['padded'],
                str_pad((string) $idSearch['raw'], 8, '0', STR_PAD_LEFT),
            ]));
            $parts = [];
            foreach ($vals as $v) {
                $v = str_replace("'", "''", (string) $v);
                $parts[] = "Quotation eq '{$v}'";
                $parts[] = "SalesOrder eq '{$v}'";
                $parts[] = "FollowOnDocument eq '{$v}'";
            }
            $filter = implode(' or ', $parts);
        } else {
            $filter = sprintf(
                "QuotationDate ge datetime'%sT00:00:00' and QuotationDate le datetime'%sT23:59:59'",
                $from,
                $to
            );
        }

        $rows = [];
        $result = $this->client->eachPage(function (array $batch) use (&$rows): void {
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }, [
            '$filter' => $filter,
        ], $service);

        $orders = [];
        foreach ($rows as $row) {
            foreach (['SalesOrder', 'FollowOnDocument'] as $key) {
                $val = ltrim(trim((string) ($row[$key] ?? '')), '0');
                if ($val !== '') {
                    $orders[$val] = true;
                }
            }
        }

        return [
            'row_count'     => count($rows),
            'sales_orders'  => array_keys($orders),
            'error'         => $orders === [] ? ($result['error'] ?? null) : null,
        ];
    }

    /**
     * Second SAP hop: ZI_SalesApi_HUB for the resolved sales orders.
     */
    private function fetchSalesOrdersIntoState(array $salesOrders, array &$state, int $year, ?string &$sapError): void
    {
        foreach (array_chunk($salesOrders, 20) as $chunk) {
            $parts = [];
            foreach ($chunk as $so) {
                $raw = ltrim((string) $so, '0');
                if ($raw === '') {
                    continue;
                }
                $padded = str_pad($raw, 10, '0', STR_PAD_LEFT);
                $rawEsc = str_replace("'", "''", $raw);
                $padEsc = str_replace("'", "''", $padded);
                $parts[] = "SalesOrder eq '{$padEsc}'";
                if ($rawEsc !== $padEsc) {
                    $parts[] = "SalesOrder eq '{$rawEsc}'";
                }
            }
            if ($parts === []) {
                continue;
            }

            $queryResult = $this->client->eachPage(function (array $batch) use (&$state, $year): void {
                foreach ($batch as $row) {
                    if (is_array($row)) {
                        $this->ingestRow($row, $state, $year);
                    }
                }
            }, [
                '$filter'  => implode(' or ', $parts),
                '$orderby' => 'CreationDate desc',
            ]);

            if (($state['row_count'] ?? 0) === 0 && !empty($queryResult['error'])) {
                $sapError = (string) $queryResult['error'];
            }
        }
    }

    /**
     * Fallback when quotation hub has no match or is unavailable.
     */
    private function fetchSalesFallback(?array $idSearch, string $from, string $to, array &$state, int $year, ?string &$sapError): void
    {
        if ($idSearch !== null) {
            $filterExpr = sprintf(
                "SalesOrder eq '%s' or SalesOrder eq '%s'",
                str_replace("'", "''", (string) $idSearch['padded']),
                str_replace("'", "''", (string) $idSearch['raw'])
            );
        } else {
            $filterExpr = sprintf(
                "CreationDate ge datetime'%sT00:00:00' and CreationDate le datetime'%sT23:59:59'",
                $from,
                $to
            );
        }

        $queryResult = $this->client->eachPage(function (array $batch) use (&$state, $year): void {
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $this->ingestRow($row, $state, $year);
                }
            }
        }, [
            '$filter'  => $filterExpr,
            '$orderby' => 'CreationDate desc',
        ]);

        if (($state['row_count'] ?? 0) === 0 && !empty($queryResult['error']) && $sapError === null) {
            $sapError = (string) $queryResult['error'];
        }
    }

    /**
     * Live ZI_SalesApi_HUB lines for one sales order.
     */
    public function getSalesApiHubByOrder(string $salesOrder): array
    {
        $raw = ltrim(trim($salesOrder), '0');
        if ($raw === '') {
            return [
                'success' => false,
                'message' => 'Sales order is required',
                'records' => [],
            ];
        }

        $padded = str_pad($raw, 10, '0', STR_PAD_LEFT);
        $rawEsc = str_replace("'", "''", $raw);
        $padEsc = str_replace("'", "''", $padded);
        $filterExpr = $rawEsc === $padEsc
            ? "SalesOrder eq '{$rawEsc}'"
            : "SalesOrder eq '{$rawEsc}' or SalesOrder eq '{$padEsc}'";

        $year = (int) date('Y');
        $state = $this->createState($year);
        $queryResult = $this->client->eachPage(function (array $batch) use (&$state, $year): void {
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $this->ingestRow($row, $state, $year);
                }
            }
        }, [
            '$filter'  => $filterExpr,
            '$orderby' => 'SalesOrderItem',
        ]);

        $records = $state['records'] ?? [];
        usort($records, static function ($a, $b) {
            return (int) ($a['line_item'] ?? 0) <=> (int) ($b['line_item'] ?? 0);
        });

        return [
            'success'      => count($records) > 0,
            'message'      => count($records) > 0 ? 'ZI_SalesApi_HUB lines loaded' : 'No ZI_SalesApi_HUB lines for this sales order',
            'sales_order'  => $raw,
            'count'        => count($records),
            'records'      => $records,
            'error'        => count($records) > 0 ? null : ($queryResult['error'] ?? null),
        ];
    }

    /**
     * Sales orders created within a date range from ZI_SalesApi_HUB.
     * Filter: CreationDate ge datetime'{from}T00:00:00' and CreationDate lt datetime'{nextDay}T00:00:00'
     */
    public function listOrdersByDateRange(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to !== '' ? $to : $from);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || strtotime($from) === false
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || strtotime($to) === false) {
            return [
                'success' => false,
                'message' => 'Choose a valid created date.',
                'orders'  => [],
            ];
        }
        $fromDt = new DateTime($from);
        $toDt = new DateTime($to);
        if ($toDt < $fromDt) {
            return [
                'success' => false,
                'message' => 'The end date must be on or after the start date.',
                'orders'  => [],
            ];
        }
        if ($toDt > (clone $fromDt)->modify('+6 months')) {
            return [
                'success' => false,
                'message' => 'A custom date range can cover at most 6 months.',
                'orders'  => [],
            ];
        }

        $nextDay = date('Y-m-d', strtotime($to . ' +1 day'));
        $filterExpr = sprintf(
            "CreationDate ge datetime'%sT00:00:00' and CreationDate lt datetime'%sT00:00:00'",
            $from,
            $nextDay
        );

        $service = '/sap/opu/odata/sap/ZI_SALESAPI_HUB_CDS/ZI_SalesApi_HUB';
        $rows = [];
        $queryResult = $this->client->eachPage(function (array $batch) use (&$rows): void {
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }, [
            '$filter'  => $filterExpr,
            '$orderby' => 'CreationDate desc, SalesOrder',
        ], $service);

        $grouped = [];
        $totalQty = 0.0;
        $totalValue = 0.0;
        $uniquePlants = [];
        $uniqueCustomers = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawSo = trim((string) $this->field($row, ['SalesOrder'], ''));
            $so = ltrim($rawSo, '0');
            if ($so === '') {
                continue;
            }

            $plant = (string) $this->field($row, ['Plant'], '—');
            $customer = (string) $this->field($row, [
                'SoldToParty',
                'Customer',
                'PurchaseOrderByCustomer',
                'CustomerGroup',
            ], '—');
            if ($plant !== '' && $plant !== '—') {
                $uniquePlants[$plant] = true;
            }
            if ($customer !== '' && $customer !== '—') {
                $uniqueCustomers[$customer] = true;
            }

            $lineAmount = $this->number($this->field($row, ['NetAmount', 'Netamount'], 0));
            if ($lineAmount <= 0) {
                $lineAmount = $this->number($this->field($row, ['Subtotal1Amount', 'Subtotal1amount'], 0));
            }
            $lineQty = $this->number($this->field($row, ['OrderQuantity', 'RequestedQuantity', 'Orderquantity'], 0));
            if ($lineAmount <= 0 && $lineQty > 0) {
                $unitPrice = $this->number($this->field($row, ['NetPriceAmount', 'Netpriceamount'], 0));
                $priceQty = max(1.0, $this->number($this->field($row, ['NetPriceQuantity', 'Netpricequantity'], 1)));
                if ($unitPrice > 0) {
                    $lineAmount = ($unitPrice / $priceQty) * $lineQty;
                }
            }
            $currency = (string) $this->field($row, ['TransactionCurrency', 'Transactioncurrency'], 'INR');
            $status = $this->statusLabel($row);
            $statusCode = strtoupper(trim((string) $this->field($row, ['SdProcessStatus', 'Sdprocessstatus', 'DeliveryStatus'], '')));

            if (!isset($grouped[$so])) {
                $rawDate = (string) $this->field($row, ['CreationDate', 'Creationdate'], '');
                $formattedDate = '';
                if (preg_match('/\/Date\((\d+)\)\//', $rawDate, $m)) {
                    $formattedDate = date('d M Y', (int) ($m[1] / 1000));
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate)) {
                    $formattedDate = date('d M Y', strtotime($rawDate));
                }

                $unit = (string) $this->field($row, ['OrderQuantityUnit', 'Orderquantityunit', 'BaseUnit', 'Baseunit'], 'EA');
                $style = (string) $this->field($row, [
                    'SalesOrderItemText',
                    'Salesorderitemtext',
                    'PurchaseOrderByCustomer',
                    'Purchaseorderbycustomer',
                    'MaterialByCustomer',
                    'Materialbycustomer',
                ], '—');
                $grouped[$so] = [
                    'sales_order'        => $so,
                    'creation_date'      => $formattedDate ?: ($from ? date('d M Y', strtotime($from)) : '—'),
                    'customer'           => $customer,
                    'customer_group'     => (string) $this->field($row, ['CustomerGroup', 'Customergroup'], '—'),
                    'sales_district'     => (string) $this->field($row, ['SalesDistrict', 'Salesdistrict'], '—'),
                    'plant'              => $plant,
                    'material'           => (string) $this->field($row, ['Material', 'OriginallyRequestedMaterial', 'Originallyrequestedmaterial'], '—'),
                    'style'              => $style !== '' ? $style : '—',
                    'orig_material'      => (string) $this->field($row, ['OriginallyRequestedMaterial', 'Originallyrequestedmaterial'], '—'),
                    'material_group'     => (string) $this->field($row, ['MaterialGroup', 'Materialgroup'], '—'),
                    'material_by_customer' => (string) $this->field($row, ['MaterialByCustomer', 'Materialbycustomer'], '—'),
                    'created_by'         => (string) $this->field($row, ['CreatedByUser', 'Createdbyuser'], '—'),
                    'currency'           => $currency !== '' ? $currency : 'INR',
                    'status'             => $status !== '' ? $status : '—',
                    'status_code'        => $statusCode,
                    'delivery_status'    => (string) $this->field($row, ['DeliveryStatus', 'Deliverystatus', 'TotalDeliveryStatus', 'Totaldeliverystatus'], '—'),
                    'rejection_status'   => (string) $this->field($row, ['SDDocumentRejectionStatus', 'Sddocumentrejectionstatus'], '—'),
                    'shipping_point'     => (string) $this->field($row, ['ShippingPoint', 'Shippingpoint'], '—'),
                    'shipping_type'      => (string) $this->field($row, ['ShippingType', 'Shippingtype'], '—'),
                    'storage_location'   => (string) $this->field($row, ['StorageLocation', 'Storagelocation'], '—'),
                    'route'              => (string) $this->field($row, ['Route'], '—'),
                    'delivery_priority'  => (string) $this->field($row, ['DeliveryPriority', 'Deliverypriority'], '—'),
                    'sales_org'          => (string) $this->field($row, ['SalesOrganization', 'Salesorganization'], '—'),
                    'distribution_channel' => (string) $this->field($row, ['DistributionChannel', 'Distributionchannel'], '—'),
                    'division'           => (string) $this->field($row, ['OrganizationDivision', 'Organizationdivision', 'Division'], '—'),
                    'sales_order_type'   => (string) $this->field($row, ['SalesOrderType', 'Salesordertype'], '—'),
                    'item_category'      => (string) $this->field($row, ['SalesOrderItemCategory', 'Salesorderitemcategory'], '—'),
                    'item_type'          => (string) $this->field($row, ['SalesOrderItemType', 'Salesorderitemtype'], '—'),
                    'unit'               => $unit !== '' ? $unit : 'EA',
                    'incoterms'          => (string) $this->field($row, ['IncotermsClassification', 'Incotermsclassification'], '—'),
                    'payment_terms'      => (string) $this->field($row, ['CustomerPaymentTerms', 'Customerpaymentterms'], '—'),
                    'billing_date'       => $this->formatDateField($row, 'Billingdocumentdate'),
                    'pricing_date'       => $this->formatDateField($row, 'Pricingdate'),
                    'last_change_date'   => $this->formatDateField($row, 'Lastchangedate'),
                    'lines'              => 0,
                    'quantity'           => 0.0,
                    'requested_qty'      => 0.0,
                    'confd_deliv_qty'    => 0.0,
                    'net_value'          => 0.0,
                    'cost'               => 0.0,
                    'unit_price'         => 0.0,
                    'tax_amount'         => 0.0,
                ];
            }

            $lineTax = $this->number($this->field($row, ['TaxAmount', 'Taxamount'], 0));
            $lineCost = $this->number($this->field($row, ['CostAmount', 'Costamount'], 0));
            $lineReqQty = $this->number($this->field($row, ['RequestedQuantity', 'Requestedquantity'], 0));
            $lineConfQty = $this->number($this->field($row, ['ConfdDelivQtyInOrderQtyUnit', 'Confddelivqtyinorderqtyunit'], 0));
            $grouped[$so]['lines']++;
            $grouped[$so]['quantity'] += $lineQty;
            $grouped[$so]['requested_qty'] += $lineReqQty;
            $grouped[$so]['confd_deliv_qty'] += $lineConfQty;
            $grouped[$so]['net_value'] += $lineAmount;
            $grouped[$so]['cost'] += $lineCost;
            $grouped[$so]['tax_amount'] += $lineTax;
            if ($status !== '' && $status !== '—') {
                $grouped[$so]['status'] = $status;
            }
            if ($statusCode !== '') {
                $grouped[$so]['status_code'] = $statusCode;
            }
            $totalQty += $lineQty;
            $totalValue += $lineAmount;
        }

        $orders = array_values($grouped);
        usort($orders, static function (array $a, array $b): int {
            return strnatcmp((string) $a['sales_order'], (string) $b['sales_order']);
        });

        foreach ($orders as &$order) {
            $order['quantity'] = round((float) $order['quantity'], 2);
            $order['requested_qty'] = round((float) ($order['requested_qty'] ?? 0), 2);
            $order['confd_deliv_qty'] = round((float) ($order['confd_deliv_qty'] ?? 0), 2);
            $order['net_value'] = round((float) $order['net_value'], 2);
            $order['cost'] = round((float) ($order['cost'] ?? 0), 2);
            $order['tax_amount'] = round((float) ($order['tax_amount'] ?? 0), 2);
            $qty = (float) $order['quantity'];
            $order['unit_price'] = $qty > 0
                ? round((float) $order['net_value'] / $qty, 2)
                : 0.0;
            $code = strtoupper((string) ($order['status_code'] ?? ''));
            $label = strtolower((string) ($order['status'] ?? ''));
            $order['is_complete'] = $code === 'C'
                || str_contains($label, 'complet')
                || str_contains($label, 'closed')
                || str_contains($label, 'fully');
        }
        unset($order);

        $sapError = count($rows) > 0 ? null : ($queryResult['error'] ?? null);
        if ($orders === [] && $sapError) {
            return [
                'success' => false,
                'message' => (string) $sapError,
                'from'    => $from,
                'to'      => $to,
                'orders'  => [],
            ];
        }

        return [
            'success'      => true,
            'message'      => $orders === [] ? 'No sales orders found for this period.' : 'Sales orders loaded successfully.',
            'from'         => $from,
            'to'           => $to,
            'count'        => count($orders),
            'line_count'   => count($rows),
            'total_qty'    => round($totalQty, 2),
            'total_value'  => round($totalValue, 2),
            'plants'       => array_keys($uniquePlants),
            'customers'    => array_keys($uniqueCustomers),
            'orders'       => $orders,
            'error'        => count($rows) > 0 ? null : ($queryResult['error'] ?? null),
        ];
    }
}
