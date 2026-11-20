<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : PO Valuation Report — KPIs (Pending / Review / Released / Approved / Total)
 *               + PO-wise activity log (release / validation / pass / sent)
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
        $limit = max(50, min(2000, (int) ($filters['limit'] ?? 500)));

        $empty = [
            'success'  => true,
            'summary'  => [
                'pending'  => 0,
                'review'   => 0,
                'released' => 0,
                'approved' => 0,
                'total'    => 0,
            ],
            'records'  => [],
            'error'    => null,
        ];

        try {
            $pdo = Database::connection();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'summary' => $empty['summary'],
                'records' => [],
                'error'   => 'Database unavailable: ' . $e->getMessage(),
            ];
        }

        $summary = $this->buildSummary($pdo, $from, $to, $po, $user);
        $records = $this->buildActivity($pdo, $from, $to, $po, $user, $limit);

        return [
            'success' => true,
            'summary' => $summary,
            'records' => $records,
            'error'   => null,
        ];
    }

    /**
     * @return array{pending:int,review:int,released:int,approved:int,total:int}
     */
    private function buildSummary(PDO $pdo, string $from, string $to, string $po, string $user): array
    {
        $pending = 0;
        $review = 0;
        $released = 0;
        $approved = 0;
        $allPos = [];

        // Latest status per PO from po_release_log (Draft→Pending, Review, Released)
        if ($this->tableExists($pdo, 'po_release_log')) {
            $where = ['1=1'];
            $params = [];
            if ($from !== '') {
                $where[] = 'r.created_on >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'r.created_on <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $where[] = "(LTRIM(REPLACE(REPLACE(r.po_number, ' ', ''), ',', '')) LIKE ? OR LTRIM(REPLACE(REPLACE(r.po_number, ' ', ''), ',', '')) LIKE ?)";
                $params[] = '%' . $po . '%';
                $params[] = '%' . str_pad($po, 10, '0', STR_PAD_LEFT) . '%';
            }
            if ($user !== '') {
                $where[] = 'LOWER(ISNULL(r.created_by, \'\')) LIKE ?';
                $params[] = '%' . strtolower($user) . '%';
            }
            $sql = "
                SELECT r.po_number, r.status
                FROM [dbo].[po_release_log] r
                INNER JOIN (
                    SELECT po_number, MAX(id) AS max_id
                    FROM [dbo].[po_release_log]
                    GROUP BY po_number
                ) t ON t.max_id = r.id
                WHERE " . implode(' AND ', $where) . "
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $poKey = ltrim(preg_replace('/\D/', '', (string) ($row['po_number'] ?? '')) ?? '', '0');
                    if ($poKey === '') {
                        continue;
                    }
                    $allPos[$poKey] = true;
                    $st = PoReleaseLogService::normalizeStatus((string) ($row['status'] ?? ''));
                    if ($st === PoReleaseLogService::STATUS_REVIEW) {
                        $review++;
                    } elseif ($st === PoReleaseLogService::STATUS_RELEASED) {
                        $released++;
                    } else {
                        // Draft or unknown → Pending
                        $pending++;
                    }
                }
            } catch (Throwable $e) {
                error_log('PoValuationReportService release summary: ' . $e->getMessage());
            }
        }

        // Approved = distinct successful Pass_po_validtion rows
        if ($this->tableExists($pdo, 'Pass_po_validtion')) {
            $where = ['(l.success = 1 OR UPPER(ISNULL(l.status, \'\')) LIKE \'%APPROV%\' OR UPPER(ISNULL(l.action, \'\')) LIKE \'%APPROV%\')'];
            $params = [];
            if ($from !== '') {
                $where[] = 'l.created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'l.created_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $where[] = 'LTRIM(REPLACE(REPLACE(CAST(l.purchase_order AS VARCHAR(50)), \' \', \'\'), \',\', \'\')) LIKE ?';
                $params[] = '%' . $po . '%';
            }
            if ($user !== '') {
                $where[] = '(LOWER(ISNULL(l.username, \'\')) LIKE ? OR LOWER(ISNULL(l.from_username, \'\')) LIKE ? OR LOWER(ISNULL(l.to_username, \'\')) LIKE ?)';
                $like = '%' . strtolower($user) . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $sql = "
                SELECT DISTINCT LTRIM(REPLACE(REPLACE(CAST(l.purchase_order AS VARCHAR(50)), ' ', ''), ',', '')) AS po_number
                FROM [dbo].[Pass_po_validtion] l
                WHERE " . implode(' AND ', $where) . "
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $poKey = ltrim(preg_replace('/\D/', '', (string) ($row['po_number'] ?? '')) ?? '', '0');
                    if ($poKey === '') {
                        continue;
                    }
                    $allPos[$poKey] = true;
                    $approved++;
                }
            } catch (Throwable $e) {
                error_log('PoValuationReportService approved summary: ' . $e->getMessage());
            }
        }

        return [
            'pending'  => $pending,
            'review'   => $review,
            'released' => $released,
            'approved' => $approved,
            'total'    => count($allPos),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildActivity(PDO $pdo, string $from, string $to, string $po, string $user, int $limit): array
    {
        $parts = [];
        $params = [];

        if ($this->tableExists($pdo, 'po_release_log')) {
            $w = ['1=1'];
            if ($from !== '') {
                $w[] = 'created_on >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $w[] = 'created_on <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $w[] = 'LTRIM(REPLACE(REPLACE(po_number, \' \', \'\'), \',\', \'\')) LIKE ?';
                $params[] = '%' . $po . '%';
            }
            if ($user !== '') {
                $w[] = 'LOWER(ISNULL(created_by, \'\')) LIKE ?';
                $params[] = '%' . strtolower($user) . '%';
            }
            $parts[] = "
                SELECT
                    CAST(po_number AS NVARCHAR(50)) AS purchase_order,
                    N'release' AS event_type,
                    CAST(ISNULL(status, N'') AS NVARCHAR(80)) AS status_label,
                    CAST(ISNULL(created_by, N'') AS NVARCHAR(120)) AS actor,
                    CAST(ISNULL(remarks, N'') AS NVARCHAR(500)) AS remarks,
                    created_on AS event_at
                FROM [dbo].[po_release_log]
                WHERE " . implode(' AND ', $w) . "
            ";
        }

        if ($this->tableExists($pdo, 'po_validation_log')) {
            $w = ['1=1'];
            if ($from !== '') {
                $w[] = 'created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $w[] = 'created_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $w[] = 'LTRIM(REPLACE(REPLACE(CAST(purchase_order AS VARCHAR(50)), \' \', \'\'), \',\', \'\')) LIKE ?';
                $params[] = '%' . $po . '%';
            }
            if ($user !== '') {
                $w[] = '(LOWER(ISNULL(created_by, \'\')) LIKE ? OR LOWER(ISNULL(assigned_username, \'\')) LIKE ?)';
                $like = '%' . strtolower($user) . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $parts[] = "
                SELECT
                    CAST(purchase_order AS NVARCHAR(50)) AS purchase_order,
                    N'validation' AS event_type,
                    CAST(ISNULL(validation_status, N'') AS NVARCHAR(80)) AS status_label,
                    CAST(ISNULL(NULLIF(assigned_username, N''), created_by) AS NVARCHAR(120)) AS actor,
                    CAST(ISNULL(description, N'') AS NVARCHAR(500)) AS remarks,
                    created_at AS event_at
                FROM [dbo].[po_validation_log]
                WHERE " . implode(' AND ', $w) . "
            ";
        }

        if ($this->tableExists($pdo, 'Pass_po_validtion')) {
            $w = ['1=1'];
            if ($from !== '') {
                $w[] = 'created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $w[] = 'created_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $w[] = 'LTRIM(REPLACE(REPLACE(CAST(purchase_order AS VARCHAR(50)), \' \', \'\'), \',\', \'\')) LIKE ?';
                $params[] = '%' . $po . '%';
            }
            if ($user !== '') {
                $w[] = '(LOWER(ISNULL(username, \'\')) LIKE ? OR LOWER(ISNULL(from_username, \'\')) LIKE ? OR LOWER(ISNULL(to_username, \'\')) LIKE ?)';
                $like = '%' . strtolower($user) . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $parts[] = "
                SELECT
                    CAST(purchase_order AS NVARCHAR(50)) AS purchase_order,
                    N'pass' AS event_type,
                    CAST(ISNULL(NULLIF(status, N''), action) AS NVARCHAR(80)) AS status_label,
                    CAST(ISNULL(NULLIF(username, N''), from_username) AS NVARCHAR(120)) AS actor,
                    CAST(N'' AS NVARCHAR(500)) AS remarks,
                    created_at AS event_at
                FROM [dbo].[Pass_po_validtion]
                WHERE " . implode(' AND ', $w) . "
            ";
        }

        if ($this->tableExists($pdo, 'po_costing_sent_log')) {
            $w = ['1=1'];
            if ($from !== '') {
                $w[] = 'created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $w[] = 'created_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($po !== '') {
                $w[] = 'LTRIM(REPLACE(REPLACE(CAST(purchase_order AS VARCHAR(50)), \' \', \'\'), \',\', \'\')) LIKE ?';
                $params[] = '%' . $po . '%';
            }
            if ($user !== '') {
                $w[] = '(LOWER(ISNULL(from_username, \'\')) LIKE ? OR LOWER(ISNULL(to_username, \'\')) LIKE ?)';
                $like = '%' . strtolower($user) . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $parts[] = "
                SELECT
                    CAST(purchase_order AS NVARCHAR(50)) AS purchase_order,
                    N'sent' AS event_type,
                    CAST(ISNULL(status, N'') AS NVARCHAR(80)) AS status_label,
                    CAST(ISNULL(from_username, N'') AS NVARCHAR(120)) AS actor,
                    CAST(ISNULL(remarks, N'') AS NVARCHAR(500)) AS remarks,
                    created_at AS event_at
                FROM [dbo].[po_costing_sent_log]
                WHERE " . implode(' AND ', $w) . "
            ";
        }

        if ($parts === []) {
            return [];
        }

        $sql = "
            SELECT TOP ({$limit}) *
            FROM (
                " . implode("\n UNION ALL \n", $parts) . "
            ) x
            ORDER BY event_at DESC
        ";

        $out = [];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $sno = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $sno++;
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
                    'sno'            => $sno,
                    'purchase_order' => $poDigits,
                    'event_type'     => $type,
                    'event_label'    => $typeLabel,
                    'status'         => trim((string) ($row['status_label'] ?? '')),
                    'user'           => trim((string) ($row['actor'] ?? '')),
                    'remarks'        => trim((string) ($row['remarks'] ?? '')),
                    'created_at'     => $when !== '' ? $when : null,
                ];
            }
        } catch (Throwable $e) {
            error_log('PoValuationReportService activity: ' . $e->getMessage());
            return [];
        }

        return $out;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT CASE WHEN OBJECT_ID(N'[dbo].[' + ? + N']', N'U') IS NULL THEN 0 ELSE 1 END"
            );
            // OBJECT_ID with concat param can fail — use literal-safe whitelist
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
