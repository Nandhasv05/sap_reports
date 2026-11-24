<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : PO Validation Report — KPIs match Draft PO (Draft/Review/Released)
 *               + Approved PO counts; one row per PO with activity progress.
 */
require_once base_path('app/core/Database.php');
require_once base_path('app/services/PoReleaseLogService.php');

class PoValuationReportService
{
    /**
     * @param array{from?:string,to?:string,po?:string,user?:string,limit?:int} $filters
     * @return array{success:bool,summary:array<string,int>,records:list<array<string,mixed>>,error:?string}
     */
    public function report(array $filters = []): array
    {
        $from = $this->normalizeDate((string) ($filters['from'] ?? ''));
        $to = $this->normalizeDate((string) ($filters['to'] ?? ''));
        $po = preg_replace('/\D/', '', (string) ($filters['po'] ?? '')) ?? '';
        $po = ltrim($po, '0');
        $user = trim((string) ($filters['user'] ?? ''));
        $limit = max(50, min(5000, (int) ($filters['limit'] ?? 2000)));

        $emptySummary = [
            'draft'    => 0,
            'review'   => 0,
            'released' => 0,
            'approved' => 0,
            'pending'  => 0, // alias of draft (legacy UI keys)
            'total'    => 0,
        ];

        try {
            $pdo = Database::connection();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'summary' => $emptySummary,
                'records' => [],
                'error'   => 'Database unavailable: ' . $e->getMessage(),
            ];
        }

        $poRows = $this->buildPoRows($pdo, $from, $to, $po, $user);
        $activityByPo = $this->activityByPo($pdo, $from, $to, $po, $user, $limit);

        $records = [];
        $summary = $emptySummary;
        foreach ($poRows as $row) {
            $poKey = (string) ($row['po_key'] ?? '');
            if ($poKey === '') {
                continue;
            }
            $events = $activityByPo[$poKey] ?? [];
            if ($events === []) {
                // Ensure progress trail has at least the current status event
                $events[] = [
                    'purchase_order' => $row['purchase_order'],
                    'event_type'     => $row['event_type'],
                    'event_label'    => $row['event_label'],
                    'status'         => $row['status'],
                    'user'           => $row['user'],
                    'remarks'        => $row['remarks'],
                    'created_at'     => $row['created_at'],
                ];
            }
            $bucket = (string) ($row['bucket'] ?? 'draft');
            if (isset($summary[$bucket])) {
                $summary[$bucket]++;
            } else {
                $summary['draft']++;
                $bucket = 'draft';
            }
            $summary['total']++;
            if ($bucket === 'draft') {
                $summary['pending']++;
            }

            $records[] = [
                'purchase_order' => $row['purchase_order'],
                'event_type'     => $row['event_type'],
                'event_label'    => $row['event_label'],
                'status'         => $row['status'],
                'bucket'         => $bucket,
                'user'           => $row['user'],
                'remarks'        => $row['remarks'],
                'created_at'     => $row['created_at'],
                'events'         => $events,
            ];
        }

        usort($records, static function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return [
            'success' => true,
            'summary' => $summary,
            'records' => $records,
            'error'   => null,
        ];
    }

