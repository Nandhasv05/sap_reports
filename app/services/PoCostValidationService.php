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
     *  1) Primary: Fetch from Z_QUOTATIONZCOSTCOM_CDS (PO + Items + Quotation + SO + Fabric/Trim/Packing/Special)
     *  2) Fallback: Fetch PO items from ZI_PURCHASEORDERAPI_HUB -> Quotations from hub -> ZCOST sheets
     *  3) Item-wise compare Material Group / Description / Trim code / Material Code
     *
     * @return array{success:bool,purchase_order:string,category:string,header:array,summary:array,records:array,flow:array,error:?string}
     */
    public function validate(
        string $purchaseOrder,
        string $category = 'all',
        string $salesOrderOverride = '',
        ?string $itemOverride = null,
        ?string $quotationOverride = null,
        string $mode = 'validated',
        string $customerQuote = '',
        string $endUserQuote = ''
    ): array {
        $category = strtolower(trim($category));
        if (!in_array($category, ['all', 'fabric', 'trims'], true)) {
            $category = 'all';
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['validated', 'non_validated'], true)) {
            $mode = 'validated';
        }

        $poDigits = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        $soOverride = $this->displayNumber(preg_replace('/\D/', '', $salesOrderOverride) ?? '');
        $itemOverride = ($itemOverride !== null && trim($itemOverride) !== '') ? trim($itemOverride) : null;
        $quotationOverride = ($quotationOverride !== null && trim($quotationOverride) !== '')
            ? $this->displayNumber(preg_replace('/\D/', '', $quotationOverride) ?? '')
            : null;
        if ($quotationOverride === '') {
            $quotationOverride = null;
        }
        $customerQuote = $this->displayNumber(preg_replace('/\D/', '', $customerQuote) ?? '');
        $endUserQuote = $this->displayNumber(preg_replace('/\D/', '', $endUserQuote) ?? '');
        if ($customerQuote === '' && $quotationOverride !== null) {
            $customerQuote = $quotationOverride;
        }

        // Prefer classic TEMPFIN / PASSPO grid (PONO, SALORDNO, BUDGET_COST_PER_GMT, ACTUAL_COST, …)
        require_once __DIR__ . '/PoTempfinCostingService.php';
        $tempfinType = $category === 'fabric' ? 'fabric' : ($category === 'trims' ? 'trims' : 'all');
        $tempfin = (new PoTempfinCostingService())->fetchForPurchaseOrder($poDigits, $tempfinType);
        if (!empty($tempfin['success']) && !empty($tempfin['records'])) {
            return [
                'success'                  => true,
                'purchase_order'           => $this->displayNumber($purchaseOrder),
                'category'                 => $category,
                'mode'                     => $mode,
                'customer_quotation'       => $customerQuote,
                'end_user_quotation'       => $endUserQuote,
                'header'                   => [
                    'purchase_order' => $this->displayNumber($purchaseOrder),
                    'sales_orders'   => array_values(array_unique(array_filter(array_map(
                        static fn ($r) => (string) ($r['salordno'] ?? ''),
                        $tempfin['records']
                    )))),
                ],
                'summary'                  => $tempfin['summary'],
                'records'                  => $tempfin['records'],
                'quote_options'            => [],
                'validated_quotations'     => [],
                'non_validated_quotations' => [],
                'costing'                  => [
                    'sales_orders' => $tempfin['summary']['sales_orders'] ?? 0,
                    'quotations'   => [],
                    'sheets'       => [],
                    'cost_lines'   => count($tempfin['records']),
                    'error'        => null,
                ],
                'cost_lines'               => [],
                'flow'                     => [
                    'source'   => 'TEMPFIN_PASSPO',
                    'mat_type' => $tempfin['mat_type'] ?? $tempfinType,
                ],
                'error'                    => null,
            ];
        }

        // Fallback: Z_QuotationZCostCom OData (validated + non-validated quote options)
        $cds = $this->fetchQuotationZCost($poDigits, $itemOverride, null, $soOverride !== '' ? $soOverride : null);

        if ($cds['success'] && !empty($cds['po_items'])) {
            $poItems = $cds['po_items'];
            if ($category !== 'all') {
                $poItems = array_values(array_filter(
                    $poItems,
                    static fn (array $r): bool => ($r['mat_category'] ?? '') === $category
                ));
            }

            $allCostLines = $cds['cost_lines'];
            $quotations = $cds['quotations'];
            $salesOrders = $cds['sales_orders'];
            $quoteOptions = $cds['quote_options'] ?? [];
            $validatedQuotes = $cds['validated_quotations'] ?? [];
            $nonValidatedQuotes = $cds['non_validated_quotations'] ?? [];

            if ($soOverride !== '' && !in_array($soOverride, $salesOrders, true)) {
                $salesOrders[] = $soOverride;
            }

            // Keep all CDS rows visible. Each row compares against its own QuotationNo cost lines
            // (or against selected Customer / End User quotations in non_validated mode).
            if ($mode === 'non_validated' && $customerQuote === '' && $nonValidatedQuotes !== []) {
                $customerQuote = (string) $nonValidatedQuotes[0];
            }

            $activeQuotes = $mode === 'validated'
                ? $validatedQuotes
                : array_values(array_filter([$customerQuote, $endUserQuote]));
            $displayLines = $mode === 'validated'
                ? array_values(array_filter(
                    $allCostLines,
                    static fn (array $l): bool => !empty($l['is_validated'])
                ))
                : array_values(array_filter(
                    $allCostLines,
                    static function (array $l) use ($activeQuotes): bool {
                        $q = (string) ($l['quotation_no'] ?? '');
                        return $q !== '' && in_array($q, $activeQuotes, true);
                    }
                ));

            $endUserLines = [];
            if ($mode === 'non_validated' && $endUserQuote !== '' && $endUserQuote !== $customerQuote) {
                $endUserLines = array_values(array_filter(
                    $allCostLines,
                    static fn (array $l): bool => (string) ($l['quotation_no'] ?? '') === $endUserQuote
                ));
            }

            $records = [];
            foreach ($poItems as $item) {
                $itemQuote = (string) ($item['quotation'] ?? '');
                if ($mode === 'non_validated' && $customerQuote !== '') {
                    $compareQuote = $customerQuote;
                } else {
                    $compareQuote = $itemQuote;
                }

                $matchLines = $compareQuote !== ''
                    ? array_values(array_filter(
                        $allCostLines,
                        static function (array $l) use ($compareQuote, $mode): bool {
                            $q = (string) ($l['quotation_no'] ?? '');
                            if ($q !== $compareQuote) {
                                return false;
                            }
                            if ($mode === 'validated') {
                                return !empty($l['is_validated']);
                            }
                            return true;
                        }
                    ))
                    : [];

                // Validated mode with no cost lines on this quote: still try validated pool for same material
                if ($mode === 'validated' && $matchLines === [] && !empty($item['is_validated_quote'])) {
                    $matchLines = array_values(array_filter(
                        $allCostLines,
                        static fn (array $l): bool => !empty($l['is_validated'])
                    ));
                }

                $quoteMap = $compareQuote !== ''
                    ? [[
                        'sales_order'    => (string) (($item['sales_orders'][0] ?? '') ?: ($salesOrders[0] ?? '')),
                        'quotation'      => $compareQuote,
                        'quotation_item' => '',
                        'error'          => null,
                    ]]
                    : [];

                $rec = $this->validateItem(
                    $item,
                    $matchLines,
                    $category,
                    $poDigits,
                    $quoteMap
                );
                $rec['compare_mode'] = $mode;
                $rec['quotation'] = $itemQuote !== '' ? $itemQuote : $compareQuote;
                $rec['is_validated_quote'] = !empty($item['is_validated_quote']);
                $rec['customer_quotation'] = $mode === 'validated'
                    ? (string) ($rec['quotation'] ?: ($validatedQuotes[0] ?? ''))
                    : $customerQuote;
                $rec['end_user_quotation'] = $endUserQuote;
                if (($rec['supplier'] ?? '') === '') {
                    $rec['supplier'] = (string) ($cds['header']['supplier'] ?? '');
                    $rec['supplier_full_name'] = (string) ($cds['header']['supplier_full_name'] ?? '');
                    $rec['created_by'] = (string) ($cds['header']['created_by'] ?? '');
                    $rec['po_date'] = (string) ($cds['header']['po_date'] ?? ($rec['po_date'] ?? ''));
                }

                if ($endUserLines !== []) {
                    $endRec = $this->validateItem(
                        $item,
                        $endUserLines,
                        $category,
                        $poDigits,
                        [[
                            'sales_order'    => $salesOrders[0] ?? '',
                            'quotation'      => $endUserQuote,
                            'quotation_item' => '',
                            'error'          => null,
                        ]]
                    );
                    $rec['end_user_maktx'] = $endRec['maktx'] ?? '';
                    $rec['end_user_cost_section'] = $endRec['cost_section'] ?? '';
                    $rec['end_user_cost_item'] = $endRec['cost_item'] ?? '';
                    $rec['end_user_expected_unit_cost'] = $endRec['expected_unit_cost'] ?? null;
                    $rec['end_user_variance_pct'] = $endRec['variance_pct'] ?? null;
                    $rec['end_user_status'] = $endRec['status'] ?? '';
                    $rec['end_user_validation_result'] = $endRec['validation_result'] ?? '';
                }

                $records[] = $rec;
            }

            $quoteMap = [];
            foreach ($activeQuotes as $q) {
                if ($q === '') {
                    continue;
                }
                $quoteMap[] = [
                    'sales_order'    => $salesOrders[0] ?? '',
                    'quotation'      => $q,
                    'quotation_item' => '',
                    'error'          => null,
                ];
            }

            $summary = $this->summarize($records);
            $header = $cds['header'];
            $header['cost_sheets'] = count($quotations);
            $header['quotations'] = $quotations;
            $header['sales_orders'] = $salesOrders;
            $header['cost_error'] = null;

            $modeError = null;
            if ($mode === 'validated' && $validatedQuotes === []) {
                $modeError = 'No validated costing quotation (CostingQuotationNo) for this PO. Switch to Non-Validated and pick Customer / End User quotations.';
            } elseif ($mode === 'non_validated' && $customerQuote === '') {
                $modeError = 'Select a Customer quotation to compare.';
            } elseif ($mode === 'non_validated' && $matchLines === []) {
                $modeError = 'No costing lines found for Customer quotation ' . $customerQuote . '.';
            }

            return [
                'success'        => true,
                'purchase_order' => $this->displayNumber($purchaseOrder),
                'category'       => $category,
                'mode'           => $mode,
                'customer_quotation' => $customerQuote,
                'end_user_quotation' => $endUserQuote,
                'header'         => $header,
                'summary'        => $summary,
                'records'        => $records,
                'quote_options'  => $quoteOptions,
                'validated_quotations' => $validatedQuotes,
                'non_validated_quotations' => $nonValidatedQuotes,
                'costing'        => [
                    'sales_orders' => $salesOrders,
                    'quotations'   => $quotations,
                    'sheets'       => $quotations,
                    'cost_lines'   => count($displayLines),
                    'error'        => null,
                ],
                'cost_lines'     => $displayLines,
                'flow'           => [
                    'source'            => 'Z_QUOTATIONZCOSTCOM_CDS',
                    'mode'              => $mode,
                    'customer_quotation'=> $customerQuote,
                    'end_user_quotation'=> $endUserQuote,
                    'step1_po'          => $this->displayNumber($purchaseOrder),
                    'step2_sales_orders'=> $salesOrders,
                    'step2_quotations'  => $quotations,
                    'step2_validated'   => $validatedQuotes,
                    'step2_non_validated'=> $nonValidatedQuotes,
                    'step2_links'       => $quoteMap,
                    'step3_docs'        => array_values(array_unique(array_merge($activeQuotes, $salesOrders))),
                    'step3_lines'       => count($displayLines),
                    'step3_error'       => $modeError,
                ],
                'error'          => $modeError,
            ];
        }

        // ── Fallback Path: Multi-hop PO -> Quotation Hub -> ZCOST ──────────
        // Used when PO has no records in Z_QUOTATIONZCOSTCOM_CDS
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

        // Sales Order → Quotation Number
        $quoteMap = $this->resolveQuotationsForSalesOrders($salesOrders);
        $quotations = array_values(array_unique(array_filter(array_map(
            static fn ($row) => trim((string) ($row['quotation'] ?? '')),
            $quoteMap
        ))));

        // ZCOST by Quotation (preferred) then Sales Order
        $docKeys = array_values(array_unique(array_filter(array_merge($quotations, $salesOrders))));
        $costing = $this->costService->fetchForSalesOrders($docKeys);
        $costLines = is_array($costing['records'] ?? null) ? $costing['records'] : [];

        // Item-wise validation
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
            'mode'           => $mode,
            'customer_quotation' => $customerQuote,
            'end_user_quotation' => $endUserQuote,
            'header'         => $header,
            'summary'        => $summary,
            'records'        => $records,
            'quote_options'  => array_map(static function (string $q): array {
                return [
                    'quotation'         => $q,
                    'costing_quotation' => '',
                    'is_validated'      => false,
                    'sales_order'       => '',
                    'fabric_lines'      => 0,
                    'trim_lines'        => 0,
                    'packing_lines'     => 0,
                    'special_lines'     => 0,
                ];
            }, $quotations),
            'validated_quotations' => [],
            'non_validated_quotations' => $quotations,
            'costing'        => [
                'sales_orders' => $salesOrders,
                'quotations'   => $quotations,
                'sheets'       => $costing['sheets'] ?? [],
                'error'        => $costing['error'] ?? null,
            ],
            'cost_lines'     => $costLines,
            'flow'           => [
                'source'            => 'FALLBACK_HUB',
                'mode'              => $mode,
                'customer_quotation'=> $customerQuote,
                'end_user_quotation'=> $endUserQuote,
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
     * Fetch PO items and expanded costing sheets (to_Fabric, to_Trim, to_Packing, to_Special)
     * directly from Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom.
     *
     * @param string $purchaseOrder
     * @param string|null $item
     * @param string|null $quotation
     * @param string|null $so
     * @return array{
     *   success: bool,
     *   po_items: array<int, array<string, mixed>>,
     *   cost_lines: array<int, array<string, mixed>>,
     *   quotations: array<int, string>,
     *   sales_orders: array<int, string>,
     *   header: array<string, mixed>,
     *   error: ?string
     * }
     */
    public function fetchQuotationZCost(
        string $purchaseOrder,
        ?string $item = null,
        ?string $quotation = null,
        ?string $so = null
    ): array {
        $poDigits = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        if ($poDigits === '') {
            return [
                'success'                   => false,
                'po_items'                  => [],
                'cost_lines'                => [],
                'quotations'                => [],
                'validated_quotations'      => [],
                'non_validated_quotations'  => [],
                'quote_options'             => [],
                'sales_orders'              => [],
                'header'                    => [],
                'error'                     => 'Purchase order number is required.',
            ];
        }

        $service = (string) ($this->cfg['quotation_costing_service']
            ?? '/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom');

        // Variants: exact digits and 10-digit zero-padded
        $poVariants = array_unique(array_filter([
            $poDigits,
            str_pad($poDigits, 10, '0', STR_PAD_LEFT),
            ltrim($poDigits, '0'),
        ]));

        $rows = [];
        $lastError = null;

        foreach ($poVariants as $poCandidate) {
            $filterParts = ["PurchaseOrder eq '{$poCandidate}'"];
            if ($item !== null && trim($item) !== '') {
                $itemClean = preg_replace('/\D/', '', $item) ?? '';
                if ($itemClean !== '') {
                    $itemPadded = str_pad(ltrim($itemClean, '0'), 5, '0', STR_PAD_LEFT);
                    $filterParts[] = "PurchaseOrderItem eq '{$itemPadded}'";
                }
            }
            if ($quotation !== null && trim($quotation) !== '') {
                $qClean = str_replace("'", "''", trim($quotation));
                $filterParts[] = "QuotationNo eq '{$qClean}'";
            }
            if ($so !== null && trim($so) !== '') {
                $soClean = str_replace("'", "''", trim($so));
                $filterParts[] = "SalesOrderReference eq '{$soClean}'";
            }

            $query = [
                '$filter' => implode(' and ', $filterParts),
                '$expand' => 'to_Fabric,to_Trim,to_Packing,to_Special',
            ];

            $res = $this->odata->fetchResults($service, $query, 1000);
            if (!empty($res['rows'])) {
                $rows = $res['rows'];
                $lastError = null;
                break;
            }
            if (!empty($res['error'])) {
                $lastError = $res['error'];
            }
        }

        if ($rows === []) {
            return [
                'success'                   => false,
                'po_items'                  => [],
                'cost_lines'                => [],
                'quotations'                => [],
                'validated_quotations'      => [],
                'non_validated_quotations'  => [],
                'quote_options'             => [],
                'sales_orders'              => [],
                'header'                    => [],
                'error'                     => $lastError ?? 'No records found in Z_QUOTATIONZCOSTCOM_CDS.',
            ];
        }

        // One UI/API row per CDS result (PurchaseOrderItem + QuotationNo) — do not collapse to unique PO items
        $itemsMap = [];
        $costLines = [];
        $seenCostKeys = [];
        $quotations = [];
        $salesOrders = [];
        $header = [];
        /** @var array<string, array{quotation:string,costing_quotation:string,is_validated:bool,sales_order:string,fabric_lines:int,trim_lines:int,packing_lines:int,special_lines:int}> */
        $quoteMeta = [];

        foreach ($rows as $row) {
            if ($header === []) {
                $header = [
                    'purchase_order'     => $this->displayNumber((string) ($row['PurchaseOrder'] ?? $purchaseOrder)),
                    'supplier'           => $this->displayNumber((string) ($row['Supplier'] ?? '')),
                    'supplier_full_name' => trim((string) ($row['SupplierFullName'] ?? '')),
                    'company_code'       => trim((string) ($row['CompanyCode'] ?? '')),
                    'purchasing_org'     => trim((string) ($row['PurchasingOrganization'] ?? '')),
                    'purchasing_group'   => trim((string) ($row['PurchasingGroup'] ?? '')),
                    'created_by'         => trim((string) ($row['CreatedByUser'] ?? '')),
                    'po_date'            => $this->sapDate($row['PurchaseOrderDate'] ?? null),
                    'currency'           => trim((string) ($row['DocumentCurrency'] ?? 'INR')),
                ];
            }

            $itemRaw = (string) ($row['PurchaseOrderItem'] ?? '');
            $itemNo = $this->displayNumber($itemRaw);
            $quoteNo = $this->displayNumber((string) ($row['QuotationNo'] ?? ''));
            $costingQuoteNo = $this->displayNumber((string) ($row['CostingQuotationNo'] ?? ''));
            $isValidatedRow = $costingQuoteNo !== '';
            $soRef = $this->displayNumber((string) ($row['SalesOrderReference'] ?? ''));
            $rowKey = $itemNo . '|' . ($quoteNo !== '' ? $quoteNo : '_');

            if ($quoteNo !== '' && !in_array($quoteNo, $quotations, true)) {
                $quotations[] = $quoteNo;
            }
            if ($soRef !== '' && !in_array($soRef, $salesOrders, true)) {
                $salesOrders[] = $soRef;
            }

            // Also check zzvbeln1 .. zzvbeln20
            for ($i = 1; $i <= 20; $i++) {
                $zv = $this->displayNumber((string) ($row['zzvbeln' . $i] ?? ''));
                if ($zv !== '' && !in_array($zv, $salesOrders, true)) {
                    $salesOrders[] = $zv;
                }
            }

            if ($quoteNo !== '') {
                if (!isset($quoteMeta[$quoteNo])) {
                    $quoteMeta[$quoteNo] = [
                        'quotation'         => $quoteNo,
                        'costing_quotation' => $costingQuoteNo,
                        'is_validated'      => $isValidatedRow,
                        'sales_order'       => $soRef,
                        'fabric_lines'      => 0,
                        'trim_lines'        => 0,
                        'packing_lines'     => 0,
                        'special_lines'     => 0,
                    ];
                } else {
                    if ($isValidatedRow) {
                        $quoteMeta[$quoteNo]['is_validated'] = true;
                        $quoteMeta[$quoteNo]['costing_quotation'] = $costingQuoteNo;
                    }
                    if ($soRef !== '' && $quoteMeta[$quoteNo]['sales_order'] === '') {
                        $quoteMeta[$quoteNo]['sales_order'] = $soRef;
                    }
                }
            }

            if (!isset($itemsMap[$rowKey])) {
                $qty = $this->sapNumber($row['OrderQuantity'] ?? 0);
                $netAmt = $this->sapNumber($row['NetPriceAmount'] ?? 0);
                // In SAP CDS view, NetPriceAmount is line net amount. Unit price = netAmt / qty.
                $unitPrice = ($qty > 0 && $netAmt > 0) ? round($netAmt / $qty, 4) : $netAmt;

                $matType = trim((string) ($row['MaterialType'] ?? ''));
                $matTypeDesc = trim((string) ($row['MatTypeDesc'] ?? ''));
                $matGroup = trim((string) ($row['MaterialGroup'] ?? ''));
                $matGrpDesc = trim((string) ($row['MatGrpDesc'] ?? ''));
                $matDesc = trim((string) ($row['MaterialDesc'] ?? ''));
                $itemText = trim((string) ($row['PurchaseOrderItemText'] ?? ''));
                $matCategory = SapPurchaseOrderService::classifyMaterial($matType, $matTypeDesc, $matGroup);

                $itemsMap[$rowKey] = [
                    'item'               => $itemNo,
                    'row_key'            => $rowKey,
                    'purchase_order'     => $this->displayNumber((string) ($row['PurchaseOrder'] ?? $purchaseOrder)),
                    'material'           => trim((string) ($row['Material'] ?? '')),
                    'description'        => $matDesc !== '' ? $matDesc : $itemText,
                    'material_desc'      => $matDesc !== '' ? $matDesc : $itemText,
                    'item_text'          => $itemText,
                    'material_type'      => $matType,
                    'mat_type_desc'      => $matTypeDesc,
                    'material_group'     => $matGroup,
                    'mat_grp_desc'       => $matGrpDesc,
                    'mat_category'       => $matCategory,
                    'plant'              => trim((string) ($row['Plant'] ?? '')),
                    'storage_location'   => trim((string) ($row['StorageLocation'] ?? '')),
                    'order_qty'          => $qty,
                    'unit'               => trim((string) ($row['PurchaseOrderQuantityUnit'] ?? '')),
                    'net_price'          => $unitPrice,
                    'net_value'          => $netAmt,
                    'currency'           => trim((string) ($row['DocumentCurrency'] ?? 'INR')),
                    'po_type'            => trim((string) ($row['PurchaseOrderType'] ?? '')),
                    'po_type_name'       => trim((string) ($row['PurchasingDocumentTypeName'] ?? '')),
                    'po_date'            => $this->sapDate($row['PurchaseOrderDate'] ?? null),
                    'supplier'           => $this->displayNumber((string) ($row['Supplier'] ?? '')),
                    'supplier_full_name' => trim((string) ($row['SupplierFullName'] ?? '')),
                    'created_by'         => trim((string) ($row['CreatedByUser'] ?? '')),
                    'sales_orders'       => $soRef !== '' ? [$soRef] : [],
                    'quotations'         => $quoteNo !== '' ? [$quoteNo] : [],
                    'quotation'          => $quoteNo,
                    'costing_quotation'  => $costingQuoteNo,
                    'is_validated_quote' => $isValidatedRow,
                ];
            } else {
                if ($soRef !== '' && !in_array($soRef, $itemsMap[$rowKey]['sales_orders'], true)) {
                    $itemsMap[$rowKey]['sales_orders'][] = $soRef;
                }
                if ($isValidatedRow) {
                    $itemsMap[$rowKey]['is_validated_quote'] = true;
                    $itemsMap[$rowKey]['costing_quotation'] = $costingQuoteNo;
                }
            }

            // Extract costing lines from expansions
            $expansions = [
                'to_Fabric'  => 'Fabric',
                'to_Trim'    => 'Trim',
                'to_Packing' => 'Packing',
                'to_Special' => 'Special',
            ];

            foreach ($expansions as $navKey => $section) {
                $lines = $row[$navKey]['results'] ?? null;
                if (!is_array($lines)) {
                    continue;
                }
                $lineCount = 0;
                foreach ($lines as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $vbeln = $this->displayNumber((string) ($line['VBELN'] ?? ''));
                    $posnr = $this->displayNumber((string) ($line['POSNR'] ?? ''));
                    $zline = $this->displayNumber((string) ($line['ZLINE'] ?? ''));
                    $itemCode = trim((string) ($line['ITEM'] ?? ''));
                    $maktx = trim((string) ($line['MAKTX'] ?? ''));
                    if ($itemCode === '' && $maktx === '') {
                        continue;
                    }

                    $parentQuote = $quoteNo !== '' ? $quoteNo : $costingQuoteNo;
                    $lineQuote = $vbeln !== '' ? $vbeln : $parentQuote;
                    $dedup = "{$lineQuote}|{$posnr}|{$zline}|{$itemCode}|{$maktx}|{$section}";
                    if (isset($seenCostKeys[$dedup])) {
                        continue;
                    }
                    $seenCostKeys[$dedup] = true;
                    $lineCount++;

                    $rate = $this->sapNumber($line['RATE'] ?? 0);
                    $purp = $this->sapNumber($line['PURP'] ?? 0);
                    $convr = $this->sapNumber($line['CONVR'] ?? 1);
                    $curunit = trim((string) ($line['CURUNIT'] ?? ''));
                    $curUpper = strtoupper($curunit);
                    if ($curUpper === 'EURO') {
                        $curunit = 'EUR';
                    } elseif ($curUpper === 'IND') {
                        $curunit = 'INR';
                    }

                    $costLines[] = [
                        'section'             => $section,
                        'zsection'            => trim((string) ($line['ZSECTION'] ?? '')),
                        'sales_order'         => $vbeln !== '' ? $vbeln : $soRef,
                        'quotation_no'        => $lineQuote,
                        'parent_quotation_no' => $parentQuote,
                        'costing_quotation_no'=> $costingQuoteNo,
                        'is_validated'        => $isValidatedRow,
                        'so_item'             => $posnr,
                        'line_no'             => $zline,
                        'item'                => $itemCode !== '' ? $itemCode : $maktx,
                        'description'         => $maktx,
                        'maktx'               => $maktx,
                        'material'            => $itemCode !== '' ? $itemCode : $maktx,
                        'wid_siz'             => trim((string) ($line['WID_SIZ'] ?? '')),
                        'net'                 => $this->sapNumber($line['NET'] ?? 0),
                        'waste'               => $this->sapNumber($line['WSTE'] ?? 0),
                        'gross'               => $this->sapNumber($line['GROSS'] ?? 0),
                        'order_qty'           => $this->sapNumber($line['GROSS'] ?? 0),
                        'currency'            => $curunit,
                        'purp'                => $purp,
                        'convr'               => $convr > 0 ? $convr : 1.0,
                        'freight'             => $this->sapNumber($line['FRIGHT'] ?? 0),
                        'duty'                => $this->sapNumber($line['DUTY'] ?? 0),
                        'fin_cost'            => $this->sapNumber($line['FINCOST'] ?? 0),
                        'rate'                => $rate,
                        'net_price'           => $rate,
                        'total_inr'           => $this->sapNumber($line['TOTALINR'] ?? 0),
                        'total_fr'            => $this->sapNumber($line['TOTALFR'] ?? 0),
                        'total_per'           => $this->sapNumber($line['TOTALPER'] ?? 0),
                        'sell_price'          => null,
                        'making'              => null,
                        'overhead'            => null,
                        'markup'              => null,
                        'subtot'              => null,
                        'mat_category'        => strtolower($section) === 'fabric' ? 'fabric' : 'trims',
                        'material_type'       => $section,
                        'mat_type_desc'       => $section . ' costing',
                        'material_group'      => $section,
                        'unit'                => $curunit,
                    ];
                }
                if ($quoteNo !== '' && isset($quoteMeta[$quoteNo]) && $lineCount > 0) {
                    if ($section === 'Fabric') {
                        $quoteMeta[$quoteNo]['fabric_lines'] += $lineCount;
                    } elseif ($section === 'Trim') {
                        $quoteMeta[$quoteNo]['trim_lines'] += $lineCount;
                    } elseif ($section === 'Packing') {
                        $quoteMeta[$quoteNo]['packing_lines'] += $lineCount;
                    } else {
                        $quoteMeta[$quoteNo]['special_lines'] += $lineCount;
                    }
                }
            }
        }

        $quoteOptions = array_values($quoteMeta);
        usort($quoteOptions, static function (array $a, array $b): int {
            if (($a['is_validated'] ?? false) !== ($b['is_validated'] ?? false)) {
                return ($a['is_validated'] ?? false) ? -1 : 1;
            }
            return strcmp((string) ($a['quotation'] ?? ''), (string) ($b['quotation'] ?? ''));
        });
        $validatedQuotes = [];
        $nonValidatedQuotes = [];
        foreach ($quoteOptions as $opt) {
            $q = (string) ($opt['quotation'] ?? '');
            if ($q === '') {
                continue;
            }
            if (!empty($opt['is_validated'])) {
                $validatedQuotes[] = $q;
            } else {
                $nonValidatedQuotes[] = $q;
            }
        }

        $header['quotations'] = $quotations;
        $header['sales_orders'] = $salesOrders;
        $header['cost_sheets'] = count($quotations);
        $header['validated_quotations'] = $validatedQuotes;
        $header['non_validated_quotations'] = $nonValidatedQuotes;

        return [
            'success'                  => true,
            'po_items'                 => array_values($itemsMap),
            'cost_lines'               => $costLines,
            'quotations'               => $quotations,
            'validated_quotations'     => $validatedQuotes,
            'non_validated_quotations' => $nonValidatedQuotes,
            'quote_options'            => $quoteOptions,
            'sales_orders'             => $salesOrders,
            'header'                   => $header,
            'error'                    => null,
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
        $description = trim((string) ($item['material_desc'] ?? $item['description'] ?? ''));
        $materialType = trim((string) ($item['material_type'] ?? ''));
        $matTypeDesc = trim((string) ($item['mat_type_desc'] ?? ''));
        $materialGroup = trim((string) ($item['material_group'] ?? ''));
        $matGrpDesc = trim((string) ($item['mat_grp_desc'] ?? ''));
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
            $expected = (float) ($match['purp'] ?? 0);
        }

        $poCurr = strtoupper(trim((string) ($item['currency'] ?? 'INR')));
        if ($poCurr === 'EURO') { $poCurr = 'EUR'; }
        if ($poCurr === 'IND') { $poCurr = 'INR'; }

        $costCurr = strtoupper(trim((string) ($match['currency'] ?? $poCurr)));
        if ($costCurr === 'EURO') { $costCurr = 'EUR'; }
        if ($costCurr === 'IND') { $costCurr = 'INR'; }

        if ($expected !== null && $expected > 0 && $poCurr !== $costCurr) {
            // Build currency conversion rates to INR from cost lines
            $currencyRates = ['INR' => 1.0];
            foreach ($costLines as $cl) {
                $c = strtoupper(trim((string) ($cl['currency'] ?? '')));
                if ($c === 'EURO') { $c = 'EUR'; }
                if ($c === 'IND') { $c = 'INR'; }
                $r = (float) ($cl['convr'] ?? 0);
                if ($c !== '' && $r > 0 && !isset($currencyRates[$c])) {
                    $currencyRates[$c] = $r;
                }
            }
            if (!isset($currencyRates['EUR'])) { $currencyRates['EUR'] = 90.0; }
            if (!isset($currencyRates['USD'])) { $currencyRates['USD'] = 80.0; }
            if (!isset($currencyRates['GBP'])) { $currencyRates['GBP'] = 105.0; }

            $costConvr = (float) ($match['convr'] ?? ($currencyRates[$costCurr] ?? 1.0));
            if ($costConvr <= 0) { $costConvr = (float) ($currencyRates[$costCurr] ?? 1.0); }
            if ($costConvr <= 0) { $costConvr = 1.0; }

            $poConvr = (float) ($currencyRates[$poCurr] ?? 1.0);
            if ($poConvr <= 0) { $poConvr = 1.0; }

            // Convert expected cost into INR, then into PO item currency
            $expectedInInr = $expected * $costConvr;
            $expected = round($expectedInInr / $poConvr, 4);
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
            'material_desc'      => $description,
            'material_type'      => $materialType,
            'mat_type_desc'      => $matTypeDesc,
            'sales_order'        => $sos[0] ?? '',
            'sales_orders'       => $sos,
            'quotation'          => $itemQuotations[0] ?? '',
            'quotations'         => $itemQuotations,
            'po_qty'             => $qty,
            'order_qty'          => $qty,
            'unit'               => (string) ($item['unit'] ?? ''),
            'currency'           => (string) ($item['currency'] ?? 'INR'),
            'mat_category'       => $matCat,
            'material_group'     => $materialGroup,
            'mat_grp_desc'       => $matGrpDesc,
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
            'cost_nav'           => $this->costNavName($costSection),
            'cost_item'          => $costItem,
            'cost_description'   => $costDesc,
            'maktx'              => $costDesc,
            'cost_rate'          => $match['rate'] ?? null,
            'cost_total_inr'     => $match['total_inr'] ?? null,
            'cost_material'      => (string) ($match['material'] ?? $costItem),
            'vbeln'              => (string) ($match['sales_order'] ?? $match['quotation_no'] ?? ''),
            'posnr'              => (string) ($match['so_item'] ?? ''),
            'zline'              => (string) ($match['line_no'] ?? ''),
            'wid_siz'            => (string) ($match['wid_siz'] ?? ''),
            'net'                => $match['net'] ?? null,
            'waste'              => $match['waste'] ?? null,
            'gross'              => $match['gross'] ?? null,
            'curunit'            => (string) ($match['currency'] ?? ''),
            'rate'               => $match['rate'] ?? null,
            'total_inr'          => $match['total_inr'] ?? null,
            'total_fr'           => $match['total_fr'] ?? null,
            'convr'              => $match['convr'] ?? null,
            'zsection'           => (string) ($match['zsection'] ?? ''),
            'item_text'          => (string) ($item['item_text'] ?? ''),
            'plant'              => (string) ($item['plant'] ?? ''),
            'po_type'            => (string) ($item['po_type'] ?? ''),
            'po_type_name'       => (string) ($item['po_type_name'] ?? ''),
            'po_date'            => (string) ($item['po_date'] ?? ''),
            'supplier'           => (string) ($item['supplier'] ?? ''),
            'supplier_full_name' => (string) ($item['supplier_full_name'] ?? ''),
            'created_by'         => (string) ($item['created_by'] ?? ''),
            'sell_price'         => $match['sell_price'] ?? null,
            'making'             => $match['making'] ?? null,
            'selected'           => false,
        ];
    }

    private function costNavName(string $section): string
    {
        $map = [
            'Fabric'  => 'to_Fabric',
            'Trim'    => 'to_Trim',
            'Packing' => 'to_Packing',
            'Special' => 'to_Special',
        ];
        return $map[$section] ?? ($section !== '' ? $section : '');
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

            // Trim / Model Code Matching (e.g. T-LML2554, T-LSL3450, T-LHT3596, T-BST3537, T-LCC3558)
            $poTrimCodes = $this->extractTrimCodes($description);
            $lineTrimCodes = $this->extractTrimCodes($lineDesc . ' ' . $lineItem);
            if ($poTrimCodes !== [] && $lineTrimCodes !== [] && array_intersect($poTrimCodes, $lineTrimCodes) !== []) {
                $score += 100;
                $flags['material_code'] = true;
            }

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
        $s = str_replace(['HANG TAG', 'HANG-TAG'], 'HANGTAG', $s);
        $s = str_replace(['CARE LBL', 'CARE-LBL', 'CARELABEL', 'CARE-LABEL', 'CARE LABEL'], 'CARELBL', $s);
        $s = str_replace(['MAIN LBL', 'MAIN-LBL', 'MAINLABEL', 'MAIN-LABEL', 'MAIN LABEL'], 'MAINLBL', $s);
        $s = str_replace(['SIZE LBL', 'SIZE-LBL', 'SIZELABEL', 'SIZE-LABEL', 'SIZE LABEL'], 'SIZELBL', $s);
        $s = str_replace(['POLY BAG', 'POLY-BAG'], 'POLYBAG', $s);
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * Extract trim/article codes like T-LML2554, T-LSL3450, T-LHT3596, T-LCC3558, etc.
     *
     * @return list<string>
     */
    private function extractTrimCodes(string $text): array
    {
        preg_match_all('/[A-Z0-9]+-[A-Z0-9]+|[A-Z]{1,4}\d{3,6}/i', $text, $m);
        $out = [];
        foreach ($m[0] ?? [] as $c) {
            $u = strtoupper(trim((string) $c));
            if (strlen($u) >= 4 && !preg_match('/^\d+$/', $u)) {
                $out[] = $u;
            }
        }
        return array_values(array_unique($out));
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
        $totalOrderQty = 0.0;
        $totalExpected = 0.0;
        $totalActual = 0.0;
        $matchedZcost = 0;
        $seenValueItems = [];
        $materials = [];
        $salesOrders = [];
        $unit = '';
        $mixedUnit = false;
        foreach ($records as $r) {
            $st = (string) ($r['status'] ?? '');
            if ($st === 'PASS') {
                $passed++;
            } elseif ($st === 'NEEDS_REVIEW') {
                $needsReview++;
            } else {
                $exceptions++;
            }
            if (($r['maktx'] ?? '') !== '' || ($r['cost_item'] ?? '') !== '' || ($r['expected_unit_cost'] ?? null) !== null) {
                $matchedZcost++;
            }
            $mat = trim((string) ($r['material'] ?? ''));
            if ($mat !== '') {
                $materials[$mat] = true;
            }
            $so = trim((string) ($r['sales_order'] ?? $r['salordno'] ?? ''));
            if ($so !== '') {
                $salesOrders[$so] = true;
            }
            foreach ((array) ($r['sales_orders'] ?? []) as $extraSo) {
                $extraSo = trim((string) $extraSo);
                if ($extraSo !== '') {
                    $salesOrders[$extraSo] = true;
                }
            }
            $expected = $r['budget_cost_per_gmt'] ?? $r['expected_unit_cost'] ?? null;
            $actual = $r['actual_cost'] ?? $r['po_unit_cost'] ?? null;
            $qty = (float) ($r['tot_poqty'] ?? $r['order_qty'] ?? $r['po_qty'] ?? 0);
            $soQty = (float) ($r['so_qty'] ?? $r['prwise_so_qty'] ?? 0);
            $costQty = $soQty > 0 ? $soQty : ($qty > 0 ? $qty : 1.0);
            if ($expected !== null && $expected !== '') {
                $totalExpected += (float) $expected * $costQty;
            }
            if ($actual !== null && $actual !== '') {
                $totalActual += (float) $actual * $costQty;
            }
            // Same PO item can appear once per QuotationNo / SO — count net value / qty once per item
            $itemKey = (string) ($r['po_item'] ?? $r['poitem'] ?? '');
            if ($itemKey !== '' && !isset($seenValueItems[$itemKey])) {
                $seenValueItems[$itemKey] = true;
                $totalValue += (float) ($r['totvalue'] ?? $r['net_value'] ?? 0);
                $totalOrderQty += $qty;
                $rowUnit = trim((string) ($r['ouom'] ?? $r['unit'] ?? ''));
                if ($rowUnit !== '') {
                    if ($unit === '') {
                        $unit = $rowUnit;
                    } elseif (strcasecmp($unit, $rowUnit) !== 0) {
                        $mixedUnit = true;
                    }
                }
            } elseif ($itemKey === '') {
                $totalValue += (float) ($r['totvalue'] ?? $r['net_value'] ?? 0);
                $totalOrderQty += $qty;
            }
        }
        $primaryCurr = $records !== []
            ? (string) ($records[0]['po_currency'] ?? $records[0]['currency'] ?? 'INR')
            : 'INR';
        return [
            'po_items'          => count($seenValueItems) > 0 ? count($seenValueItems) : count($records),
            'unique_po_items'   => count($seenValueItems),
            'materials'         => count($materials),
            'sales_orders'      => count($salesOrders),
            'total_order_qty'   => round($totalOrderQty, 3),
            'unit'              => $mixedUnit ? '' : $unit,
            'passed'            => $passed,
            'exceptions'        => $exceptions,
            'needs_review'      => $needsReview,
            'pending'           => 0,
            'zcost_matched'     => $matchedZcost,
            'total_po_value'    => round($totalValue, 2),
            'total_expected'    => round($totalExpected, 2),
            'total_actual'      => round($totalActual, 2),
            'currency'          => $primaryCurr,
            'can_pass_po'       => count($records) > 0 && $exceptions === 0 && $needsReview === 0,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'po_items'        => 0,
            'unique_po_items' => 0,
            'materials'       => 0,
            'sales_orders'    => 0,
            'total_order_qty' => 0,
            'unit'            => '',
            'passed'          => 0,
            'exceptions'      => 0,
            'needs_review'    => 0,
            'pending'         => 0,
            'zcost_matched'   => 0,
            'total_po_value'  => 0,
            'total_expected'  => 0,
            'total_actual'    => 0,
            'currency'        => 'INR',
            'can_pass_po'     => false,
        ];
    }
}
?>