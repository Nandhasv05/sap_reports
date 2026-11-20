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
        // SAP pricing conditions (per PO line) — summed into PDF-style charges
        'CONDOTHR', 'CONDFRB1', 'CONDFRB2', 'CONDZB00', 'CONDZSCI', 'CONDZBCR',
    ];

    /**
     * SAP condition columns on each PO line → pricing charge labels (classic PDF / PASSPO).
     * CondFRB1 + CondFRB2 both roll into Freight.
     *
     * @var array<string, array{label:string, keys:list<string>}>
     */
    public const SAP_CONDITION_CHARGES = [
        'other'  => ['label' => 'Other Charges',  'keys' => ['CONDOTHR', 'COND_OTHR', 'OTHR']],
        'freight'=> ['label' => 'Freight',        'keys' => ['CONDFRB1', 'CONDFRB2', 'COND_FRB1', 'COND_FRB2', 'FRB1', 'FRB2']],
        'moq'    => ['label' => 'MOQ Charges',    'keys' => ['CONDZB00', 'COND_ZB00', 'ZB00']],
        'screen' => ['label' => 'Screen Charges', 'keys' => ['CONDZSCI', 'COND_ZSCI', 'ZSCI']],
        'bank'   => ['label' => 'Bank Charges',   'keys' => ['CONDZBCR', 'COND_ZBCR', 'ZBCR']],
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
     * SAP/PASSPO pricing charges only (MOQ CondZB00, Freight, etc.) — for PO Value Breakdown.
     *
     * @return array{success:bool,charges:array<string,mixed>,sub_total:float,grand_total:float,source:string,error:?string}
     */
    public function chargesForPurchaseOrder(string $purchaseOrder): array
    {
        $empty = [
            'other' => 0.0, 'freight' => 0.0, 'moq' => 0.0, 'screen' => 0.0, 'bank' => 0.0,
            'discount' => 0.0, 'sgst' => 0.0, 'cgst' => 0.0, 'igst' => 0.0, 'total' => 0.0, 'lines' => [],
        ];
        $res = $this->fetchForPurchaseOrder($purchaseOrder, 'all');
        if (empty($res['success'])) {
            return [
                'success'     => false,
                'charges'     => $empty,
                'sub_total'   => 0.0,
                'grand_total' => 0.0,
                'source'      => 'TEMPFIN/PASSPO',
                'error'       => (string) ($res['error'] ?? 'TEMPFIN/PASSPO charges unavailable'),
            ];
        }
        $summary = is_array($res['summary'] ?? null) ? $res['summary'] : [];
        $charges = is_array($summary['charges'] ?? null) ? $summary['charges'] : $empty;
        return [
            'success'     => true,
            'charges'     => $charges,
            'sub_total'   => (float) ($summary['sub_total'] ?? $summary['total_po_value'] ?? 0),
            'grand_total' => (float) ($summary['grand_total'] ?? 0),
            'source'      => 'TEMPFIN/PASSPO',
            'error'       => null,
        ];
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
            return $this->fail($this->connectError ?? 'EVOLVBI connection unavailable.');
        }

        $matType = strtoupper(trim($matType));
        if ($matType === 'FABRIC' || $matType === 'TRIMS') {
            $isFabric = $matType === 'FABRIC';
        } else {
            $isFabric = $this->isFabricPo($po);
        }

        try {
            // Classic portal: PO_SO_VALIDATION_* fills TEMPFIN; grid lines come from that dump
            // (or SP result sets). PASSPO alone returns MATGROUP rollups (QR LABEL / TOTAL) —
            // not POITEM detail lines — which caused the workbench to show 1–6 instead of 8.
            $rows = $this->populateAndFetchDetailRows($po, $isFabric);
            if ($rows === []) {
                $rows = $this->fetchTempfinTableRows($po);
            }
            if ($rows === []) {
                // Last resort: PASSPO per SO (may only yield matgroup summaries)
                $salesOrders = $this->salesOrdersForPo($po, $isFabric);
                if ($salesOrders === []) {
                    $rows = $this->fetchPassPoRows($po, '');
                } else {
                    $rows = [];
                    $seen = [];
                    foreach ($salesOrders as $so) {
                        foreach ($this->fetchPassPoRows($po, $so) as $row) {
                            $ponoKey = strtoupper(trim((string) ($row['PONO'] ?? '')));
                            $itemKey = ltrim(preg_replace('/\D/', '', (string) ($row['POITEM'] ?? '')) ?? '', '0');
                            $isNumericPo = $ponoKey !== '' && ctype_digit(preg_replace('/\D/', '', $ponoKey) ?? '');
                            if ($isNumericPo && $itemKey !== '') {
                                $key = implode('|', [
                                    preg_replace('/\D/', '', $ponoKey) ?? '',
                                    $itemKey,
                                    ltrim(preg_replace('/\D/', '', (string) ($row['SALORDNO'] ?? '')) ?? '', '0'),
                                    trim((string) ($row['MATERIAL'] ?? '')),
                                    trim((string) ($row['STYLE'] ?? '')),
                                    (string) ($row['TOT_POQTY'] ?? ''),
                                    (string) ($row['PORATE'] ?? ''),
                                ]);
                            } else {
                                $key = implode('|', [
                                    $ponoKey,
                                    (string) ($row['POITEM'] ?? ''),
                                    (string) ($row['SALORDNO'] ?? ''),
                                    (string) ($row['MATERIAL'] ?? ''),
                                    (string) ($row['STYLE'] ?? ''),
                                ]);
                            }
                            if (isset($seen[$key])) {
                                continue;
                            }
                            $seen[$key] = true;
                            $rows[] = $row;
                        }
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

        $summary = $this->summarize($detailOnly);
        $charges = $this->summarizeCharges($allRows);
        $summary['charges'] = $charges;
        $summary['sub_total'] = (float) ($summary['total_po_value'] ?? 0);
        $summary['charges_total'] = (float) ($charges['total'] ?? 0);
        $summary['grand_total'] = round(
            (float) ($summary['sub_total'] ?? 0) + (float) ($summary['charges_total'] ?? 0),
            2
        );

        return [
            'success'  => true,
            'records'  => $allRows,
            'summary'  => $summary,
            'mat_type' => $isFabric ? 'fabric' : 'trims',
            'error'    => null,
        ];
    }

    private function connect(): ?PDO
    {
        // Classic PO Costing Validation uses EVOLVBI (PASSPO / PO_SO_VALIDATION_Trims / TEMPFIN).
        // CLIENT_API_LIVE does not host those procedures — connecting there always fails SP 2812
        // and the workbench falls back to CDS with incomplete line counts.
        $server = '192.168.9.2';
        $dbName = 'EVOLVBI';
        $user = 'sa';
        $password = 'Evolv123@';
        try {
            $dsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$server};Database={$dbName};Encrypt=no;TrustServerCertificate=yes;MARS_Connection=yes;Connection Timeout=30;";
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

    /**
     * Run classic validation SP, prefer the POITEM detail result set, else empty.
     *
     * @return list<array<string,mixed>>
     */
    private function populateAndFetchDetailRows(string $po, bool $isFabric): array
    {
        // Classic portal uses PO_SO_VALIDATION_Trims (case-sensitive collations reject PO_so_validation_Trims).
        $candidates = $isFabric
            ? [
                "DECLARE @ErrMsg VARCHAR(500); DECLARE @POStatus VARCHAR(50); EXEC [dbo].[PO_so_validation_Fabric] ?, @ErrMsg OUTPUT, @POStatus OUTPUT;",
                "DECLARE @ErrMsg VARCHAR(500); DECLARE @POStatus VARCHAR(50); EXEC [dbo].[PO_SO_VALIDATION_Fabric] ?, @ErrMsg OUTPUT, @POStatus OUTPUT;",
            ]
            : [
                "DECLARE @ErrMsg VARCHAR(500); DECLARE @POStatus VARCHAR(50); EXEC [dbo].[PO_SO_VALIDATION_Trims] ?, @ErrMsg OUTPUT, @POStatus OUTPUT;",
                "DECLARE @ErrMsg VARCHAR(500); DECLARE @POStatus VARCHAR(50); EXEC [dbo].[PO_so_validation_Trims] ?, @ErrMsg OUTPUT, @POStatus OUTPUT;",
            ];

        $last = null;
        foreach ($candidates as $sql) {
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute([$po]);
                $chunks = [];
                do {
                    $chunk = $st->fetchAll(PDO::FETCH_ASSOC);
                    if ($chunk !== []) {
                        $chunks[] = $chunk;
                    }
                } while ($st->nextRowset());
                $st->closeCursor();

                $best = $this->pickBestDetailChunk($chunks);
                if ($best !== []) {
                    return $this->upperKeysRows($best);
                }
                return [];
            } catch (Throwable $e) {
                $last = $e;
                // Try next spelling (2812 = procedure not found)
                if (!preg_match('/2812|Could not find stored procedure/i', $e->getMessage())) {
                    throw $e;
                }
            }
        }
        if ($last instanceof Throwable) {
            throw $last;
        }
        return [];
    }

    /**
     * @param list<list<array<string,mixed>>> $chunks
     * @return list<array<string,mixed>>
     */
    private function pickBestDetailChunk(array $chunks): array
    {
        $best = [];
        $bestScore = -1;
        foreach ($chunks as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $score = $this->scoreDetailChunk($chunk);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $chunk;
            }
        }
        // Require a real detail-looking grid (numeric PONO + POITEM), not status/matgroup rollups.
        return $bestScore >= 500000 ? $best : [];
    }

    /**
     * @param list<array<string,mixed>> $chunk
     */
    private function scoreDetailChunk(array $chunk): int
    {
        $nrow = count($chunk);
        $ncol = count($chunk[0]);
        $score = $nrow * max(100, $ncol);
        $row1 = [];
        foreach ($chunk[0] as $k => $v) {
            $row1[strtoupper((string) $k)] = $v;
        }
        foreach (['MATGROUP', 'MATERIAL', 'STYLE', 'PONO', 'POITEM'] as $k) {
            if (array_key_exists($k, $row1)) {
                $score += 500000;
                break;
            }
        }
        $hasNumericPo = false;
        $hasPoItem = false;
        foreach ($chunk as $row) {
            $u = [];
            foreach ($row as $k => $v) {
                $u[strtoupper((string) $k)] = $v;
            }
            $pono = strtoupper(trim((string) ($u['PONO'] ?? '')));
            if (preg_match('/^\d+$/', $pono)) {
                $hasNumericPo = true;
                $score += 2000000;
            }
            $item = trim((string) ($u['POITEM'] ?? ''));
            if ($item !== '' && preg_match('/\d/', $item)) {
                $hasPoItem = true;
                $score += 1000000;
            }
            if ($hasNumericPo && $hasPoItem) {
                break;
            }
        }
        if ($nrow === 1 && !isset($row1['POITEM']) && !isset($row1['REQQTY']) && !isset($row1['TOT_POQTY'])) {
            $score -= 400000;
        }
        // PASSPO-style MATGROUP-only rollups (no POITEM) must lose to TEMPFIN detail.
        if (!$hasPoItem) {
            $score -= 3000000;
        }
        return $score;
    }

    /**
     * Read detail lines from TEMPFIN after validation SP populate.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchTempfinTableRows(string $po): array
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT * FROM TEMPFIN WHERE LTRIM(RTRIM(CAST(PONO AS VARCHAR(30)))) = ? ORDER BY POITEM, SALORDNO, MATERIAL'
            );
            $st->execute([$po]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $this->upperKeysRows($rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function upperKeysRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $raw) {
            $row = [];
            foreach ($raw as $k => $v) {
                $row[strtoupper((string) $k)] = $v;
            }
            if (!isset($row['MAT_DESC']) && isset($raw['mat_desc'])) {
                $row['MAT_DESC'] = $raw['mat_desc'];
            }
            $out[] = $row;
        }
        return $out;
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
        $poitemRaw = trim((string) $get($row, 'POITEM'));
        $materialRaw = trim((string) $get($row, 'MATERIAL'));
        $rowKind = $this->detectRowKind($ponoUpper, $matgroupUpper, $poitemRaw, $materialRaw);

        // Classic TEMPFIN numeric fields (align with PO Costing Validation)
        $soQty = $this->num($get($row, 'SO_QTY'));
        $prSoQty = $this->num($get($row, 'PRWISE_SO_QTY'));
        $consumption = $this->num($get($row, 'CONSUMPTION'));
        $reqqty = $this->num($get($row, 'REQQTY'));
        $totPoQty = $this->num($get($row, 'TOT_POQTY'));
        $porate = $this->num($get($row, 'PORATE'));
        $totValueRaw = $this->num($get($row, 'TOTVALUE'));
        // TEMPFIN sometimes sends PORATE=0 while TOTVALUE is filled — derive unit rate
        if (($porate === null || $porate <= 0) && $totValueRaw !== null && $totValueRaw > 0
            && $totPoQty !== null && $totPoQty > 0) {
            $porate = round($totValueRaw / $totPoQty, 4);
        }
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

            // Unit CONV must not copy FX rate (BUDGET_CONV_RT) — that double-converts / inflates
            if ($conv === null || $conv <= 0) {
                $aconv = 1.0;
            }
            if ($budgetConv !== null && $budgetConv > 0 && abs((float) $conv - (float) $budgetConv) < 0.0001) {
                $conv = 1.0;
            } elseif ($poCurrency === 'INR' && (float) $conv > 20) {
                $conv = 1.0;
            }

            // TEMPFIN/PASSPO PORATE and ACTUAL_COST are already INR (USD×Curr Rate applied upstream).
            // Never multiply by BUDGET_CONV_RT again — that double-converts (e.g. 316.72 × 87 → 27554).
            $consForActual = ($consumption !== null && $consumption > 0) ? $consumption : 1.0;
            $classicActual = $this->computeClassicActualCost(
                $porate,
                $conv,
                $consForActual,
                1.0, // FX already baked into TEMPFIN PORATE
                $matgroupUpper,
                $ouom,
                $buom,
                $matDesc,
                'INR'
            );

            if ($tempfinActual !== null && $tempfinActual > 0 && !$fixedBadCons) {
                // Prefer PASSPO ACTUAL as-is (already INR)
                $actual = $tempfinActual;
            } elseif ($classicActual !== null) {
                $actual = $classicActual;
            } elseif ($porate !== null) {
                $actual = $porate;
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

        // ACTUAL_COST is always INR (TEMPFIN PORATE already includes Curr Rate)
        $shownActual = $actual ?? $porate;

        $status = 'EXCEPTION';
        $result = 'No budget / actual cost';
        $variancePct = null;
        // Classic UI: green when actual < budget − 0.005; red when actual > budget + 0.005
        $tol = 0.005;
        if ($rowKind !== 'detail') {
            $status = 'FOOTER';
            $result = $ponoUpper !== '' ? $ponoUpper : ($matgroupUpper !== '' ? $matgroupUpper : 'FOOTER');
        } elseif ($budget !== null && $budget > 0 && $shownActual !== null) {
            $variancePct = round((($shownActual - $budget) / $budget) * 100, 2);
            if ($shownActual <= $budget + $tol) {
                $status = 'PASS';
                $result = 'PASS — actual cost within budget';
            } else {
                $status = 'EXCEPTION';
                $result = 'EXCEPTION — actual cost exceeds budget';
            }
        } elseif ($rowKind === 'detail' && ($budget === null || $budget <= 0) && $shownActual !== null) {
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
            && $shownActual !== null
            && (float) $shownActual > ((float) $budget + 0.005);

        $matDescRaw = (string) $get($row, 'MAT_DESC', 'mat_desc');
        $materialCode = (string) $get($row, 'MATERIAL');
        $costItem = trim((string) $get($row, 'ITEM', 'CostingItemNo', 'COSTING_ITEM_NO'));
        $maktx = trim((string) $get($row, 'MAKTX', 'maktx', 'COST_DESC', 'COSTING_DESC'));
        $zsection = trim((string) $get($row, 'ZSECTION', 'SECTION'));
        $quoteNo = trim((string) $get($row, 'CostingQuotationNo', 'COSTING_QUOTATION_NO', 'QUOTATIONNO'));

        // PASSPO sometimes links wrong zCOST ITEM
        // (BUTTON→ELASTIC, HANGTAG→CARE & CONTENT, drawcord→STICKER). Reject and force rematch.
        $familyMismatch = false;
        $poText = $matgroupUpper . ' ' . $matDescRaw . ' ' . $materialCode;
        $costText = $costItem . ' ' . $maktx . ' ' . $zsection;
        $poFamily = $this->trimFamily($poText);
        $costFamily = $this->trimFamily($costText);
        $poKind = $this->labelKind($poText);
        $costKind = $this->labelKind($costText);
        $tokenOk = $this->poCostTokensOverlap($poText, $costText);
        $kindClash = $poKind !== '' && $costKind !== ''
            && $poKind !== $costKind
            && $poKind !== 'LABEL'
            && $costKind !== 'LABEL';
        $hasCostLink = $costItem !== '' || $maktx !== '';
        // Same LABEL family is not enough — HANGTAG must not keep CARE & CONTENT
        $sameFamilyNoProof = $poFamily !== '' && $costFamily !== '' && $poFamily === $costFamily
            && !$tokenOk
            && ($poKind === '' || $costKind === '' || $poKind !== $costKind);
        if ($rowKind === 'detail' && $hasCostLink && (
            ($poFamily !== '' && $costFamily !== '' && $poFamily !== $costFamily)
            || $kindClash
            || $sameFamilyNoProof
            || ($costFamily !== '' && $poFamily === '' && !$tokenOk)
            || ($poFamily !== '' && $costFamily === '' && !$tokenOk)
            || ($poFamily === '' && $costFamily === '' && !$tokenOk && strlen($costItem) >= 4)
        )) {
            $familyMismatch = true;
            $status = 'NEEDS_REVIEW';
            $result = 'NEEDS REVIEW — zCOST "'
                . ($costKind !== '' ? $costKind : ($costFamily !== '' ? $costFamily : $maktx))
                . '" does not match PO MATGROUP/MAT_DESC "'
                . trim((string) $get($row, 'MATGROUP')) . ' / ' . $materialCode
                . '" (' . ($poKind !== '' ? $poKind : ($poFamily !== '' ? $poFamily : 'unclassified')) . ') — select matching line';
            $costItem = '';
            $maktx = '';
            $zsection = '';
        }

        $needsManual = $rowKind === 'detail' && (
            $familyMismatch
            || $budget === null
            || $budget <= 0
            || $shownActual === null
            || ($hasCostLink === false && $poFamily !== '')
        );

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
            'totvalue'             => $totValueRaw ?? $this->num($get($row, 'TOTVALUE')),
            'mat_desc'             => $matDescRaw,
            'po_currency'          => $poCurrency !== '' ? $poCurrency : (string) $get($row, 'PO_CURRENCY'),
            'budgeted_cons'        => $budgetedCons,
            'budget_inr'           => $budgetInr,
            'budget_currency'      => (string) $get($row, 'BUDGET_CURRENCY'),
            'budget_conv_rt'       => $budgetConv,
            'budget_cost_per_gmt'  => $budget,
            'conv'                 => $conv,
            'buom'                 => $buom !== '' ? $buom : (string) $get($row, 'BUOM'),
            'actual_cost'          => $shownActual,
            // SAP pricing conditions (CondOTHR / CondFRB* / CondZB00 / CondZSCI / CondZBCR)
            'cond_othr'            => $this->num($get($row, 'CONDOTHR', 'COND_OTHR', 'OTHR')),
            'cond_frb1'            => $this->num($get($row, 'CONDFRB1', 'COND_FRB1', 'FRB1')),
            'cond_frb2'            => $this->num($get($row, 'CONDFRB2', 'COND_FRB2', 'FRB2')),
            'cond_zb00'            => $this->num($get($row, 'CONDZB00', 'COND_ZB00', 'ZB00')),
            'cond_zsci'            => $this->num($get($row, 'CONDZSCI', 'COND_ZSCI', 'ZSCI')),
            'cond_zbcr'            => $this->num($get($row, 'CONDZBCR', 'COND_ZBCR', 'ZBCR')),
            // Lock TEMPFIN budget/actual/consumption so zCOST match cannot overwrite Excel values
            // Lock whenever PASSPO supplied a real value — zCOST match must not overwrite
            'tempfin_budget_locked'=> $rowKind === 'detail' && $budget !== null && (float) $budget > 0,
            'tempfin_actual_locked'=> $rowKind === 'detail' && $shownActual !== null && (float) $shownActual > 0,
            'tempfin_cons_locked'  => $rowKind === 'detail' && $consumption !== null && $consumption > 0,
            // PORATE from TEMPFIN is already INR — client must not × Curr Rate again
            'porate_already_inr'   => $rowKind === 'detail',
            'cost_item'            => $costItem,
            'zcost_item'           => $costItem,
            'costing_quotation_no' => $quoteNo,
            'costing_item_no'      => $costItem,
            'zsection'             => $zsection,
            'maktx'                => $maktx,
            'cost_description'     => $maktx,
            'family_mismatch'      => $familyMismatch,
            'cost_matrix'          => ($budget !== null || $shownActual !== null)
                ? [
                    'budget' => $budget,
                    'actual' => $shownActual,
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
            'material_desc'        => $matDescRaw,
            'material_group'       => (string) $get($row, 'MATGROUP'),
            'order_qty'            => $this->num($get($row, 'TOT_POQTY', 'SO_WISE_POQTY')),
            'po_qty'               => $this->num($get($row, 'TOT_POQTY', 'SO_WISE_POQTY')),
            'unit'                 => (string) $get($row, 'OUOM', 'BUOM'),
            'net_value'            => $this->num($get($row, 'TOTVALUE', 'SO_WISE_PO_VAL')),
            'currency'             => (string) $get($row, 'PO_CURRENCY') ?: 'INR',
            'po_unit_cost'         => $shownActual,
            'expected_unit_cost'   => $budget,
            'status'               => $status,
            'validation_result'    => $result,
            'needs_manual_match'   => $needsManual,
            'selected'             => false,
        ];
    }

    /**
     * Coarse trim family for mismatch checks (BUTTON vs ELASTIC, CORD vs STICKER, etc.).
     * Also reads embedded material-code tokens (e.g. 20DRCCTNDRANTL → CORD).
     */
    private function trimFamily(string $text): string
    {
        $t = strtoupper(preg_replace('/[^A-Z0-9]+/', ' ', trim($text)) ?? '');
        $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
        if ($t === '') {
            return '';
        }
        // Material-code shortcuts first (20DRCCTNDRANTL… = drawcord)
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
        if (preg_match('/\b(POLYBAG|POLY\s*BAG|BAG)\b/', $t) || str_contains($t, 'POLYBAG')) {
            return 'BAG';
        }
        return '';
    }

    /**
     * Finer LABEL kinds so HANGTAG never auto-matches CARE & CONTENT / WASH CARE.
     */
    private function labelKind(string $text): string
    {
        $t = strtoupper(preg_replace('/[^A-Z0-9&]+/', ' ', trim($text)) ?? '');
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
        if (preg_match('/SIZE\s*LBL|SIZELBL|SIZE\s*LABEL|\bLSL\b|T[\s\-]?LSL/', $t)) {
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
     * True when PO material/desc and zCOST item/desc share a meaningful token.
     */
    private function poCostTokensOverlap(string $poText, string $costText): bool
    {
        $norm = static function (string $s): array {
            $s = strtoupper(preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '');
            $parts = preg_split('/\s+/', trim($s)) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if (strlen($p) < 3) {
                    continue;
                }
                // Skip generic noise
                if (in_array($p, ['THE', 'AND', 'FOR', 'WITH', 'PCS', 'YDS', 'USD', 'INR', 'ACCESSOR', 'TRIM', 'TRIMS', 'PACKING', 'OTHERS'], true)) {
                    continue;
                }
                $out[] = $p;
            }
            return array_values(array_unique($out));
        };
        $a = $norm($poText);
        $b = $norm($costText);
        if ($a === [] || $b === []) {
            return false;
        }
        if (array_intersect($a, $b) !== []) {
            return true;
        }
        // Partial contains (e.g. DRCCTNDRANTL vs DRC)
        foreach ($a as $pa) {
            foreach ($b as $pb) {
                if (strlen($pa) >= 4 && strlen($pb) >= 4 && (str_contains($pa, $pb) || str_contains($pb, $pa))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function detectRowKind(
        string $ponoUpper,
        string $matgroupUpper,
        string $poitem = '',
        string $material = ''
    ): string {
        $itemDigits = preg_replace('/\D/', '', $poitem) ?? '';
        $ponoDigits = preg_replace('/\D/', '', $ponoUpper) ?? '';
        $hasMaterial = trim($material) !== '';
        // Real PASSPO line item: numeric PO plus (POITEM and/or MATERIAL).
        // Never treat these as charge/tax — MATGROUP words like SCREEN/FREIGHT/BANK/MOQ
        // are normal trim groups and were incorrectly hiding lines (8 API → 6 UI).
        if ($ponoDigits !== '' && ($itemDigits !== '' || $hasMaterial)) {
            return 'detail';
        }

        $label = $ponoUpper !== '' ? $ponoUpper : $matgroupUpper;
        if ($label === '') {
            return 'detail';
        }
        $labelCompact = strtoupper(preg_replace('/[\s\-]+/', '', $label) ?? $label);
        if (in_array($labelCompact, ['SUBTOTAL', 'SUBTOT', 'TOTAL'], true)
            || in_array($label, ['SUB-TOTAL', 'SUB TOTAL', 'SUBTOTAL', 'TOTAL'], true)) {
            return 'footer';
        }
        if (preg_match('/^(IGST|SGST|CGST|GST|TAX)(\s|$)/', $label)) {
            return 'tax';
        }
        // Charge footers use a text label in PONO (not a numeric PO number)
        if ($ponoDigits === '' && (
            str_contains($label, 'CHARGES')
            || preg_match('/\b(other\s*charges|freight|moq|screen\s*print|bank\s*charges)\b/i', $label)
        )) {
            return 'charge';
        }
        return 'detail';
    }

    /**
     * Roll SAP Cond* line amounts (and classic PASSPO charge footer rows) into PDF-style charges.
     *
     * @param list<array<string,mixed>> $records
     * @return array{
     *   other:float,freight:float,moq:float,screen:float,bank:float,
     *   discount:float,sgst:float,cgst:float,igst:float,
     *   total:float,lines:list<array{key:string,label:string,amount:float,sap:string}>
     * }
     */
    private function summarizeCharges(array $records): array
    {
        $out = [
            'other' => 0.0,
            'freight' => 0.0,
            'moq' => 0.0,
            'screen' => 0.0,
            'bank' => 0.0,
            'discount' => 0.0,
            'sgst' => 0.0,
            'cgst' => 0.0,
            'igst' => 0.0,
            'total' => 0.0,
            'lines' => [],
        ];
        $seenItem = [];
        $cond = ['other' => 0.0, 'freight' => 0.0, 'moq' => 0.0, 'screen' => 0.0, 'bank' => 0.0];
        $footer = [
            'other' => 0.0, 'freight' => 0.0, 'moq' => 0.0, 'screen' => 0.0, 'bank' => 0.0,
            'discount' => 0.0, 'sgst' => 0.0, 'cgst' => 0.0, 'igst' => 0.0,
        ];

        foreach ($records as $r) {
            $kind = (string) ($r['row_kind'] ?? 'detail');
            if ($kind === 'detail') {
                $item = trim((string) ($r['poitem'] ?? $r['po_item'] ?? ''));
                $seenKey = $item !== ''
                    ? $item
                    : ('__' . trim((string) ($r['material'] ?? '')) . '|' . trim((string) ($r['salordno'] ?? '')));
                if (isset($seenItem[$seenKey])) {
                    continue;
                }
                $seenItem[$seenKey] = true;

                $cond['other'] += $this->num($r['cond_othr'] ?? null) ?? 0.0;
                $cond['freight'] += ($this->num($r['cond_frb1'] ?? null) ?? 0.0)
                    + ($this->num($r['cond_frb2'] ?? null) ?? 0.0);
                $cond['moq'] += $this->num($r['cond_zb00'] ?? null) ?? 0.0;
                $cond['screen'] += $this->num($r['cond_zsci'] ?? null) ?? 0.0;
                $cond['bank'] += $this->num($r['cond_zbcr'] ?? null) ?? 0.0;
                continue;
            }

            // Classic PASSPO charge / tax footer rows (fallback when Cond* blank)
            $label = strtoupper(trim(preg_replace(
                '/\s+/',
                ' ',
                (string) ($r['pono'] ?? $r['matgroup'] ?? $r['validation_result'] ?? '')
            ) ?? ''));
            $amt = $this->num(
                $r['so_wise_po_val']
                ?? $r['totvalue']
                ?? $r['net_value']
                ?? $r['budget_inr']
                ?? $r['actual_cost']
                ?? $r['po_unit_cost']
                ?? $r['porate']
                ?? null
            ) ?? 0.0;
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

        // Per-bucket max: Cond* may miss MOQ while PASSPO footer has it (and vice versa)
        foreach (['other', 'freight', 'moq', 'screen', 'bank'] as $k) {
            $out[$k] = max((float) ($cond[$k] ?? 0), (float) ($footer[$k] ?? 0));
        }
        // Tax / discount always from footer labels when present
        foreach (['discount', 'sgst', 'cgst', 'igst'] as $k) {
            $out[$k] = (float) ($footer[$k] ?? 0);
        }

        foreach (['other', 'freight', 'moq', 'screen', 'bank', 'discount', 'sgst', 'cgst', 'igst'] as $k) {
            $out[$k] = round((float) $out[$k], 2);
        }
        $out['total'] = round(
            $out['other'] + $out['freight'] + $out['moq'] + $out['screen'] + $out['bank']
            + $out['sgst'] + $out['cgst'] + $out['igst'] - $out['discount'],
            2
        );
        $out['lines'] = [
            ['key' => 'other',   'label' => 'Other Charges',  'amount' => $out['other'],   'sap' => 'CondOTHR'],
            ['key' => 'freight', 'label' => 'Freight',        'amount' => $out['freight'], 'sap' => 'CondFRB1 / CondFRB2'],
            ['key' => 'moq',     'label' => 'MOQ Charges',    'amount' => $out['moq'],     'sap' => 'CondZB00'],
            ['key' => 'screen',  'label' => 'Screen Charges', 'amount' => $out['screen'],  'sap' => 'CondZSCI'],
            ['key' => 'bank',    'label' => 'Bank Charges',   'amount' => $out['bank'],    'sap' => 'CondZBCR'],
            ['key' => 'discount','label' => 'Discount',       'amount' => $out['discount'],'sap' => ''],
            ['key' => 'sgst',    'label' => 'SGST',           'amount' => $out['sgst'],    'sap' => ''],
            ['key' => 'cgst',    'label' => 'CGST',           'amount' => $out['cgst'],    'sap' => ''],
            ['key' => 'igst',    'label' => 'IGST',           'amount' => $out['igst'],    'sap' => ''],
        ];

        return $out;
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
            $poQty = (float) ($r['tot_poqty'] ?? $r['order_qty'] ?? $r['po_qty'] ?? 0);
            $soQty = (float) ($r['so_qty'] ?? $r['prwise_so_qty'] ?? 0);
            $poRate = $r['porate'] ?? $r['po_unit_cost'] ?? $r['net_price'] ?? null;
            $poRate = ($poRate !== null && $poRate !== '') ? (float) $poRate : null;
            $poLineNet = ($poRate !== null && $poQty > 0)
                ? ($poRate * $poQty)
                : (float) ($r['totvalue'] ?? $r['net_value'] ?? $r['so_wise_po_val'] ?? 0);
            $budget = $r['budget_cost_per_gmt'] ?? $r['expected_unit_cost'] ?? null;
            $actual = $r['porate'] ?? $r['actual_cost'] ?? $r['po_unit_cost'] ?? null;
            $garmentQty = $soQty > 0 ? $soQty : ($poQty > 0 ? $poQty : 1.0);
            if ($item !== '' && !isset($seenValueItems[$item])) {
                $seenValueItems[$item] = true;
                $totalValue += $poLineNet;
                $totalOrderQty += $poQty;
                if ($budget !== null && $budget !== '') {
                    $totalExpected += (float) $budget * $garmentQty;
                }
                if ($actual !== null && $actual !== '') {
                    $totalActual += (float) $actual * $garmentQty;
                }
            } elseif ($item === '') {
                $totalValue += $poLineNet;
                $totalOrderQty += $poQty;
                if ($budget !== null && $budget !== '') {
                    $totalExpected += (float) $budget * $garmentQty;
                }
                if ($actual !== null && $actual !== '') {
                    $totalActual += (float) $actual * $garmentQty;
                }
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
        // CONV must never carry FX rate
        if ($currency !== 'INR' && abs($conv - $fx) < 0.0001) {
            $conv = 1.0;
        } elseif ($currency === 'INR' && $conv > 20) {
            $conv = 1.0;
        }

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