    /**
     * One row per PO from Draft PO (Related/NotApproved) + Approved PO.
     *
     * @return list<array<string,mixed>>
     */
    private function buildPoRows(PDO $pdo, string $from, string $to, string $po, string $user): array
    {
        $byPo = [];

        // Draft / Review / Released — same source as Draft PO page
        if (is_file(base_path('app/services/PoCostValidationService.php'))) {
            require_once base_path('app/services/PoCostValidationService.php');
            try {
                $payload = (new PoCostValidationService())->listRelatedPurchaseOrders(
                    $po !== '' ? ['po' => $po] : []
                );
                $related = is_array($payload['records'] ?? null) ? $payload['records'] : [];
                $poNumbers = [];
                foreach ($related as $rec) {
                    if (is_array($rec)) {
                        $poNumbers[] = (string) ($rec['purchase_order'] ?? '');
                    }
                }
                $latest = [];
                if ($poNumbers !== [] && is_file(base_path('app/services/PoReleaseLogService.php'))) {
                    $latest = (new PoReleaseLogService())->latestStatusesForPos($poNumbers);
                }
                foreach ($related as $rec) {
                    if (!is_array($rec)) {
                        continue;
                    }
                    $poDisp = ltrim(preg_replace('/\D/', '', (string) ($rec['purchase_order'] ?? '')) ?? '', '0');
                    if ($poDisp === '') {
                        continue;
                    }
                    if ($po !== '' && !str_contains($poDisp, $po) && $poDisp !== $po) {
                        continue;
                    }
                    $info = $latest[$poDisp] ?? null;
                    $rawStatus = is_array($info) ? (string) ($info['status'] ?? '') : '';
                    $status = PoReleaseLogService::normalizeStatus($rawStatus);
                    if ($status === '') {
                        $status = PoReleaseLogService::STATUS_DRAFT;
                    }
                    $owner = is_array($info) ? trim((string) ($info['created_by'] ?? '')) : '';
                    $when = is_array($info) ? substr(str_replace('T', ' ', (string) ($info['created_on'] ?? '')), 0, 19) : '';
                    $remarks = is_array($info) ? trim((string) ($info['remarks'] ?? '')) : '';
                    if ($user !== '' && $owner !== '' && stripos($owner, $user) === false) {
                        continue;
                    }
                    if (!$this->inDateRange($when, $from, $to)) {
                        continue;
                    }
                    $bucket = $this->bucketForStatus($status);
                    $byPo[$poDisp] = [
                        'po_key'         => $poDisp,
                        'purchase_order' => $poDisp,
                        'status'         => $status,
                        'bucket'         => $bucket,
                        'event_type'     => 'release',
                        'event_label'    => 'Release Status',
                        'user'           => $owner !== '' ? $owner : '—',
                        'remarks'        => $remarks,
                        'created_at'     => $when !== '' ? $when : null,
                    ];
                }
            } catch (Throwable $e) {
                error_log('PoValuationReportService related POs: ' . $e->getMessage());
            }
        }

        // Approved — same source as Approved PO page (action = approve)
        if (is_file(base_path('app/services/PassPoValidationLogService.php'))) {
            require_once base_path('app/services/PassPoValidationLogService.php');
            try {
                $pass = (new PassPoValidationLogService())->listPassed([
                    'approved_only' => '1',
                    'success'       => '1',
                    'po'            => $po,
                    'username'      => $user,
                    'from'          => $from,
                    'to'            => $to,
                    'limit'         => 500,
                ]);
                $rows = is_array($pass['rows'] ?? null) ? $pass['rows'] : [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $poDisp = ltrim(preg_replace('/\D/', '', (string) ($row['purchase_order'] ?? '')) ?? '', '0');
                    if ($poDisp === '') {
                        continue;
                    }
                    $owner = trim((string) ($row['username'] ?? $row['user'] ?? ''));
                    $when = substr(str_replace('T', ' ', (string) ($row['created_at'] ?? '')), 0, 19);
                    // Approved overrides Draft/Review/Released for the same PO
                    $byPo[$poDisp] = [
                        'po_key'         => $poDisp,
                        'purchase_order' => $poDisp,
                        'status'         => 'Approved',
                        'bucket'         => 'approved',
                        'event_type'     => 'pass',
                        'event_label'    => 'Pass / eSign',
                        'user'           => $owner !== '' ? $owner : '—',
                        'remarks'        => trim((string) ($row['remarks'] ?? 'Approved')),
                        'created_at'     => $when !== '' ? $when : null,
                    ];
                }
            } catch (Throwable $e) {
                error_log('PoValuationReportService approved POs: ' . $e->getMessage());
            }
        }

        return array_values($byPo);
    }

    private function bucketForStatus(string $status): string
    {
        if ($status === PoReleaseLogService::STATUS_REVIEW) {
            return 'review';
        }
        if ($status === PoReleaseLogService::STATUS_RELEASED) {
            return 'released';
        }
        if (strcasecmp($status, 'Approved') === 0) {
            return 'approved';
        }
        return 'draft';
    }

