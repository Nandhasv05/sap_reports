<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : PO Costing Validation — PO → SO → Quotation → ZCOST item-wise compare
*/
require_once base_path('app/services/SapPurchaseOrderService.php');
require_once base_path('app/services/SapCostService.php');
require_once base_path('app/services/SapValueFormat.php');
require_once base_path('app/core/SapODataClient.php');

class PoCostValidationService
{
    use SapValueFormat;

    /** Variance above this % (PO cost higher than expected) is EXCEPTION */
    public const VARIANCE_LIMIT_PCT = 0.0;

    /** Minimum score for an automatic PASS/EXCEPTION match */
    public const MATCH_SCORE_PASS = 90;

    /** Scores in this band are marked Needs Review (uncertain mapping) */
    public const MATCH_SCORE_REVIEW = 40;

    private SapPurchaseOrderService $poService;
    private SapCostService $costService;
    private SapODataClient $odata;
    private array $cfg;

    public function __construct(
        ?SapPurchaseOrderService $poService = null,
        ?SapCostService $costService = null,
        ?SapODataClient $odata = null
    ) {
        $this->poService = $poService ?? new SapPurchaseOrderService();
        $this->costService = $costService ?? new SapCostService();
        $this->odata = $odata ?? new SapODataClient();
        $this->cfg = config('sap');
    }

