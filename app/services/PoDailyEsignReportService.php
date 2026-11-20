<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : Daily PO E-Signature report — KPIs + pending table from
 *               dbo.po_esign_requests (+ optional SAP), emailed to daily_report assignees at 23:00 IST
 */
require_once base_path('app/core/Database.php');

class PoDailyEsignReportService
{
    private const TZ = 'Asia/Kolkata';
    private const TARGET_DAYS = 5;

    /**
     * Build snapshot (KPIs + pending rows) then email Daily Report assignees.
     *
     * @return array{success:bool,sent:int,failed:int,skipped:int,errors:list<string>,summary:array,recipients:list<string>,error:?string}
     */
    public function runAndSend(?DateTimeImmutable $asOf = null): array
    {
        $asOf = $asOf ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $report = $this->buildReport($asOf);
        if (empty($report['success'])) {
            return [
                'success' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => [(string) ($report['error'] ?? 'Report build failed')],
                'summary' => $report['summary'] ?? $this->emptySummary(),
                'recipients' => [],
                'error' => (string) ($report['error'] ?? 'Report build failed'),
            ];
        }

        $recipients = $this->resolveRecipients();
        if ($recipients === []) {
            return [
                'success' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => ['No Daily Report assignees configured (Workflow → Daily Report Assignment)'],
                'summary' => $report['summary'],
                'recipients' => [],
                'error' => 'No Daily Report assignees configured',
            ];
        }

        require_once base_path('app/services/SimpleMailService.php');
        $mailCfg = function_exists('config') ? config('mail') : [];
        $fromEmail = (string) ($mailCfg['from']['address'] ?? 'support@evolvclothing.com');
        $fromName = (string) ($mailCfg['from']['name'] ?? 'IT Support');
        $subject = 'PO E-Signature Report — ' . $asOf->format('d M Y');
        $html = $this->buildEmailHtml($report, $asOf);

        $mailer = new SimpleMailService($mailCfg);
        $result = $mailer->send(
            array_column($recipients, 'email'),
            $subject,
            $html,
            $fromEmail,
            $fromName
        );

        return [
            'success' => ((int) ($result['sent'] ?? 0)) > 0,
            'sent' => (int) ($result['sent'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'skipped' => 0,
            'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            'summary' => $report['summary'],
            'recipients' => array_map(static fn ($r) => (string) ($r['email'] ?? ''), $recipients),
            'error' => ((int) ($result['sent'] ?? 0)) > 0
                ? null
                : (string) (($result['errors'][0] ?? null) ?: 'Mail send failed'),
        ];
    }

    /**
     * @return array{success:bool,summary:array,pending:list<array>,live_url:string,error:?string}
     */
    public function buildReport(?DateTimeImmutable $asOf = null): array
    {
        $asOf = $asOf ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $dayStart = $asOf->setTime(0, 0, 0);
        $dayEnd = $asOf->setTime(23, 59, 59);
        $summary = $this->emptySummary();
        $pending = [];

        try {
            $pdo = Database::connection();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'summary' => $summary,
                'pending' => [],
                'live_url' => $this->liveReportUrl(),
                'error' => 'Database unavailable: ' . $e->getMessage(),
            ];
        }

        if (!$this->tableExists($pdo, 'po_esign_requests')) {
            return [
                'success' => false,
                'summary' => $summary,
                'pending' => [],
                'live_url' => $this->liveReportUrl(),
                'error' => 'Table dbo.po_esign_requests not found',
            ];
        }

        $cols = $this->columnMap($pdo, 'po_esign_requests');
        $createdCol = $this->firstExisting($cols, [
            'CreatedAt', 'CreatedOn', 'UploadedAt', 'UploadedOn', 'RequestDate', 'created_at', 'uploaded_at',
        ]);
        $signedCol = $this->firstExisting($cols, [
            'ApprovedAt', 'SignedAt', 'CheckedAt', 'CompletedAt', 'approved_at', 'signed_at', 'checked_at',
        ]);
        $updatedCol = $this->firstExisting($cols, ['UpdatedAt', 'updated_at', 'ModifiedAt']);

        // Always hit the DB for latest e-sign rows
        $sql = "
            SELECT
                Id,
                PoNumber,
                Status,
                AssignedToUser,
                ForwardedToUser,
                CurrentApprover,
                UploadedBy
                " . ($createdCol ? ", [{$createdCol}] AS UploadedAt" : ", NULL AS UploadedAt") . "
                " . ($signedCol ? ", [{$signedCol}] AS SignedAt" : ", NULL AS SignedAt") . "
                " . ($updatedCol ? ", [{$updatedCol}] AS UpdatedAt" : ", NULL AS UpdatedAt") . "
            FROM dbo.po_esign_requests
            WHERE ISNULL(IsDeleted, 0) = 0
            ORDER BY Id DESC
        ";

        $latestByPo = [];
        try {
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $po = $this->normalizePo((string) ($row['PoNumber'] ?? ''));
                if ($po === '' || isset($latestByPo[$po])) {
                    continue;
                }
                $latestByPo[$po] = $row;
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'summary' => $summary,
                'pending' => [],
                'live_url' => $this->liveReportUrl(),
                'error' => 'po_esign_requests query failed: ' . $e->getMessage(),
            ];
        }

        // Optional SAP ping so the job always touches SAP as well (best-effort)
        $sapTouch = $this->touchSapPurchaseOrders($asOf);

        $pendingCount = 0;
        $createdToday = 0;
        $signedToday = 0;

        foreach ($latestByPo as $po => $row) {
            $raw = strtolower(trim((string) ($row['Status'] ?? '')));
            $uploadedAt = $this->parseDt($row['UploadedAt'] ?? null);
            $signedAt = $this->parseDt($row['SignedAt'] ?? null) ?? $this->parseDt($row['UpdatedAt'] ?? null);

            if ($uploadedAt && $uploadedAt >= $dayStart && $uploadedAt <= $dayEnd) {
                $createdToday++;
            }

            $isCompleted = in_array($raw, ['completed', 'signed_completed', 'fully_signed'], true)
                || ($raw === 'signed' && $signedAt !== null);
            // Treat explicit completed statuses only for signed-today KPI
            if (in_array($raw, ['completed'], true) && $signedAt && $signedAt >= $dayStart && $signedAt <= $dayEnd) {
                $signedToday++;
            } elseif ($raw === 'completed' && !$signedAt && $uploadedAt && $uploadedAt >= $dayStart && $uploadedAt <= $dayEnd) {
                // fallback if no signed timestamp
                $signedToday++;
            }

            $isPending = $raw !== ''
                && !in_array($raw, ['completed', 'rejected', 'not_started'], true);
            if (!$isPending) {
                continue;
            }

            $pendingCount++;
            $owner = trim((string) (
                $row['AssignedToUser']
                ?? $row['CurrentApprover']
                ?? $row['ForwardedToUser']
                ?? $row['UploadedBy']
                ?? ''
            ));
            $statusLabel = $this->statusLabel($raw, $owner);
            $targetAt = $uploadedAt ? $uploadedAt->modify('+' . self::TARGET_DAYS . ' days') : null;

            $pending[] = [
                'purchase_order' => $po,
                'uploaded_at' => $uploadedAt ? $uploadedAt->format('d/m/Y H:i') : '—',
                'uploaded_raw' => $uploadedAt?->format('Y-m-d H:i:s'),
                'target_at' => $targetAt ? $targetAt->format('d/m/Y H:i') : '—',
                'status' => 'Pending',
                'status_detail' => $statusLabel,
                'owner' => $owner,
                'raw_status' => $raw,
            ];
        }

        // Sort pending by uploaded desc
        usort($pending, static function ($a, $b) {
            return strcmp((string) ($b['uploaded_raw'] ?? ''), (string) ($a['uploaded_raw'] ?? ''));
        });

        $summary = [
            'pending_for_sign' => $pendingCount,
            'created_today' => $createdToday,
            'signed_today' => $signedToday,
            'as_of' => $asOf->format('d/m/Y H:i'),
            'sap_touched' => !empty($sapTouch['ok']),
            'sap_note' => (string) ($sapTouch['note'] ?? ''),
        ];

        return [
            'success' => true,
            'summary' => $summary,
            'pending' => $pending,
            'live_url' => $this->liveReportUrl(),
            'error' => null,
        ];
    }

