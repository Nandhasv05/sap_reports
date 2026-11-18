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

    /*
     * Constructor
    */  
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
     * Full validation pipeline — API only:
     * Z_QuotationZCostCom?$filter=PurchaseOrder eq '{po}'&$expand=to_Fabric,to_Trim,to_Packing,to_Special
     * No TEMPFIN / PASSPO / hub / local store fallbacks.
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

        // ONLY this API (same as Postman PO search) — never TEMPFIN / other stores
        $cds = $this->fetchQuotationZCostByPurchaseOrderOnly($poDigits, $itemOverride);

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
                'approved_status'=> $cds['approved_status'] ?? 'NotApproved',
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

        // Postman-empty: do not use TEMPFIN / hub / local store — show empty workbench
        $apiError = (string) ($cds['error'] ?? 'No records found in Z_QUOTATIONZCOSTCOM_CDS for this purchase order.');
        return [
            'success'                  => true,
            'purchase_order'           => $this->displayNumber($purchaseOrder),
            'category'                 => $category,
            'mode'                     => $mode,
            'customer_quotation'       => $customerQuote,
            'end_user_quotation'       => $endUserQuote,
            'header'                   => [
                'purchase_order'  => $this->displayNumber($purchaseOrder),
                'sales_orders'    => [],
                'approved_status' => 'NotApproved',
            ],
            'approved_status'          => 'NotApproved',
            'summary'                  => $this->emptySummary(),
            'records'                  => [],
            'quote_options'            => [],
            'validated_quotations'     => [],
            'non_validated_quotations' => [],
            'costing'                  => [
                'sales_orders' => [],
                'quotations'   => [],
                'sheets'       => [],
                'cost_lines'   => 0,
                'error'        => $apiError,
            ],
            'cost_lines'               => [],
            'flow'                     => [
                'source'     => 'Z_QUOTATIONZCOSTCOM_CDS',
                'step1_po'   => $this->displayNumber($purchaseOrder),
                'step3_lines'=> 0,
                'step3_error'=> $apiError,
            ],
            'error'                    => $apiError,
        ];
    }

    /**
     * Bulk PO list from Z_QuotationZCostCom only:
     * $filter=ApprovedStatus eq 'NotApproved' (or PurchaseOrder eq '{po}' when searching)
     * &$expand=to_Fabric,to_Trim,to_Packing,to_Special
     *
     * @param array<string,mixed> $filters
     * @return array{records:list,total:int,totals:array,range:array,error:?string}
     */
    public function listNotApprovedPurchaseOrders(array $filters = []): array
    {
        $service = (string) ($this->cfg['quotation_costing_service']
            ?? '/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom');

        $searchPo = preg_replace('/\D/', '', (string) ($filters['po'] ?? $filters['purchase_order'] ?? $filters['q'] ?? '')) ?? '';
        if ($searchPo !== '' && !preg_match('/^\d{1,10}$/', $searchPo)) {
            $searchPo = '';
        }

        $matCat = strtolower(trim((string) ($filters['mat_cat'] ?? $filters['category'] ?? 'all')));
        if (!in_array($matCat, ['all', 'fabric', 'trims'], true)) {
            $matCat = 'all';
        }
        $brands = SapPurchaseOrderService::normalizeBrands($filters['brand'] ?? $filters['brand_name'] ?? '');
        $brand = $brands[0] ?? '';

        $pageSize = (int) ($filters['page_size'] ?? $filters['limit'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        if ($pageSize < 1) {
            $pageSize = 25;
        }
        if ($pageSize > 100) {
            $pageSize = 100;
        }
        if ($page < 1) {
            $page = 1;
        }

        if ($searchPo !== '') {
            $poDisplay = $this->displayNumber($searchPo);
            $poPadded = preg_match('/^\d+$/', $poDisplay)
                ? str_pad($poDisplay, 10, '0', STR_PAD_LEFT)
                : $poDisplay;
            $filter = ($poPadded !== '' && $poPadded !== $poDisplay)
                ? "(PurchaseOrder eq '{$poDisplay}' or PurchaseOrder eq '{$poPadded}')"
                : "PurchaseOrder eq '{$poDisplay}'";
        } else {
            $filter = "ApprovedStatus eq 'NotApproved'";
        }

        $res = $this->odata->fetchResults($service, [
            '$filter' => $filter,
            '$expand' => 'to_Fabric,to_Trim,to_Packing,to_Special',
        ], 5000);

        $emptyTotals = [
            'pos_count'       => 0,
            'items_count'     => 0,
            'total_qty'       => 0,
            'total_value'     => 0,
            'currency'        => 'INR',
            'suppliers_count' => 0,
        ];

        if (!empty($res['error']) && empty($res['rows'])) {
            return [
                'records'       => [],
                'total'         => 0,
                'totals'        => $emptyTotals,
                'range'         => ['preset' => 'all', 'from' => '', 'to' => ''],
                'truncated'     => false,
                'items_fetched' => 0,
                'error'         => $res['error'],
                'source'        => 'Z_QUOTATIONZCOSTCOM_CDS',
            ];
        }

        /** @var array<string, array<string,mixed>> $pos */
        $pos = [];
        foreach ($res['rows'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $po = $this->displayNumber((string) ($row['PurchaseOrder'] ?? ''));
            if ($po === '') {
                continue;
            }
            $key = ltrim(preg_replace('/\D/', '', $po) ?? '', '0');
            if ($key === '') {
                $key = $po;
            }

            $matType = trim((string) ($row['MaterialType'] ?? ''));
            $matTypeDesc = trim((string) ($row['MatTypeDesc'] ?? ''));
            $matGroup = trim((string) ($row['MaterialGroup'] ?? ''));
            $cat = SapPurchaseOrderService::classifyMaterial($matType, $matTypeDesc, $matGroup);
            if ($matCat === 'fabric' && $cat !== 'fabric') {
                continue;
            }
            if ($matCat === 'trims' && $cat !== 'trims') {
                continue;
            }

            $brandName = trim((string) ($row['BrandName'] ?? $row['brand_name'] ?? ''));
            if ($brands !== []) {
                $matched = false;
                foreach ($brands as $bCode) {
                    if (strcasecmp($brandName, $bCode) === 0 || stripos($brandName, $bCode) !== false) {
                        $matched = true;
                        break;
                    }
                    $label = SapPurchaseOrderService::brandLabel($bCode);
                    if ($label !== '' && (
                        strcasecmp($brandName, $label) === 0
                        || stripos($brandName, $label) !== false
                    )) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }

            $qty = $this->sapNumber($row['OrderQuantity'] ?? 0);
            $net = $this->sapNumber($row['NetPriceAmount'] ?? 0);
            $itemNo = $this->displayNumber((string) ($row['PurchaseOrderItem'] ?? ''));
            $material = trim((string) ($row['Material'] ?? ''));
            $currency = trim((string) ($row['DocumentCurrency'] ?? 'INR')) ?: 'INR';
            $supplier = $this->displayNumber((string) ($row['Supplier'] ?? ''));
            $supplierName = trim((string) ($row['SupplierFullName'] ?? ''));
            $poDate = $this->sapDate($row['PurchaseOrderDate'] ?? null);
            $approved = $this->normalizeApprovedStatus($row['ApprovedStatus'] ?? null);

            if (!isset($pos[$key])) {
                $pos[$key] = [
                    'purchase_order'     => $po,
                    'po_date'            => $poDate,
                    'supplier'           => $supplier,
                    'supplier_full_name' => $supplierName,
                    'brand_name'         => $brandName !== '' ? $brandName : '—',
                    'brand_code'         => $brandName,
                    'currency'           => $currency,
                    'approved_status'    => $approved,
                    'items_count'        => 0,
                    'total_qty'          => 0.0,
                    'total_value'        => 0.0,
                    'materials'          => [],
                    'material_types'     => [],
                    'mat_categories'     => [],
                    'plants'             => [],
                    '_items'             => [],
                ];
            }

            if ($itemNo !== '' && !isset($pos[$key]['_items'][$itemNo])) {
                $pos[$key]['_items'][$itemNo] = true;
                $pos[$key]['items_count']++;
                $pos[$key]['total_qty'] += $qty;
                $pos[$key]['total_value'] += $net;
            }
            if ($material !== '') {
                $pos[$key]['materials'][$material] = true;
            }
            if ($matType !== '') {
                $pos[$key]['material_types'][$matType] = true;
            }
            if ($cat !== '') {
                $pos[$key]['mat_categories'][$cat] = true;
            }
            $plant = trim((string) ($row['Plant'] ?? ''));
            if ($plant !== '') {
                $pos[$key]['plants'][$plant] = true;
            }
            if ($approved === 'Approved') {
                $pos[$key]['approved_status'] = 'Approved';
            }
            if ($brandName !== '' && ($pos[$key]['brand_name'] === '—' || $pos[$key]['brand_name'] === '')) {
                $pos[$key]['brand_name'] = $brandName;
            }
        }

        $records = [];
        foreach ($pos as $r) {
            $cats = array_keys($r['mat_categories']);
            $matCategory = count($cats) > 1 ? 'mixed' : ($cats[0] ?? 'other');
            $records[] = [
                'purchase_order'     => $r['purchase_order'],
                'po_date'            => $r['po_date'],
                'supplier'           => $r['supplier'],
                'supplier_full_name' => $r['supplier_full_name'],
                'brand_name'         => $r['brand_name'],
                'brand_code'         => $r['brand_code'],
                'currency'           => $r['currency'],
                'approved_status'    => $r['approved_status'],
                'items_count'        => $r['items_count'],
                'total_qty'          => round($r['total_qty'], 3),
                'total_value'        => round($r['total_value'], 2),
                'materials_count'    => count($r['materials']),
                'material'           => implode(', ', array_keys($r['materials'])),
                'material_type'      => implode(', ', array_keys($r['material_types'])),
                'mat_category'       => $matCategory,
                'plant'              => implode(', ', array_keys($r['plants'])),
                'status_bucket'      => 'not_started',
                'validation_status'  => 'MAPPING_REQUIRED',
            ];
        }

        // PO Validation page: only POs marked Released in dbo.po_release_log
        if ($records !== [] && is_file(base_path('app/services/PoReleaseLogService.php'))) {
            require_once base_path('app/services/PoReleaseLogService.php');
            $poNums = [];
            foreach ($records as $rec) {
                $poNums[] = (string) ($rec['purchase_order'] ?? '');
            }
            $latest = (new PoReleaseLogService())->latestStatusesForPos($poNums);
            $released = [];
            foreach ($records as $rec) {
                $poKey = ltrim(preg_replace('/\D/', '', (string) ($rec['purchase_order'] ?? '')) ?? '', '0');
                $info = $latest[$poKey] ?? null;
                $st = PoReleaseLogService::normalizeStatus(
                    is_array($info) ? (string) ($info['status'] ?? '') : ''
                );
                if ($st === PoReleaseLogService::STATUS_RELEASED) {
                    $released[] = $rec;
                }
            }
            $records = $released;
        } else {
            $records = [];
        }

        usort($records, static function (array $a, array $b): int {
            return strcmp((string) ($b['po_date'] ?? ''), (string) ($a['po_date'] ?? ''))
                ?: strcmp((string) ($b['purchase_order'] ?? ''), (string) ($a['purchase_order'] ?? ''));
        });

        $total = count($records);
        $offset = ($page - 1) * $pageSize;
        $pageRows = array_slice($records, $offset, $pageSize);

        $totalQty = 0.0;
        $totalVal = 0.0;
        $totalItems = 0;
        $suppliers = [];
        $currencies = [];
        foreach ($records as $r) {
            $totalQty += (float) ($r['total_qty'] ?? 0);
            $totalVal += (float) ($r['total_value'] ?? 0);
            $totalItems += (int) ($r['items_count'] ?? 0);
            $sup = trim((string) ($r['supplier'] ?? ''));
            if ($sup !== '') {
                $suppliers[$sup] = true;
            }
            $cur = trim((string) ($r['currency'] ?? ''));
            if ($cur !== '') {
                $currencies[$cur] = ($currencies[$cur] ?? 0) + 1;
            }
        }
        arsort($currencies);
        $primaryCurrency = key($currencies) ?: 'INR';

        return [
            'records'       => $pageRows,
            'total'         => $total,
            'page'          => $page,
            'page_size'     => $pageSize,
            'page_count'    => max(1, (int) ceil($total / $pageSize)),
            'totals'        => [
                'pos_count'       => $total,
                'items_count'     => $totalItems,
                'total_qty'       => round($totalQty, 3),
                'total_value'     => round($totalVal, 2),
                'currency'        => $primaryCurrency,
                'suppliers_count' => count($suppliers),
            ],
            'range'         => ['preset' => 'all', 'from' => '', 'to' => ''],
            'truncated'     => false,
            'items_fetched' => (int) ($res['row_count'] ?? 0),
            'error'         => null,
            'source'        => 'Z_QUOTATIONZCOSTCOM_CDS',
            'approved_status' => $searchPo !== '' ? '' : 'NotApproved',
        ];
    }

    /**
     * Related PO list columns (matches Purchase Order list screenshot).
     *
     * @return list<array{key:string,label:string,type:string}>
     */
    public static function relatedPoColumns(): array
    {
        return [
            ['key' => 'sno', 'label' => 'S.No', 'type' => 'num'],
            ['key' => 'purchase_order', 'label' => 'PO Number', 'type' => 'text'],
            ['key' => 'purchase_order_type', 'label' => 'PO Type', 'type' => 'text'],
            ['key' => 'po_type_name', 'label' => 'PO Type Name', 'type' => 'text'],
            ['key' => 'date', 'label' => 'PO Date', 'type' => 'date'],
            ['key' => 'plant', 'label' => 'Plant', 'type' => 'text'],
            ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'text'],
            ['key' => 'supplier_full_name', 'label' => 'Supplier Name', 'type' => 'text'],
            ['key' => 'brand_name', 'label' => 'Brand Name', 'type' => 'text'],
            ['key' => 'material_type', 'label' => 'Material', 'type' => 'text'],
            ['key' => 'mat_type_desc', 'label' => 'MatTypeDesc', 'type' => 'text'],
            ['key' => 'material_group', 'label' => 'MaterialGroup', 'type' => 'text'],
            ['key' => 'items_count', 'label' => 'Items', 'type' => 'num'],
            ['key' => 'total_qty', 'label' => 'Order Qty', 'type' => 'num'],
            ['key' => 'unit', 'label' => 'Unit', 'type' => 'text'],
            ['key' => 'total_value', 'label' => 'Net Value', 'type' => 'num'],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'status_update', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'status_remarks', 'label' => 'Remarks', 'type' => 'text'],
            ['key' => 'sent', 'label' => 'Sent', 'type' => 'text'],
            ['key' => 'sent_receive_log', 'label' => 'Sent / Receive Log', 'type' => 'text'],
            ['key' => 'esign', 'label' => 'E-Sign', 'type' => 'text'],
            ['key' => 'pdf', 'label' => 'View PDF', 'type' => 'text'],
        ];
    }

    /**
     * Related PO sidebar — Z_QuotationZCostCom NotApproved with expand navigations.
     * Aggregates to one row per purchase order for the professional list table.
     *
     * @param array<string,mixed> $filters
     * @return array{records:list,total:int,totals:array,columns:list,error:?string,source:string}
     */
    public function listRelatedPurchaseOrders(array $filters = []): array
    {
        $service = (string) ($this->cfg['quotation_costing_service']
            ?? '/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom');

        $searchPo = preg_replace('/\D/', '', (string) ($filters['po'] ?? $filters['q'] ?? '')) ?? '';
        if ($searchPo !== '' && !preg_match('/^\d{1,10}$/', $searchPo)) {
            $searchPo = '';
        }

        $matCat = strtolower(trim((string) ($filters['mat_cat'] ?? $filters['category'] ?? 'all')));
        if (!in_array($matCat, ['all', 'fabric', 'trims'], true)) {
            $matCat = 'all';
        }
        $brands = SapPurchaseOrderService::normalizeBrands($filters['brand'] ?? $filters['brand_name'] ?? '');

        if ($searchPo !== '') {
            $poDisplay = $this->displayNumber($searchPo);
            $poPadded = preg_match('/^\d+$/', $poDisplay)
                ? str_pad($poDisplay, 10, '0', STR_PAD_LEFT)
                : $poDisplay;
            $filter = ($poPadded !== '' && $poPadded !== $poDisplay)
                ? "(PurchaseOrder eq '{$poDisplay}' or PurchaseOrder eq '{$poPadded}')"
                : "PurchaseOrder eq '{$poDisplay}'";
        } else {
            $filter = "ApprovedStatus eq 'NotApproved'";
        }

        $res = $this->odata->fetchResults($service, [
            '$filter' => $filter,
            '$expand' => 'to_Fabric,to_Trim,to_Packing,to_Special',
        ], 8000);

        $emptyTotals = [
            'pos_count'       => 0,
            'items_count'     => 0,
            'total_qty'       => 0,
            'total_value'     => 0,
            'currency'        => 'INR',
            'suppliers_count' => 0,
        ];

        if (!empty($res['error']) && empty($res['rows'])) {
            return [
                'records'       => [],
                'total'         => 0,
                'totals'        => $emptyTotals,
                'columns'       => self::relatedPoColumns(),
                'truncated'     => false,
                'items_fetched' => 0,
                'error'         => $res['error'],
                'source'        => 'Z_QUOTATIONZCOSTCOM_CDS',
                'odata_filter'  => $filter,
                'mat_cat'       => $matCat,
                'brand'         => implode(',', $brands),
            ];
        }

        $docTypeMap = SapPurchaseOrderService::documentTypeMap();

        /** @var array<string, array<string,mixed>> $pos */
        $pos = [];
        foreach ($res['rows'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $po = $this->displayNumber((string) ($row['PurchaseOrder'] ?? ''));
            if ($po === '') {
                continue;
            }
            $key = ltrim(preg_replace('/\D/', '', $po) ?? '', '0');
            if ($key === '') {
                $key = $po;
            }

            $matType = trim((string) ($row['MaterialType'] ?? ''));
            $matTypeDesc = trim((string) ($row['MatTypeDesc'] ?? ''));
            $matGroup = trim((string) ($row['MaterialGroup'] ?? ''));
            $cat = SapPurchaseOrderService::classifyMaterial($matType, $matTypeDesc, $matGroup);
            if ($matCat === 'fabric' && $cat !== 'fabric') {
                continue;
            }
            if ($matCat === 'trims' && $cat !== 'trims') {
                continue;
            }

            $brandName = trim((string) ($row['BrandName'] ?? $row['brand_name'] ?? ''));
            if ($brands !== []) {
                $matched = false;
                foreach ($brands as $bCode) {
                    if (strcasecmp($brandName, $bCode) === 0 || stripos($brandName, $bCode) !== false) {
                        $matched = true;
                        break;
                    }
                    $label = SapPurchaseOrderService::brandLabel($bCode);
                    if ($label !== '' && (
                        strcasecmp($brandName, $label) === 0
                        || stripos($brandName, $label) !== false
                    )) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }

            $qty = $this->sapNumber($row['OrderQuantity'] ?? 0);
            $net = $this->sapNumber($row['NetPriceAmount'] ?? 0);
            $itemNo = $this->displayNumber((string) ($row['PurchaseOrderItem'] ?? ''));
            $plant = trim((string) ($row['Plant'] ?? ''));
            $unit = trim((string) ($row['PurchaseOrderQuantityUnit'] ?? ''));
            $poType = trim((string) ($row['PurchaseOrderType'] ?? ''));
            $typeName = trim((string) ($row['PurchasingDocumentTypeName'] ?? ''));
            if ($typeName === '' && $poType !== '' && isset($docTypeMap[$poType])) {
                $typeName = $docTypeMap[$poType];
            }

            if (!isset($pos[$key])) {
                $pos[$key] = [
                    'purchase_order'          => $po,
                    'purchase_order_type'     => $poType,
                    'po_type_name'            => $typeName,
                    'date'                    => $this->sapDate($row['PurchaseOrderDate'] ?? null),
                    'supplier'                => $this->displayNumber((string) ($row['Supplier'] ?? '')),
                    'supplier_full_name'      => trim((string) ($row['SupplierFullName'] ?? '')),
                    'brand_name'              => $brandName,
                    'currency'                => trim((string) ($row['DocumentCurrency'] ?? 'INR')) ?: 'INR',
                    'unit'                    => $unit,
                    'items_count'             => 0,
                    'total_qty'               => 0.0,
                    'total_value'             => 0.0,
                    'plants'                  => [],
                    'mat_categories'          => [],
                    'mat_type_descs'          => [],
                    'material_groups'         => [],
                    'brand_names'             => [],
                    '_items'                  => [],
                    'fabric_lines'            => 0,
                    'trim_lines'              => 0,
                    'packing_lines'           => 0,
                    'special_lines'           => 0,
                ];
            }

            $p = &$pos[$key];
            if ($itemNo !== '' && !isset($p['_items'][$itemNo])) {
                $p['_items'][$itemNo] = true;
                $p['items_count']++;
                $p['total_qty'] += $qty;
                $p['total_value'] += $net;
            } elseif ($itemNo === '') {
                $p['items_count']++;
                $p['total_qty'] += $qty;
                $p['total_value'] += $net;
            }

            if ($plant !== '' && !in_array($plant, $p['plants'], true)) {
                $p['plants'][] = $plant;
            }
            if (in_array($cat, ['fabric', 'trims'], true) && !in_array($cat, $p['mat_categories'], true)) {
                $p['mat_categories'][] = $cat;
            }
            if ($matTypeDesc !== '' && !in_array($matTypeDesc, $p['mat_type_descs'], true)) {
                $p['mat_type_descs'][] = $matTypeDesc;
            }
            if ($matGroup !== '' && !in_array($matGroup, $p['material_groups'], true)) {
                $p['material_groups'][] = $matGroup;
            }
            if ($brandName !== '' && !in_array($brandName, $p['brand_names'], true)) {
                $p['brand_names'][] = $brandName;
            }
            if ($p['unit'] === '' && $unit !== '') {
                $p['unit'] = $unit;
            }
            if ($p['purchase_order_type'] === '' && $poType !== '') {
                $p['purchase_order_type'] = $poType;
            }
            if ($p['po_type_name'] === '' && $typeName !== '') {
                $p['po_type_name'] = $typeName;
            }
            if ($p['brand_name'] === '' && $brandName !== '') {
                $p['brand_name'] = $brandName;
            }

            foreach (['to_Fabric' => 'fabric_lines', 'to_Trim' => 'trim_lines', 'to_Packing' => 'packing_lines', 'to_Special' => 'special_lines'] as $nav => $field) {
                $navData = $row[$nav] ?? null;
                $results = is_array($navData) ? ($navData['results'] ?? []) : [];
                if (is_array($results) && $results !== []) {
                    $p[$field] += count($results);
                }
            }
            unset($p);
        }

        $records = [];
        foreach ($pos as $r) {
            unset($r['_items']);
            $r['plant'] = implode(', ', $r['plants']);
            $cats = $r['mat_categories'];
            // Prefer expand counts when classifyMaterial is inconclusive
            if ($cats === []) {
                if ((int) ($r['fabric_lines'] ?? 0) > 0) {
                    $cats[] = 'fabric';
                }
                if ((int) ($r['trim_lines'] ?? 0) > 0 || (int) ($r['packing_lines'] ?? 0) > 0 || (int) ($r['special_lines'] ?? 0) > 0) {
                    $cats[] = 'trims';
                }
            }
            $labels = [];
            if (in_array('fabric', $cats, true)) {
                $labels[] = 'Fabric';
            }
            if (in_array('trims', $cats, true)) {
                $labels[] = 'Trims';
            }
            $r['material_type'] = $labels !== [] ? implode(', ', $labels) : '—';
            $r['mat_category'] = count($labels) > 1 ? 'mixed' : strtolower($labels[0] ?? 'other');
            $r['mat_type_desc'] = implode(', ', $r['mat_type_descs']);
            $r['material_group'] = implode(', ', $r['material_groups']);
            if ($r['brand_name'] === '' && $r['brand_names'] !== []) {
                $r['brand_name'] = implode(', ', $r['brand_names']);
            }
            $r['total_qty'] = round((float) $r['total_qty'], 3);
            $r['total_value'] = round((float) $r['total_value'], 2);
            unset($r['plants'], $r['mat_categories'], $r['mat_type_descs'], $r['material_groups'], $r['brand_names']);
            $records[] = $r;
        }

        usort($records, static function (array $a, array $b): int {
            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''))
                ?: strcmp((string) ($b['purchase_order'] ?? ''), (string) ($a['purchase_order'] ?? ''));
        });

        $totalQty = 0.0;
        $totalVal = 0.0;
        $totalItems = 0;
        $suppliers = [];
        $currencies = [];
        foreach ($records as $r) {
            $totalQty += (float) ($r['total_qty'] ?? 0);
            $totalVal += (float) ($r['total_value'] ?? 0);
            $totalItems += (int) ($r['items_count'] ?? 0);
            $sup = trim((string) ($r['supplier'] ?? ''));
            if ($sup !== '') {
                $suppliers[$sup] = true;
            }
            $cur = trim((string) ($r['currency'] ?? ''));
            if ($cur !== '') {
                $currencies[$cur] = ($currencies[$cur] ?? 0) + 1;
            }
        }
        arsort($currencies);
        $primaryCurrency = key($currencies) ?: 'INR';

        return [
            'records'       => $records,
            'total'         => count($records),
            'totals'        => [
                'pos_count'       => count($records),
                'items_count'     => $totalItems,
                'total_qty'       => round($totalQty, 3),
                'total_value'     => round($totalVal, 2),
                'currency'        => $primaryCurrency,
                'suppliers_count' => count($suppliers),
            ],
            'columns'       => self::relatedPoColumns(),
            'truncated'     => (int) ($res['row_count'] ?? 0) >= 8000,
            'items_fetched' => (int) ($res['row_count'] ?? 0),
            'error'         => null,
            'source'        => 'Z_QUOTATIONZCOSTCOM_CDS',
            'odata_filter'  => $filter,
            'approved_status' => $searchPo !== '' ? '' : 'NotApproved',
            'mat_cat'       => $matCat,
            'brand'         => implode(',', $brands),
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
                'approved_status'           => 'NotApproved',
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

        // Fallback: If PO Candidate filter returned 0 rows, but quotation or SO is specified, query directly by QuotationNo / SalesOrderReference
        if ($rows === [] && (($quotation !== null && trim($quotation) !== '') || ($so !== null && trim($so) !== ''))) {
            $directFilterParts = [];
            if ($quotation !== null && trim($quotation) !== '') {
                $qClean = str_replace("'", "''", trim($quotation));
                $directFilterParts[] = "QuotationNo eq '{$qClean}'";
            }
            if ($so !== null && trim($so) !== '') {
                $soClean = str_replace("'", "''", trim($so));
                $directFilterParts[] = "SalesOrderReference eq '{$soClean}'";
            }
            if ($item !== null && trim($item) !== '') {
                $itemClean = preg_replace('/\D/', '', $item) ?? '';
                if ($itemClean !== '') {
                    $itemPadded = str_pad(ltrim($itemClean, '0'), 5, '0', STR_PAD_LEFT);
                    $directFilterParts[] = "PurchaseOrderItem eq '{$itemPadded}'";
                }
            }
            $query = [
                '$filter' => implode(' and ', $directFilterParts),
                '$expand' => 'to_Fabric,to_Trim,to_Packing,to_Special',
            ];
            $res = $this->odata->fetchResults($service, $query, 1000);
            if (!empty($res['rows'])) {
                $rows = $res['rows'];
                $lastError = null;
            } elseif (!empty($res['error'])) {
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
                'approved_status'           => 'NotApproved',
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
        $approvedStatus = 'NotApproved';
        /** @var array<string, array{quotation:string,costing_quotation:string,is_validated:bool,sales_order:string,fabric_lines:int,trim_lines:int,packing_lines:int,special_lines:int}> */
        $quoteMeta = [];

        foreach ($rows as $row) {
            if ($this->normalizeApprovedStatus($row['ApprovedStatus'] ?? $row['APPROVEDSTATUS'] ?? null) === 'Approved') {
                $approvedStatus = 'Approved';
            }
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
                    'approved_status'    => $approvedStatus,
                ];
            }

            $itemRaw = (string) ($row['PurchaseOrderItem'] ?? '');
            $itemNo = $this->displayNumber($itemRaw);
            $quoteNo = $this->displayNumber((string) ($row['QuotationNo'] ?? ''));
            $costingQuoteNo = $this->displayNumber((string) ($row['CostingQuotationNo'] ?? ''));
            $costingItemNo = trim((string) ($row['CostingItemNo'] ?? $row['CostingItem'] ?? ''));
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

                $soQty = $this->sapNumber($row['SaleOrderQuantity'] ?? $row['SO_QTY'] ?? 0);
                $reqQty = $this->sapNumber($row['ReqBOMQuantity'] ?? $row['REQQTY'] ?? 0);
                $style = trim((string) ($row['style'] ?? $row['Style'] ?? ''));

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
                    'costing_quotation_no' => $costingQuoteNo !== '' ? $costingQuoteNo : $quoteNo,
                    'costing_item_no'    => $costingItemNo,
                    'is_validated_quote' => $isValidatedRow,
                    'so_qty'             => $soQty,
                    'prwise_so_qty'      => $soQty,
                    'reqqty'             => $reqQty,
                    'style'              => $style,
                ];
            } else {
                $soQty = $this->sapNumber($row['SaleOrderQuantity'] ?? $row['SO_QTY'] ?? 0);
                $reqQty = $this->sapNumber($row['ReqBOMQuantity'] ?? $row['REQQTY'] ?? 0);
                $style = trim((string) ($row['style'] ?? $row['Style'] ?? ''));
                if (($itemsMap[$rowKey]['so_qty'] ?? 0) <= 0 && $soQty > 0) {
                    $itemsMap[$rowKey]['so_qty'] = $soQty;
                    $itemsMap[$rowKey]['prwise_so_qty'] = $soQty;
                }
                if (($itemsMap[$rowKey]['reqqty'] ?? 0) <= 0 && $reqQty > 0) {
                    $itemsMap[$rowKey]['reqqty'] = $reqQty;
                }
                if (($itemsMap[$rowKey]['style'] ?? '') === '' && $style !== '') {
                    $itemsMap[$rowKey]['style'] = $style;
                }
                if ($soRef !== '' && !in_array($soRef, $itemsMap[$rowKey]['sales_orders'], true)) {
                    $itemsMap[$rowKey]['sales_orders'][] = $soRef;
                }
                if ($quoteNo !== '' && !in_array($quoteNo, $itemsMap[$rowKey]['quotations'], true)) {
                    $itemsMap[$rowKey]['quotations'][] = $quoteNo;
                }
                if ($isValidatedRow) {
                    $itemsMap[$rowKey]['is_validated_quote'] = true;
                    $itemsMap[$rowKey]['costing_quotation'] = $costingQuoteNo;
                    $itemsMap[$rowKey]['costing_quotation_no'] = $costingQuoteNo;
                }
                if ($costingItemNo !== '' && ($itemsMap[$rowKey]['costing_item_no'] ?? '') === '') {
                    $itemsMap[$rowKey]['costing_item_no'] = $costingItemNo;
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
                    $gross = $this->sapNumber($line['GROSS'] ?? 0);
                    $totalInr = $this->sapNumber($line['TOTALINR'] ?? 0);
                    // Budget per GMT = Total in INR ÷ Gross (classic zCOST sheet)
                    $budgetPerGmt = null;
                    if ($totalInr !== null && $totalInr > 0 && $gross !== null && $gross > 0) {
                        $budgetPerGmt = round($totalInr / $gross, 4);
                    } elseif ($rate !== null && $rate > 0) {
                        $budgetPerGmt = $rate;
                    } elseif ($purp !== null && $purp > 0) {
                        $budgetPerGmt = $purp;
                    }
                    $curunit = trim((string) ($line['CURUNIT'] ?? ''));
                    $curUpper = strtoupper($curunit);
                    if ($curUpper === 'EURO') {
                        $curunit = 'EUR';
                    } elseif ($curUpper === 'IND') {
                        $curunit = 'INR';
                    }

                    // ITEM is the only value shown in "zCOST Item". Do not invent POSNR-ZLINE when ITEM is blank.
                    $costLines[] = [
                        'section'             => $section,
                        'zsection'            => trim((string) ($line['ZSECTION'] ?? '')),
                        'sales_order'         => $vbeln !== '' ? $vbeln : $soRef,
                        'quotation_no'        => $lineQuote,
                        'parent_quotation_no' => $parentQuote,
                        'costing_quotation_no'=> $costingQuoteNo !== '' ? $costingQuoteNo : $lineQuote,
                        'costing_item_no'     => $itemCode,
                        'zcost_item'          => $itemCode,
                        'is_validated'        => $isValidatedRow,
                        'so_item'             => $posnr,
                        'line_no'             => $zline,
                        'item'                => $itemCode,
                        'description'         => $maktx,
                        'maktx'               => $maktx,
                        'material'            => $itemCode !== '' ? $itemCode : $maktx,
                        'wid_siz'             => trim((string) ($line['WID_SIZ'] ?? '')),
                        'net'                 => $this->sapNumber($line['NET'] ?? 0),
                        'waste'               => $this->sapNumber($line['WSTE'] ?? 0),
                        'gross'               => $gross,
                        'order_qty'           => $gross,
                        'currency'            => $curunit,
                        'purp'                => $purp,
                        'convr'               => $convr > 0 ? $convr : 1.0,
                        'freight'             => $this->sapNumber($line['FRIGHT'] ?? 0),
                        'duty'                => $this->sapNumber($line['DUTY'] ?? 0),
                        'fin_cost'            => $this->sapNumber($line['FINCOST'] ?? 0),
                        'rate'                => $budgetPerGmt ?? $rate,
                        'sheet_rate'          => $rate,
                        'net_price'           => $budgetPerGmt ?? $rate,
                        'budget_per_gmt'      => $budgetPerGmt,
                        'total_inr'           => $totalInr,
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
                        'unit'                => trim((string) ($line['MEINS'] ?? $line['UOM'] ?? $line['UNIT'] ?? '')),
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
        $header['approved_status'] = $approvedStatus;

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
            'approved_status'          => $approvedStatus,
            'error'                    => null,
        ];
    }

    /**
     * Normalize SAP ApprovedStatus / APPROVEDSTATUS → Approved | NotApproved.
     */
    private function normalizeApprovedStatus(mixed $raw): string
    {
        $v = strtolower(preg_replace('/\s+/', '', (string) $raw) ?? '');
        if ($v === 'approved' || $v === 'x' || $v === 'true' || $v === '1') {
            return 'Approved';
        }
        return 'NotApproved';
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
        // When unit price is 0 but line net value exists, derive PORATE = net ÷ qty
        if ($poUnit <= 0) {
            $netVal = (float) ($item['net_value'] ?? $item['totvalue'] ?? 0);
            if ($netVal > 0 && $qty > 0) {
                $poUnit = round($netVal / $qty, 4);
            }
        }
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

        // Prefer Total in INR ÷ Gross (same as zCOST sheet budget-per-GMT)
        $expected = null;
        if ($match) {
            $expected = isset($match['budget_per_gmt']) ? (float) $match['budget_per_gmt'] : null;
            if ($expected === null || $expected <= 0) {
                $ti = (float) ($match['total_inr'] ?? 0);
                $gr = (float) ($match['gross'] ?? 0);
                if ($ti > 0 && $gr > 0) {
                    $expected = round($ti / $gr, 4);
                }
            }
            if ($expected === null || $expected <= 0) {
                $expected = (float) ($match['rate'] ?? 0);
            }
            if ($expected === null || $expected <= 0) {
                $expected = (float) ($match['purp'] ?? 0);
            }
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

        // zCOST Item column must mirror API ITEM only (empty ITEM => empty UI)
        $costItem = trim((string) ($match['zcost_item'] ?? ''));
        if ($costItem === '') {
            $costItem = trim((string) ($match['item'] ?? ''));
        }
        // Reject synthetic POSNR-ZLINE placeholders previously used when ITEM was blank
        if ($costItem !== '' && preg_match('/^\d+-\d+$/', $costItem)) {
            $soItem = trim((string) ($match['so_item'] ?? ($match['posnr'] ?? '')));
            $lineNo = trim((string) ($match['line_no'] ?? ($match['zline'] ?? '')));
            if ($soItem !== '' && $lineNo !== '' && $costItem === "{$soItem}-{$lineNo}") {
                $costItem = '';
            }
        }
        $costDesc = (string) ($match['description'] ?? ($match['maktx'] ?? ''));
        $costSection = (string) ($match['section'] ?? '');

        $soQty = (float) ($item['so_qty'] ?? $item['SaleOrderQuantity'] ?? 0);
        $prSoQty = (float) ($item['prwise_so_qty'] ?? 0);
        if ($soQty <= 0 && $prSoQty > 0) {
            $soQty = $prSoQty;
        }
        $reqQty = (float) ($item['reqqty'] ?? $item['ReqBOMQuantity'] ?? 0);
        $style = (string) ($item['style'] ?? '');

        $gross = isset($match['gross']) && $match['gross'] !== null ? (float) $match['gross'] : null;
        $net = isset($match['net']) && $match['net'] !== null ? (float) $match['net'] : null;
        $convr = isset($match['convr']) && $match['convr'] !== null ? (float) $match['convr'] : 1.0;
        if ($convr <= 0) {
            $convr = 1.0;
        }

        // CONSUMPTION = PO/TEMPFIN per-garment only. Never fall back to zCOST GROSS/NET
        // (that inflated ACTUAL_COST, e.g. PORATE×FX×Gross → 21.924).
        $poConsumption = (float) ($item['consumption'] ?? 0);
        $itemBudgetedCons = (float) ($item['budgeted_cons'] ?? 0);
        $budgetedCons = $net !== null && $net > 0 ? $net : ($gross !== null && $gross > 0 ? $gross : null);
        if ($itemBudgetedCons > 0) {
            $budgetedCons = $itemBudgetedCons;
        }
        // Prefer TEMPFIN budgeted_cons only when it is clearly PO cons (not zCOST Gross dump)
        if ($poConsumption <= 0 && $itemBudgetedCons > 0) {
            $looksLikeGross = $gross !== null && $gross > 1.05
                && abs($itemBudgetedCons - $gross) < 0.0001;
            if (!$looksLikeGross) {
                $poConsumption = $itemBudgetedCons;
            }
        }
        $consumption = $poConsumption > 0 ? $poConsumption : 1.0;

        $totPoQty = $qty;
        $poRate = $poUnit;
        $totValue = (float) ($item['net_value'] ?? ($totPoQty * $poRate));

        if ($reqQty <= 0 && $soQty > 0 && $consumption !== null) {
            $reqQty = round($soQty * $consumption, 4);
        }
        if ($soQty <= 0 && $reqQty > 0 && $consumption !== null && $consumption > 0) {
            $soQty = round($reqQty / $consumption);
        }

        $soWisePoQty = $totPoQty;
        $soWisePoVal = round($poRate * $soWisePoQty * $convr, 2);

        $budgetCostPerGmt = $expected;
        $budgetInr = (float) ($match['total_inr'] ?? ($match['rate'] ?? $expected));
        $budgetCurrency = (string) ($match['currency'] ?? ($item['currency'] ?? 'INR'));
        $poCurrency = (string) ($item['currency'] ?? 'INR');
        $ouom = strtoupper(trim((string) ($item['ouom'] ?? $item['unit'] ?? '')));
        $matchUom = trim((string) ($match['unit'] ?? $match['uom'] ?? ''));
        if (in_array(strtoupper($matchUom), ['INR', 'USD', 'EUR', 'GBP', 'AUD', ''], true)) {
            $matchUom = '';
        }
        $buomRaw = strtoupper(trim((string) ($item['buom'] ?? '')));
        $buom = $matchUom !== '' ? strtoupper($matchUom) : ($buomRaw !== '' ? $buomRaw : ($ouom !== '' ? $ouom : 'EA'));
        $consForActual = ($consumption !== null && $consumption > 0) ? $consumption : 1.0;
        // If PO cons was missing and somehow equals zCOST GROSS, do not inflate ACTUAL
        if ($gross !== null && $gross > 1.05 && abs($consForActual - $gross) < 0.0001
            && $poConsumption <= 0) {
            $consForActual = 1.0;
        }
        $matDescUpper = strtoupper(trim((string) ($description ?? '')));
        $matGroupUpper = strtoupper(trim($materialGroup));
        $poCurrUpper = strtoupper(trim($poCurrency) ?: 'INR');
        // Classic: BUDGET_CONV_RT = FX (Curr Rate); CONV = unit factor (never copy FX into CONV)
        $budgetConvRt = (float) ($item['budget_conv_rt'] ?? $item['BUDGET_CONV_RT'] ?? 0);
        if ($budgetConvRt <= 0) {
            $budgetConvRt = $convr > 0 ? $convr : 1.0;
        }
        $unitConv = (float) ($item['conv'] ?? $item['CONV'] ?? 1.0);
        if ($unitConv <= 0) {
            $unitConv = 1.0;
        }
        // Guard: CONV must not mirror FX rate (double-converts / inflates INR actual)
        if ($poCurrUpper !== 'INR' && abs($unitConv - $budgetConvRt) < 0.0001) {
            $unitConv = 1.0;
        } elseif ($poCurrUpper === 'INR' && $unitConv > 20) {
            $unitConv = 1.0;
        }
        // TEMPFIN/list PORATE is already INR. Only × Curr Rate when explicitly flagged.
        $needsFx = !empty($item['porate_needs_fx']);
        $baseRate = ($needsFx && $poCurrUpper !== 'INR') ? ($poRate * $budgetConvRt) : $poRate;
        $midActual = $baseRate * $unitConv * $consForActual;
        // Classic calc-columns: GRO÷144 only for button-like groups (not every GRO UOM)
        $isButtonGro = ($ouom === 'GRO' || $buom === 'GRO') && (
            str_contains($matGroupUpper, 'BUTTON')
            || str_contains($matGroupUpper, 'SEMI DULL')
            || str_contains($matGroupUpper, 'HOOK & EYE')
            || str_contains($matGroupUpper, 'PEARL BTN')
            || str_contains($matDescUpper, 'BTN')
            || str_contains($matDescUpper, 'BUTTON')
            || str_contains($matDescUpper, 'PLASTIC')
        );
        if ($isButtonGro) {
            $actualCost = round(round($midActual, 0) / 144.0, 4);
        } elseif ($ouom === 'KG' || $buom === 'KG') {
            $actualCost = round($midActual / 500.0, 4);
        } else {
            $actualCost = round($midActual, 4);
        }

        // Re-evaluate PASS/EXCEPTION against INR actual (not raw PORATE)
        if ($match && $expected !== null && $expected > 0 && $actualCost !== null) {
            $diff = round($actualCost - $expected, 4);
            $variancePct = round(($diff / $expected) * 100, 2);
            if ($score >= self::MATCH_SCORE_PASS) {
                if ($actualCost <= $expected + 0.005) {
                    $status = 'PASS';
                    $result = 'PASS — material/group/description matched';
                } else {
                    $status = 'EXCEPTION';
                    $result = 'Cost variance exceeds limit';
                }
            }
        }

        return [
            'pono'                => $this->displayNumber($poNumber),
            'purchase_order'      => $this->displayNumber($poNumber),
            'poitem'              => (string) ($item['item'] ?? ''),
            'po_item'             => (string) ($item['item'] ?? ''),
            'item'                => (string) ($item['item'] ?? ''),
            'prno'                => '',
            'salordno'            => $sos[0] ?? '',
            'sales_order'         => $sos[0] ?? '',
            'sales_orders'        => $sos,
            'style'               => $style,
            'material'            => $material,
            'material_desc'       => $description,
            'mat_desc'            => $description,
            'description'         => $description,
            'material_type'       => $materialType,
            'mat_type_desc'       => $matTypeDesc,
            'material_group'      => $materialGroup,
            'matgroup'            => $materialGroup,
            'mat_grp_desc'        => $matGrpDesc,
            'mat_category'        => $matCat,
            'so_qty'              => $soQty,
            'prwise_so_qty'       => $soQty,
            'consumption'         => $consumption,
            'reqqty'              => $reqQty,
            'tot_poqty'           => $totPoQty,
            'po_qty'              => $totPoQty,
            'order_qty'           => $totPoQty,
            'so_wise_poqty'       => $soWisePoQty,
            'so_wise_po_val'      => $soWisePoVal,
            'ouom'                => $ouom,
            'buom'                => $buom,
            'unit'                => $ouom,
            'porate'              => $poRate,
            'net_price'           => $poRate,
            // ACTUAL_COST column = PORATE × Curr Rate (INR), with classic unit rules
            'po_unit_cost'        => $actualCost !== null ? $actualCost : $poRate,
            'actual_cost'         => $actualCost !== null ? $actualCost : $poRate,
            'totvalue'            => $totValue,
            'net_value'           => $totValue,
            'po_currency'         => $poCurrency,
            'currency'            => $poCurrency,
            'budgeted_cons'       => $budgetedCons,
            'budget_inr'          => $budgetInr,
            'cost_total_inr'      => $budgetInr,
            'budget_currency'     => $budgetCurrency,
            'budget_conv_rt'      => $budgetConvRt,
            'conv'                => $unitConv,
            'convr'               => $budgetConvRt,
            'budget_cost_per_gmt' => $budgetCostPerGmt,
            'expected_unit_cost'  => $budgetCostPerGmt,
            'cost_section'        => $costSection,
            'cost_nav'            => $this->costNavName($costSection),
            'cost_item'           => $costItem,
            'zcost_item'          => $costItem,
            'cost_description'    => $costDesc !== '' ? $costDesc : $description,
            'maktx'               => $costDesc !== '' ? $costDesc : $description,
            'quotation'           => $itemQuotations[0] ?? '',
            'quotations'          => $itemQuotations,
            'costing_quotation_no'=> (string) (
                $match['costing_quotation_no']
                ?? $match['costing_quotation']
                ?? $item['costing_quotation_no']
                ?? $item['costing_quotation']
                ?? ($itemQuotations[0] ?? '')
            ),
            'costing_item_no'     => $costItem,
            'costing_ref'         => $costingRef,
            'difference'          => $diff,
            'variance_pct'        => $variancePct,
            'status'              => $status,
            'validation_result'   => $result,
            'cost_mismatch'       => $expected !== null && $expected > 0
                && $actualCost !== null && $actualCost > ($expected + 0.005),
            'match_score'         => $score,
            'match_flags'         => $matchFlags,
            'quoted_currency'     => $budgetCurrency,
            'quoted_uom'          => $buom,
            'vbeln'               => (string) ($match['sales_order'] ?? $match['quotation_no'] ?? ''),
            'posnr'               => (string) ($match['so_item'] ?? ''),
            'zline'               => (string) ($match['line_no'] ?? ''),
            'wid_siz'             => (string) ($match['wid_siz'] ?? ''),
            'net'                 => $match['net'] ?? null,
            'waste'               => $match['waste'] ?? null,
            'gross'               => $match['gross'] ?? null,
            'curunit'             => $budgetCurrency,
            'rate'                => $match['rate'] ?? null,
            'total_inr'           => $match['total_inr'] ?? null,
            'total_fr'            => $match['total_fr'] ?? null,
            'zsection'            => (string) (
                ($match['zsection'] ?? '') !== ''
                    ? $match['zsection']
                    : ($match['section'] ?? $costSection ?? '')
            ),
            'item_text'           => (string) ($item['item_text'] ?? ''),
            'plant'               => (string) ($item['plant'] ?? ''),
            'po_type'             => (string) ($item['po_type'] ?? ''),
            'po_type_name'        => (string) ($item['po_type_name'] ?? ''),
            'po_date'             => (string) ($item['po_date'] ?? ''),
            'supplier'            => (string) ($item['supplier'] ?? ''),
            'supplier_full_name'  => (string) ($item['supplier_full_name'] ?? ''),
            'created_by'          => (string) ($item['created_by'] ?? ''),
            'sell_price'          => $match['sell_price'] ?? null,
            'making'              => $match['making'] ?? null,
            'selected'            => false,
            'manual_match'        => false,
            'needs_manual_match'  => $match === null
                || $status === 'EXCEPTION' && preg_match('/no matching|no linked|cannot load|no reliable/i', (string) $result),
        ];
    }

    /**
     * Z_QuotationZCostCom by PurchaseOrder only — same filter as Postman.
     * Quotation/SO args left null so the SO/quote fallback inside fetchQuotationZCost
     * does not invent Costing Data when the PO filter returns [].
     *
     * @return array<string,mixed>
     */
    private function fetchQuotationZCostByPurchaseOrderOnly(string $poDigits, ?string $item = null): array
    {
        return $this->fetchQuotationZCost($poDigits, $item, null, null);
    }

    /**
     * @param list<array<string,mixed>> $records
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function recountTempfinStatusSummary(array $records, array $base): array
    {
        $passed = 0;
        $exceptions = 0;
        $needsReview = 0;
        $matched = 0;
        $detail = 0;
        foreach ($records as $r) {
            $kind = (string) ($r['row_kind'] ?? 'detail');
            if (in_array($kind, ['footer', 'charge', 'tax'], true)) {
                continue;
            }
            $st = strtoupper(str_replace(' ', '_', (string) ($r['status'] ?? '')));
            if ($st === 'FOOTER') {
                continue;
            }
            $detail++;
            if ($st === 'PASS') {
                $passed++;
            } elseif ($st === 'NEEDS_REVIEW') {
                $needsReview++;
            } else {
                // NOT_MATCHED / EXCEPTION / other → Mapping Required bucket
                $exceptions++;
            }
            if (($r['maktx'] ?? '') !== '' || ($r['cost_item'] ?? '') !== '' || !empty($r['manual_match'])) {
                $matched++;
            }
        }
        $base['passed'] = $passed;
        $base['exceptions'] = $exceptions;
        $base['needs_review'] = $needsReview;
        $base['zcost_matched'] = $matched;
        $base['can_pass_po'] = $detail > 0 && $exceptions === 0 && $needsReview === 0 && $passed > 0;
        return $base;
    }

    /**
     * Strip Costing Data columns from a TEMPFIN row when Z_QUOTATIONZCOSTCOM_CDS
     * has no matching lines (Postman empty / stale PASSPO link).
     * PO Data fields (PORATE, qty, material, …) are kept.
     *
     * @param array<string,mixed> $rec
     * @return array<string,mixed>
     */
    private function clearTempfinCostingFields(array $rec, string $reason): array
    {
        $rec['cost_item'] = '';
        $rec['zcost_item'] = '';
        $rec['costing_item_no'] = '';
        $rec['costing_quotation_no'] = '';
        $rec['matched_quotation'] = '';
        $rec['quotation'] = '';
        $rec['maktx'] = '';
        $rec['cost_description'] = '';
        $rec['zsection'] = '';
        $rec['cost_section'] = '';
        $rec['cost_nav'] = '';
        $rec['budget_cost_per_gmt'] = null;
        $rec['budget_cost_per_piece'] = null;
        $rec['expected_unit_cost'] = null;
        $rec['budget_inr'] = null;
        $rec['cost_total_inr'] = null;
        $rec['total_inr'] = null;
        $rec['gross'] = null;
        $rec['budgeted_cons'] = null;
        $rec['variance_pct'] = null;
        $rec['cost_mismatch'] = false;
        $rec['cost_matrix'] = null;
        $rec['tempfin_budget_locked'] = false;
        $rec['family_mismatch'] = false;
        // Hide costing ACTUAL until a live CDS line is mapped (keep PORATE for PO Data)
        $rec['actual_cost'] = null;
        $rec['status'] = 'NOT_MATCHED';
        $rec['validation_result'] = $reason;
        $rec['needs_manual_match'] = true;
        $rec['_zcost_pending'] = true;
        return $rec;
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

            // Reject clear family clashes (BUTTON↔ELASTIC, HANGTAG↔CARE, CORD↔STICKER, …)
            $poMatchText = $materialGroup . ' ' . $description . ' ' . $material;
            $lineMatchText = $lineItem . ' ' . $lineDesc . ' ' . (string) ($line['zcost_item'] ?? '');
            $poFamily = $this->trimFamily($poMatchText);
            $lineFamily = $this->trimFamily($lineMatchText);
            $poKind = $this->labelKind($poMatchText);
            $lineKind = $this->labelKind($lineMatchText);
            if ($poFamily !== '' && $lineFamily !== '' && $poFamily !== $lineFamily) {
                continue;
            }
            if ($poKind !== '' && $lineKind !== '' && $poKind !== $lineKind
                && $poKind !== 'LABEL' && $lineKind !== 'LABEL') {
                continue; // e.g. HANGTAG must not auto-pick CARE & CONTENT
            }
            if ($poFamily !== '' && $lineFamily !== '' && $poFamily === $lineFamily) {
                $score += 40;
            }
            if ($poKind !== '' && $lineKind !== '' && $poKind === $lineKind) {
                $score += 50;
            }

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

            // Style codes (VVVVSWH096) — strong SIZE LABEL match across L/M/S PO sizes
            $poStyles = $this->extractStyleCodes($description . ' ' . $material);
            $lineStyles = $this->extractStyleCodes($lineDesc . ' ' . $lineItem);
            $styleHit = array_values(array_intersect($poStyles, $lineStyles));
            if ($styleHit === [] && $poStyles !== [] && $lineStyles !== []) {
                foreach ($poStyles as $ps) {
                    foreach ($lineStyles as $ls) {
                        if (str_contains($ps, $ls) || str_contains($ls, $ps)) {
                            $styleHit[] = $ps;
                            break 2;
                        }
                    }
                }
            }
            if ($styleHit !== []) {
                $score += 120;
                $flags['description'] = true;
                $flags['material_code'] = true;
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

            // SIZE kind from PO material (SIZSTN…#L-16) ↔ SIZE LABEL line
            if ($poKind === 'SIZE' && $lineKind === 'SIZE') {
                $score += 40;
            } elseif (($poKind === 'SIZE' && $lineKind === 'LABEL') || ($poKind === 'LABEL' && $lineKind === 'SIZE')) {
                $score += 20;
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
     * Coarse trim family so BUTTON never auto-matches ELASTIC, CORD≠STICKER, etc.
     */
    private function trimFamily(string $text): string
    {
        $t = strtoupper(preg_replace('/[^A-Z0-9]+/', ' ', trim($text)) ?? '');
        $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
        if ($t === '') {
            return '';
        }
        if (preg_match('/DRC[A-Z0-9]*/', $t) || str_contains($t, 'DRAWCORD') || str_contains($t, 'DRAW CORD')) {
            return 'CORD';
        }
        if (preg_match('/\b(BUTTON|BTN|PEARLBTN|HOOK\s*EYE|SNAP)\b/', $t) || str_contains($t, 'BUTTON')) {
            return 'BUTTON';
        }
        if (preg_match('/\b(ELASTIC|ELASTANE|RUBBERTAPE)\b/', $t) || str_contains($t, 'ELASTIC')) {
            return 'ELASTIC';
        }
        if (preg_match('/\b(THREAD|THREADS)\b/', $t) || str_contains($t, 'THREAD')) {
            return 'THREAD';
        }
        if (preg_match('/\b(ZIPPER|ZIP)\b/', $t) || str_contains($t, 'ZIPPER')) {
            return 'ZIPPER';
        }
        if (preg_match('/\b(STICKER|STICKERS|RFID)\b/', $t) || str_contains($t, 'STICKER') || str_contains($t, 'RFID')) {
            return 'STICKER';
        }
        if (
            preg_match('/\b(LABEL|LBL|HANGTAG|MAINLBL|CARELBL|SIZELBL)\b/', $t)
            || str_contains($t, 'LABEL')
            || str_contains($t, 'HANGTAG')
            || preg_match('/CARE\s*&?\s*CONTENT|WASH\s*CARE/', $t)
        ) {
            return 'LABEL';
        }
        if (preg_match('/\b(DRAWCORD|CORD)\b/', $t) || str_contains($t, 'CORD')) {
            return 'CORD';
        }
        if (preg_match('/\b(RIBBON|TAPE|WEBBING)\b/', $t)) {
            return 'TAPE';
        }
        if (preg_match('/\b(POLYBAG|BAG)\b/', $t) || str_contains($t, 'POLYBAG')) {
            return 'BAG';
        }
        return '';
    }

    /**
     * Finer LABEL kinds so HANGTAG never auto-matches CARE & CONTENT / WASH CARE.
     */
    private function labelKind(string $text): string
    {
        $raw = strtoupper(trim($text));
        $t = strtoupper(preg_replace('/[^A-Z0-9&#]+/', ' ', $raw) ?? '');
        $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
        if ($t === '') {
            return '';
        }
        if (preg_match('/HANG\s*TAG|HANGTAG|\bLHT\b|T[\s\-]?LHT/', $t)) {
            return 'HANGTAG';
        }
        if (preg_match('/CARE\s*&?\s*CONTENT|WASH\s*CARE|CARE\s*LBL|CARELBL|CARE\s*LABEL|\bLCC\b|T[\s\-]?LCC/', $t)) {
            return 'CARE';
        }
        if (preg_match('/MAIN\s*LBL|MAINLBL|MAIN\s*LABEL|\bLML\b|T[\s\-]?LML/', $t)) {
            return 'MAIN';
        }
        // SIZE LABEL + codes like 30SIZSTNSIZNTN#L-16 / SIDE SEW LABEL … L/16
        if (
            preg_match('/SIZE\s*LBL|SIZELBL|SIZE\s*LABEL|\bLSL\b|T[\s\-]?LSL/', $t)
            || preg_match('/SIZSTN|SIZNTN|\bSIZ\b/', $t)
            || (preg_match('/#(S|M|L|XL|XXL)-\d+/', $raw) && preg_match('/LABEL|LBL|SEW/', $t))
            || (preg_match('/SIDE\s*SEW\s*LABEL/', $t) && preg_match('/\b(S|M|L|XL|XXL)\b|\b[SML]\/\d+/', $t))
        ) {
            return 'SIZE';
        }
        if (preg_match('/WOVEN\s*LABEL/', $t)) {
            return 'WOVEN';
        }
        if (preg_match('/LABEL|LBL/', $t)) {
            return 'LABEL';
        }
        return '';
    }

    /**
     * Style / article codes shared by PO MAT_DESC and zCOST (e.g. VVVVSWH096).
     *
     * @return list<string>
     */
    private function extractStyleCodes(string $text): array
    {
        $raw = strtoupper($text);
        $out = [];
        if (preg_match_all('/[A-Z]{2,}\d{2,}[A-Z0-9]*|[A-Z]{3,}[A-Z0-9]{3,}/', $raw, $m)) {
            foreach ($m[0] as $c) {
                $u = preg_replace('/[^A-Z0-9]/', '', $c) ?? '';
                if (strlen($u) >= 6 && strlen($u) <= 24 && !preg_match('/^(SIZE|LABEL|TRIM|PACK|THREAD|BUTTON)/', $u)) {
                    $out[] = $u;
                }
            }
        }
        if (preg_match_all('/[A-Z]{3,}[A-Z0-9]{2,}(?=[-_\/\s,])/', $raw, $m2)) {
            foreach ($m2[0] as $c) {
                $u = preg_replace('/[^A-Z0-9]/', '', $c) ?? '';
                if (strlen($u) >= 6 && strlen($u) <= 24) {
                    $out[] = $u;
                }
            }
        }
        return array_values(array_unique($out));
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
        $detailCount = 0;
        foreach ($records as $r) {
            $kind = (string) ($r['row_kind'] ?? 'detail');
            if (in_array($kind, ['footer', 'charge', 'tax'], true)) {
                continue;
            }
            $detailCount++;
            $st = strtoupper((string) ($r['status'] ?? ''));
            if ($st === 'PASS') {
                $passed++;
            } elseif ($st === 'NEEDS_REVIEW') {
                $needsReview++;
            } elseif ($st === 'FOOTER') {
                continue;
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
            'can_pass_po'       => $detailCount > 0 && $exceptions === 0 && $needsReview === 0 && $passed > 0,
        ];
    }

    /**
     * Read CondOTHR / CondFRB1|2 / CondZB00 / CondZSCI / CondZBCR from ZI_PurchaseOrderAPI_HUB.
     *
     * @return array<string, array{cond_othr:float,cond_frb1:float,cond_frb2:float,cond_zb00:float,cond_zsci:float,cond_zbcr:float}>
     */
    private function fetchPoConditionCharges(string $poDigits): array
    {
        try {
            $detail = $this->poService->report($poDigits);
            $rows = is_array($detail['records'] ?? null) ? $detail['records'] : [];
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $item = ltrim((string) ($r['item'] ?? $r['poitem'] ?? ''), '0');
            if ($item === '') {
                $item = '0';
            }
            $out[$item] = [
                'cond_othr' => (float) ($r['cond_othr'] ?? 0),
                'cond_frb1' => (float) ($r['cond_frb1'] ?? 0),
                'cond_frb2' => (float) ($r['cond_frb2'] ?? 0),
                'cond_zb00' => (float) ($r['cond_zb00'] ?? 0),
                'cond_zsci' => (float) ($r['cond_zsci'] ?? 0),
                'cond_zbcr' => (float) ($r['cond_zbcr'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Cond* line amounts + PASSPO charge/tax footer rows (IGST/SGST/CGST) → PDF-style charges.
     *
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function rollConditionCharges(array $records): array
    {
        $out = [
            'other' => 0.0, 'freight' => 0.0, 'moq' => 0.0, 'screen' => 0.0, 'bank' => 0.0,
            'discount' => 0.0, 'sgst' => 0.0, 'cgst' => 0.0, 'igst' => 0.0, 'total' => 0.0,
            'lines' => [],
        ];
        $cond = ['other' => 0.0, 'freight' => 0.0, 'moq' => 0.0, 'screen' => 0.0, 'bank' => 0.0];
        $footer = [
            'other' => 0.0, 'freight' => 0.0, 'moq' => 0.0, 'screen' => 0.0, 'bank' => 0.0,
            'discount' => 0.0, 'sgst' => 0.0, 'cgst' => 0.0, 'igst' => 0.0,
        ];
        $seen = [];
        foreach ($records as $r) {
            $kind = (string) ($r['row_kind'] ?? 'detail');
            if ($kind === 'detail') {
                $item = trim((string) ($r['poitem'] ?? $r['po_item'] ?? ''));
                if ($item !== '' && isset($seen[$item])) {
                    continue;
                }
                if ($item !== '') {
                    $seen[$item] = true;
                }
                $cond['other'] += (float) ($r['cond_othr'] ?? 0);
                $cond['freight'] += (float) ($r['cond_frb1'] ?? 0) + (float) ($r['cond_frb2'] ?? 0);
                $cond['moq'] += (float) ($r['cond_zb00'] ?? 0);
                $cond['screen'] += (float) ($r['cond_zsci'] ?? 0);
                $cond['bank'] += (float) ($r['cond_zbcr'] ?? 0);
                continue;
            }

            $label = strtoupper(trim(preg_replace(
                '/\s+/',
                ' ',
                (string) ($r['pono'] ?? $r['matgroup'] ?? $r['validation_result'] ?? '')
            ) ?? ''));
            $amt = $this->chargeAmount($r);
            if ($amt == 0.0 || $label === '') {
                continue;
            }
            if (str_contains($label, 'OTHER')) {
                $footer['other'] += $amt;
            } elseif (str_contains($label, 'FREIGHT')) {
                $footer['freight'] += $amt;
            } elseif (str_contains($label, 'MOQ')) {
                $footer['moq'] += $amt;
            } elseif (str_contains($label, 'SCREEN')) {
                $footer['screen'] += $amt;
            } elseif (str_contains($label, 'BANK')) {
                $footer['bank'] += $amt;
            } elseif (str_contains($label, 'SGST')) {
                $footer['sgst'] += $amt;
            } elseif (str_contains($label, 'CGST')) {
                $footer['cgst'] += $amt;
            } elseif (str_contains($label, 'IGST')) {
                $footer['igst'] += $amt;
            } elseif (str_contains($label, 'DISCOUNT')) {
                $footer['discount'] += $amt;
            }
        }

        $condSum = array_sum($cond);
        $src = $condSum > 0.0001 ? $cond : $footer;
        foreach (['other', 'freight', 'moq', 'screen', 'bank'] as $k) {
            $out[$k] = round((float) ($src[$k] ?? 0), 2);
        }
        foreach (['discount', 'sgst', 'cgst', 'igst'] as $k) {
            $out[$k] = round((float) ($footer[$k] ?? 0), 2);
        }
        $out['total'] = round(
            $out['other'] + $out['freight'] + $out['moq'] + $out['screen'] + $out['bank']
            + $out['sgst'] + $out['cgst'] + $out['igst'] - $out['discount'],
            2
        );
        $out['lines'] = [
            ['key' => 'other', 'label' => 'Other Charges', 'amount' => $out['other'], 'sap' => 'CondOTHR'],
            ['key' => 'freight', 'label' => 'Freight', 'amount' => $out['freight'], 'sap' => 'CondFRB1 / CondFRB2'],
            ['key' => 'moq', 'label' => 'MOQ Charges', 'amount' => $out['moq'], 'sap' => 'CondZB00'],
            ['key' => 'screen', 'label' => 'Screen Charges', 'amount' => $out['screen'], 'sap' => 'CondZSCI'],
            ['key' => 'bank', 'label' => 'Bank Charges', 'amount' => $out['bank'], 'sap' => 'CondZBCR'],
            ['key' => 'discount', 'label' => 'Discount', 'amount' => $out['discount'], 'sap' => ''],
            ['key' => 'sgst', 'label' => 'SGST', 'amount' => $out['sgst'], 'sap' => ''],
            ['key' => 'cgst', 'label' => 'CGST', 'amount' => $out['cgst'], 'sap' => ''],
            ['key' => 'igst', 'label' => 'IGST', 'amount' => $out['igst'], 'sap' => ''],
        ];
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     */
    private function chargeAmount(array $r): float
    {
        foreach ([
            'so_wise_po_val', 'totvalue', 'net_value', 'budget_inr',
            'actual_cost', 'po_unit_cost', 'porate', 'budget_cost_per_gmt',
        ] as $k) {
            if (!array_key_exists($k, $r) || $r[$k] === null || $r[$k] === '') {
                continue;
            }
            $n = (float) $r[$k];
            if (abs($n) > 0.0000001) {
                return $n;
            }
        }
        return 0.0;
    }

    /**
     * Keep the larger amount per charge/tax bucket (Cond* + PASSPO footers).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function mergeChargeBuckets(array $a, array $b): array
    {
        $keys = ['other', 'freight', 'moq', 'screen', 'bank', 'discount', 'sgst', 'cgst', 'igst'];
        $out = $b !== [] ? $b : $a;
        foreach ($keys as $k) {
            $av = (float) ($a[$k] ?? 0);
            $bv = (float) ($b[$k] ?? 0);
            $out[$k] = round(max($av, $bv), 2);
        }
        $out['total'] = round(
            (float) ($out['other'] ?? 0) + (float) ($out['freight'] ?? 0) + (float) ($out['moq'] ?? 0)
            + (float) ($out['screen'] ?? 0) + (float) ($out['bank'] ?? 0)
            + (float) ($out['sgst'] ?? 0) + (float) ($out['cgst'] ?? 0) + (float) ($out['igst'] ?? 0)
            - (float) ($out['discount'] ?? 0),
            2
        );
        $out['lines'] = $b['lines'] ?? $a['lines'] ?? [];
        return $out;
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