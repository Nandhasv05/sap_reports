<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 11/11/2026
 * DESCRIPTION : Persist Manual Map Costing so all users see the same mapping for a PO
 */
require_once base_path('app/core/Database.php');

class PoManualCostingMapService
{
    private const TABLE = 'po_manual_costing_map';

    /** @var bool|null */
    private static ?bool $tableReady = null;

    public function tableExists(?PDO $pdo = null): bool
    {
        if (self::$tableReady === true) {
            return true;
        }
        try {
            $pdo = $pdo ?? Database::connection();
            $stmt = $pdo->query(
                "SELECT CASE WHEN OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL THEN 0 ELSE 1 END AS ok"
            );
            $ok = (int) ($stmt->fetchColumn() ?: 0) === 1;
            self::$tableReady = $ok;
            return $ok;
        } catch (Throwable $e) {
            self::$tableReady = false;
            return false;
        }
    }

    public function ensureTable(): void
    {
        try {
            $pdo = Database::connection();
            if ($this->tableExists($pdo)) {
                return;
            }
            try {
                $pdo->exec("
                    IF OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL
                    BEGIN
                        CREATE TABLE [dbo].[" . self::TABLE . "] (
                            [id] BIGINT IDENTITY(1,1) NOT NULL,
                            [purchase_order] NVARCHAR(20) NOT NULL,
                            [po_item] NVARCHAR(20) NOT NULL,
                            [material] NVARCHAR(100) NULL,
                            [mat_cat] NVARCHAR(20) NULL,
                            [quotation_no] NVARCHAR(40) NULL,
                            [zcost_item] NVARCHAR(100) NULL,
                            [maktx] NVARCHAR(255) NULL,
                            [zsection] NVARCHAR(80) NULL,
                            [total_inr] DECIMAL(18,6) NULL,
                            [gross] DECIMAL(18,6) NULL,
                            [currency] NVARCHAR(10) NULL,
                            [convr] DECIMAL(18,6) NULL,
                            [unit] NVARCHAR(20) NULL,
                            [line_no] NVARCHAR(40) NULL,
                            [matched_line_key] NVARCHAR(120) NULL,
                            [payload_json] NVARCHAR(MAX) NULL,
                            [created_by] NVARCHAR(100) NULL,
                            [updated_by] NVARCHAR(100) NULL,
                            [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_po_manual_costing_map_created] DEFAULT SYSUTCDATETIME(),
                            [updated_at] DATETIME2 NOT NULL CONSTRAINT [DF_po_manual_costing_map_updated] DEFAULT SYSUTCDATETIME(),
                            CONSTRAINT [PK_po_manual_costing_map] PRIMARY KEY CLUSTERED ([id])
                        );
                        CREATE UNIQUE NONCLUSTERED INDEX [UX_po_manual_costing_map_po_item_mat]
                            ON [dbo].[" . self::TABLE . "] ([purchase_order], [po_item], [material]);
                    END
                ");
                self::$tableReady = null;
                $this->tableExists($pdo);
            } catch (Throwable $createErr) {
                error_log('PoManualCostingMapService ensureTable: ' . $createErr->getMessage());
                self::$tableReady = false;
            }
        } catch (Throwable $e) {
            error_log('PoManualCostingMapService ensureTable connect: ' . $e->getMessage());
            self::$tableReady = false;
        }
    }

    private function displayPo(string $po): string
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $display = ltrim($digits, '0');
        return $display !== '' ? $display : ($digits !== '' ? $digits : '');
    }

    private function normItem(string $item): string
    {
        $digits = preg_replace('/\D/', '', $item) ?? '';
        $n = ltrim($digits, '0');
        return $n !== '' ? $n : ($digits !== '' ? $digits : trim($item));
    }