    private function inDateRange(?string $when, string $from, string $to): bool
    {
        if ($from === '' && $to === '') {
            return true;
        }
        // POs with no status date still count when filtering (same as Draft default)
        if ($when === null || $when === '') {
            return $from === '' && $to === '';
        }
        $day = substr($when, 0, 10);
        if ($from !== '' && $day < $from) {
            return false;
        }
        if ($to !== '' && $day > $to) {
            return false;
        }
        return true;
    }

    /**
     * @return array<string, list<array<string,mixed>>>
     */
    private function activityByPo(PDO $pdo, string $from, string $to, string $po, string $user, int $limit): array
    {
        $flat = $this->buildActivity($pdo, $from, $to, $po, $user, $limit);
        $map = [];
        foreach ($flat as $row) {
            $poKey = ltrim(preg_replace('/\D/', '', (string) ($row['purchase_order'] ?? '')) ?? '', '0');
            if ($poKey === '') {
                continue;
            }
            if (!isset($map[$poKey])) {
                $map[$poKey] = [];
            }
            $map[$poKey][] = $row;
        }
        foreach ($map as $k => $list) {
            usort($list, static function ($a, $b) {
                return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
            });
            $map[$k] = $list;
        }
        return $map;
    }

    /**
     * Fetch activity per source table (never fail the whole feed if one source breaks).
     *
     * @return list<array<string,mixed>>
     */
    private function buildActivity(PDO $pdo, string $from, string $to, string $po, string $user, int $limit): array
    {
        $raw = [];
        $sources = [];

        if ($this->tableExists($pdo, 'po_release_log')) {
            $sources[] = [
                'type' => 'release',
                'sql'  => "
                    SELECT TOP ({$limit})
                        CAST(po_number AS NVARCHAR(50)) AS purchase_order,
                        CAST(ISNULL(status, N'') AS NVARCHAR(80)) AS status_label,
                        CAST(ISNULL(created_by, N'') AS NVARCHAR(120)) AS actor,
                        CAST(ISNULL(remarks, N'') AS NVARCHAR(500)) AS remarks,
                        created_on AS event_at
                    FROM [dbo].[po_release_log]
                    WHERE {where}
                    ORDER BY created_on DESC
                ",
                'dateCol' => 'created_on',
                'poCol'   => 'po_number',
                'userSql' => 'LOWER(ISNULL(created_by, \'\')) LIKE ?',
                'userN'   => 1,
            ];
        }

        if ($this->tableExists($pdo, 'po_validation_log')) {
            $sources[] = [
                'type' => 'validation',
                'sql'  => "
                    SELECT TOP ({$limit})
                        CAST(purchase_order AS NVARCHAR(50)) AS purchase_order,
                        CAST(ISNULL(validation_status, N'') AS NVARCHAR(80)) AS status_label,
                        CAST(ISNULL(NULLIF(assigned_username, N''), created_by) AS NVARCHAR(120)) AS actor,
                        CAST(ISNULL(CAST(description AS NVARCHAR(500)), N'') AS NVARCHAR(500)) AS remarks,
                        created_at AS event_at
                    FROM [dbo].[po_validation_log]
                    WHERE {where}
                    ORDER BY created_at DESC
                ",
                'dateCol' => 'created_at',
                'poCol'   => 'CAST(purchase_order AS VARCHAR(50))',
                'userSql' => '(LOWER(ISNULL(created_by, \'\')) LIKE ? OR LOWER(ISNULL(assigned_username, \'\')) LIKE ?)',
                'userN'   => 2,
            ];
        }

        if ($this->tableExists($pdo, 'Pass_po_validtion')) {
            $sources[] = [
                'type' => 'pass',
                'sql'  => "
                    SELECT TOP ({$limit})
                        CAST(purchase_order AS NVARCHAR(50)) AS purchase_order,
                        CAST(ISNULL(NULLIF(status, N''), action) AS NVARCHAR(80)) AS status_label,
                        CAST(ISNULL(NULLIF(username, N''), from_username) AS NVARCHAR(120)) AS actor,
                        CAST(ISNULL(remarks, N'') AS NVARCHAR(500)) AS remarks,
                        created_at AS event_at
                    FROM [dbo].[Pass_po_validtion]
                    WHERE {where}
                    ORDER BY created_at DESC
                ",
                'dateCol' => 'created_at',
                'poCol'   => 'CAST(purchase_order AS VARCHAR(50))',
                'userSql' => '(LOWER(ISNULL(username, \'\')) LIKE ? OR LOWER(ISNULL(from_username, \'\')) LIKE ? OR LOWER(ISNULL(to_username, \'\')) LIKE ?)',
                'userN'   => 3,
            ];
        }

        if ($this->tableExists($pdo, 'po_costing_sent_log')) {
            $sources[] = [
                'type' => 'sent',
                'sql'  => "
                    SELECT TOP ({$limit})
                        CAST(purchase_order AS NVARCHAR(50)) AS purchase_order,
                        CAST(ISNULL(status, N'') AS NVARCHAR(80)) AS status_label,
                        CAST(ISNULL(from_username, N'') AS NVARCHAR(120)) AS actor,
                        CAST(ISNULL(remarks, N'') AS NVARCHAR(500)) AS remarks,
                        created_at AS event_at
                    FROM [dbo].[po_costing_sent_log]
                    WHERE {where}
                    ORDER BY created_at DESC
                ",
                'dateCol' => 'created_at',
                'poCol'   => 'CAST(purchase_order AS VARCHAR(50))',
                'userSql' => '(LOWER(ISNULL(from_username, \'\')) LIKE ? OR LOWER(ISNULL(to_username, \'\')) LIKE ?)',
                'userN'   => 2,
            ];
        }

        foreach ($sources as $src) {
            $where = ['1=1'];
            $params = [];
            $dateCol = (string) $src['dateCol'];
            $poCol = (string) $src['poCol'];
            if ($from !== '') {
                $where[] = "{$dateCol} >= ?";
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = "{$dateCol} <= ?";
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $where[] = "LTRIM(REPLACE(REPLACE({$poCol}, ' ', ''), ',', '')) LIKE ?";
                $params[] = '%' . $po . '%';
            }
            if ($user !== '') {
                $where[] = (string) $src['userSql'];
                $like = '%' . strtolower($user) . '%';
                $n = max(1, (int) ($src['userN'] ?? 1));
                for ($i = 0; $i < $n; $i++) {
                    $params[] = $like;
                }
            }
            $sql = str_replace('{where}', implode(' AND ', $where), (string) $src['sql']);
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row['event_type'] = (string) $src['type'];
                    $raw[] = $row;
                }
            } catch (Throwable $e) {
                error_log('PoValuationReportService activity ' . $src['type'] . ': ' . $e->getMessage());
            }
        }

        usort($raw, static function ($a, $b) {
            return strcmp((string) ($b['event_at'] ?? ''), (string) ($a['event_at'] ?? ''));
        });
        if (count($raw) > $limit) {
            $raw = array_slice($raw, 0, $limit);
        }

        $out = [];
        foreach ($raw as $row) {
            $poDigits = ltrim(preg_replace('/\D/', '', (string) ($row['purchase_order'] ?? '')) ?? '', '0');
            $when = substr(str_replace('T', ' ', (string) ($row['event_at'] ?? '')), 0, 19);
            $type = strtolower(trim((string) ($row['event_type'] ?? '')));
            $typeLabel = match ($type) {
                'release' => 'Release Status',
                'validation' => 'Validation',
                'pass' => 'Pass / eSign',
                'sent' => 'Sent / Receive',
                default => ucfirst($type ?: 'Activity'),
            };
            $out[] = [
                'purchase_order' => $poDigits,
                'event_type'     => $type,
                'event_label'    => $typeLabel,
                'status'         => trim((string) ($row['status_label'] ?? '')),
                'user'           => trim((string) ($row['actor'] ?? '')),
                'remarks'        => trim((string) ($row['remarks'] ?? '')),
                'created_at'     => $when !== '' ? $when : null,
            ];
        }

        return $out;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
            if ($safe === '') {
                return false;
            }
            $q = $pdo->query(
                "SELECT CASE WHEN OBJECT_ID(N'[dbo].[{$safe}]', N'U') IS NULL THEN 0 ELSE 1 END"
            );
            return (int) ($q->fetchColumn() ?: 0) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}
