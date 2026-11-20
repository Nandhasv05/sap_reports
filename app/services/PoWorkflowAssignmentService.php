<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 16/11/2026
 * DESCRIPTION : PO Costing Workflow Assignment — Approvers / Daily Report (dbo.po_costing_workflow_assignment)
 */
require_once base_path('app/core/Database.php');

class PoWorkflowAssignmentService
{
    private const TABLE = 'po_costing_workflow_assignment';

    /** Sentinel PO key for the global (common) workflow configuration — not a real PO. */
    public const GLOBAL_KEY = '__GLOBAL__';

    public const ROLE_VIEWER = 'viewer';
    public const ROLE_APPROVER = 'approver';
    public const ROLE_DAILY_REPORT = 'daily_report';

    public const VIEWER_STATUSES = [
        'Assigned',
        'Email Sent',
        'Viewed',
        'E-Sign Pending',
        'Signed',
    ];

    public const APPROVER_STATUSES = [
        'Assigned',
        'Email Sent',
        'Pending Approval',
        'Approved',
        'Rejected',
    ];

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
            self::$tableReady = $ok ? true : false;
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
            if (!$this->tableExists($pdo)) {
                try {
                    $pdo->exec("
                        IF OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL
                        BEGIN
                            CREATE TABLE [dbo].[" . self::TABLE . "] (
                                [id] BIGINT IDENTITY(1,1) NOT NULL,
                                [purchase_order] NVARCHAR(40) NOT NULL,
                                [role] NVARCHAR(20) NOT NULL,
                                [user_id] INT NULL,
                                [username] NVARCHAR(100) NOT NULL,
                                [email] NVARCHAR(255) NULL,
                                [status] NVARCHAR(40) NOT NULL,
                                [remarks] NVARCHAR(MAX) NULL,
                                [assigned_by] NVARCHAR(100) NULL,
                                [assigned_at] DATETIME2 NOT NULL CONSTRAINT [DF_pcw_wf_assigned_at] DEFAULT SYSUTCDATETIME(),
                                [email_sent_at] DATETIME2 NULL,
                                [email_count] INT NOT NULL CONSTRAINT [DF_pcw_wf_email_count] DEFAULT (0),
                                [viewed_at] DATETIME2 NULL,
                                [signed_at] DATETIME2 NULL,
                                [decided_at] DATETIME2 NULL,
                                [is_active] BIT NOT NULL CONSTRAINT [DF_pcw_wf_is_active] DEFAULT (1),
                                [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_pcw_wf_created] DEFAULT SYSUTCDATETIME(),
                                [updated_at] DATETIME2 NOT NULL CONSTRAINT [DF_pcw_wf_updated] DEFAULT SYSUTCDATETIME(),
                                CONSTRAINT [PK_po_costing_workflow_assignment] PRIMARY KEY CLUSTERED ([id])
                            );
                            CREATE NONCLUSTERED INDEX [IX_pcw_wf_po_role_active]
                                ON [dbo].[" . self::TABLE . "] ([purchase_order], [role], [is_active]);
                            CREATE NONCLUSTERED INDEX [IX_pcw_wf_user_active]
                                ON [dbo].[" . self::TABLE . "] ([username], [is_active], [created_at] DESC);
                        END
                    ");
                    self::$tableReady = null;
                    $this->tableExists($pdo);
                } catch (Throwable $createErr) {
                    error_log('PoWorkflowAssignmentService ensureTable: ' . $createErr->getMessage());
                    self::$tableReady = false;
                    return;
                }
            }
            // Existing DBs may still have legacy TEXT columns → clash with varchar binds
            $this->ensureNvarcharColumns($pdo);
        } catch (Throwable $e) {
            error_log('PoWorkflowAssignmentService ensureTable connect: ' . $e->getMessage());
            self::$tableReady = false;
        }
    }

    /**
     * Convert legacy TEXT/NTEXT columns to NVARCHAR so = / COALESCE work with varchar params.
     */
    private function ensureNvarcharColumns(PDO $pdo): void
    {
        $table = self::TABLE;
        $targets = [
            'purchase_order' => 'NVARCHAR(40) NOT NULL',
            'role' => 'NVARCHAR(20) NOT NULL',
            'username' => 'NVARCHAR(100) NOT NULL',
            'email' => 'NVARCHAR(255) NULL',
            'status' => 'NVARCHAR(40) NOT NULL',
            'assigned_by' => 'NVARCHAR(100) NULL',
            'remarks' => 'NVARCHAR(MAX) NULL',
        ];
        foreach ($targets as $col => $ddl) {
            try {
                $stmt = $pdo->prepare("
                    SELECT DATA_TYPE
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = N'dbo'
                      AND LOWER(TABLE_NAME) = LOWER(?)
                      AND LOWER(COLUMN_NAME) = LOWER(?)
                ");
                $stmt->execute([$table, $col]);
                $type = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
                if (!in_array($type, ['text', 'ntext'], true)) {
                    continue;
                }
                // TEXT/NTEXT cannot be compared to varchar — widen to NVARCHAR
                $pdo->exec("ALTER TABLE [dbo].[{$table}] ALTER COLUMN [{$col}] {$ddl}");
            } catch (Throwable $e) {
                error_log("PoWorkflowAssignmentService ensureNvarcharColumns {$col}: " . $e->getMessage());
            }
        }
    }

    public static function displayPo(string $po): string
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $display = ltrim($digits, '0');
        return $display !== '' ? $display : $digits;
    }

    private function normalizeRole(string $role): ?string
    {
        $r = strtolower(trim($role));
        if ($r === 'daily' || $r === 'dailyreport' || $r === 'daily-report') {
            $r = self::ROLE_DAILY_REPORT;
        }
        if ($r === self::ROLE_VIEWER || $r === self::ROLE_APPROVER || $r === self::ROLE_DAILY_REPORT) {
            return $r;
        }
        return null;
    }

    private function defaultStatus(string $role, bool $global = false): string
    {
        if ($global) {
            return 'Configured';
        }
        return $role === self::ROLE_APPROVER ? 'Assigned' : 'Assigned';
    }

    /**
     * Load the common (global) Approver / Daily Report configuration.
     *
     * @return array{success:bool,viewers:list<array>,approvers:list<array>,daily_reporters:list<array>,summary:array,error:?string,is_global:bool}
     */
    public function getGlobalConfig(): array
    {
        $list = $this->listByKey(self::GLOBAL_KEY, null);
        $list['is_global'] = true;
        $list['current_user_approver'] = null;
        return $list;
    }

    /**
     * Save common workflow configuration. Does NOT send emails.
     *
     * @param list<array{id?:int|string|null,username?:string,email?:string}> $viewers
     * @param list<array{id?:int|string|null,username?:string,email?:string}> $approvers
     * @param list<array{id?:int|string|null,username?:string,email?:string}> $dailyReporters
     * @return array{success:bool,viewers:list,approvers:list,daily_reporters:list,summary:array,error:?string,is_global:bool}
     */
    public function saveGlobalConfig(array $viewers, array $approvers, string $assignedBy, array $dailyReporters = []): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return [
                    'success' => false,
                    'viewers' => [],
                    'approvers' => [],
                    'daily_reporters' => [],
                    'summary' => $this->emptySummary(),
                    'is_global' => true,
                    'error' => 'Workflow table dbo.' . self::TABLE . ' is missing. Ask a DBA to create it.',
                ];
            }

            // Viewers retired from UI — always clear global viewer rows
            $this->syncRole($pdo, self::GLOBAL_KEY, self::ROLE_VIEWER, $viewers, $assignedBy, true);
            $this->syncRole($pdo, self::GLOBAL_KEY, self::ROLE_APPROVER, $approvers, $assignedBy, true);
            $this->syncRole($pdo, self::GLOBAL_KEY, self::ROLE_DAILY_REPORT, $dailyReporters, $assignedBy, true);

            $this->auditWorkflow(self::GLOBAL_KEY, 'config_save', [
                'viewers' => $viewers,
                'approvers' => $approvers,
                'daily_reporters' => $dailyReporters,
                'assigned_by' => $assignedBy,
            ], true);

            $cfg = $this->getGlobalConfig();
            return [
                'success' => true,
                'viewers' => $cfg['viewers'],
                'approvers' => $cfg['approvers'],
                'daily_reporters' => $cfg['daily_reporters'],
                'summary' => $cfg['summary'],
                'is_global' => true,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'viewers' => [],
                'approvers' => [],
                'daily_reporters' => [],
                'summary' => $this->emptySummary(),
                'is_global' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * When a PO enters the costing workflow: copy global config → per-PO rows (once),
     * then email all configured Approvers. Idempotent (skips already emailed).
     *
     * @return array{success:bool,applied:bool,notified:bool,sent:int,failed:int,skipped:int,viewers:list,approvers:list,daily_reporters:list,summary:array,error:?string}
     */
    public function applyGlobalToPoAndNotify(string $po, string $fromUsername = 'system'): array
    {
        $display = self::displayPo($po);
        if ($display === '' || $display === self::GLOBAL_KEY) {
            return [
                'success' => false,
                'applied' => false,
                'notified' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'error' => 'Purchase order is required',
            ];
        }

        $cfg = $this->getGlobalConfig();
        if (empty($cfg['success'])) {
            return [
                'success' => false,
                'applied' => false,
                'notified' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'error' => (string) ($cfg['error'] ?? 'Could not load global config'),
            ];
        }

        $viewers = $cfg['viewers'] ?? [];
        $approvers = $cfg['approvers'] ?? [];
        if ($viewers === [] && $approvers === []) {
            return [
                'success' => true,
                'applied' => false,
                'notified' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'error' => null,
            ];
        }

        // Materialize global users onto this PO (keeps per-PO eSign / approval status)
        $viewerUsers = array_map(static function ($r) {
            return [
                'id' => $r['user_id'] ?? null,
                'username' => $r['username'] ?? '',
                'email' => $r['email'] ?? '',
            ];
        }, $viewers);
        $approverUsers = array_map(static function ($r) {
            return [
                'id' => $r['user_id'] ?? null,
                'username' => $r['username'] ?? '',
                'email' => $r['email'] ?? '',
            ];
        }, $approvers);

        $saved = $this->saveAssignments($display, $viewerUsers, $approverUsers, $fromUsername);
        if (empty($saved['success'])) {
            return [
                'success' => false,
                'applied' => false,
                'notified' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'error' => (string) ($saved['error'] ?? 'Could not apply workflow to PO'),
            ];
        }

        $notify = $this->notify($display, null, $fromUsername, false);
        return [
            'success' => !empty($notify['success']),
            'applied' => true,
            'notified' => ((int) ($notify['sent'] ?? 0)) > 0,
            'sent' => (int) ($notify['sent'] ?? 0),
            'failed' => (int) ($notify['failed'] ?? 0),
            'skipped' => (int) ($notify['skipped'] ?? 0),
            'viewers' => $notify['viewers'] ?? $saved['viewers'],
            'approvers' => $notify['approvers'] ?? $saved['approvers'],
            'summary' => $notify['summary'] ?? $saved['summary'],
            'error' => $notify['error'] ?? null,
            'errors' => $notify['errors'] ?? [],
        ];
    }

    /**
     * After SAP Approve API success: email configured Approvers with PO PDF
     * and mark per-PO Approver status as Approved.
     *
     * @return array{success:bool,sent:int,failed:int,skipped:int,errors:list<string>,approvers:list,pdf:?string,error:?string}
     */
    public function notifyApproversOnSapApproved(string $po, string $fromUsername = 'system'): array
    {
        require_once base_path('app/services/SimpleMailService.php');
        require_once base_path('app/services/PoValidationLogService.php');
        require_once base_path('app/services/PoPassApprovalService.php');

        $display = self::displayPo($po);
        if ($display === '') {
            return [
                'success' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => ['Purchase order is required'],
                'approvers' => [],
                'pdf' => null,
                'error' => 'Purchase order is required',
            ];
        }

        $cfg = $this->getGlobalConfig();
        $globalApprovers = is_array($cfg['approvers'] ?? null) ? $cfg['approvers'] : [];
        if ($globalApprovers === []) {
            return [
                'success' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => [],
                'approvers' => [],
                'pdf' => null,
                'error' => null,
            ];
        }

        // Ensure per-PO approver rows exist (status tracking)
        $approverUsers = array_map(static function ($r) {
            return [
                'id' => $r['user_id'] ?? null,
                'username' => $r['username'] ?? '',
                'email' => $r['email'] ?? '',
            ];
        }, $globalApprovers);
        $existing = $this->listForPo($display, $fromUsername);
        $viewerUsers = array_map(static function ($r) {
            return [
                'id' => $r['user_id'] ?? null,
                'username' => $r['username'] ?? '',
                'email' => $r['email'] ?? '',
            ];
        }, is_array($existing['viewers'] ?? null) ? $existing['viewers'] : []);
        $this->saveAssignments($display, $viewerUsers, $approverUsers, $fromUsername);

        // Attach only a verified signed PDF (never cache/fetch unsigned SAP PDF to disk)
        $passSvc = new PoPassApprovalService();
        $signed = $passSvc->findEsignNetworkPdf($display);
        $dbOk = $passSvc->isEsignCompletedInDb($display);
        $pdfPath = null;
        $pdfName = null;
        if (!empty($signed['has_file']) && !empty($signed['path']) && @is_file((string) $signed['path']) && $dbOk) {
            $pdfPath = (string) $signed['path'];
            $pdfName = (string) ($signed['pdf_file'] ?? ('PO-' . $display . '.pdf'));
        }

        $mailCfg = function_exists('config') ? config('mail') : [];
        $fromEmail = (string) ($mailCfg['from']['address'] ?? 'apps@evolvclothing.com');
        $fromName = (string) ($mailCfg['from']['name'] ?? 'Evolv Apps');
        $fallbackDomain = trim((string) ($mailCfg['fallback_domain'] ?? 'evolvclothing.com'));
        $userSvc = new PoValidationLogService();
        $mailer = new SimpleMailService($mailCfg);

        $list = $this->listForPo($display, $fromUsername);
        $approvers = is_array($list['approvers'] ?? null) ? $list['approvers'] : [];
        $pdfLink = function_exists('url')
            ? url('purchase-order/pdf') . '?po=' . rawurlencode($display)
            : ('/sap_reports/purchase-order/pdf?po=' . rawurlencode($display));
        $workbench = function_exists('url')
            ? url('purchase-order') . '?view=costing&po=' . rawurlencode($display)
            : ('/sap_reports/purchase-order?view=costing&po=' . rawurlencode($display));

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $pdo = Database::connection();

        foreach ($approvers as $row) {
            $username = trim((string) ($row['username'] ?? ''));
            if ($username === '') {
                $skipped++;
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $found = $userSvc->findUsersByUsernames([$username]);
                $email = trim((string) ($found[0]['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower($username) . '@' . $fallbackDomain;
                }
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                $errors[] = 'Invalid email for ' . $username;
                // Still mark Approved locally
                $this->markApproverApproved($pdo, (int) ($row['id'] ?? 0), $email);
                continue;
            }

            $safePo = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
            $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $safeFrom = htmlspecialchars($fromUsername !== '' ? $fromUsername : 'System', ENT_QUOTES, 'UTF-8');
            $safePdf = htmlspecialchars($pdfLink, ENT_QUOTES, 'UTF-8');
            $safeWb = htmlspecialchars($workbench, ENT_QUOTES, 'UTF-8');
            $attachNote = $pdfPath
                ? 'The approved PO PDF is attached to this email.'
                : 'Open the PDF link below if the attachment is missing.';

            $subject = 'PO ' . $display . ' approved in SAP — PDF attached';
            $body = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
        <tr><td style="padding:20px 24px;background:#0f766e;color:#fff;">
          <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;font-weight:700;">PO Costing</div>
          <div style="font-size:20px;font-weight:800;margin-top:4px;">SAP Approved</div>
          <div style="margin-top:10px;display:inline-block;background:rgba(255,255,255,.18);padding:8px 12px;border-radius:999px;font-size:12px;font-weight:800;">PO {$safePo}</div>
        </td></tr>
        <tr><td style="padding:22px 24px;color:#0f172a;font-size:14px;line-height:1.55;">
          <p style="margin:0 0 12px;">Hi <strong>{$safeUser}</strong>,</p>
          <p style="margin:0 0 12px;"><strong>{$safeFrom}</strong> completed the SAP Approve API for PO <strong>{$safePo}</strong>. Status is now <strong style="color:#0f766e;">Approved</strong>.</p>
          <p style="margin:0 0 16px;">{$attachNote}</p>
          <p style="margin:0 0 10px;">
            <a href="{$safePdf}" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#0f766e;color:#fff;text-decoration:none;font-weight:800;">Open PDF</a>
            <a href="{$safeWb}" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#e2e8f0;color:#0f172a;text-decoration:none;font-weight:800;margin-left:8px;">Open Workbench</a>
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

            $attachments = [];
            if ($pdfPath && $pdfName) {
                $attachments[] = [
                    'path' => $pdfPath,
                    'name' => $pdfName,
                    'mime' => 'application/pdf',
                ];
            }

            $mail = $mailer->send([$email], $subject, $body, $fromEmail, $fromName, $attachments);
            $mailOk = (int) ($mail['sent'] ?? 0) > 0;
            if ($mailOk) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $username . ': ' . (string) (($mail['errors'][0] ?? null) ?: 'Mail not delivered');
            }

            $this->markApproverApproved($pdo, (int) ($row['id'] ?? 0), $email, $mailOk);
        }

        $this->auditWorkflow($display, 'sap_approve_notify', [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'pdf' => $pdfName,
            'errors' => $errors,
        ], $failed === 0 || $sent > 0);

        $fresh = $this->listForPo($display, $fromUsername);
        return [
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
            'approvers' => $fresh['approvers'] ?? $approvers,
            'pdf' => $pdfName,
            'error' => null,
        ];
    }

    private function markApproverApproved(PDO $pdo, int $id, string $email = '', bool $mailed = false): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            if ($mailed) {
                if ($email !== '') {
                    $upd = $pdo->prepare("
                        UPDATE [dbo].[" . self::TABLE . "]
                        SET status = N'Approved',
                            email = CAST(? AS NVARCHAR(255)),
                            email_sent_at = SYSUTCDATETIME(),
                            email_count = ISNULL(email_count, 0) + 1,
                            decided_at = COALESCE(decided_at, SYSUTCDATETIME()),
                            remarks = CASE WHEN remarks IS NULL THEN N'SAP Approved — PDF mailed' ELSE remarks END,
                            updated_at = SYSUTCDATETIME()
                        WHERE id = ? AND is_active = 1
                    ");
                    $upd->bindValue(1, $email, PDO::PARAM_STR);
                    $upd->bindValue(2, $id, PDO::PARAM_INT);
                    $upd->execute();
                } else {
                    $upd = $pdo->prepare("
                        UPDATE [dbo].[" . self::TABLE . "]
                        SET status = N'Approved',
                            email_sent_at = SYSUTCDATETIME(),
                            email_count = ISNULL(email_count, 0) + 1,
                            decided_at = COALESCE(decided_at, SYSUTCDATETIME()),
                            remarks = CASE WHEN remarks IS NULL THEN N'SAP Approved — PDF mailed' ELSE remarks END,
                            updated_at = SYSUTCDATETIME()
                        WHERE id = ? AND is_active = 1
                    ");
                    $upd->bindValue(1, $id, PDO::PARAM_INT);
                    $upd->execute();
                }
            } else {
                if ($email !== '') {
                    $upd = $pdo->prepare("
                        UPDATE [dbo].[" . self::TABLE . "]
                        SET status = N'Approved',
                            email = CAST(? AS NVARCHAR(255)),
                            decided_at = COALESCE(decided_at, SYSUTCDATETIME()),
                            remarks = CASE WHEN remarks IS NULL THEN N'SAP Approved' ELSE remarks END,
                            updated_at = SYSUTCDATETIME()
                        WHERE id = ? AND is_active = 1
                    ");
                    $upd->bindValue(1, $email, PDO::PARAM_STR);
                    $upd->bindValue(2, $id, PDO::PARAM_INT);
                    $upd->execute();
                } else {
                    $upd = $pdo->prepare("
                        UPDATE [dbo].[" . self::TABLE . "]
                        SET status = N'Approved',
                            decided_at = COALESCE(decided_at, SYSUTCDATETIME()),
                            remarks = CASE WHEN remarks IS NULL THEN N'SAP Approved' ELSE remarks END,
                            updated_at = SYSUTCDATETIME()
                        WHERE id = ? AND is_active = 1
                    ");
                    $upd->bindValue(1, $id, PDO::PARAM_INT);
                    $upd->execute();
                }
            }
        } catch (Throwable $ignored) {
        }
    }

    /**
     * @return array{success:bool,viewers:list<array>,approvers:list<array>,summary:array,error:?string,current_user_approver:?array}
     */
    public function listForPo(string $po, ?string $currentUsername = null): array
    {
        $display = self::displayPo($po);
        if ($display === '') {
            return [
                'success' => false,
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'current_user_approver' => null,
                'error' => 'Purchase order is required',
            ];
        }
        return $this->listByKey($display, $currentUsername);
    }

    /**
     * @return array{success:bool,viewers:list<array>,approvers:list<array>,summary:array,error:?string,current_user_approver:?array}
     */
    private function listByKey(string $key, ?string $currentUsername = null): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return [
                    'success' => true,
                    'viewers' => [],
                    'approvers' => [],
                    'daily_reporters' => [],
                    'summary' => $this->emptySummary(),
                    'current_user_approver' => null,
                    'error' => null,
                ];
            }

            $stmt = $pdo->prepare("
                SELECT id, purchase_order, role, user_id, username, email, status, remarks,
                       assigned_by, assigned_at, email_sent_at, email_count,
                       viewed_at, signed_at, decided_at, is_active, created_at, updated_at
                FROM [dbo].[" . self::TABLE . "]
                WHERE CAST(purchase_order AS NVARCHAR(40)) = ? AND is_active = 1
                ORDER BY CAST(role AS NVARCHAR(20)) ASC, CAST(username AS NVARCHAR(100)) ASC, id ASC
            ");
            $stmt->bindValue(1, $key, PDO::PARAM_STR);
            $stmt->execute();
            $viewers = [];
            $approvers = [];
            $dailyReporters = [];
            $currentApprover = null;
            $cur = strtolower(trim((string) $currentUsername));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $mapped = $this->mapRow($row);
                if ($mapped['role'] === self::ROLE_VIEWER) {
                    $viewers[] = $mapped;
                } elseif ($mapped['role'] === self::ROLE_DAILY_REPORT) {
                    $dailyReporters[] = $mapped;
                } else {
                    $approvers[] = $mapped;
                    if ($cur !== '' && strtolower((string) $mapped['username']) === $cur) {
                        $currentApprover = $mapped;
                    }
                }
            }

            return [
                'success' => true,
                'viewers' => $viewers,
                'approvers' => $approvers,
                'daily_reporters' => $dailyReporters,
                'summary' => $this->buildSummary($viewers, $approvers, $dailyReporters),
                'current_user_approver' => $currentApprover,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'viewers' => [],
                'approvers' => [],
                'daily_reporters' => [],
                'summary' => $this->emptySummary(),
                'current_user_approver' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upsert viewers + approvers for a PO. Deactivates removed users; keeps history.
     *
     * @param list<array{id?:int|string|null,username?:string,email?:string}> $viewers
     * @param list<array{id?:int|string|null,username?:string,email?:string}> $approvers
     * @return array{success:bool,viewers:list,approvers:list,summary:array,error:?string}
     */
    public function saveAssignments(
        string $po,
        array $viewers,
        array $approvers,
        string $assignedBy
    ): array {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return [
                    'success' => false,
                    'viewers' => [],
                    'approvers' => [],
                    'summary' => $this->emptySummary(),
                    'error' => 'Workflow table dbo.' . self::TABLE . ' is missing. Ask a DBA to create it.',
                ];
            }
            $display = self::displayPo($po);
            if ($display === '') {
                return [
                    'success' => false,
                    'viewers' => [],
                    'approvers' => [],
                    'summary' => $this->emptySummary(),
                    'error' => 'Purchase order is required',
                ];
            }

            $this->syncRole($pdo, $display, self::ROLE_VIEWER, $viewers, $assignedBy);
            $this->syncRole($pdo, $display, self::ROLE_APPROVER, $approvers, $assignedBy);

            $this->auditWorkflow($display, 'save', [
                'viewers' => $viewers,
                'approvers' => $approvers,
                'assigned_by' => $assignedBy,
            ], true);

            $list = $this->listForPo($display, $assignedBy);
            return [
                'success' => true,
                'viewers' => $list['viewers'],
                'approvers' => $list['approvers'],
                'summary' => $list['summary'],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /** Normalize portal user id for INT/BIGINT columns (never bind username as id). */
    private function normalizeUserId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        $s = trim((string) $raw);
        if ($s === '' || !preg_match('/^\d+$/', $s)) {
            return null;
        }
        $n = (int) $s;
        return $n > 0 ? $n : null;
    }

    /**
     * @param list<array{id?:int|string|null,username?:string,email?:string}> $users
     */
    private function syncRole(
        PDO $pdo,
        string $display,
        string $role,
        array $users,
        string $assignedBy,
        bool $global = false
    ): void {
        $wanted = [];
        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            $username = trim((string) ($u['username'] ?? $u['UserName'] ?? ''));
            if ($username === '') {
                continue;
            }
            $key = strtolower($username);
            // Prefer numeric id / user_id — never cast username into user_id
            $rawId = $u['id'] ?? $u['user_id'] ?? $u['Id'] ?? null;
            $wanted[$key] = [
                'user_id' => $this->normalizeUserId($rawId),
                'username' => $username,
                'email' => trim((string) ($u['email'] ?? '')),
            ];
        }

        // CAST avoids text = varchar (legacy TEXT columns) until ALTER completes
        $stmt = $pdo->prepare("
            SELECT id, username, email, status, email_count, is_active
            FROM [dbo].[" . self::TABLE . "]
            WHERE CAST(purchase_order AS NVARCHAR(40)) = ?
              AND CAST(role AS NVARCHAR(20)) = ?
        ");
        $stmt->bindValue(1, $display, PDO::PARAM_STR);
        $stmt->bindValue(2, $role, PDO::PARAM_STR);
        $stmt->execute();
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byUser = [];
        foreach ($existing as $row) {
            $byUser[strtolower(trim((string) ($row['username'] ?? '')))] = $row;
        }

        // Deactivate removed
        foreach ($byUser as $key => $row) {
            if (!isset($wanted[$key]) && !empty($row['is_active'])) {
                $upd = $pdo->prepare("
                    UPDATE [dbo].[" . self::TABLE . "]
                    SET is_active = 0, updated_at = SYSUTCDATETIME()
                    WHERE id = ?
                ");
                $upd->bindValue(1, (int) $row['id'], PDO::PARAM_INT);
                $upd->execute();
            }
        }

        $statusDefault = $this->defaultStatus($role, $global);

        // Insert or reactivate — bind INT/NULL explicitly; avoid COALESCE(varchar, text)
        foreach ($wanted as $key => $u) {
            if (isset($byUser[$key])) {
                $row = $byUser[$key];
                $emailSql = $u['email'] !== ''
                    ? 'email = CAST(? AS NVARCHAR(255)),'
                    : '';
                $uidSql = $u['user_id'] !== null ? 'user_id = ?,' : '';
                $upd = $pdo->prepare("
                    UPDATE [dbo].[" . self::TABLE . "]
                    SET is_active = 1,
                        {$uidSql}
                        {$emailSql}
                        assigned_by = ?,
                        assigned_at = CASE WHEN is_active = 0 THEN SYSUTCDATETIME() ELSE assigned_at END,
                        status = CASE
                            WHEN ? = 1 THEN CAST(? AS NVARCHAR(40))
                            WHEN is_active = 0 THEN CAST(? AS NVARCHAR(40))
                            ELSE CAST(status AS NVARCHAR(40))
                        END,
                        updated_at = SYSUTCDATETIME()
                    WHERE id = ?
                ");
                $bind = 1;
                if ($u['user_id'] !== null) {
                    $upd->bindValue($bind++, $u['user_id'], PDO::PARAM_INT);
                }
                if ($u['email'] !== '') {
                    $upd->bindValue($bind++, $u['email'], PDO::PARAM_STR);
                }
                if ($assignedBy !== '') {
                    $upd->bindValue($bind++, $assignedBy, PDO::PARAM_STR);
                } else {
                    $upd->bindValue($bind++, null, PDO::PARAM_NULL);
                }
                $upd->bindValue($bind++, $global ? 1 : 0, PDO::PARAM_INT);
                $upd->bindValue($bind++, $statusDefault, PDO::PARAM_STR);
                $upd->bindValue($bind++, $statusDefault, PDO::PARAM_STR);
                $upd->bindValue($bind++, (int) $row['id'], PDO::PARAM_INT);
                $upd->execute();
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO [dbo].[" . self::TABLE . "] (
                        purchase_order, role, user_id, username, email, status,
                        assigned_by, assigned_at, email_count, is_active, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), 0, 1, SYSUTCDATETIME(), SYSUTCDATETIME())
                ");
                $ins->bindValue(1, $display, PDO::PARAM_STR);
                $ins->bindValue(2, $role, PDO::PARAM_STR);
                if ($u['user_id'] !== null) {
                    $ins->bindValue(3, $u['user_id'], PDO::PARAM_INT);
                } else {
                    $ins->bindValue(3, null, PDO::PARAM_NULL);
                }
                $ins->bindValue(4, $u['username'], PDO::PARAM_STR);
                if ($u['email'] !== '') {
                    $ins->bindValue(5, $u['email'], PDO::PARAM_STR);
                } else {
                    $ins->bindValue(5, null, PDO::PARAM_NULL);
                }
                $ins->bindValue(6, $statusDefault, PDO::PARAM_STR);
                if ($assignedBy !== '') {
                    $ins->bindValue(7, $assignedBy, PDO::PARAM_STR);
                } else {
                    $ins->bindValue(7, null, PDO::PARAM_NULL);
                }
                $ins->execute();
            }
        }
    }

    /**
     * Send notification emails. Skips users already emailed unless $force.
     *
     * @param list<string>|null $roles
     * @return array{success:bool,sent:int,failed:int,skipped:int,errors:list<string>,viewers:list,approvers:list,summary:array,error:?string}
     */
    public function notify(
        string $po,
        ?array $roles,
        string $fromUsername,
        bool $force = false
    ): array {
        require_once base_path('app/services/SimpleMailService.php');
        require_once base_path('app/services/PoValidationLogService.php');

        $list = $this->listForPo($po, $fromUsername);
        if (empty($list['success'])) {
            return [
                'success' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => [(string) ($list['error'] ?? 'Could not load assignments')],
                'viewers' => [],
                'approvers' => [],
                'summary' => $this->emptySummary(),
                'error' => (string) ($list['error'] ?? 'Could not load assignments'),
            ];
        }

        $display = self::displayPo($po);
        $roleFilter = null;
        if (is_array($roles) && $roles !== []) {
            $roleFilter = [];
            foreach ($roles as $r) {
                $nr = $this->normalizeRole((string) $r);
                if ($nr) {
                    $roleFilter[$nr] = true;
                }
            }
        }

        $targets = [];
        foreach (['viewers' => self::ROLE_VIEWER, 'approvers' => self::ROLE_APPROVER] as $key => $role) {
            if ($roleFilter !== null && empty($roleFilter[$role])) {
                continue;
            }
            foreach ($list[$key] as $row) {
                $targets[] = $row;
            }
        }

        $mailCfg = function_exists('config') ? config('mail') : [];
        $fromEmail = (string) ($mailCfg['from']['address'] ?? 'apps@evolvclothing.com');
        $fromName = (string) ($mailCfg['from']['name'] ?? 'Evolv Apps');
        $fallbackDomain = trim((string) ($mailCfg['fallback_domain'] ?? 'evolvclothing.com'));
        $userSvc = new PoValidationLogService();
        $mailer = new SimpleMailService($mailCfg);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $pdo = Database::connection();

        foreach ($targets as $row) {
            $emailCount = (int) ($row['email_count'] ?? 0);
            if (!$force && $emailCount > 0) {
                $skipped++;
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            $username = (string) ($row['username'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $found = $userSvc->findUsersByUsernames([$username]);
                $email = trim((string) ($found[0]['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower($username) . '@' . $fallbackDomain;
                }
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                $errors[] = 'Invalid email for ' . $username;
                continue;
            }

            $role = (string) ($row['role'] ?? self::ROLE_VIEWER);
            $isViewer = $role === self::ROLE_VIEWER;
            $link = $this->buildLink($display, $isViewer);
            $subject = $isViewer
                ? (($fromUsername !== '' ? $fromUsername : 'Someone') . ' assigned you as Viewer for PO ' . $display)
                : (($fromUsername !== '' ? $fromUsername : 'Someone') . ' requests your approval for PO ' . $display);
            $body = $this->buildEmailHtml($display, $username, $fromUsername, $isViewer, $link);

            $mail = $mailer->send([$email], $subject, $body, $fromEmail, $fromName);
            $mailOk = (int) ($mail['sent'] ?? 0) > 0;

            $newStatus = $isViewer ? 'Email Sent' : 'Email Sent';
            // Approvers move toward Pending Approval after email
            if (!$isViewer) {
                $newStatus = 'Pending Approval';
            }

            try {
                $upd = $pdo->prepare("
                    UPDATE [dbo].[" . self::TABLE . "]
                    SET email = ?,
                        email_sent_at = SYSUTCDATETIME(),
                        email_count = ISNULL(email_count, 0) + 1,
                        status = CASE
                            WHEN status IN (N'Signed', N'Approved', N'Rejected', N'Viewed', N'E-Sign Pending') THEN status
                            ELSE ?
                        END,
                        updated_at = SYSUTCDATETIME()
                    WHERE id = ? AND is_active = 1
                ");
                // After first email for viewer keep Email Sent unless already further along
                $statusToSet = $isViewer ? 'Email Sent' : 'Pending Approval';
                $upd->execute([$email, $statusToSet, (int) $row['id']]);
            } catch (Throwable $ignored) {
            }

            if ($mailOk) {
                $sent++;
            } else {
                $failed++;
                $err = (string) (($mail['errors'][0] ?? null) ?: 'Mail not delivered');
                $errors[] = $username . ': ' . $err;
            }
        }

        $this->auditWorkflow($display, 'notify', [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
        ], $failed === 0 || $sent > 0);

        $fresh = $this->listForPo($display, $fromUsername);
        return [
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
            'viewers' => $fresh['viewers'],
            'approvers' => $fresh['approvers'],
            'summary' => $fresh['summary'],
            'error' => null,
        ];
    }

    /**
     * Viewer opens PO → Viewed / E-Sign Pending.
     *
     * @return array{success:bool,row:?array,error:?string}
     */
    public function ackViewer(string $po, string $username, bool $esignPending = false): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return ['success' => false, 'row' => null, 'error' => 'Workflow table missing'];
            }
            $display = self::displayPo($po);
            $username = trim($username);
            if ($display === '' || $username === '') {
                return ['success' => false, 'row' => null, 'error' => 'PO and username required'];
            }

            $stmt = $pdo->prepare("
                SELECT TOP 1 id, status FROM [dbo].[" . self::TABLE . "]
                WHERE CAST(purchase_order AS NVARCHAR(40)) = ?
                  AND CAST(role AS NVARCHAR(20)) = ?
                  AND LOWER(CAST(username AS NVARCHAR(100))) = LOWER(?)
                  AND is_active = 1
                ORDER BY id DESC
            ");
            $stmt->bindValue(1, $display, PDO::PARAM_STR);
            $stmt->bindValue(2, self::ROLE_VIEWER, PDO::PARAM_STR);
            $stmt->bindValue(3, $username, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => true, 'row' => null, 'error' => null]; // not a viewer — no-op
            }

            $cur = trim((string) ($row['status'] ?? ''));
            if (in_array($cur, ['Signed'], true)) {
                return ['success' => true, 'row' => $this->getById((int) $row['id']), 'error' => null];
            }

            $newStatus = $esignPending ? 'E-Sign Pending' : 'Viewed';
            if ($cur === 'E-Sign Pending' && !$esignPending) {
                $newStatus = 'E-Sign Pending';
            }
            if ($cur === 'Signed') {
                $newStatus = 'Signed';
            }

            $upd = $pdo->prepare("
                UPDATE [dbo].[" . self::TABLE . "]
                SET status = ?,
                    viewed_at = COALESCE(viewed_at, SYSUTCDATETIME()),
                    updated_at = SYSUTCDATETIME()
                WHERE id = ?
            ");
            $upd->execute([$newStatus, (int) $row['id']]);

            return ['success' => true, 'row' => $this->getById((int) $row['id']), 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'row' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Mark assigned viewer as Signed after eSign save.
     *
     * @return array{success:bool,row:?array,error:?string}
     */
    public function markViewerSigned(string $po, string $username): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return ['success' => false, 'row' => null, 'error' => 'Workflow table missing'];
            }
            $display = self::displayPo($po);
            $username = trim($username);
            if ($display === '' || $username === '') {
                return ['success' => false, 'row' => null, 'error' => 'PO and username required'];
            }

            $stmt = $pdo->prepare("
                SELECT TOP 1 id FROM [dbo].[" . self::TABLE . "]
                WHERE CAST(purchase_order AS NVARCHAR(40)) = ?
                  AND CAST(role AS NVARCHAR(20)) = ?
                  AND LOWER(CAST(username AS NVARCHAR(100))) = LOWER(?)
                  AND is_active = 1
                ORDER BY id DESC
            ");
            $stmt->bindValue(1, $display, PDO::PARAM_STR);
            $stmt->bindValue(2, self::ROLE_VIEWER, PDO::PARAM_STR);
            $stmt->bindValue(3, $username, PDO::PARAM_STR);
            $stmt->execute();
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id <= 0) {
                return ['success' => true, 'row' => null, 'error' => null];
            }

            $upd = $pdo->prepare("
                UPDATE [dbo].[" . self::TABLE . "]
                SET status = N'Signed',
                    signed_at = SYSUTCDATETIME(),
                    viewed_at = COALESCE(viewed_at, SYSUTCDATETIME()),
                    updated_at = SYSUTCDATETIME()
                WHERE id = ?
            ");
            $upd->execute([$id]);

            return ['success' => true, 'row' => $this->getById($id), 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'row' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approver Approve / Reject (local workflow only).
     *
     * @return array{success:bool,row:?array,error:?string}
     */
    public function decide(string $po, string $username, string $decision, string $remarks = ''): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return ['success' => false, 'row' => null, 'error' => 'Workflow table missing'];
            }
            $display = self::displayPo($po);
            $username = trim($username);
            $decision = strtolower(trim($decision));
            if ($display === '' || $username === '') {
                return ['success' => false, 'row' => null, 'error' => 'PO and username required'];
            }
            if (!in_array($decision, ['approve', 'approved', 'reject', 'rejected'], true)) {
                return ['success' => false, 'row' => null, 'error' => 'decision must be approve or reject'];
            }
            $approved = in_array($decision, ['approve', 'approved'], true);
            if (!$approved && trim($remarks) === '') {
                return ['success' => false, 'row' => null, 'error' => 'Remarks are required when rejecting'];
            }

            $stmt = $pdo->prepare("
                SELECT TOP 1 id, status FROM [dbo].[" . self::TABLE . "]
                WHERE CAST(purchase_order AS NVARCHAR(40)) = ?
                  AND CAST(role AS NVARCHAR(20)) = ?
                  AND LOWER(CAST(username AS NVARCHAR(100))) = LOWER(?)
                  AND is_active = 1
                ORDER BY id DESC
            ");
            $stmt->bindValue(1, $display, PDO::PARAM_STR);
            $stmt->bindValue(2, self::ROLE_APPROVER, PDO::PARAM_STR);
            $stmt->bindValue(3, $username, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'row' => null, 'error' => 'You are not an assigned Approver for this PO'];
            }

            $newStatus = $approved ? 'Approved' : 'Rejected';
            $upd = $pdo->prepare("
                UPDATE [dbo].[" . self::TABLE . "]
                SET status = ?,
                    remarks = ?,
                    decided_at = SYSUTCDATETIME(),
                    updated_at = SYSUTCDATETIME()
                WHERE id = ?
            ");
            $upd->execute([
                $newStatus,
                trim($remarks) !== '' ? trim($remarks) : null,
                (int) $row['id'],
            ]);

            $this->auditWorkflow($display, 'decide', [
                'username' => $username,
                'decision' => $newStatus,
                'remarks' => $remarks,
            ], true);

            return ['success' => true, 'row' => $this->getById((int) $row['id']), 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'row' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed>|null */
    private function getById(int $id): ?array
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("
                SELECT id, purchase_order, role, user_id, username, email, status, remarks,
                       assigned_by, assigned_at, email_sent_at, email_count,
                       viewed_at, signed_at, decided_at, is_active, created_at, updated_at
                FROM [dbo].[" . self::TABLE . "]
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->mapRow($row) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function buildLink(string $displayPo, bool $isViewer): string
    {
        $base = function_exists('url') ? url('purchase-order') : '/sap_reports/purchase-order';
        if ($isViewer) {
            return $base . '?view=costing&po=' . rawurlencode($displayPo) . '&open=esign&wf=viewer';
        }
        // Approvers open workbench to review + Approve / Reject
        return $base . '?view=costing&po=' . rawurlencode($displayPo) . '&wf=approver';
    }

    private function buildEmailHtml(
        string $po,
        string $toUser,
        string $fromUser,
        bool $isViewer,
        string $link
    ): string {
        $safePo = htmlspecialchars($po, ENT_QUOTES, 'UTF-8');
        $safeTo = htmlspecialchars($toUser, ENT_QUOTES, 'UTF-8');
        $safeFrom = htmlspecialchars($fromUser !== '' ? $fromUser : 'Someone', ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $roleLabel = $isViewer ? 'Viewer' : 'Approver';
        $cta = $isViewer ? 'Open PO &amp; E-Sign' : 'Open PO Approval';
        $blurb = $isViewer
            ? 'You have been assigned as a <strong>Viewer</strong>. Open the PO, review the document, and complete E-Sign when ready.'
            : 'You have been assigned as an <strong>Approver</strong>. Open the PO, review it, then Approve or Reject with remarks.';

        return <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
        <tr><td style="padding:20px 24px;background:#0f766e;color:#fff;">
          <div style="font-size:12px;opacity:.9;letter-spacing:.06em;text-transform:uppercase;">PO Costing Workflow</div>
          <div style="font-size:20px;font-weight:800;margin-top:4px;">{$roleLabel} assignment · PO {$safePo}</div>
        </td></tr>
        <tr><td style="padding:22px 24px;color:#0f172a;">
          <p style="margin:0 0 12px;font-size:14px;">Hi <strong>{$safeTo}</strong>,</p>
          <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#334155;">
            <strong>{$safeFrom}</strong> assigned you on purchase order <strong>{$safePo}</strong>.
            {$blurb}
          </p>
          <a href="{$safeLink}" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#0f766e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:800;">
            {$cta} →
          </a>
        </td></tr>
        <tr><td style="padding:14px 24px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;">
          <div style="font-size:11px;color:#94a3b8;">Sent by <strong style="color:#64748b;">{$safeFrom}</strong> · Evolv Clothing · SAP Insight</div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
    {
        $fmt = static function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            if (function_exists('sap_reports_to_mumbai')) {
                $m = sap_reports_to_mumbai($v);
                return $m !== '' ? $m : null;
            }
            return (string) $v;
        };

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'purchase_order' => (string) ($row['purchase_order'] ?? ''),
            'role' => strtolower(trim((string) ($row['role'] ?? ''))),
            'user_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'username' => trim((string) ($row['username'] ?? '')) ?: null,
            'email' => trim((string) ($row['email'] ?? '')) ?: null,
            'status' => trim((string) ($row['status'] ?? 'Assigned')) ?: 'Assigned',
            'remarks' => trim((string) ($row['remarks'] ?? '')) ?: null,
            'assigned_by' => trim((string) ($row['assigned_by'] ?? '')) ?: null,
            'assigned_at' => $fmt($row['assigned_at'] ?? null),
            'email_sent_at' => $fmt($row['email_sent_at'] ?? null),
            'email_count' => isset($row['email_count']) ? (int) $row['email_count'] : 0,
            'viewed_at' => $fmt($row['viewed_at'] ?? null),
            'signed_at' => $fmt($row['signed_at'] ?? null),
            'decided_at' => $fmt($row['decided_at'] ?? null),
            'is_active' => !empty($row['is_active']),
        ];
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'viewers_total' => 0,
            'approvers_total' => 0,
            'daily_reporters_total' => 0,
            'viewers_by_status' => [],
            'approvers_by_status' => [],
            'daily_reporters_by_status' => [],
        ];
    }

    /**
     * @param list<array> $viewers
     * @param list<array> $approvers
     * @param list<array> $dailyReporters
     * @return array<string,mixed>
     */
    private function buildSummary(array $viewers, array $approvers, array $dailyReporters = []): array
    {
        $countBy = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) {
                $s = (string) ($r['status'] ?? 'Assigned');
                $out[$s] = ($out[$s] ?? 0) + 1;
            }
            return $out;
        };
        return [
            'viewers_total' => count($viewers),
            'approvers_total' => count($approvers),
            'daily_reporters_total' => count($dailyReporters),
            'viewers_by_status' => $countBy($viewers),
            'approvers_by_status' => $countBy($approvers),
            'daily_reporters_by_status' => $countBy($dailyReporters),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function auditWorkflow(string $po, string $action, array $payload, bool $ok): void
    {
        try {
            // Pass_po_validtion requires numeric PO — skip sentinel __GLOBAL__
            $poDigits = preg_replace('/\D/', '', $po) ?? '';
            if ($poDigits === '') {
                return;
            }
            require_once base_path('app/services/PassPoValidationLogService.php');
            $user = function_exists('portal_user') ? portal_user() : null;
            $uid = $this->normalizeUserId($user['id'] ?? ($_SESSION['user_id'] ?? null));
            (new PassPoValidationLogService())->save([
                'user_id' => $uid,
                'username' => $user['username'] ?? ($_SESSION['username'] ?? null),
                'purchase_order' => $poDigits,
                'action' => 'workflow',
                'status' => $ok ? ('Workflow ' . ucfirst($action)) : ('Workflow ' . ucfirst($action) . ' Failed'),
                'method' => 'POST',
                'url' => 'local://po-costing/workflow/' . $action,
                'payload' => $payload,
                'response' => ['success' => $ok],
                'http_code' => $ok ? 200 : 500,
                'success' => $ok,
            ]);
        } catch (Throwable $ignored) {
        }
    }
}
