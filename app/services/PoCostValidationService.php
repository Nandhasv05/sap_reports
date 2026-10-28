<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Full PO Costing Validation — compare SAP PO lines vs ZCOST_SRV expected rates
*/
require_once base_path('app/services/SapPurchaseOrderService.php');
require_once base_path('app/services/SapCostService.php');
require_once base_path('app/services/SapValueFormat.php');

class PoCostValidationService
{
    use SapValueFormat;

    /** Variance above this % (PO cost higher than expected) is EXCEPTION */
    public const VARIANCE_LIMIT_PCT = 0.0;

    private SapPurchaseOrderService $poService;
    private SapCostService $costService;

    public function __construct(?SapPurchaseOrderService $poService = null, ?SapCostService $costService = null)
    {
        $this->poService = $poService ?? new SapPurchaseOrderService();
        $this->costService = $costService ?? new SapCostService();
    }

    /**
     * Validate a purchase order against ZCOST sheets.
     *
     * @param string $purchaseOrder
     * @param string $category all|fabric|trims
     * @param string $salesOrderOverride optional SO when PO has none linked
     * @return array{success:bool,purchase_order:string,category:string,header:array,summary:array,records:array,error:?string}
     */
    public function validate(string $purchaseOrder, string $category = 'all', string $salesOrderOverride = ''): array
    {
        $category = strtolower(trim($category));
        if (!in_array($category, ['all', 'fabric', 'trims'], true)) {
            $category = 'all';
        }

        $poReport = $this->poService->report($purchaseOrder);
        if (($poReport['error'] ?? null) !== null) {
            return [
                'success'        => false,
                'purchase_order' => $this->displayNumber($purchaseOrder),
                'category'       => $category,
                'header'         => [],
                'summary'        => $this->emptySummary(),
                'records'        => [],
                'error'          => $poReport['error'],
            ];
        }

        $poItems = is_array($poReport['records'] ?? null) ? $poReport['records'] : [];
        if ($category !== 'all') {
            $poItems = array_values(array_filter(
                $poItems,
                static fn (array $r): bool => ($r['mat_category'] ?? '') === $category
            ));
        }

        $extraSos = [];
        $soOverride = $this->displayNumber(preg_replace('/\D/', '', $salesOrderOverride) ?? '');
        if ($soOverride !== '' && $soOverride !== '0') {
            $extraSos[] = $soOverride;
        }

        $salesOrders = $extraSos;
        foreach ($poItems as $item) {
            foreach ((array) ($item['sales_orders'] ?? []) as $so) {
                $so = $this->displayNumber((string) $so);
                if ($so !== '' && !in_array($so, $salesOrders, true)) {
                    $salesOrders[] = $so;
                }
            }
        }

        $costing = $this->costService->fetchForSalesOrders($salesOrders);
        $costLines = is_array($costing['records'] ?? null) ? $costing['records'] : [];

        $records = [];
        foreach ($poItems as $item) {
            $records[] = $this->validateItem($item, $costLines, $category, (string) ($poReport['purchase_order'] ?? $purchaseOrder));
        }

        $summary = $this->summarize($records);
        $header = is_array($poReport['header'] ?? null) ? $poReport['header'] : [];
        $header['cost_sheets'] = (int) ($costing['summary']['sheets'] ?? 0);
        $header['cost_error'] = $costing['error'] ?? null;

        return [
            'success'        => true,
            'purchase_order' => (string) ($poReport['purchase_order'] ?? $this->displayNumber($purchaseOrder)),
            'category'       => $category,
            'header'         => $header,
            'summary'        => $summary,
            'records'        => $records,
            'costing'        => [
                'sales_orders' => $salesOrders,
                'sheets'       => $costing['sheets'] ?? [],
                'error'        => $costing['error'] ?? null,
            ],
            'error'          => null,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $costLines
     * @return array<string,mixed>
     */
    private function validateItem(array $item, array $costLines, string $category, string $poNumber): array
    {
        $poUnit = (float) ($item['net_price'] ?? 0);
        $qty = (float) ($item['order_qty'] ?? 0);
        $material = trim((string) ($item['material'] ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $matCat = (string) ($item['mat_category'] ?? 'other');
        $sos = array_values(array_filter(array_map(
            fn ($s) => $this->displayNumber((string) $s),
            (array) ($item['sales_orders'] ?? [])
        )));

        $match = $this->findBestCostMatch($costLines, $material, $description, $sos, $matCat, $category);
        $expected = $match ? (float) ($match['rate'] ?? 0) : null;
        if ($match && ($expected === null || $expected <= 0)) {
            $expected = (float) ($match['purp'] ?? 0) * max(1.0, (float) ($match['convr'] ?? 1));
        }

        $status = 'EXCEPTION';
        $result = 'No quotation reference';
        $variancePct = null;
        $diff = null;
        $costingRef = '';

        if ($match) {
            $costingRef = trim((string) ($match['sales_order'] ?? ''));
            if ($costingRef !== '') {
                $costingRef = 'SO ' . $costingRef . (!empty($match['so_item']) ? (' / ' . $match['so_item']) : '');
            }
            $section = (string) ($match['section'] ?? '');
            if ($section !== '') {
                $costingRef = trim($costingRef . ' · ' . $section);
            }
            if (!empty($match['item'])) {
                $costingRef = trim($costingRef . ' · ' . $match['item']);
            }

            if ($expected === null || $expected <= 0) {
                $result = 'Costing sheet found but rate is zero';
            } else {
                $diff = round($poUnit - $expected, 4);
                $variancePct = round(($diff / $expected) * 100, 2);
                if ($variancePct > self::VARIANCE_LIMIT_PCT) {
                    $status = 'EXCEPTION';
                    $result = 'Cost variance exceeds limit';
                } else {
                    $status = 'PASS';
                    $result = 'PASS';
                }
            }
        } elseif ($sos === []) {
            $result = 'No linked sales order — cannot load ZCOST';
        }

        $costItem = (string) ($match['item'] ?? '');
        $costDesc = (string) ($match['description'] ?? '');
        // ZCOST "Label" / material-group rows often put many codes in description
        $materialGroup = trim((string) ($item['material_group'] ?? ''));
        if ($materialGroup === '' && $costItem !== '') {
            $materialGroup = $costItem;
        }

        return [
            'po_item'            => (string) ($item['item'] ?? ''),
            'material'           => $material,
            'description'        => $description,
            'sales_order'        => $sos[0] ?? '',
            'sales_orders'       => $sos,
            'po_qty'             => $qty,
            'unit'               => (string) ($item['unit'] ?? ''),
            'currency'           => (string) ($item['currency'] ?? 'INR'),
            'mat_category'       => $matCat,
            'material_group'     => $materialGroup,
            'costing_ref'        => $costingRef,
            'expected_unit_cost' => $expected,
            'po_unit_cost'       => $poUnit,
            'difference'         => $diff,
            'variance_pct'       => $variancePct,
            'status'             => $status,
            'validation_result'  => $result,
            'purchase_order'     => $this->displayNumber($poNumber),
            'net_value'          => (float) ($item['net_value'] ?? ($qty * $poUnit)),
            'quoted_currency'    => $match['currency'] ?? 'INR',
            'quoted_uom'         => $match['unit'] ?? ($item['unit'] ?? ''),
            'cost_section'       => $match['section'] ?? '',
            'cost_item'          => $costItem,
            'cost_description'   => $costDesc,
            'sell_price'         => $match['sell_price'] ?? null,
            'making'             => $match['making'] ?? null,
            'selected'           => false,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $costLines
     */
    private function findBestCostMatch(
        array $costLines,
        string $material,
        string $description,
        array $salesOrders,
        string $matCat,
        string $categoryFilter
    ): ?array {
        if ($costLines === []) {
            return null;
        }

        $wantSections = match ($categoryFilter !== 'all' ? $categoryFilter : $matCat) {
            'fabric' => ['Fabric'],
            'trims'  => ['Trim', 'Packing', 'Special'],
            default  => ['Fabric', 'Trim', 'Packing', 'Special'],
        };

        $matNorm = $this->normText($material);
        $descNorm = $this->normText($description);
        $best = null;
        $bestScore = -1;

        foreach ($costLines as $line) {
            $section = (string) ($line['section'] ?? '');
            if ($wantSections !== [] && !in_array($section, $wantSections, true)) {
                continue;
            }

            $score = 0;
            $lineSo = $this->displayNumber((string) ($line['sales_order'] ?? ''));
            if ($salesOrders !== [] && $lineSo !== '' && in_array($lineSo, $salesOrders, true)) {
                $score += 50;
            } elseif ($salesOrders !== [] && $lineSo !== '') {
                $score -= 20;
            }

            $lineItem = $this->normText((string) ($line['item'] ?? ''));
            $lineDesc = $this->normText((string) ($line['description'] ?? ''));
            $lineMat = $this->normText((string) ($line['material'] ?? ''));
            // Label / multi-code ZCOST rows: "L1798, L2618, L2580…"
            $descCodes = $this->splitMaterialCodes((string) ($line['description'] ?? ''));

            if ($matNorm !== '' && ($lineItem === $matNorm || $lineMat === $matNorm)) {
                $score += 100;
            } elseif ($matNorm !== '' && ($lineItem !== '' && str_contains($lineItem, $matNorm) || $lineMat !== '' && str_contains($lineMat, $matNorm))) {
                $score += 70;
            } elseif ($matNorm !== '' && $descCodes !== [] && in_array($matNorm, $descCodes, true)) {
                // Exact code hit inside a Label / material-group list → same group
                $score += 95;
            } elseif ($matNorm !== '' && $lineDesc !== '' && str_contains($lineDesc, $matNorm)) {
                $score += 40;
            }

            if ($descNorm !== '' && $lineDesc !== '') {
                if ($lineDesc === $descNorm) {
                    $score += 80;
                } elseif (str_contains($lineDesc, $descNorm) || str_contains($descNorm, $lineDesc)) {
                    $score += 45;
                } else {
                    // token overlap
                    $a = array_filter(explode(' ', $descNorm));
                    $b = array_filter(explode(' ', $lineDesc));
                    $overlap = count(array_intersect($a, $b));
                    if ($overlap > 0) {
                        $score += min(30, $overlap * 8);
                    }
                }
            }

            $rate = (float) ($line['rate'] ?? 0);
            if ($rate > 0) {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $line;
            }
        }

        // Require a meaningful match (not just section + rate bonus)
        if ($bestScore < 40) {
            return null;
        }
        return $best;
    }

    private function normText(string $s): string
    {
        $s = strtoupper(trim($s));
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * Split ZCOST material lists like "L1798, L2618, L2580, GS113" into normalized codes.
     *
     * @return list<string>
     */
    private function splitMaterialCodes(string $raw): array
    {
        $parts = preg_split('/[,;+|\/]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $n = $this->normText($part);
            // Keep compact codes (skip long sentence descriptions)
            if ($n !== '' && strlen(str_replace(' ', '', $n)) <= 40) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function summarize(array $records): array
    {
        $passed = 0;
        $exceptions = 0;
        $totalValue = 0.0;
        foreach ($records as $r) {
            if (($r['status'] ?? '') === 'PASS') {
                $passed++;
            } else {
                $exceptions++;
            }
            $totalValue += (float) ($r['net_value'] ?? 0);
        }
        return [
            'po_items'      => count($records),
            'passed'        => $passed,
            'exceptions'    => $exceptions,
            'total_po_value'=> round($totalValue, 2),
            'currency'      => 'INR',
            'can_pass_po'   => count($records) > 0 && $exceptions === 0,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'po_items'       => 0,
            'passed'         => 0,
            'exceptions'     => 0,
            'total_po_value' => 0,
            'currency'       => 'INR',
            'can_pass_po'    => false,
        ];
    }
}