    /**
     * Full validation pipeline:
     *  1) Fetch PO items (+ linked Sales Orders)
     *  2) Resolve Quotation numbers from each Sales Order (QuotationSalesOrder hub)
     *  3) Fetch ZCOST sheets for Quotation (preferred) and Sales Order keys
     *  4) Item-wise compare Material Group / Description / Material Code
     *
     * @return array{success:bool,purchase_order:string,category:string,header:array,summary:array,records:array,flow:array,error:?string}
     */
    public function validate(string $purchaseOrder, string $category = 'all', string $salesOrderOverride = ''): array
    {
        $category = strtolower(trim($category));
        if (!in_array($category, ['all', 'fabric', 'trims'], true)) {
            $category = 'all';
        }

        // ── Step 1: Fetch Purchase Order ──────────────────────────────────
        $poReport = $this->poService->report($purchaseOrder);
        if (($poReport['error'] ?? null) !== null) {
            return [
                'success'        => false,
                'purchase_order' => $this->displayNumber($purchaseOrder),
                'category'       => $category,
                'header'         => [],
                'summary'        => $this->emptySummary(),
                'records'        => [],
                'flow'           => ['step' => 1, 'error' => $poReport['error']],
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
        foreach ((array) ($poReport['header']['sales_orders'] ?? []) as $so) {
            $so = $this->displayNumber((string) $so);
            if ($so !== '' && !in_array($so, $salesOrders, true)) {
                $salesOrders[] = $so;
            }
        }

        // ── Step 2: Sales Order → Quotation Number ────────────────────────
        $quoteMap = $this->resolveQuotationsForSalesOrders($salesOrders);
        $quotations = array_values(array_unique(array_filter(array_map(
            static fn ($row) => trim((string) ($row['quotation'] ?? '')),
            $quoteMap
        ))));

        // ── Step 3: ZCOST by Quotation (preferred) then Sales Order ───────
        $docKeys = array_values(array_unique(array_filter(array_merge($quotations, $salesOrders))));
        $costing = $this->costService->fetchForSalesOrders($docKeys);
        $costLines = is_array($costing['records'] ?? null) ? $costing['records'] : [];

        // ── Step 4: Item-wise validation ──────────────────────────────────
        $records = [];
        foreach ($poItems as $item) {
            $records[] = $this->validateItem(
                $item,
                $costLines,
                $category,
                (string) ($poReport['purchase_order'] ?? $purchaseOrder),
                $quoteMap
            );
        }

        $summary = $this->summarize($records);
        $header = is_array($poReport['header'] ?? null) ? $poReport['header'] : [];
        $header['cost_sheets'] = (int) ($costing['summary']['sheets'] ?? 0);
        $header['cost_error'] = $costing['error'] ?? null;
        $header['quotations'] = $quotations;
        $header['sales_orders'] = $salesOrders;

        $flowError = null;
        if ($salesOrders === []) {
            $flowError = 'No linked sales order on PO lines — cannot resolve quotation / ZCOST.';
        } elseif ($quotations === [] && $costLines === []) {
            $flowError = $quoteMap[0]['error']
                ?? $costing['error']
                ?? 'No quotation found for linked sales orders and ZCOST returned no lines.';
        } elseif ($costLines === [] && ($costing['error'] ?? null)) {
            $flowError = $costing['error'];
        }

        return [
            'success'        => true,
            'purchase_order' => (string) ($poReport['purchase_order'] ?? $this->displayNumber($purchaseOrder)),
            'category'       => $category,
            'header'         => $header,
            'summary'        => $summary,
            'records'        => $records,
            'costing'        => [
                'sales_orders' => $salesOrders,
                'quotations'   => $quotations,
                'sheets'       => $costing['sheets'] ?? [],
                'error'        => $costing['error'] ?? null,
            ],
            'flow'           => [
                'step1_po'          => (string) ($poReport['purchase_order'] ?? $purchaseOrder),
                'step2_sales_orders'=> $salesOrders,
                'step2_quotations'  => $quotations,
                'step2_links'       => $quoteMap,
                'step3_docs'        => $docKeys,
                'step3_lines'       => count($costLines),
                'step3_error'       => $costing['error'] ?? null,
            ],
            'error'          => $flowError,
        ];
    }

    /**
     * Resolve quotation numbers for one or more sales orders via
     * ZI_QuotationSalesOrder_HUB (actual fields: Quotation, SalesOrder, FollowOnDocument).
     *
     * @param array<int,string> $salesOrders
     * @return array<int,array{sales_order:string,quotation:string,quotation_item:string,error:?string}>
     */
    public function resolveQuotationsForSalesOrders(array $salesOrders): array
    {
        $out = [];
        if ($salesOrders === []) {
            return $out;
        }

        $service = (string) ($this->cfg['quotation_service']
            ?? '/sap/opu/odata/sap/ZI_QUOTATIONSALESORDER_HUB_CDS/ZI_QuotationSalesOrder_HUB');

        foreach (array_chunk($salesOrders, 15) as $chunk) {
            $parts = [];
            foreach ($chunk as $so) {
                $digits = preg_replace('/\D/', '', (string) $so) ?? '';
                if ($digits === '') {
                    continue;
                }
                $variants = array_unique(array_filter([
                    $digits,
                    ltrim($digits, '0') ?: $digits,
                    str_pad($digits, 10, '0', STR_PAD_LEFT),
                ]));
                foreach ($variants as $v) {
                    $esc = str_replace("'", "''", $v);
                    $parts[] = "SalesOrder eq '{$esc}'";
                    $parts[] = "FollowOnDocument eq '{$esc}'";
                }
            }
            if ($parts === []) {
                continue;
            }

            $rows = [];
            $result = $this->odata->eachPage(function (array $batch) use (&$rows): void {
                foreach ($batch as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }, [
                '$filter'  => implode(' or ', $parts),
                '$select'  => 'Quotation,QuotationItem,SalesOrder,FollowOnDocument',
            ], $service, 2000);

            if ($rows === [] && ($result['error'] ?? null)) {
                foreach ($chunk as $so) {
                    $out[] = [
                        'sales_order'    => $this->displayNumber((string) $so),
                        'quotation'      => '',
                        'quotation_item' => '',
                        'error'          => $result['error'],
                    ];
                }
                continue;
            }

            $seen = [];
            foreach ($rows as $row) {
                $quotation = $this->displayNumber((string) ($row['Quotation'] ?? ''));
                $so = $this->displayNumber((string) ($row['SalesOrder'] ?? ''));
                if ($so === '') {
                    $so = $this->displayNumber((string) ($row['FollowOnDocument'] ?? ''));
                }
                $qi = $this->displayNumber((string) ($row['QuotationItem'] ?? ''));
                $key = $so . '|' . $quotation . '|' . $qi;
                if ($quotation === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'sales_order'    => $so,
                    'quotation'      => $quotation,
                    'quotation_item' => $qi,
                    'error'          => null,
                ];
            }

            // Ensure every requested SO appears at least once (even if no quote found)
            foreach ($chunk as $so) {
                $soDisp = $this->displayNumber((string) $so);
                $found = false;
                foreach ($out as $link) {
                    if (($link['sales_order'] ?? '') === $soDisp && ($link['quotation'] ?? '') !== '') {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $alreadyBlank = false;
                    foreach ($out as $link) {
                        if (($link['sales_order'] ?? '') === $soDisp) {
                            $alreadyBlank = true;
                            break;
                        }
                    }
                    if (!$alreadyBlank) {
                        $out[] = [
                            'sales_order'    => $soDisp,
                            'quotation'      => '',
                            'quotation_item' => '',
                            'error'          => 'No quotation linked to this sales order in QuotationSalesOrder hub.',
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $costLines
     * @param array<int,array<string,mixed>> $quoteMap
     * @return array<string,mixed>
     */
    private function validateItem(
        array $item,
        array $costLines,
        string $category,
        string $poNumber,
        array $quoteMap = []
    ): array {
        $poUnit = (float) ($item['net_price'] ?? 0);
        $qty = (float) ($item['order_qty'] ?? 0);
        $material = trim((string) ($item['material'] ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $materialGroup = trim((string) ($item['material_group'] ?? ''));
        $matCat = (string) ($item['mat_category'] ?? 'other');
        $sos = array_values(array_filter(array_map(
            fn ($s) => $this->displayNumber((string) $s),
            (array) ($item['sales_orders'] ?? [])
        )));

        $itemQuotations = [];
        foreach ($quoteMap as $link) {
            $linkSo = (string) ($link['sales_order'] ?? '');
            $q = trim((string) ($link['quotation'] ?? ''));
            if ($q !== '' && ($sos === [] || in_array($linkSo, $sos, true))) {
                if (!in_array($q, $itemQuotations, true)) {
                    $itemQuotations[] = $q;
                }
            }
        }

        $matchResult = $this->findBestCostMatch(
            $costLines,
            $material,
            $description,
            $materialGroup,
            $sos,
            $itemQuotations,
            $matCat,
            $category
        );
        $match = $matchResult['line'];
        $score = (int) $matchResult['score'];
        $matchFlags = $matchResult['flags'];

        $expected = $match ? (float) ($match['rate'] ?? 0) : null;
        if ($match && ($expected === null || $expected <= 0)) {
            $expected = (float) ($match['purp'] ?? 0) * max(1.0, (float) ($match['convr'] ?? 1));
        }

        $status = 'EXCEPTION';
        $result = 'No matching ZCOST line';
        $variancePct = null;
        $diff = null;
        $costingRef = '';

        if ($match) {
            $docRef = trim((string) ($match['sales_order'] ?? ''));
            if ($docRef !== '') {
                $costingRef = 'Doc ' . $docRef . (!empty($match['so_item']) ? (' / ' . $match['so_item']) : '');
            }
            $section = (string) ($match['section'] ?? '');
            if ($section !== '') {
                $costingRef = trim($costingRef . ' · ' . $section);
            }
            if (!empty($match['item'])) {
                $costingRef = trim($costingRef . ' · ' . $match['item']);
            }

            if ($score >= self::MATCH_SCORE_PASS) {
                if ($expected === null || $expected <= 0) {
                    $status = 'NEEDS_REVIEW';
                    $result = 'Matched ZCOST line but rate is zero — Needs Review';
                } else {
                    $diff = round($poUnit - $expected, 4);
                    $variancePct = round(($diff / $expected) * 100, 2);
                    if ($variancePct > self::VARIANCE_LIMIT_PCT) {
                        $status = 'EXCEPTION';
                        $result = 'Cost variance exceeds limit';
                    } else {
                        $status = 'PASS';
                        $result = 'PASS — material/group/description matched';
                    }
                }
            } elseif ($score >= self::MATCH_SCORE_REVIEW) {
                $status = 'NEEDS_REVIEW';
                $result = 'Uncertain item mapping — Needs Review';
                if ($expected !== null && $expected > 0) {
                    $diff = round($poUnit - $expected, 4);
                    $variancePct = round(($diff / $expected) * 100, 2);
                }
            } else {
                $status = 'EXCEPTION';
                $result = 'No reliable ZCOST match for this PO item';
                $match = null;
                $expected = null;
                $costingRef = '';
            }
        } elseif ($sos === [] && $itemQuotations === []) {
            $result = 'No linked sales order / quotation — cannot load ZCOST';
        }

        $costItem = (string) ($match['item'] ?? '');
        $costDesc = (string) ($match['description'] ?? '');
        $costSection = (string) ($match['section'] ?? '');

        return [
            'po_item'            => (string) ($item['item'] ?? ''),
            'material'           => $material,
            'description'        => $description,
            'sales_order'        => $sos[0] ?? '',
            'sales_orders'       => $sos,
            'quotation'          => $itemQuotations[0] ?? '',
            'quotations'         => $itemQuotations,
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
            'match_score'        => $score,
            'match_flags'        => $matchFlags,
            'purchase_order'     => $this->displayNumber($poNumber),
            'net_value'          => (float) ($item['net_value'] ?? ($qty * $poUnit)),
            'quoted_currency'    => $match['currency'] ?? 'INR',
            'quoted_uom'         => $match['unit'] ?? ($item['unit'] ?? ''),
            'cost_section'       => $costSection,
            'cost_item'          => $costItem,
            'cost_description'   => $costDesc,
            'cost_material'      => (string) ($match['material'] ?? $costItem),
            'sell_price'         => $match['sell_price'] ?? null,
            'making'             => $match['making'] ?? null,
            'selected'           => false,
        ];
    }

    /**
     * Item-wise match against ZCOST lines.
     * Scoring uses Material Code, Material Description (Maktx), Material Group ↔ Section mapping,
     * and document (quotation / sales order) affinity.
     *
     * @param array<int,array<string,mixed>> $costLines
     * @param array<int,string> $salesOrders
     * @param array<int,string> $quotations
     * @return array{line:?array,score:int,flags:array<string,bool>}
     */
    private function findBestCostMatch(
        array $costLines,
        string $material,
        string $description,
        string $materialGroup,
        array $salesOrders,
        array $quotations,
        string $matCat,
        string $categoryFilter
    ): array {
        $empty = ['line' => null, 'score' => 0, 'flags' => [
            'material_code' => false,
            'description'   => false,
            'material_group'=> false,
            'document'      => false,
        ]];
        if ($costLines === []) {
            return $empty;
        }

        $wantSections = $this->sectionsForGroup($materialGroup, $categoryFilter !== 'all' ? $categoryFilter : $matCat);
        $matNorm = $this->normText($material);
        $descNorm = $this->normText($description);
        $groupNorm = $this->normText($materialGroup);

        $best = null;
        $bestScore = -1;
        $bestFlags = $empty['flags'];

        foreach ($costLines as $line) {
            $section = (string) ($line['section'] ?? '');
            if ($wantSections !== [] && !in_array($section, $wantSections, true)) {
                // Soft penalty — still allow if material code is an exact hit
                $sectionOk = false;
            } else {
                $sectionOk = true;
            }

            $score = 0;
            $flags = [
                'material_code'  => false,
                'description'    => false,
                'material_group' => false,
                'document'       => false,
            ];

            $lineDoc = $this->displayNumber((string) ($line['sales_order'] ?? ''));
            if ($lineDoc !== '') {
                if (in_array($lineDoc, $quotations, true) || in_array($lineDoc, $salesOrders, true)) {
                    $score += 50;
                    $flags['document'] = true;
                } elseif ($salesOrders !== [] || $quotations !== []) {
                    $score -= 15;
                }
            }

            $lineItem = $this->normText((string) ($line['item'] ?? ''));
            $lineDesc = $this->normText((string) ($line['description'] ?? '')); // Maktx
            $lineMat = $this->normText((string) ($line['material'] ?? ''));
            $descCodes = $this->splitMaterialCodes((string) ($line['description'] ?? ''));

            // Material Code
            if ($matNorm !== '') {
                if ($lineItem === $matNorm || $lineMat === $matNorm) {
                    $score += 100;
                    $flags['material_code'] = true;
                } elseif (($lineItem !== '' && str_contains($lineItem, $matNorm))
                    || ($lineMat !== '' && str_contains($lineMat, $matNorm))) {
                    $score += 70;
                    $flags['material_code'] = true;
                } elseif ($descCodes !== [] && in_array($matNorm, $descCodes, true)) {
                    $score += 95;
                    $flags['material_code'] = true;
                } elseif ($lineDesc !== '' && str_contains($lineDesc, $matNorm)) {
                    $score += 35;
                }
            }

            // Material Description ↔ ZCOST Maktx (exact / contains / token overlap)
            if ($descNorm !== '' && $lineDesc !== '') {
                if ($lineDesc === $descNorm) {
                    $score += 80;
                    $flags['description'] = true;
                } elseif (str_contains($lineDesc, $descNorm) || str_contains($descNorm, $lineDesc)) {
                    $score += 45;
                    $flags['description'] = true;
                } else {
                    $a = array_filter(explode(' ', $descNorm));
                    $b = array_filter(explode(' ', $lineDesc));
                    $overlap = count(array_intersect($a, $b));
                    if ($overlap >= 2) {
                        $score += min(30, $overlap * 8);
                        $flags['description'] = true;
                    } elseif ($overlap === 1) {
                        $score += 8;
                    }
                }
            }

            // Material Group ↔ ZCOST Section (configurable mapping)
            if ($sectionOk && $wantSections !== [] && in_array($section, $wantSections, true)) {
                $score += 25;
                $flags['material_group'] = true;
            } elseif ($groupNorm !== '' && $this->normText($section) !== '' && str_contains($groupNorm, $this->normText($section))) {
                $score += 15;
                $flags['material_group'] = true;
            }

            $rate = (float) ($line['rate'] ?? 0);
            if ($rate > 0) {
                $score += 5;
            }

            // Exact material code can override section filter
            if (!$sectionOk && !$flags['material_code']) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $line;
                $bestFlags = $flags;
            }
        }

        if ($bestScore < self::MATCH_SCORE_REVIEW) {
            return $empty;
        }

        return [
            'line'  => $best,
            'score' => $bestScore,
            'flags' => $bestFlags,
        ];
    }

    /**
     * Configurable PO Material Group → ZCOST section mapping.
     * Override via config('sap')['po_cost_section_map'].
     *
     * @return array<int,string>
     */
    private function sectionsForGroup(string $materialGroup, string $matCat): array
    {
        $map = $this->cfg['po_cost_section_map'] ?? [
            'FABRIC'   => ['Fabric'],
            'TRIM'     => ['Trim', 'Packing', 'Special'],
            'TRIMS'    => ['Trim', 'Packing', 'Special'],
            'ACCESSOR' => ['Trim', 'Packing', 'Special'],
            'PACK'     => ['Packing'],
            'SPECIAL'  => ['Special'],
        ];

        $groupNorm = $this->normText($materialGroup);
        if ($groupNorm !== '') {
            foreach ($map as $needle => $sections) {
                if (str_contains($groupNorm, $this->normText((string) $needle))) {
                    return array_values($sections);
                }
            }
        }

        return match ($matCat) {
            'fabric' => ['Fabric'],
            'trims'  => ['Trim', 'Packing', 'Special'],
            default  => ['Fabric', 'Trim', 'Packing', 'Special'],
        };
    }

    private function normText(string $s): string
    {
        $s = strtoupper(trim($s));
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * @return list<string>
     */
    private function splitMaterialCodes(string $raw): array
    {
        $parts = preg_split('/[,;+|\/]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $n = $this->normText($part);
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
        $needsReview = 0;
        $totalValue = 0.0;
        foreach ($records as $r) {
            $st = (string) ($r['status'] ?? '');
            if ($st === 'PASS') {
                $passed++;
            } elseif ($st === 'NEEDS_REVIEW') {
                $needsReview++;
            } else {
                $exceptions++;
            }
            $totalValue += (float) ($r['net_value'] ?? 0);
        }
        return [
            'po_items'       => count($records),
            'passed'         => $passed,
            'exceptions'     => $exceptions,
            'needs_review'   => $needsReview,
            'pending'        => 0,
            'total_po_value' => round($totalValue, 2),
            'currency'       => 'INR',
            'can_pass_po'    => count($records) > 0 && $exceptions === 0 && $needsReview === 0,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'po_items'       => 0,
            'passed'         => 0,
            'exceptions'     => 0,
            'needs_review'   => 0,
            'pending'        => 0,
            'total_po_value' => 0,
            'currency'       => 'INR',
            'can_pass_po'    => false,
        ];
    }
}
