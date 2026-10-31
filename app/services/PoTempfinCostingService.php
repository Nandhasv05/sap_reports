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

        $detailRows = [];
        foreach ($rows as $row) {
            $mg = strtoupper(trim((string) ($row['MATGROUP'] ?? '')));
            if ($mg === '' || $mg === 'TOTAL' || $mg === 'SUB-TOTAL' || $mg === 'SUBTOTAL') {
                continue;
            }
            if (preg_match('/charges|igst|freight|screen|bank|moq|other\s*charges/i', $mg)) {
                continue;
            }
            $detailRows[] = $this->normalizeRow($row);
        }

        return [
            'success'  => true,
            'records'  => $detailRows,
            'summary'  => $this->summarize($detailRows),
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

        $budget = $this->num($get($row, 'BUDGET_COST_PER_GMT', 'BUDGET COST PER GMT'));
        $actual = $this->num($get($row, 'ACTUAL_COST', 'ACTUAL COST'));
        $status = 'EXCEPTION';
        $result = 'No budget / actual cost';
        $variancePct = null;
        if ($budget !== null && $actual !== null) {
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

        $pono = (string) $get($row, 'PONO');
        $poitem = (string) $get($row, 'POITEM');
        $salord = (string) $get($row, 'SALORDNO');

        return [
            'pono'                 => $pono,
            'poitem'               => $poitem,
            'prno'                 => (string) $get($row, 'PRNO'),
            'salordno'             => $salord,
            'style'                => (string) $get($row, 'STYLE'),
            'material'             => (string) $get($row, 'MATERIAL'),
            'matgroup'             => (string) $get($row, 'MATGROUP'),
            'so_qty'               => $this->num($get($row, 'SO_QTY')),
            'prwise_so_qty'        => $this->num($get($row, 'PRWISE_SO_QTY')),
            'consumption'          => $this->num($get($row, 'CONSUMPTION')),
            'reqqty'               => $this->num($get($row, 'REQQTY')),
            'tot_poqty'            => $this->num($get($row, 'TOT_POQTY')),
            'so_wise_poqty'        => $this->num($get($row, 'SO_WISE_POQTY')),
            'so_wise_po_val'       => $this->num($get($row, 'SO_WISE_PO_VAL')),
            'ouom'                 => (string) $get($row, 'OUOM'),
            'porate'               => $this->num($get($row, 'PORATE')),
            'totvalue'             => $this->num($get($row, 'TOTVALUE')),
            'mat_desc'             => (string) $get($row, 'MAT_DESC', 'mat_desc'),
            'po_currency'          => (string) $get($row, 'PO_CURRENCY'),
            'budgeted_cons'        => $this->num($get($row, 'BUDGETED_CONS')),
            'budget_inr'           => $this->num($get($row, 'BUDGET_INR')),
            'budget_currency'      => (string) $get($row, 'BUDGET_CURRENCY'),
            'budget_conv_rt'       => $this->num($get($row, 'BUDGET_CONV_RT')),
            'budget_cost_per_gmt'  => $budget,
            'conv'                 => $this->num($get($row, 'CONV')),
            'buom'                 => (string) $get($row, 'BUOM'),
            'actual_cost'          => $actual,
            'cost_item'            => '',
            'maktx'                => '',
            'cost_matrix'          => ($budget !== null || $actual !== null)
                ? [
                    'budget' => $budget,
                    'actual' => $actual,
                    'status' => $status,
                ]
                : null,
            'variance_pct'         => $variancePct,
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
        ];
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
