<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Batch PO validation / e-signature status from CLIENT_API_LIVE.po_esign_requests
*/
require_once base_path('app/core/Database.php');

class PoValidationStatusService
{
    /** Friendly workflow buckets used on the costing list */
    public const BUCKET_NOT_STARTED = 'not_started';
    public const BUCKET_PENDING = 'pending';
    public const BUCKET_IN_REVIEW = 'in_review';
    public const BUCKET_COMPLETED = 'completed';
    public const BUCKET_REJECTED = 'rejected';

    /**
     * Extra columns for PO Costing Validation list
     */
    public static function statusColumns(): array
    {
        return [
            ['key' => 'validation_status', 'label' => 'Validation',     'type' => 'text'],
            ['key' => 'esign_status',       'label' => 'E-Sign Status',  'type' => 'text'],
            ['key' => 'workflow_stage',     'label' => 'Workflow Stage', 'type' => 'text'],
            ['key' => 'status_owner',       'label' => 'With',           'type' => 'text'],
        ];
    }

    /**
     * List columns for costing view (PO list + status checks)
     */
    public static function costingListColumns(): array
    {
        require_once base_path('app/services/SapPurchaseOrderService.php');
        $base = SapPurchaseOrderService::listColumns();
        $out = [];
        foreach ($base as $col) {
            $out[] = $col;
            if ($col['key'] === 'purchase_order') {
                foreach (self::statusColumns() as $statusCol) {
                    $out[] = $statusCol;
                }
            }
        }
        return $out;
    }

