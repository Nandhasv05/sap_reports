<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 31/10/2026
 * DESCRIPTION : Load classic PO costing TEMPFIN / PASSPO rows (PONO, SALORDNO, BUDGET_COST_PER_GMT, ACTUAL_COST, …)
 */

declare(strict_types=1);

final class PoTempfinCostingService
{
    /** @var list<string> */
    public const DETAIL_COLUMNS = [
        'PONO', 'POITEM', 'PRNO', 'SALORDNO', 'STYLE', 'MATERIAL', 'MATGROUP',
        'SO_QTY', 'PRWISE_SO_QTY', 'CONSUMPTION', 'REQQTY', 'TOT_POQTY',
        'SO_WISE_POQTY', 'SO_WISE_PO_VAL', 'OUOM', 'PORATE', 'TOTVALUE',
        'MAT_DESC', 'PO_CURRENCY', 'BUDGETED_CONS', 'BUDGET_INR', 'BUDGET_CURRENCY',
        'BUDGET_CONV_RT', 'BUDGET_COST_PER_GMT', 'CONV', 'BUOM', 'ACTUAL_COST',
    ];

    private ?PDO $pdo = null;
    private ?string $connectError = null;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
            return;
        }
        $this->pdo = $this->connect();
    }

    public function available(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function connectError(): ?string
    {
        return $this->connectError;
    }

    /**
     * @return array{
     *   success: bool,
     *   records: list<array<string,mixed>>,
     *   summary: array<string,mixed>,
     *   mat_type: string,
     *   error: ?string
     * }
     */
    public function fetchForPurchaseOrder(string $purchaseOrder, string $matType = 'all'): array
    {
        $po = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        if ($po === '') {
            return $this->fail('Purchase order number is required.');
        }
        if (!$this->pdo) {
            return $this->fail($this->connectError ?? 'CLIENT_API_LIVE connection unavailable.');
        }

        $matType = strtoupper(trim($matType));
        if ($matType === 'FABRIC' || $matType === 'TRIMS') {
            $isFabric = $matType === 'FABRIC';
        } else {
            $isFabric = $this->isFabricPo($po);
        }

        try {
            $this->populateTempfin($po, $isFabric);
            $salesOrders = $this->salesOrdersForPo($po, $isFabric);
            if ($salesOrders === []) {
                // Still try PASSPO with empty SO — some SPs accept PO-only via TEMPFIN dump
                $rows = $this->fetchPassPoRows($po, '');
            } else {
                $rows = [];
                $seen = [];
                foreach ($salesOrders as $so) {
                    foreach ($this->fetchPassPoRows($po, $so) as $row) {
                        $key = implode('|', [
                            (string) ($row['PONO'] ?? ''),
                            (string) ($row['POITEM'] ?? ''),
                            (string) ($row['SALORDNO'] ?? ''),
                            (string) ($row['MATERIAL'] ?? ''),
                            (string) ($row['STYLE'] ?? ''),
                        ]);
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $rows[] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            return $this->fail('TEMPFIN/PASSPO failed: ' . $e->getMessage());
        }

        // Keep classic PASSPO layout: detail lines + SUB-TOTAL / charges / tax / TOTAL
        $allRows = [];
        foreach ($rows as $row) {
            $allRows[] = $this->normalizeRow($row);
        }

        $detailOnly = array_values(array_filter(
            $allRows,
            static fn (array $r): bool => ($r['row_kind'] ?? 'detail') === 'detail'
        ));

        return [
            'success'  => true,
            'records'  => $allRows,
            'summary'  => $this->summarize($detailOnly),
            'mat_type' => $isFabric ? 'fabric' : 'trims',
            'error'    => null,
        ];
    }

    private function connect(): ?PDO
    {
        $evolDb = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'EVOL' . DIRECTORY_SEPARATOR . 'db_config_client_api.php';
        if (is_file($evolDb)) {
            require_once $evolDb;
            if (function_exists('evol_client_api_pdo')) {
                try {
                    $pdo = evol_client_api_pdo();
                    $pdo->exec('SET ANSI_NULLS ON');
                    $pdo->exec('SET ANSI_WARNINGS ON');
                    return $pdo;
                } catch (Throwable $e) {
                    $this->connectError = $e->getMessage();
                }
            }
        }

        // Fallback: same defaults as root index.php costing page
        $server = '10.103.10.33,1433';
        $dbName = 'CLIENT_API_LIVE';
        $user = 'sa';
        $password = 'Developer@123';
        try {
            $dsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$server};Database={$dbName};Encrypt=no;TrustServerCertificate=yes;";
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('SET ANSI_NULLS ON');
            $pdo->exec('SET ANSI_WARNINGS ON');
            return $pdo;
        } catch (Throwable $e) {
            $this->connectError = $e->getMessage();
            return null;
        }
    }

    private function isFabricPo(string $po): bool
    {
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM PO_VALIDATE_SALORD_Fabric WHERE PONO = ?');
            $st->execute([$po]);
            $n = (int) $st->fetchColumn();
            $st->closeCursor();
            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function populateTempfin(string $po, bool $isFabric): void
    {
        $sql = $isFabric
            ? "DECLARE @ErrMsg VARCHAR(500); DECLARE @POStatus VARCHAR(50); EXEC [dbo].[PO_so_validation_Fabric] ?, @ErrMsg OUTPUT, @POStatus OUTPUT;"
            : "DECLARE @ErrMsg VARCHAR(500); DECLARE @POStatus VARCHAR(50); EXEC [dbo].[PO_so_validation_Trims] ?, @ErrMsg OUTPUT, @POStatus OUTPUT;";
        $st = $this->pdo->prepare($sql);
        $st->execute([$po]);
        while ($st->nextRowset()) {
            // drain
        }
        $st->closeCursor();
    }

    /**
     * @return list<string>
     */
    private function salesOrdersForPo(string $po, bool $isFabric): array
    {
        $table = $isFabric ? 'PO_VALIDATE_SALORD_Fabric' : 'PO_VALIDATE_SALORD';
        try {
            $st = $this->pdo->prepare("SELECT DISTINCT salordno FROM {$table} WHERE PONO = ?");
            $st->execute([$po]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            while ($st->nextRowset()) {
            }
            $st->closeCursor();
        } catch (Throwable $e) {
            $st = $this->pdo->prepare('SELECT DISTINCT salordno FROM PO_VALIDATE_SALORD WHERE PONO = ?');
            $st->execute([$po]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            while ($st->nextRowset()) {
            }
            $st->closeCursor();
        }
        $out = [];
        foreach ($rows as $r) {
            $so = preg_replace('/\D/', '', (string) ($r['salordno'] ?? $r['SALORDNO'] ?? '')) ?? '';
            if ($so !== '' && !in_array($so, $out, true)) {
                $out[] = $so;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchPassPoRows(string $po, string $so): array
    {
        $sql = 'SET ANSI_WARNINGS OFF; EXEC PASSPO ?, ?; SET ANSI_WARNINGS ON;';
        $st = $this->pdo->prepare($sql);
        $st->execute([$po, $so]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        while ($st->nextRowset()) {
        }
        $st->closeCursor();
        $out = [];
        foreach ($rows as $raw) {
            $row = [];
            foreach ($raw as $k => $v) {
                $row[strtoupper((string) $k)] = $v;
            }
            // Normalize mat_desc casing
            if (!isset($row['MAT_DESC']) && isset($row['MAT_DESC'])) {
                $row['MAT_DESC'] = $row['MAT_DESC'];
            }
            if (!isset($row['MAT_DESC']) && isset($raw['mat_desc'])) {
                $row['MAT_DESC'] = $raw['mat_desc'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $get = static function (array $r, string ...$keys) {
            foreach ($keys as $k) {
                $u = strtoupper($k);
                if (array_key_exists($u, $r) && $r[$u] !== null && $r[$u] !== '') {
                    return $r[$u];
                }
                if (array_key_exists($k, $r) && $r[$k] !== null && $r[$k] !== '') {
                    return $r[$k];
                }
            }
            return '';
        };

        $ponoRaw = trim((string) $get($row, 'PONO'));
        $ponoUpper = strtoupper(preg_replace('/\s+/', ' ', $ponoRaw) ?? '');
        $matgroupUpper = strtoupper(trim((string) $get($row, 'MATGROUP')));
        $rowKind = $this->detectRowKind($ponoUpper, $matgroupUpper);

        // Classic TEMPFIN numeric fields (align with PO Costing Validation)
        $soQty = $this->num($get($row, 'SO_QTY'));
        $prSoQty = $this->num($get($row, 'PRWISE_SO_QTY'));
        $consumption = $this->num($get($row, 'CONSUMPTION'));
        $reqqty = $this->num($get($row, 'REQQTY'));
        $totPoQty = $this->num($get($row, 'TOT_POQTY'));
        $porate = $this->num($get($row, 'PORATE'));
        $budgetedCons = $this->num($get($row, 'BUDGETED_CONS'));
        $budgetInr = $this->num($get($row, 'BUDGET_INR'));
        $budgetConv = $this->num($get($row, 'BUDGET_CONV_RT'));
        $budget = $this->num($get($row, 'BUDGET_COST_PER_GMT', 'BUDGET COST PER GMT'));
        $conv = $this->num($get($row, 'CONV'));
        $actual = $this->num($get($row, 'ACTUAL_COST', 'ACTUAL COST'));
        $ouom = strtoupper(trim((string) $get($row, 'OUOM')));
        $buom = strtoupper(trim((string) $get($row, 'BUOM')));
        $matDesc = strtoupper(trim((string) $get($row, 'MAT_DESC', 'mat_desc')));
        $poCurrency = strtoupper(trim((string) $get($row, 'PO_CURRENCY')) ?: 'INR');
        $tempfinActual = $actual;
        $tempfinBudget = $budget;

        if ($rowKind === 'detail') {
            // SO_QTY often blank in TEMPFIN while PRWISE_SO_QTY is filled (e.g. PO 1000028025)
            if (($soQty === null || $soQty <= 0) && $prSoQty !== null && $prSoQty > 0) {
                $soQty = $prSoQty;
            }

            // Match classic portal: costing_fix_trims_consumption_not_po_ratio()
            // CONSUMPTION is per garment — never PO qty ÷ SO qty (that inflates ACTUAL).
            $baseSo = $soQty !== null && $soQty > 0 ? $soQty : $prSoQty;
            $poSoRatio = ($totPoQty !== null && $totPoQty > 0 && $baseSo !== null && $baseSo > 0)
                ? ($totPoQty / $baseSo)
                : null;
            $looksLikePoSo = $consumption !== null && $poSoRatio !== null
                && abs($consumption - $poSoRatio) <= max(0.08, abs($poSoRatio) * 0.04);
            $looksLikePoReq = $consumption !== null && $consumption > 1.2
                && $reqqty !== null && $totPoQty !== null
                && abs($reqqty - $totPoQty) <= max(2.0, abs($totPoQty) * 0.03);

            $fixedBadCons = false;
            if ($consumption === null || $consumption <= 0 || $looksLikePoSo || $looksLikePoReq) {
                if ($budgetedCons !== null && $budgetedCons > 0) {
                    $consumption = $budgetedCons;
                } elseif ($consumption === null || $consumption <= 0 || $looksLikePoSo || $looksLikePoReq) {
                    $consumption = 1.0;
                }
                $fixedBadCons = true;
            }

            if ($conv === null && $budgetConv !== null) {
                $conv = $budgetConv;
            }
            if ($conv === null || $conv <= 0) {
                $conv = 1.0;
            }

            // Classic portal: trust PASSPO ACTUAL_COST / BUDGET_COST_PER_GMT as returned.
            // Only recompute when blank, or when we just fixed a bad PO÷SO consumption
            // (classic then sets ACTUAL = PORATE × cons, with button GRO / FX rules).
            $consForActual = ($consumption !== null && $consumption > 0)
                ? $consumption
                : (($budgetedCons !== null && $budgetedCons > 0) ? $budgetedCons : 1.0);
            $classicActual = $this->computeClassicActualCost(
                $porate,
                $conv,
                $consForActual,
                $budgetConv,
                $matgroupUpper,
                $ouom,
                $buom,
                $matDesc,
                $poCurrency
            );
            if ($tempfinActual !== null && $tempfinActual > 0 && !$fixedBadCons) {
                // Keep PASSPO value — same as classic grid ($shown = ACTUAL_COST from SP)
                $actual = $tempfinActual;
            } elseif ($classicActual !== null) {
                $actual = $classicActual;
            } elseif ($tempfinActual !== null && $tempfinActual > 0) {
                $actual = $tempfinActual;
            }

            // Trust PASSPO BUDGET_COST_PER_GMT — never invent from zCOST RATE.
            // Fill only when blank using BUDGETED_CONS × BUDGET_INR (classic Excel).
            if ($tempfinBudget !== null && $tempfinBudget > 0) {
                $budget = $tempfinBudget;
            } elseif ($budget === null || $budget <= 0) {
                if ($budgetedCons !== null && $budgetedCons > 0 && $budgetInr !== null && $budgetInr > 0) {
                    $budget = round($budgetedCons * $budgetInr, 4);
                } elseif ($budgetInr !== null && $budgetInr > 0) {
                    $scaleRef = $actual ?? $porate;
                    $looksLikeUnit = $scaleRef === null || $scaleRef <= 0
                        || $budgetInr <= max(50.0, abs((float) $scaleRef) * 50.0);
                    if ($looksLikeUnit) {
                        $budget = $budgetInr;
                    }
                }
            }
            if ($budget !== null && $budget > 0) {
                $tempfinBudget = $budget;
            }
        }

        $status = 'EXCEPTION';
        $result = 'No budget / actual cost';
        $variancePct = null;
        // Classic UI: green when actual < budget − 0.005; red when actual > budget + 0.005
        $tol = 0.005;
        if ($rowKind !== 'detail') {
            $status = 'FOOTER';
            $result = $ponoUpper !== '' ? $ponoUpper : ($matgroupUpper !== '' ? $matgroupUpper : 'FOOTER');
        } elseif ($budget !== null && $budget > 0 && $actual !== null) {
            $variancePct = round((($actual - $budget) / $budget) * 100, 2);
            if ($actual <= $budget + $tol) {
                $status = 'PASS';
                $result = 'PASS — actual cost within budget';
            } else {
                $status = 'EXCEPTION';
                $result = 'EXCEPTION — actual cost exceeds budget';
            }
        } elseif ($rowKind === 'detail' && ($budget === null || $budget <= 0) && $actual !== null) {
            $status = 'NEEDS_REVIEW';
            $result = 'No budget RATE — Needs Review';
        }

        $pono = $ponoRaw;
        $poitem = (string) $get($row, 'POITEM');
        $salord = (string) $get($row, 'SALORDNO');
        // Classic portal: danger when actual > budget + 0.005
        $costMismatch = $rowKind === 'detail'
            && $budget !== null
            && (float) $budget > 0
            && $actual !== null
            && (float) $actual > ((float) $budget + 0.005);

        return [
            'pono'                 => $pono,
            'poitem'               => $poitem,
            'prno'                 => (string) $get($row, 'PRNO'),
            'salordno'             => $salord,
            'style'                => (string) $get($row, 'STYLE'),
            'material'             => (string) $get($row, 'MATERIAL'),
            'matgroup'             => (string) $get($row, 'MATGROUP'),
            'so_qty'               => $soQty,
            'prwise_so_qty'        => $prSoQty,
            'consumption'          => $consumption,
            'reqqty'               => $reqqty,
            'tot_poqty'            => $totPoQty,
            'so_wise_poqty'        => $this->num($get($row, 'SO_WISE_POQTY')),
            'so_wise_po_val'       => $this->num($get($row, 'SO_WISE_PO_VAL')),
            'ouom'                 => $ouom !== '' ? $ouom : (string) $get($row, 'OUOM'),
            'porate'               => $porate,
            'totvalue'             => $this->num($get($row, 'TOTVALUE')),
            'mat_desc'             => (string) $get($row, 'MAT_DESC', 'mat_desc'),
            'po_currency'          => $poCurrency !== '' ? $poCurrency : (string) $get($row, 'PO_CURRENCY'),
            'budgeted_cons'        => $budgetedCons,
            'budget_inr'           => $budgetInr,
            'budget_currency'      => (string) $get($row, 'BUDGET_CURRENCY'),
            'budget_conv_rt'       => $budgetConv,
            'budget_cost_per_gmt'  => $budget,
            'conv'                 => $conv,
            'buom'                 => $buom !== '' ? $buom : (string) $get($row, 'BUOM'),
            'actual_cost'          => $actual,
            // Lock TEMPFIN budget/actual/consumption so zCOST match cannot overwrite Excel values
            // Lock whenever PASSPO supplied a real value — zCOST match must not overwrite
            'tempfin_budget_locked'=> $rowKind === 'detail' && $budget !== null && (float) $budget > 0,
            'tempfin_actual_locked'=> $rowKind === 'detail' && $actual !== null && (float) $actual > 0,
            'tempfin_cons_locked'  => $rowKind === 'detail' && $consumption !== null && $consumption > 0,
            'cost_item'            => (string) $get($row, 'ITEM', 'CostingItemNo', 'COSTING_ITEM_NO'),
            'zcost_item'           => (string) $get($row, 'ITEM', 'CostingItemNo', 'COSTING_ITEM_NO'),
            'costing_quotation_no' => (string) $get($row, 'CostingQuotationNo', 'COSTING_QUOTATION_NO', 'QUOTATIONNO'),
            'costing_item_no'      => (string) $get($row, 'ITEM', 'CostingItemNo', 'COSTING_ITEM_NO'),
            'maktx'                => '',
            'cost_matrix'          => ($budget !== null || $actual !== null)
                ? [
                    'budget' => $budget,
                    'actual' => $actual,
                    'status' => $status,
                ]
                : null,
            'variance_pct'         => $variancePct,
            'row_kind'             => $rowKind,
            'cost_mismatch'        => $costMismatch,
            // Compatibility with existing workbench KPIs / filters
            'purchase_order'       => $pono,
            'po_item'              => $poitem,
            'sales_order'          => $salord,
            'material_desc'        => (string) $get($row, 'MAT_DESC', 'mat_desc'),
            'material_group'       => (string) $get($row, 'MATGROUP'),
            'order_qty'            => $this->num($get($row, 'TOT_POQTY', 'SO_WISE_POQTY')),
            'po_qty'               => $this->num($get($row, 'TOT_POQTY', 'SO_WISE_POQTY')),
            'unit'                 => (string) $get($row, 'OUOM', 'BUOM'),
            'net_value'            => $this->num($get($row, 'TOTVALUE', 'SO_WISE_PO_VAL')),
            'currency'             => (string) $get($row, 'PO_CURRENCY') ?: 'INR',
            'po_unit_cost'         => $actual,
            'expected_unit_cost'   => $budget,
            'status'               => $status,
            'validation_result'    => $result,
            // TEMPFIN budget+actual is enough to PASS/FAIL — empty zCOST ITEM is not "needs review"
            'needs_manual_match'   => $rowKind === 'detail'
                && ($budget === null || $budget <= 0 || $actual === null),
            'selected'             => false,
        ];
    }

    private function detectRowKind(string $ponoUpper, string $matgroupUpper): string
    {
        $label = $ponoUpper !== '' ? $ponoUpper : $matgroupUpper;
        if ($label === '') {
            return 'detail';
        }
        if (in_array($label, ['SUB-TOTAL', 'SUB TOTAL', 'SUBTOTAL', 'TOTAL'], true)) {
            return 'footer';
        }
        if (preg_match('/^(IGST|SGST|CGST|GST|TAX)(\s|$)/', $label)) {
            return 'tax';
        }
        if (str_contains($label, 'CHARGES') || preg_match('/freight|moq|screen|bank|other\s*charges/i', $label)) {
            return 'charge';
        }
        if (preg_match('/charges|igst|freight|screen|bank|moq|other\s*charges/i', $matgroupUpper)) {
            return 'charge';
        }
        return 'detail';
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
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
        $materials = [];
        $salesOrders = [];
        $items = [];
        $seenValueItems = [];
        $unit = '';
        $detailCount = 0;
        foreach ($records as $r) {
            $kind = (string) ($r['row_kind'] ?? 'detail');
            // SUB-TOTAL / charges / tax must not count as exceptions (was forcing STATUS : NOT PASSED)
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
            $mat = trim((string) ($r['material'] ?? ''));
            if ($mat !== '') {
                $materials[$mat] = true;
            }
            $so = trim((string) ($r['salordno'] ?? $r['sales_order'] ?? ''));
            if ($so !== '') {
                $salesOrders[$so] = true;
            }
            $item = trim((string) ($r['poitem'] ?? $r['po_item'] ?? ''));
            if ($item !== '') {
                $items[$item] = true;
            }
            // TOTVALUE / TOT_POQTY repeat on every SO row — count once per PO item
            if ($item !== '' && !isset($seenValueItems[$item])) {
                $seenValueItems[$item] = true;
                $totalValue += (float) ($r['totvalue'] ?? $r['net_value'] ?? 0);
                $totalOrderQty += (float) ($r['tot_poqty'] ?? $r['order_qty'] ?? $r['po_qty'] ?? 0);
            } elseif ($item === '') {
                $totalValue += (float) ($r['totvalue'] ?? $r['net_value'] ?? 0);
                $totalOrderQty += (float) ($r['tot_poqty'] ?? $r['order_qty'] ?? 0);
            }
            $budget = $r['budget_cost_per_gmt'] ?? $r['expected_unit_cost'] ?? null;
            $actual = $r['actual_cost'] ?? $r['po_unit_cost'] ?? null;
            $soQty = (float) ($r['so_qty'] ?? $r['prwise_so_qty'] ?? 0);
            $costQty = $soQty > 0 ? $soQty : 1.0;
            if ($budget !== null && $budget !== '') {
                $totalExpected += (float) $budget * $costQty;
            }
            if ($actual !== null && $actual !== '') {
                $totalActual += (float) $actual * $costQty;
            }
            $u = trim((string) ($r['ouom'] ?? $r['buom'] ?? $r['unit'] ?? ''));
            if ($u !== '' && $unit === '') {
                $unit = $u;
            }
        }
        return [
            'po_items'        => count($items) > 0 ? count($items) : $detailCount,
            'unique_po_items' => count($items),
            'materials'       => count($materials),
            'sales_orders'    => count($salesOrders),
            'total_order_qty' => round($totalOrderQty, 3),
            'unit'            => $unit,
            'passed'          => $passed,
            'exceptions'      => $exceptions,
            'needs_review'    => $needsReview,
            'pending'         => 0,
            'zcost_matched'   => $passed + $needsReview,
            'total_po_value'  => round($totalValue, 2),
            'total_expected'  => round($totalExpected, 2),
            'total_actual'    => round($totalActual, 2),
            'currency'        => (string) ($records[0]['po_currency'] ?? $records[0]['currency'] ?? 'INR'),
            'can_pass_po'     => $detailCount > 0 && $exceptions === 0 && $needsReview === 0 && $passed > 0,
        ];
    }

    /**
     * Classic portal ACTUAL_COST — same rules as PO Costing Validation/lib/calc-columns.php
     * po_calc_compute_actual_cost() / po_calc_actual_cost_path().
     * GRO÷144 only for button-like groups when OUOM is GRO (not every GRO line).
     */
    private function computeClassicActualCost(
        ?float $porate,
        ?float $conv,
        ?float $cons,
        ?float $budgetConv,
        string $matgroup,
        string $ouom,
        string $buom,
        string $matDesc,
        string $currency
    ): ?float {
        if ($porate === null) {
            return null;
        }
        $conv = ($conv !== null && $conv > 0) ? $conv : 1.0;
        $fx = ($budgetConv !== null && $budgetConv > 0) ? $budgetConv : 1.0;
        $mg = strtoupper(trim($matgroup));
        $ouom = strtoupper(trim($ouom));
        $buom = strtoupper(trim($buom));
        $matDesc = strtoupper(trim($matDesc));
        $currency = strtoupper(trim($currency) ?: 'INR');

        if ($mg === 'THREADS1' && $buom === 'CPS') {
            return round(($porate / 1000.0) * $conv, 4);
        }
        if ($mg === 'THREADS1' && $buom === 'BOX') {
            return round(($porate / 10000.0) * $conv, 4);
        }

        if ($cons === null) {
            return null;
        }

        // Classic: OUOM=GRO + button family only (BUOM=GRO alone is not enough)
        $isButtonGro = ($ouom === 'GRO' || $buom === 'GRO') && (
            str_contains($mg, 'BUTTON')
            || str_contains($mg, 'SEMI DULL')
            || str_contains($mg, 'HOOK & EYE')
            || str_contains($mg, 'PEARL BTN')
            || str_contains($matDesc, 'BTN')
            || str_contains($matDesc, 'BUTTON')
            || str_contains($matDesc, 'PLASTIC')
        );

        $base = ($currency !== 'INR') ? ($porate * $fx) : $porate;
        $mid = $base * $conv;

        if ($isButtonGro) {
            return round(round($mid * $cons, 0) / 144.0, 4);
        }
        if ($ouom === 'KG' || $buom === 'KG') {
            return round(($mid * $cons) / 500.0, 4);
        }
        return round($mid * $cons, 4);
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $clean = str_replace([',', ' '], '', (string) $v);
        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * @return array{success:bool,records:list,summary:array,mat_type:string,error:?string}
     */
    private function fail(string $message): array
    {
        return [
            'success'  => false,
            'records'  => [],
            'summary'  => [
                'po_items' => 0,
                'materials' => 0,
                'sales_orders' => 0,
                'total_order_qty' => 0,
                'unit' => '',
                'passed' => 0,
                'exceptions' => 0,
                'needs_review' => 0,
                'total_po_value' => 0,
                'total_expected' => 0,
                'total_actual' => 0,
                'currency' => 'INR',
                'can_pass_po' => false,
            ],
            'mat_type' => 'trims',
            'error'    => $message,
        ];
    }
}
?>