    /**
     * Recipients = Daily Report Assignment ONLY (role = daily_report).
     * Approvers / viewers are never included.
     *
     * @return list<array{username:string,email:string}>
     */
    public function resolveRecipients(): array
    {
        $out = [];
        $seen = [];
        try {
            require_once base_path('app/services/PoWorkflowAssignmentService.php');
            $cfg = (new PoWorkflowAssignmentService())->getGlobalConfig();
            // HARD RULE: only daily_reporters — ignore approvers / viewers completely
            $rows = is_array($cfg['daily_reporters'] ?? null) ? $cfg['daily_reporters'] : [];
            $mailCfg = function_exists('config') ? config('mail') : [];
            $fallbackDomain = trim((string) ($mailCfg['fallback_domain'] ?? 'evolvclothing.com'));

            require_once base_path('app/services/PoValidationLogService.php');
            $userSvc = new PoValidationLogService();

            foreach ($rows as $row) {
                // Skip any row that somehow isn't a daily_report role
                $role = strtolower(trim((string) ($row['role'] ?? PoWorkflowAssignmentService::ROLE_DAILY_REPORT)));
                if ($role !== '' && $role !== PoWorkflowAssignmentService::ROLE_DAILY_REPORT
                    && $role !== 'daily' && $role !== 'dailyreport' && $role !== 'daily-report') {
                    continue;
                }
                $username = trim((string) ($row['username'] ?? ''));
                $email = trim((string) ($row['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    if ($username !== '') {
                        $found = $userSvc->findUsersByUsernames([$username]);
                        $email = trim((string) ($found[0]['email'] ?? ''));
                    }
                }
                if (($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) && $username !== '') {
                    $email = strtolower($username) . '@' . $fallbackDomain;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $key = strtolower($email);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'username' => $username !== '' ? $username : $email,
                    'email' => $email,
                ];
            }
        } catch (Throwable $e) {
            error_log('PoDailyEsignReportService resolveRecipients: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * @param array{summary:array,pending:list<array>,live_url:string} $report
     */
    public function buildEmailHtml(array $report, DateTimeImmutable $asOf): string
    {
        $summary = $report['summary'] ?? $this->emptySummary();
        $pending = is_array($report['pending'] ?? null) ? $report['pending'] : [];
        $liveUrl = htmlspecialchars((string) ($report['live_url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $dateLabel = htmlspecialchars($asOf->format('d/m/Y H:i'), ENT_QUOTES, 'UTF-8');
        $pendingN = (int) ($summary['pending_for_sign'] ?? count($pending));
        $createdN = (int) ($summary['created_today'] ?? 0);
        $signedN = (int) ($summary['signed_today'] ?? 0);

        $rowsHtml = '';
        if ($pending === []) {
            $rowsHtml = '<tr><td colspan="4" style="padding:16px;text-align:center;color:#64748b;font-size:13px;">No pending POs.</td></tr>';
        } else {
            foreach ($pending as $r) {
                $po = htmlspecialchars('#' . (string) ($r['purchase_order'] ?? ''), ENT_QUOTES, 'UTF-8');
                $up = htmlspecialchars((string) ($r['uploaded_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $tg = htmlspecialchars((string) ($r['target_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $detail = htmlspecialchars((string) ($r['status_detail'] ?? ''), ENT_QUOTES, 'UTF-8');
                $rowsHtml .= <<<HTML
<tr>
  <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;font-size:13px;">{$po}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:12px;">{$up}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:12px;border-left:3px solid #a16207;">{$tg}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;">
    <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#ffedd5;color:#c2410c;font-size:11px;font-weight:800;">Pending</span>
    <div style="margin-top:4px;font-size:11px;color:#64748b;">{$detail}</div>
  </td>
</tr>
HTML;
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Segoe UI,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:20px 12px;">
    <tr><td align="center">
      <table width="680" cellpadding="0" cellspacing="0" style="max-width:680px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
        <tr>
          <td style="padding:22px 24px;background:#0f172a;color:#fff;">
            <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">EVOLV · PO E-SIGNATURE</div>
            <div style="font-size:22px;font-weight:800;margin-top:4px;">Daily Report</div>
            <div style="font-size:12px;margin-top:6px;opacity:.8;">Snapshot {$dateLabel}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 24px 8px;">
            <a href="{$liveUrl}" style="display:inline-block;padding:10px 16px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-size:13px;font-weight:800;">View live report</a>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 24px 18px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td width="33%" style="padding:4px;">
                  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 12px;text-align:center;">
                    <div style="font-size:11px;font-weight:800;letter-spacing:.06em;color:#1d4ed8;text-transform:uppercase;">Pending for Sign</div>
                    <div style="font-size:28px;font-weight:800;color:#1e40af;margin-top:4px;">{$pendingN}</div>
                  </div>
                </td>
                <td width="33%" style="padding:4px;">
                  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 12px;text-align:center;">
                    <div style="font-size:11px;font-weight:800;letter-spacing:.06em;color:#b45309;text-transform:uppercase;">Created Today</div>
                    <div style="font-size:28px;font-weight:800;color:#c2410c;margin-top:4px;">{$createdN}</div>
                  </div>
                </td>
                <td width="33%" style="padding:4px;">
                  <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:14px 12px;text-align:center;">
                    <div style="font-size:11px;font-weight:800;letter-spacing:.06em;color:#047857;text-transform:uppercase;">Signed Today</div>
                    <div style="font-size:28px;font-weight:800;color:#15803d;margin-top:4px;">{$signedN}</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:4px 24px 8px;">
            <div style="font-size:14px;font-weight:800;color:#0f172a;">Pending POs ({$pendingN})</div>
          </td>
        </tr>
        <tr>
          <td style="padding:0 16px 20px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
              <tr style="background:#f8fafc;">
                <th align="left" style="padding:10px 14px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;">PO No.</th>
                <th align="left" style="padding:10px 14px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;">Uploaded</th>
                <th align="left" style="padding:10px 14px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;">Target (+5D)</th>
                <th align="left" style="padding:10px 14px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;">Status</th>
              </tr>
              {$rowsHtml}
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:12px 24px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">
            Automated daily report · Evolv Clothing · SAP Insight · Sent at 23:00 IST
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /** @return array{pending_for_sign:int,created_today:int,signed_today:int,as_of:string,sap_touched:bool,sap_note:string} */
    private function emptySummary(): array
    {
        return [
            'pending_for_sign' => 0,
            'created_today' => 0,
            'signed_today' => 0,
            'as_of' => '',
            'sap_touched' => false,
            'sap_note' => '',
        ];
    }

    private function liveReportUrl(): string
    {
        $appUrl = '';
        if (function_exists('config')) {
            $appUrl = rtrim((string) (config('app')['url'] ?? ''), '/');
        }
        if ($appUrl === '') {
            $appUrl = 'http://localhost:8888/sap_reports';
        }
        return $appUrl . '/po-costing?panel=dashboard';
    }

    private function normalizePo(string $po): string
    {
        $po = strtoupper(trim(str_replace(['#', ' ', ','], '', $po)));
        if ($po === '' || !preg_match('/^\d+$/', $po)) {
            return $po;
        }
        $trimmed = ltrim($po, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private function statusLabel(string $raw, string $owner): string
    {
        $map = [
            'pending_kalai' => 'Under Review',
            'pending_signature' => 'Pending Signature',
            'pending_checker' => 'Under Review',
            'signed' => 'Partially Signed',
            'pending_authorised' => 'Pending Authorised',
        ];
        $base = $map[$raw] ?? ('Under Review');
        if ($owner !== '') {
            return $base . ' — ' . $owner;
        }
        return $base;
    }

    private function parseDt(mixed $v): ?DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            if ($v instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($v)->setTimezone(new DateTimeZone(self::TZ));
            }
            $s = trim((string) $v);
            if ($s === '') {
                return null;
            }
            return new DateTimeImmutable($s, new DateTimeZone(self::TZ));
        } catch (Throwable $e) {
            return null;
        }
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

    /** @return array<string,true> */
    private function columnMap(PDO $pdo, string $table): array
    {
        $out = [];
        try {
            $stmt = $pdo->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = N'dbo' AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            while ($name = $stmt->fetchColumn()) {
                $out[(string) $name] = true;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * @param array<string,true> $cols
     * @param list<string> $candidates
     */
    private function firstExisting(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (isset($cols[$c])) {
                return $c;
            }
            foreach ($cols as $name => $_) {
                if (strcasecmp($name, $c) === 0) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * Best-effort SAP call so the daily job always touches SAP + DB.
     * @return array{ok:bool,note:string}
     */
    private function touchSapPurchaseOrders(DateTimeImmutable $asOf): array
    {
        try {
            if (!is_file(base_path('app/services/SapPurchaseOrderService.php'))) {
                return ['ok' => false, 'note' => 'SapPurchaseOrderService missing'];
            }
            require_once base_path('app/services/SapPurchaseOrderService.php');
            $svc = new SapPurchaseOrderService();
            $from = $asOf->format('Y-m-d');
            $to = $asOf->format('Y-m-d');
            if (method_exists($svc, 'listReport')) {
                try {
                    $svc->listReport([
                        'from' => $from,
                        'to' => $to,
                        'range' => 'custom',
                    ]);
                    return ['ok' => true, 'note' => 'SAP listReport touched for ' . $from];
                } catch (Throwable $e) {
                    // fall through
                }
            }
            SapPurchaseOrderService::brands();
            return ['ok' => true, 'note' => 'SAP brands touched'];
        } catch (Throwable $e) {
            return ['ok' => false, 'note' => $e->getMessage()];
        }
    }
}