    private function mapKey(string $poItem, string $material): string
    {
        return strtolower($this->normItem($poItem) . '|' . trim($material));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{success:bool,id:?int,error:?string}
     */
    public function upsert(array $payload): array
    {
        $this->ensureTable();
        if (!$this->tableExists()) {
            return [
                'success' => false,
                'id' => null,
                'error' => 'Table dbo.po_manual_costing_map is missing. Ask DBA to run sql/po_manual_costing_map.sql once.',
            ];
        }

        $po = $this->displayPo((string) ($payload['purchase_order'] ?? $payload['po'] ?? ''));
        $poItem = $this->normItem((string) ($payload['po_item'] ?? $payload['poitem'] ?? ''));
        $material = trim((string) ($payload['material'] ?? ''));
        if ($po === '' || $poItem === '') {
            return ['success' => false, 'id' => null, 'error' => 'PO and PO item are required.'];
        }

        $user = trim((string) ($payload['updated_by'] ?? $payload['created_by'] ?? 'system'));
        $row = [
            'purchase_order' => $po,
            'po_item' => $poItem,
            'material' => $material,
            'mat_cat' => trim((string) ($payload['mat_cat'] ?? '')),
            'quotation_no' => trim((string) ($payload['quotation_no'] ?? $payload['costing_quotation_no'] ?? '')),
            'zcost_item' => trim((string) ($payload['zcost_item'] ?? $payload['cost_item'] ?? '')),
            'maktx' => trim((string) ($payload['maktx'] ?? $payload['cost_description'] ?? '')),
            'zsection' => trim((string) ($payload['zsection'] ?? $payload['cost_section'] ?? '')),
            'total_inr' => $this->numOrNull($payload['total_inr'] ?? $payload['cost_total_inr'] ?? null),
            'gross' => $this->numOrNull($payload['gross'] ?? null),
            'currency' => trim((string) ($payload['currency'] ?? $payload['budget_currency'] ?? 'INR')),
            'convr' => $this->numOrNull($payload['convr'] ?? $payload['budget_conv_rt'] ?? null),
            'unit' => trim((string) ($payload['unit'] ?? $payload['buom'] ?? '')),
            'line_no' => trim((string) ($payload['line_no'] ?? $payload['matched_line_no'] ?? '')),
            'matched_line_key' => trim((string) ($payload['matched_line_key'] ?? '')),
            'payload_json' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => $user !== '' ? $user : 'system',
        ];

        try {
            $pdo = Database::connection();
            $existing = $pdo->prepare("
                SELECT TOP 1 id FROM [dbo].[" . self::TABLE . "]
                WHERE purchase_order = ?
                  AND po_item = ?
                  AND ISNULL(material, '') = ?
            ");
            $existing->execute([$po, $poItem, $material]);
            $id = (int) ($existing->fetchColumn() ?: 0);

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE [dbo].[" . self::TABLE . "] SET
                        mat_cat = ?, quotation_no = ?, zcost_item = ?, maktx = ?, zsection = ?,
                        total_inr = ?, gross = ?, currency = ?, convr = ?, unit = ?,
                        line_no = ?, matched_line_key = ?, payload_json = ?,
                        updated_by = ?, updated_at = SYSUTCDATETIME()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $row['mat_cat'], $row['quotation_no'], $row['zcost_item'], $row['maktx'], $row['zsection'],
                    $row['total_inr'], $row['gross'], $row['currency'], $row['convr'], $row['unit'],
                    $row['line_no'], $row['matched_line_key'], $row['payload_json'],
                    $row['updated_by'], $id,
                ]);
                return ['success' => true, 'id' => $id, 'error' => null];
            }

            $stmt = $pdo->prepare("
                INSERT INTO [dbo].[" . self::TABLE . "] (
                    purchase_order, po_item, material, mat_cat, quotation_no, zcost_item, maktx, zsection,
                    total_inr, gross, currency, convr, unit, line_no, matched_line_key, payload_json,
                    created_by, updated_by
                )
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $row['purchase_order'], $row['po_item'], $row['material'], $row['mat_cat'],
                $row['quotation_no'], $row['zcost_item'], $row['maktx'], $row['zsection'],
                $row['total_inr'], $row['gross'], $row['currency'], $row['convr'], $row['unit'],
                $row['line_no'], $row['matched_line_key'], $row['payload_json'],
                $row['updated_by'], $row['updated_by'],
            ]);
            $newId = (int) ($stmt->fetchColumn() ?: 0);
            return ['success' => true, 'id' => $newId > 0 ? $newId : null, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForPo(string $purchaseOrder): array
    {
        $this->ensureTable();
        if (!$this->tableExists()) {
            return [];
        }
        $po = $this->displayPo($purchaseOrder);
        if ($po === '') {
            return [];
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("
                SELECT *
                FROM [dbo].[" . self::TABLE . "]
                WHERE purchase_order = ?
                ORDER BY updated_at DESC, id DESC
            ");
            $stmt->execute([$po]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Apply saved manual mappings onto validate records (shared for all users).
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    public function applyToRecords(string $purchaseOrder, array $records): array
    {
        $maps = $this->listForPo($purchaseOrder);
        if ($maps === [] || $records === []) {
            return $records;
        }

        $byKey = [];
        foreach ($maps as $m) {
            $key = $this->mapKey((string) ($m['po_item'] ?? ''), (string) ($m['material'] ?? ''));
            if ($key === '|' || isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = $m;
        }

        foreach ($records as &$rec) {
            if (!empty($rec['is_footer']) || !empty($rec['_footer'])) {
                continue;
            }
            $poItem = (string) ($rec['poitem'] ?? $rec['po_item'] ?? '');
            $material = (string) ($rec['material'] ?? '');
            $key = $this->mapKey($poItem, $material);
            $m = $byKey[$key] ?? null;
            if ($m === null && $material !== '') {
                // Fallback: same PO item only when material blank on either side
                $itemOnly = strtolower($this->normItem($poItem) . '|');
                foreach ($byKey as $k => $cand) {
                    if (str_starts_with($k, $itemOnly) || $this->normItem((string) ($cand['po_item'] ?? '')) === $this->normItem($poItem)) {
                        if (strcasecmp(trim((string) ($cand['material'] ?? '')), trim($material)) === 0
                            || trim((string) ($cand['material'] ?? '')) === '') {
                            $m = $cand;
                            break;
                        }
                    }
                }
            }
            if ($m === null) {
                continue;
            }
            $rec = $this->mergeMappingIntoRecord($rec, $m);
        }
        unset($rec);

        return $records;
    }

    /**
     * @param array<string,mixed> $rec
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private function mergeMappingIntoRecord(array $rec, array $m): array
    {
        $extra = [];
        if (!empty($m['payload_json'])) {
            $decoded = json_decode((string) $m['payload_json'], true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }

        $totalInr = $this->numOrNull($m['total_inr'] ?? $extra['total_inr'] ?? null);
        $gross = $this->numOrNull($m['gross'] ?? $extra['gross'] ?? null);
        $budget = null;
        if ($totalInr !== null && $gross !== null && $gross > 0) {
            $budget = round($totalInr / $gross, 6);
        } elseif (isset($extra['budget_cost_per_gmt'])) {
            $budget = $this->numOrNull($extra['budget_cost_per_gmt']);
        }

        $zItem = trim((string) ($m['zcost_item'] ?? $extra['zcost_item'] ?? ''));
        $maktx = trim((string) ($m['maktx'] ?? $extra['maktx'] ?? ''));
        $section = trim((string) ($m['zsection'] ?? $extra['zsection'] ?? ''));
        $quote = trim((string) ($m['quotation_no'] ?? $extra['quotation_no'] ?? ''));
        $curr = trim((string) ($m['currency'] ?? $extra['currency'] ?? $rec['budget_currency'] ?? 'INR'));
        $convr = $this->numOrNull($m['convr'] ?? $extra['convr'] ?? $rec['budget_conv_rt'] ?? null);

        $rec['zcost_item'] = $zItem;
        $rec['cost_item'] = $zItem;
        $rec['costing_item_no'] = $zItem;
        $rec['costing_quotation_no'] = $quote;
        $rec['matched_quotation'] = $quote;
        $rec['maktx'] = $maktx;
        $rec['cost_description'] = $maktx;
        $rec['cost_section'] = $section;
        $rec['zsection'] = $section;
        if ($totalInr !== null) {
            $rec['total_inr'] = $totalInr;
            $rec['cost_total_inr'] = $totalInr;
            $rec['budget_inr'] = $totalInr;
        }
        if ($gross !== null) {
            $rec['gross'] = $gross;
            $rec['budgeted_cons'] = $gross;
        }
        $rec['budget_currency'] = $curr !== '' ? $curr : 'INR';
        if ($convr !== null && $convr > 0) {
            $rec['budget_conv_rt'] = $convr;
        }
        if ($budget !== null && $budget > 0) {
            $rec['budget_cost_per_gmt'] = $budget;
            $rec['expected_unit_cost'] = $budget;
        }
        $unit = trim((string) ($m['unit'] ?? $extra['unit'] ?? ''));
        if ($unit !== '' && empty($rec['buom'])) {
            $rec['buom'] = $unit;
        }
        $rec['matched_line_key'] = trim((string) ($m['matched_line_key'] ?? $extra['matched_line_key'] ?? ''));
        $rec['matched_line_no'] = trim((string) ($m['line_no'] ?? $extra['line_no'] ?? ''));
        $rec['manual_match'] = true;
        $rec['needs_manual_match'] = false;
        $rec['family_mismatch'] = false;
        $rec['_zcost_pending'] = false;
        $rec['_editingMatch'] = false;

        // Recompute actual / status like client applyCostLineToRow
        $porate = $this->numOrNull($rec['porate'] ?? $rec['net_price'] ?? null);
        $rate = $convr !== null && $convr > 0 ? $convr : 1.0;
        $cons = $this->numOrNull($rec['consumption'] ?? null);
        if ($cons === null || $cons <= 0) {
            $cons = 1.0;
        }
        $actual = null;
        if ($porate !== null) {
            $actual = round($porate * $rate * $cons, 6);
            $rec['actual_cost'] = $actual;
            $rec['po_unit_cost'] = $actual;
        }

        if ($budget !== null && $budget > 0 && $actual !== null) {
            $rec['variance_pct'] = round((($actual - $budget) / $budget) * 100, 2);
            if ($actual <= $budget + 0.005) {
                $rec['status'] = 'PASS';
                $rec['validation_result'] = 'PASS — manually matched to zCOST line';
                $rec['cost_mismatch'] = false;
            } else {
                $rec['status'] = 'EXCEPTION';
                $rec['validation_result'] = 'NOT VALIDATED — actual cost exceeds Total INR / Gross budget';
                $rec['cost_mismatch'] = true;
            }
        } elseif ($budget !== null && $budget > 0) {
            $rec['status'] = 'PASS';
            $rec['validation_result'] = 'PASS — manually matched to zCOST line';
            $rec['cost_mismatch'] = false;
        } else {
            $rec['status'] = 'NEEDS_REVIEW';
            $rec['validation_result'] = 'Matched zCOST line but Total INR / Gross budget is zero — Needs Review';
        }

        $rec['cost_matrix'] = [
            'budget' => $budget,
            'actual' => $actual,
            'status' => $rec['status'],
        ];

        return $rec;
    }

    private function numOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
        }
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }
}