    /**
     * Normalize PO number for map keys (strip #/spaces, drop leading zeros).
     */
    public static function normalizePo(string $po): string
    {
        $po = strtoupper(trim(str_replace(['#', ' ', ','], '', $po)));
        if ($po === '' || !preg_match('/^\d+$/', $po)) {
            return $po;
        }
        $trimmed = ltrim($po, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Map raw Status to a display bucket + labels.
     *
     * @return array{bucket:string,validation_status:string,esign_status:string,workflow_stage:string,status_owner:string,tone:string}
     */
    public static function mapStatusRow(array $row): array
    {
        $raw = strtolower(trim((string) ($row['Status'] ?? '')));
        $owner = trim((string) (
            $row['AssignedToUser']
            ?? $row['ForwardedToUser']
            ?? $row['CurrentApprover']
            ?? $row['UploadedBy']
            ?? ''
        ));

        if ($raw === '' || $raw === 'not_started') {
            return [
                'bucket'             => self::BUCKET_NOT_STARTED,
                'validation_status'  => 'Not Started',
                'esign_status'       => '—',
                'workflow_stage'     => 'No validation request',
                'status_owner'       => '',
                'tone'               => 'muted',
            ];
        }

        if ($raw === 'rejected') {
            return [
                'bucket'             => self::BUCKET_REJECTED,
                'validation_status'  => 'Rejected',
                'esign_status'       => 'Rejected',
                'workflow_stage'     => 'Rejected',
                'status_owner'       => $owner,
                'tone'               => 'danger',
            ];
        }

        if ($raw === 'completed') {
            return [
                'bucket'             => self::BUCKET_COMPLETED,
                'validation_status'  => 'Validated',
                'esign_status'       => 'Completed',
                'workflow_stage'     => 'Fully signed & completed',
                'status_owner'       => $owner,
                'tone'               => 'ok',
            ];
        }

        $stageMap = [
            'pending_kalai'      => 'Pending upload review',
            'pending_signature'  => 'Pending preparer signature',
            'pending_checker'    => 'Pending checker',
            'signed'             => 'Checker signed — awaiting final',
            'pending_authorised' => 'Pending authorised signatory',
        ];
        $stage = $stageMap[$raw] ?? ucwords(str_replace('_', ' ', $raw));

        $inReview = in_array($raw, ['pending_checker', 'signed', 'pending_authorised'], true);

        return [
            'bucket'             => $inReview ? self::BUCKET_IN_REVIEW : self::BUCKET_PENDING,
            'validation_status'  => $inReview ? 'In Review' : 'Pending Validation',
            'esign_status'       => ucwords(str_replace('_', ' ', $raw)),
            'workflow_stage'     => $stage,
            'status_owner'       => $owner,
            'tone'               => $inReview ? 'warn' : 'info',
        ];
    }

    /**
     * Batch-load latest e-sign row per PO number.
     *
     * @param array<int, string> $purchaseOrders
     * @return array<string, array{bucket:string,validation_status:string,esign_status:string,workflow_stage:string,status_owner:string,tone:string,request_id:?int,raw_status:string}>
     */
    public function statusesForPurchaseOrders(array $purchaseOrders): array
    {
        $keys = [];
        foreach ($purchaseOrders as $po) {
            $n = self::normalizePo((string) $po);
            if ($n !== '') {
                $keys[$n] = true;
            }
        }
        $poList = array_keys($keys);
        $default = self::mapStatusRow([]);
        $out = [];
        foreach ($poList as $po) {
            $out[$po] = $default + ['request_id' => null, 'raw_status' => ''];
        }
        if ($poList === []) {
            return $out;
        }

        try {
            $pdo = Database::connection();
        } catch (Throwable $e) {
            return $out;
        }

        // Confirm table exists (dev DBs may not have e-sign yet)
        try {
            $check = $pdo->query("SELECT TOP 1 1 AS ok FROM sys.tables WHERE name = 'po_esign_requests'");
            if (!$check || !$check->fetch()) {
                return $out;
            }
        } catch (Throwable $e) {
            return $out;
        }

        foreach (array_chunk($poList, 300) as $chunk) {
            $variants = [];
            foreach ($chunk as $po) {
                $variants[$po] = true;
                if (preg_match('/^\d+$/', $po)) {
                    $variants[str_pad($po, 10, '0', STR_PAD_LEFT)] = true;
                }
            }
            $variantList = array_keys($variants);
            // Build unique positional placeholders (SQLSRV PDO rejects reused named params)
            $phA = [];
            $phB = [];
            $bind = [];
            foreach ($variantList as $i => $value) {
                $phA[] = '?';
                $phB[] = '?';
                $bind[] = $value;
            }
            foreach ($variantList as $value) {
                $bind[] = $value;
            }
            $inA = implode(',', $phA);
            $inB = implode(',', $phB);
            $sql = "
                SELECT Id, PoNumber, Status, AssignedToUser, ForwardedToUser, CurrentApprover, UploadedBy, CheckedAt, ApprovedAt
                FROM dbo.po_esign_requests
                WHERE ISNULL(IsDeleted, 0) = 0
                  AND (
                    REPLACE(REPLACE(LTRIM(RTRIM(ISNULL(PoNumber, N''))), N'#', N''), N' ', N'') IN ($inA)
                    OR LTRIM(RTRIM(ISNULL(PoNumber, N''))) IN ($inB)
                  )
                ORDER BY Id DESC
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $norm = self::normalizePo((string) ($row['PoNumber'] ?? ''));
                    if ($norm === '' || !isset($out[$norm])) {
                        continue;
                    }
                    // Keep first row (latest Id DESC)
                    if (($out[$norm]['raw_status'] ?? '') !== '') {
                        continue;
                    }
                    $mapped = self::mapStatusRow($row);
                    $out[$norm] = $mapped + [
                        'request_id' => isset($row['Id']) ? (int) $row['Id'] : null,
                        'raw_status' => strtolower(trim((string) ($row['Status'] ?? ''))),
                    ];
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $out;
    }

    /**
     * Enrich PO list records with validation/e-sign status fields and status totals.
     *
     * @param array{records?:array,totals?:array} $listPayload
     * @return array
     */
    public function enrichListPayload(array $listPayload): array
    {
        $records = is_array($listPayload['records'] ?? null) ? $listPayload['records'] : [];
        $poNumbers = array_map(
            static fn (array $r): string => (string) ($r['purchase_order'] ?? ''),
            $records
        );
        $map = $this->statusesForPurchaseOrders($poNumbers);

        $counts = [
            'not_started' => 0,
            'pending'     => 0,
            'in_review'   => 0,
            'completed'   => 0,
            'rejected'    => 0,
        ];

        foreach ($records as &$row) {
            $po = self::normalizePo((string) ($row['purchase_order'] ?? ''));
            $status = $map[$po] ?? (self::mapStatusRow([]) + ['request_id' => null, 'raw_status' => '']);
            $row['validation_status'] = $status['validation_status'];
            $row['esign_status'] = $status['esign_status'];
            $row['workflow_stage'] = $status['workflow_stage'];
            $row['status_owner'] = $status['status_owner'];
            $row['status_bucket'] = $status['bucket'];
            $row['status_tone'] = $status['tone'];
            $row['esign_request_id'] = $status['request_id'];
            $bucket = (string) ($status['bucket'] ?? self::BUCKET_NOT_STARTED);
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            } else {
                $counts['not_started']++;
            }
        }
        unset($row);

        $listPayload['records'] = $records;
        if (!isset($listPayload['totals']) || !is_array($listPayload['totals'])) {
            $listPayload['totals'] = [];
        }
        $listPayload['totals']['status_not_started'] = $counts['not_started'];
        $listPayload['totals']['status_pending'] = $counts['pending'];
        $listPayload['totals']['status_in_review'] = $counts['in_review'];
        $listPayload['totals']['status_completed'] = $counts['completed'];
        $listPayload['totals']['status_rejected'] = $counts['rejected'];
        $listPayload['totals']['status_validated'] = $counts['completed'];
        $listPayload['validation_enriched'] = true;

        return $listPayload;
    }
}
