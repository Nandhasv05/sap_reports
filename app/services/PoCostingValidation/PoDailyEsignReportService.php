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
        $fromEmail = (string) ($mailCfg['from']['address'] ?? 'apps@evolvclothing.com');
        $fromName = (string) ($mailCfg['from']['name'] ?? 'apps');
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

        $latestByPo = [];
        $sourceNotes = [];

        // 1) Primary: dbo.po_esign_requests (workflow statuses)
        if ($this->tableExists($pdo, 'po_esign_requests')) {
            try {
                $latestByPo = $this->loadFromEsignRequests($pdo);
                $sourceNotes[] = 'po_esign_requests=' . count($latestByPo);
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'summary' => $summary,
                    'pending' => [],
                    'live_url' => $this->liveReportUrl(),
                    'error' => 'po_esign_requests query failed: ' . $e->getMessage(),
                ];
            }
        } else {
            $sourceNotes[] = 'po_esign_requests=missing';
        }

        // 2) Fallback / merge: dbo.Pass_po_validtion (portal e-sign log)
        if ($this->tableExists($pdo, 'Pass_po_validtion')) {
            try {
                $fromPass = $this->loadFromPassPoValidation($pdo);
                $sourceNotes[] = 'Pass_po_validtion=' . count($fromPass);
                foreach ($fromPass as $po => $row) {
                    if (!isset($latestByPo[$po])) {
                        $latestByPo[$po] = $row;
                        continue;
                    }
                    // Prefer richer pending status from po_esign_requests; fill dates from Pass log if missing
                    if (empty($latestByPo[$po]['UploadedAt']) && !empty($row['UploadedAt'])) {
                        $latestByPo[$po]['UploadedAt'] = $row['UploadedAt'];
                    }
                    if (empty($latestByPo[$po]['SignedAt']) && !empty($row['SignedAt'])) {
                        $latestByPo[$po]['SignedAt'] = $row['SignedAt'];
                    }
                }
            } catch (Throwable $e) {
                $sourceNotes[] = 'Pass_po_validtion_error=' . $e->getMessage();
            }
        }

        // Optional SAP ping so the job always touches SAP as well (best-effort)
        $sapTouch = $this->touchSapPurchaseOrders($asOf);

        // KPI cards + open list = Draft / Review / Released / Approved (PO Validation Report)
        $valuation = $this->loadValuationSnapshot();
        $statusKpis = $valuation['summary'];
        $pending = $valuation['open_rows'];
        $sourceNotes[] = 'status_kpis=draft:' . $statusKpis['draft']
            . ',review:' . $statusKpis['review']
            . ',released:' . $statusKpis['released']
            . ',approved:' . $statusKpis['approved'];
        $sourceNotes[] = 'open_list=' . count($pending);

        $summary = [
            'draft' => $statusKpis['draft'],
            'review' => $statusKpis['review'],
            'released' => $statusKpis['released'],
            'approved' => $statusKpis['approved'],
            'total' => $statusKpis['total'],
            'pending_for_sign' => count($pending),
            'as_of' => $asOf->format('d/m/Y H:i'),
            'sap_touched' => !empty($sapTouch['ok']),
            'sap_note' => (string) ($sapTouch['note'] ?? ''),
            'source_note' => implode('; ', $sourceNotes),
            'rows_scanned' => count($latestByPo) + (int) ($valuation['records_total'] ?? 0),
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
     * Latest row per PO from dbo.po_esign_requests.
     *
     * @return array<string, array<string,mixed>>
     */
    private function loadFromEsignRequests(PDO $pdo): array
    {
        $cols = $this->columnMap($pdo, 'po_esign_requests');
        $createdCol = $this->firstExisting($cols, [
            'CreatedAt', 'CreatedOn', 'UploadedAt', 'UploadedOn', 'RequestDate', 'created_at', 'uploaded_at',
        ]);
        $signedCol = $this->firstExisting($cols, [
            'ApprovedAt', 'SignedAt', 'CheckedAt', 'CompletedAt', 'approved_at', 'signed_at', 'checked_at',
        ]);
        $updatedCol = $this->firstExisting($cols, ['UpdatedAt', 'updated_at', 'ModifiedAt']);

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
        return $latestByPo;
    }

    /**
     * Latest e-sign related row per PO from dbo.Pass_po_validtion.
     *
     * @return array<string, array<string,mixed>>
     */
    private function loadFromPassPoValidation(PDO $pdo): array
    {
        $sql = "
            SELECT TOP 5000
                purchase_order AS PoNumber,
                action,
                status AS Status,
                username AS UploadedBy,
                created_at AS UploadedAt,
                created_at AS UpdatedAt,
                CASE
                    WHEN LOWER(ISNULL(status, '')) LIKE N'%completed%'
                      OR LOWER(ISNULL(status, '')) LIKE N'%signed%'
                    THEN created_at
                    ELSE NULL
                END AS SignedAt,
                success
            FROM dbo.Pass_po_validtion
            WHERE LOWER(ISNULL(action, '')) IN (N'esign', N'pass', N'approve')
            ORDER BY id DESC
        ";
        $latestByPo = [];
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $po = $this->normalizePo((string) ($row['PoNumber'] ?? ''));
            if ($po === '' || isset($latestByPo[$po])) {
                continue;
            }
            $status = strtolower(trim((string) ($row['Status'] ?? '')));
            $action = strtolower(trim((string) ($row['action'] ?? '')));
            // Normalize portal statuses into the same buckets as po_esign_requests
            if ($status === '' || str_contains($status, 'completed') || (str_contains($status, 'signed') && !str_contains($status, 'pending'))) {
                if (!empty($row['success']) || str_contains($status, 'completed')) {
                    $row['Status'] = 'completed';
                }
            } elseif (str_contains($status, 'fail') || str_contains($status, 'reject')) {
                $row['Status'] = 'rejected';
            } elseif ($action === 'esign') {
                $row['Status'] = 'pending_signature';
            } elseif ($action === 'pass' || $action === 'approve') {
                // Passed/approved in portal but still track as pending sign if not completed
                if (!$this->isCompletedStatus($status)) {
                    $row['Status'] = 'pending_signature';
                }
            }
            $latestByPo[$po] = $row;
        }
        return $latestByPo;
    }

    private function isCompletedStatus(string $raw): bool
    {
        $raw = strtolower(trim($raw));
        return in_array($raw, ['completed', 'signed_completed', 'fully_signed', 'e-sign completed', 'esign completed'], true)
            || str_contains($raw, 'e-sign completed')
            || str_contains($raw, 'esign completed');
    }

    private function isPendingStatus(string $raw): bool
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || in_array($raw, ['completed', 'rejected', 'not_started', 'approved'], true)) {
            return false;
        }
        if ($this->isCompletedStatus($raw)) {
            return false;
        }
        if (str_contains($raw, 'reject') || str_contains($raw, 'fail')) {
            return false;
        }
        // pending_* / signed (partial) / portal pending labels
        return true;
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
        $draftN = (int) ($summary['draft'] ?? 0);
        $reviewN = (int) ($summary['review'] ?? 0);
        $releasedN = (int) ($summary['released'] ?? 0);
        $approvedN = (int) ($summary['approved'] ?? 0);
        $pendingN = (int) ($summary['pending_for_sign'] ?? count($pending));

        $badgeStyles = [
            'draft' => 'background:#dbeafe;color:#1d4ed8;',
            'review' => 'background:#ffedd5;color:#c2410c;',
            'released' => 'background:#ccfbf1;color:#0f766e;',
            'approved' => 'background:#dcfce7;color:#15803d;',
        ];

        $rowsHtml = '';
        if ($pending === []) {
            $rowsHtml = '<tr><td colspan="4" style="padding:16px;text-align:center;color:#64748b;font-size:13px;">No open POs in Draft / Review / Released.</td></tr>';
        } else {
            foreach ($pending as $r) {
                $po = htmlspecialchars('#' . (string) ($r['purchase_order'] ?? ''), ENT_QUOTES, 'UTF-8');
                $up = htmlspecialchars((string) ($r['uploaded_at'] ?? '-'), ENT_QUOTES, 'UTF-8');
                $tg = htmlspecialchars((string) ($r['target_at'] ?? '-'), ENT_QUOTES, 'UTF-8');
                $detail = htmlspecialchars((string) ($r['status_detail'] ?? ''), ENT_QUOTES, 'UTF-8');
                $bucket = strtolower(trim((string) ($r['bucket'] ?? 'draft')));
                if (!isset($badgeStyles[$bucket])) {
                    $bucket = 'draft';
                }
                $statusText = htmlspecialchars((string) ($r['status'] ?? ucfirst($bucket)), ENT_QUOTES, 'UTF-8');
                $badgeCss = $badgeStyles[$bucket];
                $rowsHtml .= <<<HTML
    <tr>
    <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;font-size:13px;">{$po}</td>
    <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:12px;">{$up}</td>
    <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:12px;border-left:3px solid #a16207;">{$tg}</td>
    <td style="padding:12px 14px;border-bottom:1px solid #e2e8f0;">
        <span style="display:inline-block;padding:3px 10px;border-radius:999px;{$badgeCss}font-size:11px;font-weight:800;">{$statusText}</span>
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
                    <td width="25%" style="padding:4px;">
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 8px;text-align:center;">
                        <div style="font-size:10px;font-weight:800;letter-spacing:.06em;color:#1d4ed8;text-transform:uppercase;">Draft</div>
                        <div style="font-size:26px;font-weight:800;color:#1e40af;margin-top:4px;">{$draftN}</div>
                    </div>
                    </td>
                    <td width="25%" style="padding:4px;">
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 8px;text-align:center;">
                        <div style="font-size:10px;font-weight:800;letter-spacing:.06em;color:#b45309;text-transform:uppercase;">Review</div>
                        <div style="font-size:26px;font-weight:800;color:#c2410c;margin-top:4px;">{$reviewN}</div>
                    </div>
                    </td>
                    <td width="25%" style="padding:4px;">
                    <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:14px 8px;text-align:center;">
                        <div style="font-size:10px;font-weight:800;letter-spacing:.06em;color:#0f766e;text-transform:uppercase;">Released</div>
                        <div style="font-size:26px;font-weight:800;color:#0f766e;margin-top:4px;">{$releasedN}</div>
                    </div>
                    </td>
                    <td width="25%" style="padding:4px;">
                    <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:14px 8px;text-align:center;">
                        <div style="font-size:10px;font-weight:800;letter-spacing:.06em;color:#047857;text-transform:uppercase;">Approved</div>
                        <div style="font-size:26px;font-weight:800;color:#15803d;margin-top:4px;">{$approvedN}</div>
                    </div>
                    </td>
                </tr>
                </table>
            </td>
            </tr>
            <tr>
            <td style="padding:4px 24px 8px;">
                <div style="font-size:14px;font-weight:800;color:#0f172a;">Open POs - Draft / Review / Released ({$pendingN})</div>
            </td>
            </tr>
            <tr>
            <td style="padding:0 16px 20px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
                <tr style="background:#f8fafc;">
                    <th align="left" style="padding:10px 14px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;">PO No.</th>
                    <th align="left" style="padding:10px 14px;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;">Updated</th>
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

    /**
     * Snapshot from PO Validation Report — KPIs + open (non-approved) rows for the email list.
     *
     * @return array{
     *   summary:array{draft:int,review:int,released:int,approved:int,total:int},
     *   open_rows:list<array<string,mixed>>,
     *   records_total:int
     * }
     */
    private function loadValuationSnapshot(): array
    {
        $summary = [
            'draft' => 0,
            'review' => 0,
            'released' => 0,
            'approved' => 0,
            'total' => 0,
        ];
        $openRows = [];
        $recordsTotal = 0;
        try {
            $path = base_path('app/services/PoValuationReportService.php');
            if (!is_file($path)) {
                return ['summary' => $summary, 'open_rows' => [], 'records_total' => 0];
            }
            require_once $path;
            $res = (new PoValuationReportService())->report([]);
            $sum = is_array($res['summary'] ?? null) ? $res['summary'] : [];
            $summary['draft'] = (int) ($sum['draft'] ?? 0);
            $summary['review'] = (int) ($sum['review'] ?? 0);
            $summary['released'] = (int) ($sum['released'] ?? 0);
            $summary['approved'] = (int) ($sum['approved'] ?? 0);
            $summary['total'] = (int) ($sum['total'] ?? (
                $summary['draft'] + $summary['review'] + $summary['released'] + $summary['approved']
            ));

            $records = is_array($res['records'] ?? null) ? $res['records'] : [];
            $recordsTotal = count($records);
            foreach ($records as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $bucket = strtolower(trim((string) ($row['bucket'] ?? '')));
                if (!in_array($bucket, ['draft', 'review', 'released'], true)) {
                    continue;
                }
                $po = $this->normalizePo((string) ($row['purchase_order'] ?? ''));
                if ($po === '') {
                    continue;
                }
                $whenRaw = trim((string) ($row['created_at'] ?? ''));
                $uploadedAt = $this->parseDt($whenRaw !== '' ? $whenRaw : null);
                $targetAt = $uploadedAt ? $uploadedAt->modify('+' . self::TARGET_DAYS . ' days') : null;
                $owner = trim((string) ($row['user'] ?? ''));
                $status = trim((string) ($row['status'] ?? ucfirst($bucket)));
                $remarks = trim((string) ($row['remarks'] ?? ''));
                $detail = $owner !== '' ? ($status . ' - ' . $owner) : $status;
                if ($remarks !== '') {
                    $detail .= ($owner !== '' ? ' | ' : ' - ') . $remarks;
                }
                $openRows[] = [
                    'purchase_order' => $po,
                    'uploaded_at' => $uploadedAt ? $uploadedAt->format('d/m/Y H:i') : ($whenRaw !== '' ? $whenRaw : '-'),
                    'uploaded_raw' => $uploadedAt ? $uploadedAt->format('Y-m-d H:i:s') : $whenRaw,
                    'target_at' => $targetAt ? $targetAt->format('d/m/Y H:i') : '-',
                    'status' => $status !== '' ? $status : ucfirst($bucket),
                    'bucket' => $bucket,
                    'status_detail' => $detail,
                    'owner' => $owner,
                    'raw_status' => $bucket,
                ];
            }

            usort($openRows, static function ($a, $b) {
                return strcmp((string) ($b['uploaded_raw'] ?? ''), (string) ($a['uploaded_raw'] ?? ''));
            });
        } catch (Throwable $e) {
            error_log('PoDailyEsignReportService loadValuationSnapshot: ' . $e->getMessage());
        }

        return [
            'summary' => $summary,
            'open_rows' => $openRows,
            'records_total' => $recordsTotal,
        ];
    }

    /** @return array{draft:int,review:int,released:int,approved:int,total:int,pending_for_sign:int,as_of:string,sap_touched:bool,sap_note:string} */
    private function emptySummary(): array
    {
        return [
            'draft' => 0,
            'review' => 0,
            'released' => 0,
            'approved' => 0,
            'total' => 0,
            'pending_for_sign' => 0,
            'as_of' => '',
            'sap_touched' => false,
            'sap_note' => '',
            'source_note' => '',
            'rows_scanned' => 0,
        ];
    }

    private function liveReportUrl(): string
    {
        $production = 'https://apps.evolvclothing.com/sap_reports';
        $appUrl = '';
        if (function_exists('config')) {
            $appUrl = rtrim((string) (config('app')['url'] ?? ''), '/');
        }
        // Cron/CLI often has no HTTP_HOST, so config falls back to localhost — use production public URL.
        if ($appUrl === '' || str_contains(strtolower($appUrl), 'localhost')) {
            $forced = trim((string) (getenv('EVOLV_PUBLIC_URL') ?: getenv('EVOLV_HTTP_HOST') ?: ''));
            if ($forced !== '') {
                if (!preg_match('#^https?://#i', $forced)) {
                    $forced = 'https://' . $forced;
                }
                $appUrl = rtrim($forced, '/');
                if (!preg_match('#/sap_reports$#i', $appUrl)) {
                    $appUrl .= '/sap_reports';
                }
            } else {
                $appUrl = $production;
            }
        }
        return $appUrl . '/po-costing?panel=valuation';
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
            return $base . ' - ' . $owner;
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
