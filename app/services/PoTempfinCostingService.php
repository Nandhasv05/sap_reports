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

        if ($rowKind === 'detail') {
            // Same as classic: consumption is per-garment, not PO÷SO ratio
            $baseSo = $soQty !== null && $soQty > 0 ? $soQty : $prSoQty;
            if (
                $consumption !== null
                && $baseSo !== null
                && $baseSo > 0
                && $totPoQty !== null
                && $totPoQty > 0
            ) {
                $ratio = $totPoQty / $baseSo;
                $isPoSoRatio = abs($consumption - $ratio) <= max(0.08, $ratio * 0.04)
                    || ($consumption > 1.2 && $reqqty !== null && abs($reqqty - $totPoQty) <= max(2.0, $totPoQty * 0.03));
                if ($isPoSoRatio) {
                    $useCons = ($budgetedCons !== null && $budgetedCons > 0) ? $budgetedCons : 1.0;
                    $consumption = $useCons;
                    $reqqty = round($baseSo * $useCons, 4);
                    if ($porate !== null) {
                        $actual = round($porate * $useCons, 2);
                    }
                }
            }

            // Classic: ACTUAL_COST = PORATE × BUDGETED_CONS when actual missing / zero
            $useCons = ($budgetedCons !== null && $budgetedCons > 0)
                ? $budgetedCons
                : (($consumption !== null && $consumption > 0) ? $consumption : null);
            if ($porate !== null && $useCons !== null && ($actual === null || $actual == 0.0)) {
                $actual = round($porate * $useCons, 2);
            }

            // Classic: BUDGET_COST_PER_GMT ≈ BUDGETED_CONS × BUDGET_INR when GMT blank
            if ($budget === null && $budgetedCons !== null && $budgetInr !== null) {
                $budget = round($budgetedCons * $budgetInr, 4);
            }
            if ($conv === null && $budgetConv !== null) {
                $conv = $budgetConv;
            }
        }

        $status = 'EXCEPTION';
        $result = 'No budget / actual cost';
        $variancePct = null;
        if ($rowKind !== 'detail') {
            $status = 'FOOTER';
            $result = $ponoUpper !== '' ? $ponoUpper : ($matgroupUpper !== '' ? $matgroupUpper : 'FOOTER');
        } elseif ($budget !== null && $actual !== null) {
            if ($budget != 0.0) {
                $variancePct = round((($actual - $budget) / $budget) * 100, 2);
            } else {
                $variancePct = $actual == 0.0 ? 0.0 : 100.0;
            }
            if ($actual <= $budget) {
                $status = 'PASS';
                $result = 'PASS — actual cost within budget';
            } else {
                $status = 'EXCEPTION';
                $result = 'EXCEPTION — actual cost exceeds budget';
            }
        }

        $pono = $ponoRaw;
        $poitem = (string) $get($row, 'POITEM');
        $salord = (string) $get($row, 'SALORDNO');
        $costMismatch = $rowKind === 'detail'
            && $budget !== null
            && $actual !== null
            && abs((float) $actual - (float) $budget) > 0.0001;

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
            'ouom'                 => (string) $get($row, 'OUOM'),
            'porate'               => $porate,
            'totvalue'             => $this->num($get($row, 'TOTVALUE')),
            'mat_desc'             => (string) $get($row, 'MAT_DESC', 'mat_desc'),
            'po_currency'          => (string) $get($row, 'PO_CURRENCY'),
            'budgeted_cons'        => $budgetedCons,
            'budget_inr'           => $budgetInr,
            'budget_currency'      => (string) $get($row, 'BUDGET_CURRENCY'),
            'budget_conv_rt'       => $budgetConv,
            'budget_cost_per_gmt'  => $budget,
            'conv'                 => $conv,
            'buom'                 => (string) $get($row, 'BUOM'),
            'actual_cost'          => $actual,
            'cost_item'            => '',
            'costing_quotation_no' => (string) $get($row, 'CostingQuotationNo', 'COSTING_QUOTATION_NO', 'QUOTATIONNO'),
            'costing_item_no'      => (string) $get($row, 'CostingItemNo', 'COSTING_ITEM_NO', 'ITEM'),
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
            'needs_manual_match'   => $rowKind === 'detail' && ($budget === null || $budget <= 0),
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
        foreach ($records as $r) {
            $st = (string) ($r['status'] ?? '');
            if ($st === 'PASS') {
                $passed++;
            } elseif ($st === 'NEEDS_REVIEW') {
                $needsReview++;
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
            'po_items'        => count($items) > 0 ? count($items) : count($records),
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
            'can_pass_po'     => count($records) > 0 && $exceptions === 0 && $needsReview === 0,
        ];
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