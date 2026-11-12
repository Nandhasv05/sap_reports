<?php
declare(strict_types=1);

/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/11/2026
 * DESCRIPTION : SAP Reports User Access Control Controller
 *               Restricted to: admin, saravan, nandhakumar
 *               Reports: View · PO Costing: View/Pass PO/Assign/Validate/eSign · ZCOST: Add/Update/Copy/Approve
 *               Persisted to dbo.sap_report_userascesscontrol
*/

require_once base_path('app/core/Controller.php');
require_once base_path('app/core/Database.php');
require_once base_path('app/core/portal_auth.php');
require_once base_path('app/core/access.php');

class UserAccessControlController extends Controller
{
    /** Canonical permission table (one table for all modules). */
    public const ACCESS_TABLE = 'sap_report_userascesscontrol';

    /**
     * Allowed usernames who can access this management screen
     */
    private const ALLOWED_ADMINS = ['admin', 'saravan', 'saravanan', 'nandhakumar'];

    /**
     * Check if current user is authorized to manage SAP Reports access
     */
    private function ensureAuthorized(): void
    {
        $user = function_exists('portal_user') ? portal_user() : null;
        $username = strtolower(trim((string) ($user['username'] ?? ($_SESSION['username'] ?? ''))));

        if (!in_array($username, self::ALLOWED_ADMINS, true)) {
            if (sap_reports_wants_json()) {
                sap_reports_json_error(403, 'Access denied. Only authorized administrators (admin, saravan, nandhakumar) can manage user permissions.', 'forbidden');
            }
            header('Location: ' . url('/'));
            exit;
        }
    }

    /**
     * Catalog of all 12 SAP Report pages
     */
    public static function getPagesCatalog(): array
    {
        $viewOnly = [
            ['key' => 'view', 'label' => 'View', 'column' => 'can_view'],
        ];
        $report = static function (array $page) use ($viewOnly): array {
            $page['kind'] = 'report';
            $page['actions'] = $viewOnly;
            return $page;
        };

        return [
            $report([
                'page_no'     => 1,
                'name'        => 'Purchase Order Report',
                'route'       => 'purchase-order',
                'category'    => 'Orders',
                'icon'        => 'fa-receipt',
                'icon_color'  => '#3b82f6',
                'icon_bg'     => '#eff6ff',
                'description' => 'PO line items, supplier breakdown, conditions, delivery status & pricing'
            ]),
            [
                'page_no'     => 2,
                'name'        => 'PO Costing Validation',
                'route'       => 'po-costing',
                'category'    => 'Costing',
                'kind'        => 'po-costing',
                'icon'        => 'fa-file-invoice-dollar',
                'icon_color'  => '#10b981',
                'icon_bg'     => '#ecfdf5',
                'description' => '',
                'actions'     => [
                    ['key' => 'view', 'label' => 'View', 'column' => 'can_view'],
                    ['key' => 'pass_po', 'label' => 'Pass PO', 'column' => 'can_approve'],
                    ['key' => 'assign', 'label' => 'Assign', 'column' => 'can_assign'],
                    ['key' => 'validate', 'label' => 'Validate', 'column' => 'can_validate'],
                    // Stored in can_copy (unused for PO Costing otherwise)
                    ['key' => 'esign', 'label' => 'eSign', 'column' => 'can_copy'],
                ],
            ],
            $report([
                'page_no'     => 3,
                'name'        => 'Sales Dashboard',
                'route'       => 'sales',
                'category'    => 'Analytics',
                'icon'        => 'fa-chart-line',
                'icon_color'  => '#8b5cf6',
                'icon_bg'     => '#f5f3ff',
                'description' => 'Sales KPIs, order fulfillment charts, revenue velocity & trends'
            ]),
            $report([
                'page_no'     => 4,
                'name'        => 'Fabric Utilization',
                'route'       => 'fabric',
                'category'    => 'Materials',
                'icon'        => 'fa-layer-group',
                'icon_color'  => '#0ea5e9',
                'icon_bg'     => '#e0f2fe',
                'description' => 'Fabric roll logs, cutting yield, wastage %, shrinkage & meter reconciliation'
            ]),
            $report([
                'page_no'     => 5,
                'name'        => 'Fabric Sale Order',
                'route'       => 'fabric-sale',
                'category'    => 'Sales',
                'icon'        => 'fa-tags',
                'icon_color'  => '#059669',
                'icon_bg'     => '#ecfdf5',
                'description' => 'Fabric sale orders, stock reservations, pricing & dispatch tracking'
            ]),
            $report([
                'page_no'     => 6,
                'name'        => 'Production Report',
                'route'       => 'production',
                'category'    => 'Manufacturing',
                'icon'        => 'fa-industry',
                'icon_color'  => '#d97706',
                'icon_bg'     => '#fffbeb',
                'description' => 'Assembly line efficiency, plant targets vs actuals, sewing output & WIP'
            ]),
            $report([
                'page_no'     => 7,
                'name'        => 'Procurement Report',
                'route'       => 'procurement',
                'category'    => 'Sourcing',
                'icon'        => 'fa-dolly',
                'icon_color'  => '#f97316',
                'icon_bg'     => '#fff7ed',
                'description' => 'Open PR status, vendor lead times, purchase orders & material lead metrics'
            ]),
            $report([
                'page_no'     => 8,
                'name'        => 'Trims Utilization',
                'route'       => 'trims',
                'category'    => 'Materials',
                'icon'        => 'fa-scissors',
                'icon_color'  => '#ec4899',
                'icon_bg'     => '#fdf2f8',
                'description' => 'Buttons, zippers, badges, packaging stock balance & line issue ledger'
            ]),
            $report([
                'page_no'     => 9,
                'name'        => 'Overall Utilization',
                'route'       => 'utilization',
                'category'    => 'Efficiency',
                'icon'        => 'fa-chart-pie',
                'icon_color'  => '#6366f1',
                'icon_bg'     => '#eef2ff',
                'description' => 'Consolidated material balance, global yield benchmarks & scrap analysis'
            ]),
            $report([
                'page_no'     => 10,
                'name'        => 'Audit Log',
                'route'       => 'audit-log',
                'category'    => 'Security',
                'admin_only'  => true,
                'icon'        => 'fa-clock-rotate-left',
                'icon_color'  => '#475569',
                'icon_bg'     => '#f1f5f9',
                'description' => ''
            ]),
            $report([
                'page_no'     => 11,
                'name'        => 'User Access Control',
                'route'       => 'user-access-control',
                'category'    => 'Administration',
                'admin_only'  => true,
                'icon'        => 'fa-user-lock',
                'icon_color'  => '#e11d48',
                'icon_bg'     => '#ffe4e6',
                'description' => ''
            ]),
            [
                'page_no'     => 12,
                'name'        => 'Zcost Costing Sheet',
                'route'       => 'zcost',
                'category'    => 'Costing',
                'kind'        => 'zcost',
                'icon'        => 'fa-calculator',
                'icon_color'  => '#ea580c',
                'icon_bg'     => '#ffedd5',
                'description' => 'Add sheet, update costing, copy costing, and approve costing.',
                'actions'     => [
                    ['key' => 'add', 'label' => 'Add', 'column' => 'can_add'],
                    ['key' => 'update', 'label' => 'Update', 'column' => 'can_edit'],
                    ['key' => 'copy', 'label' => 'Copy', 'column' => 'can_copy'],
                    ['key' => 'approve', 'label' => 'Approve', 'column' => 'can_approve'],
                ],
            ],
        ];
    }

    /**
     * Render the User Access Control page
     */
    public function index(): void
    {
        $this->ensureAuthorized();
        $this->ensureTableExists();

        $currentUser = function_exists('portal_user') ? portal_user() : null;
        $currentUsername = strtolower(trim((string) ($currentUser['username'] ?? ($_SESSION['username'] ?? ''))));

        $users = $this->fetchActiveUsers();

        $pages = self::getPagesCatalog();
        $pageTitle = 'User Access Control';

        $this->view('reports/user_access_control', [
            'users'           => $users,
            'pages'           => $pages,
            'currentUser'     => $currentUser,
            'currentUsername' => $currentUsername,
            'pageTitle'       => $pageTitle,
            'appShell'        => true,
            'layoutWide'      => true,
        ], 'layouts/main');
    }

    /**
     * API: Get permissions for a specific user ID
     */
    public function getUserPermissions(): void
    {
        $this->ensureAuthorized();
        $this->ensureTableExists();

        $userId = (int) ($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid user ID'], 422);
        }

        try {
            $pdo = Database::connection();
            $username = '';
            try {
                $uStmt = $pdo->prepare("SELECT TOP 1 UserName FROM [dbo].[users] WHERE Id = ?");
                $uStmt->execute([$userId]);
                $username = strtolower(trim((string) ($uStmt->fetchColumn() ?: '')));
            } catch (Throwable $e) {
                $username = '';
            }

            $table = self::ACCESS_TABLE;
            // Do not SELECT page_name/page_route — ODBC can raise 22001 on fetch for wide NVARCHAR cols,
            // and the API response only needs page_no + bit flags.
            $stmt = $pdo->prepare("
                SELECT page_no,
                       CAST(can_add AS INT) AS can_add,
                       CAST(can_view AS INT) AS can_view,
                       CAST(can_edit AS INT) AS can_edit,
                       CAST(can_approve AS INT) AS can_approve,
                       CAST(ISNULL(can_copy, 0) AS INT) AS can_copy,
                       CAST(ISNULL(can_assign, 0) AS INT) AS can_assign,
                       CAST(ISNULL(can_validate, 0) AS INT) AS can_validate
                FROM [dbo].[{$table}]
                WHERE user_id = ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback: match by username if this user_id has no rows yet
            if (!$rows && $username !== '') {
                $stmt2 = $pdo->prepare("
                    SELECT page_no,
                           CAST(can_add AS INT) AS can_add,
                           CAST(can_view AS INT) AS can_view,
                           CAST(can_edit AS INT) AS can_edit,
                           CAST(can_approve AS INT) AS can_approve,
                           CAST(ISNULL(can_copy, 0) AS INT) AS can_copy,
                           CAST(ISNULL(can_assign, 0) AS INT) AS can_assign,
                           CAST(ISNULL(can_validate, 0) AS INT) AS can_validate
                    FROM [dbo].[{$table}]
                    WHERE username IS NOT NULL AND LOWER(LTRIM(RTRIM(username))) = ?
                ");
                $stmt2->bindValue(1, $username, PDO::PARAM_STR);
                $stmt2->execute();
                $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            $permissions = [];
            foreach ($rows as $row) {
                $pNo = (int) $row['page_no'];
                if ($pNo <= 0) {
                    continue;
                }
                if (!isset($permissions[$pNo])) {
                    $permissions[$pNo] = [
                        'add' => 0, 'view' => 0, 'edit' => 0, 'approve' => 0,
                        'copy' => 0, 'pass_po' => 0, 'update' => 0,
                        'assign' => 0, 'validate' => 0, 'esign' => 0,
                    ];
                }
                $permissions[$pNo]['add'] = max($permissions[$pNo]['add'], (int) $row['can_add']);
                $permissions[$pNo]['view'] = max($permissions[$pNo]['view'], (int) $row['can_view']);
                $permissions[$pNo]['edit'] = max($permissions[$pNo]['edit'], (int) $row['can_edit']);
                $permissions[$pNo]['update'] = $permissions[$pNo]['edit'];
                $permissions[$pNo]['approve'] = max($permissions[$pNo]['approve'], (int) $row['can_approve']);
                $permissions[$pNo]['copy'] = max($permissions[$pNo]['copy'], (int) ($row['can_copy'] ?? 0));
                $permissions[$pNo]['esign'] = $permissions[$pNo]['copy']; // PO Costing eSign uses can_copy
                $permissions[$pNo]['pass_po'] = $permissions[$pNo]['approve'];
                $permissions[$pNo]['assign'] = max($permissions[$pNo]['assign'], (int) ($row['can_assign'] ?? 0));
                $permissions[$pNo]['validate'] = max($permissions[$pNo]['validate'], (int) ($row['can_validate'] ?? 0));
            }

            $this->jsonResponse([
                'success'     => true,
                'user_id'     => $userId,
                'permissions' => $permissions
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * API: Save permissions for a specific user
     */
    public function saveUserPermissions(): void
    {
        $this->ensureAuthorized();
        $this->ensureTableExists();

        $input = json_decode(sap_reports_request_raw_body(), true);
        if (!is_array($input)) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 422);
        }

        $userId = (int) ($input['user_id'] ?? 0);
        $username = trim((string) ($input['username'] ?? ''));
        $permissions = $input['permissions'] ?? [];

        if ($userId <= 0 || !is_array($permissions)) {
            $this->jsonResponse(['success' => false, 'error' => 'Missing user_id or permissions array'], 422);
        }

        try {
            $pdo = Database::connection();

            // Always trust users.UserName from DB (never email / client display text)
            $uStmt = $pdo->prepare('SELECT UserName FROM [dbo].[users] WHERE Id = ?');
            $uStmt->execute([$userId]);
            $dbUser = trim((string) ($uStmt->fetchColumn() ?: ''));
            if ($dbUser !== '') {
                $username = $dbUser;
            } elseif ($username === '') {
                $username = 'user' . $userId;
            }

            $pagesCatalog = self::getPagesCatalog();
            $catalogMap = [];
            foreach ($pagesCatalog as $p) {
                $catalogMap[$p['page_no']] = $p;
            }

            $table = self::ACCESS_TABLE;
            $this->ensureBitColumns($pdo);
            $this->ensureStringColumnsWide($pdo);

            try {
                $result = $this->persistPermissions($pdo, $table, $userId, $username, $permissions, $catalogMap);
            } catch (Throwable $first) {
                // SQLSTATE 22001 = string truncation — widen/rebuild then retry once
                if (!$this->isStringTruncation($first)) {
                    throw $first;
                }
                error_log('UAC save truncation — repairing schema: ' . $first->getMessage());
                $this->ensureStringColumnsWide($pdo, true);
                $result = $this->persistPermissions($pdo, $table, $userId, $username, $permissions, $catalogMap);
            }

            $this->jsonResponse([
                'success' => true,
                'message' => "Successfully saved permissions for user {$username} ({$result} pages updated)",
                'user_id' => $userId,
                'saved'   => $result,
                'save_version' => 'uac-v7',
                'table' => self::ACCESS_TABLE,
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $ignored) {
                }
            }
            $msg = $e->getMessage();
            if ($this->isStringTruncation($e)) {
                $msg = 'Permission text too long for database column. Refresh the page and save again. (' . $msg . ')';
            }
            $this->jsonResponse([
                'success' => false,
                'error' => $msg,
                'save_version' => 'uac-v7',
                'table' => self::ACCESS_TABLE,
            ], 500);
        }
    }

    /**
     * Replace all rights for one user. Strings come only from the server catalog (never from client).
     *
     * @param array<int, array<string, mixed>> $catalogMap
     * @param list<array<string, mixed>> $permissions
     */
    private function persistPermissions(
        PDO $pdo,
        string $table,
        int $userId,
        string $username,
        array $permissions,
        array $catalogMap
    ): int {
        $limits = $this->stringColumnLimits($pdo, $table);
        $userMax = max(40, (int) ($limits['username'] ?? 255));
        $nameMax = max(40, (int) ($limits['page_name'] ?? 255));
        $routeMax = max(40, (int) ($limits['page_route'] ?? 255));

        // Login name only — strip email/display junk, clip to column width
        $safeUser = strtolower($this->clip(preg_replace('/[^a-zA-Z0-9._-]/', '', $username) ?? '', $userMax));
        if ($safeUser === '' || str_contains($safeUser, '@')) {
            $safeUser = $this->clip('user' . $userId, $userMax);
        }

        $byPage = [];
        foreach ($permissions as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pageNo = (int) ($p['page_no'] ?? 0);
            if ($pageNo > 0) {
                $byPage[$pageNo] = $p;
            }
        }

        $adminOnlyUsers = ['admin', 'nandhakumar', 'saravanan', 'saravan'];
        $isAdminTarget = in_array($safeUser, $adminOnlyUsers, true);
        $zcostSync = null;
        $savedCount = 0;

        // ODBC + ANSI_WARNINGS ON turns any length mismatch into SQLSTATE 22001
        try {
            $pdo->exec('SET ANSI_WARNINGS OFF');
        } catch (Throwable $ignored) {
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM [dbo].[' . $table . '] WHERE user_id = ?');
            $del->bindValue(1, (int) $userId, PDO::PARAM_INT);
            $del->execute();

            // Plain binds + PHP clip (no CAST) — CAST(? AS NVARCHAR(n)) itself can raise 22001 under ODBC
            $ins = $pdo->prepare(
                'INSERT INTO [dbo].[' . $table . ']
                    (user_id, username, page_no, page_name, page_route,
                     can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())'
            );

            foreach ($catalogMap as $pageNo => $meta) {
                $pageNo = (int) $pageNo;
                $client = $byPage[$pageNo] ?? [];
                $flags = $this->normalizePagePermissions($client, is_array($meta) ? $meta : []);

                if (!empty($meta['admin_only']) && !$isAdminTarget) {
                    $flags = [
                        'can_add' => 0, 'can_view' => 0, 'can_edit' => 0, 'can_approve' => 0,
                        'can_copy' => 0, 'can_assign' => 0, 'can_validate' => 0,
                    ];
                }

                $pageName = $this->clip((string) ($meta['name'] ?? ('Page ' . $pageNo)), $nameMax);
                $pageRoute = $this->clip((string) ($meta['route'] ?? ''), $routeMax);

                $ins->bindValue(1, (int) $userId, PDO::PARAM_INT);
                $ins->bindValue(2, $safeUser, PDO::PARAM_STR);
                $ins->bindValue(3, $pageNo, PDO::PARAM_INT);
                $ins->bindValue(4, $pageName, PDO::PARAM_STR);
                $ins->bindValue(5, $pageRoute, PDO::PARAM_STR);
                $ins->bindValue(6, (int) $flags['can_add'], PDO::PARAM_INT);
                $ins->bindValue(7, (int) $flags['can_view'], PDO::PARAM_INT);
                $ins->bindValue(8, (int) $flags['can_edit'], PDO::PARAM_INT);
                $ins->bindValue(9, (int) $flags['can_approve'], PDO::PARAM_INT);
                $ins->bindValue(10, (int) $flags['can_copy'], PDO::PARAM_INT);
                $ins->bindValue(11, (int) $flags['can_assign'], PDO::PARAM_INT);
                $ins->bindValue(12, (int) $flags['can_validate'], PDO::PARAM_INT);
                $ins->execute();
                $savedCount++;

                if ($pageNo === 12 || $pageRoute === 'zcost') {
                    $zcostSync = [
                        $safeUser, $userId,
                        (int) $flags['can_view'], (int) $flags['can_add'], (int) $flags['can_edit'],
                        (int) $flags['can_approve'], (int) $flags['can_copy'],
                    ];
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            try {
                $pdo->exec('SET ANSI_WARNINGS ON');
            } catch (Throwable $ignored) {
            }
        }

        if (is_array($zcostSync)) {
            try {
                $this->syncZcostPermissions(
                    $zcostSync[0], $zcostSync[1], $zcostSync[2], $zcostSync[3],
                    $zcostSync[4], $zcostSync[5], $zcostSync[6]
                );
            } catch (Throwable $e) {
                error_log('UAC zcost sync ignored: ' . $e->getMessage());
            }
        }

        return $savedCount;
    }

    private function isStringTruncation(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, '22001')
            || stripos($msg, 'String data, right truncated') !== false
            || stripos($msg, 'right truncation') !== false;
    }

    /**
     * Widen username / page_name / page_route to NVARCHAR(100).
     * Names like "PO Costing Validation" (21 chars) fail on NVARCHAR(20).
     * Rebuilds the table when ALTER is blocked.
     */
    private function ensureStringColumnsWide(PDO $pdo, bool $forceRebuild = false): void
    {
        $table = self::ACCESS_TABLE;

        if ($forceRebuild) {
            $this->rebuildAccessTable($pdo);
            return;
        }

        $limits = $this->stringColumnLimits($pdo, $table);
        $alterFailed = false;
        $targets = ['username' => 255, 'page_name' => 255, 'page_route' => 255];
        foreach (['username' => 'NULL', 'page_name' => 'NOT NULL', 'page_route' => 'NULL'] as $col => $nullability) {
            $cur = (int) ($limits[$col] ?? 0);
            $want = (int) $targets[$col];
            if ($cur <= 0 || $cur >= $want) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE [dbo].[{$table}] ALTER COLUMN [{$col}] NVARCHAR({$want}) {$nullability}");
            } catch (Throwable $e) {
                error_log("UAC ALTER {$col}: " . $e->getMessage());
                $alterFailed = true;
            }
        }

        $limits = $this->stringColumnLimits($pdo, $table);
        $stillNarrow = (($limits['username'] ?? 255) < 40)
            || (($limits['page_name'] ?? 255) < 40)
            || (($limits['page_route'] ?? 255) < 40);

        if ($alterFailed || $stillNarrow) {
            $this->rebuildAccessTable($pdo);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchActiveUsers(): array
    {
        $queries = [
            "SELECT Id, UserName, Email, Role, Department, IsActive FROM [dbo].[users] WHERE IsActive = 1 ORDER BY UserName ASC",
            "SELECT Id, UserName, Email, Role, CAST('' AS NVARCHAR(80)) AS Department, IsActive FROM [dbo].[users] WHERE IsActive = 1 ORDER BY UserName ASC",
            "SELECT Id, UserName, CAST('' AS NVARCHAR(150)) AS Email, Role, CAST('' AS NVARCHAR(80)) AS Department, IsActive FROM [dbo].[users] WHERE IsActive = 1 ORDER BY UserName ASC",
        ];
        foreach ($queries as $sql) {
            try {
                $pdo = Database::connection();
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                return is_array($rows) ? $rows : [];
            } catch (Throwable $e) {
                error_log('UserAccessControl fetch users fallback: ' . $e->getMessage());
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $meta
     * @return array{can_add:int,can_view:int,can_edit:int,can_approve:int,can_copy:int,can_assign:int,can_validate:int}
     */
    private function normalizePagePermissions(array $p, array $meta): array
    {
        $kind = (string) ($meta['kind'] ?? 'report');
        $flags = [
            'can_add' => 0,
            'can_view' => 0,
            'can_edit' => 0,
            'can_approve' => 0,
            'can_copy' => 0,
            'can_assign' => 0,
            'can_validate' => 0,
        ];
        $actions = is_array($meta['actions'] ?? null) ? $meta['actions'] : [];
        foreach ($actions as $act) {
            $column = (string) ($act['column'] ?? '');
            $key = (string) ($act['key'] ?? '');
            if ($column === '' || !array_key_exists($column, $flags)) {
                continue;
            }
            $on = !empty($p[$column]) || !empty($p[$key]) || ($key !== '' && !empty($p['can_' . $key]));
            if ($on) {
                $flags[$column] = 1;
            }
        }

        if ($kind === 'report') {
            $flags['can_add'] = 0;
            $flags['can_edit'] = 0;
            $flags['can_approve'] = 0;
            $flags['can_copy'] = 0;
            $flags['can_assign'] = 0;
            $flags['can_validate'] = 0;
        } elseif ($kind === 'po-costing') {
            $flags['can_add'] = 0;
            $flags['can_edit'] = 0;
            // can_copy stores eSign for PO Costing
            // View can stand alone; Pass PO / Assign / Validate / eSign keep their own bits
            if ($flags['can_approve'] === 1 || $flags['can_assign'] === 1 || $flags['can_validate'] === 1 || $flags['can_copy'] === 1) {
                $flags['can_view'] = 1;
            }
        } elseif ($kind === 'zcost') {
            $flags['can_assign'] = 0;
            $flags['can_validate'] = 0;
            $flags['can_view'] = (
                $flags['can_add'] === 1
                || $flags['can_edit'] === 1
                || $flags['can_copy'] === 1
                || $flags['can_approve'] === 1
            ) ? 1 : 0;
        }

        return $flags;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        $max = max(1, $max);
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $max, 'UTF-8');
        }
        return substr($value, 0, $max);
    }

    /**
     * @return array{username:int,page_name:int,page_route:int}
     */
    private function stringColumnLimits(PDO $pdo, string $table): array
    {
        $defaults = ['username' => 255, 'page_name' => 255, 'page_route' => 255];
        try {
            $stmt = $pdo->prepare("
                SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo'
                  AND LOWER(TABLE_NAME) = LOWER(?)
                  AND LOWER(COLUMN_NAME) IN ('username', 'page_name', 'page_route')
            ");
            $stmt->execute([$table]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
                $len = (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
                if ($name !== '' && $len > 0) {
                    $defaults[$name] = min(255, $len);
                } elseif ($name !== '' && $len < 0) {
                    // NVARCHAR(MAX)
                    $defaults[$name] = 255;
                }
            }
        } catch (Throwable $e) {
            // keep defaults
        }
        return $defaults;
    }

    private function ensureBitColumns(PDO $pdo): void
    {
        $table = self::ACCESS_TABLE;
        $suffix = substr(sha1((string) microtime(true)), 0, 8);
        foreach (['can_copy', 'can_assign', 'can_validate'] as $bitCol) {
            try {
                $pdo->exec("
                    IF COL_LENGTH('dbo.{$table}', '{$bitCol}') IS NULL
                    BEGIN
                        ALTER TABLE [dbo].[{$table}] ADD [{$bitCol}] BIT NOT NULL
                            CONSTRAINT [DF_uac_{$bitCol}_{$suffix}] DEFAULT 0;
                    END
                ");
            } catch (Throwable $e) {
                error_log("ensureBitColumns {$bitCol}: " . $e->getMessage());
            }
        }
    }

    /** @deprecated use ensureBitColumns — kept so older call sites do not fatally error */
    private function repairAccessTableSchema(PDO $pdo): void
    {
        $this->ensureBitColumns($pdo);
    }

    /**
     * Recreate access table with wide string columns.
     * Copies into a temp table, drops the live table, renames temp — no leftover _bak tables.
     */
    private function rebuildAccessTable(PDO $pdo): void
    {
        $table = self::ACCESS_TABLE;
        $suffix = date('YmdHis') . substr(sha1((string) microtime(true)), 0, 4);
        $temp = 'uac_tmp_' . $suffix;

        $pdo->exec("IF OBJECT_ID(N'[dbo].[{$temp}]', N'U') IS NOT NULL DROP TABLE [dbo].[{$temp}]");

        $pdo->exec("
            CREATE TABLE [dbo].[{$temp}] (
                [id] INT IDENTITY(1,1) NOT NULL,
                [user_id] INT NOT NULL,
                [username] NVARCHAR(255) NULL,
                [page_no] INT NOT NULL,
                [page_name] NVARCHAR(255) NOT NULL,
                [page_route] NVARCHAR(255) NULL,
                [can_add] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_add] DEFAULT 0,
                [can_view] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_view] DEFAULT 0,
                [can_edit] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_edit] DEFAULT 0,
                [can_approve] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_approve] DEFAULT 0,
                [can_copy] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_copy] DEFAULT 0,
                [can_assign] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_assign] DEFAULT 0,
                [can_validate] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_validate] DEFAULT 0,
                [created_at] DATETIME NOT NULL CONSTRAINT [DF_uac_{$suffix}_created] DEFAULT GETDATE(),
                [updated_at] DATETIME NOT NULL CONSTRAINT [DF_uac_{$suffix}_updated] DEFAULT GETDATE(),
                CONSTRAINT [PK_uac_{$suffix}] PRIMARY KEY ([id]),
                CONSTRAINT [uq_uac_{$suffix}_user_page] UNIQUE ([user_id], [page_no])
            )
        ");

        $exists = (bool) $pdo->query("SELECT OBJECT_ID(N'[dbo].[{$table}]', N'U')")->fetchColumn();
        if ($exists) {
            try {
                $pdo->exec("
                    INSERT INTO [dbo].[{$temp}]
                    (user_id, username, page_no, page_name, page_route,
                     can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate,
                     created_at, updated_at)
                    SELECT
                        user_id,
                        LEFT(CAST(ISNULL(username,'') AS NVARCHAR(255)), 255),
                        page_no,
                        LEFT(CAST(ISNULL(page_name,'Page') AS NVARCHAR(255)), 255),
                        LEFT(CAST(ISNULL(page_route,'') AS NVARCHAR(255)), 255),
                        CAST(ISNULL(can_add,0) AS BIT),
                        CAST(ISNULL(can_view,0) AS BIT),
                        CAST(ISNULL(can_edit,0) AS BIT),
                        CAST(ISNULL(can_approve,0) AS BIT),
                        CAST(ISNULL(can_copy,0) AS BIT),
                        CAST(ISNULL(can_assign,0) AS BIT),
                        CAST(ISNULL(can_validate,0) AS BIT),
                        ISNULL(created_at, GETDATE()),
                        ISNULL(updated_at, GETDATE())
                    FROM [dbo].[{$table}]
                ");
            } catch (Throwable $e) {
                try {
                    $pdo->exec("
                        INSERT INTO [dbo].[{$temp}]
                        (user_id, username, page_no, page_name, page_route,
                         can_add, can_view, can_edit, can_approve, created_at, updated_at)
                        SELECT
                            user_id,
                            LEFT(CAST(ISNULL(username,'') AS NVARCHAR(255)), 255),
                            page_no,
                            LEFT(CAST(ISNULL(page_name,'Page') AS NVARCHAR(255)), 255),
                            LEFT(CAST(ISNULL(page_route,'') AS NVARCHAR(255)), 255),
                            CAST(ISNULL(can_add,0) AS BIT),
                            CAST(ISNULL(can_view,0) AS BIT),
                            CAST(ISNULL(can_edit,0) AS BIT),
                            CAST(ISNULL(can_approve,0) AS BIT),
                            ISNULL(created_at, GETDATE()),
                            ISNULL(updated_at, GETDATE())
                        FROM [dbo].[{$table}]
                    ");
                } catch (Throwable $e2) {
                    error_log('rebuildAccessTable copy: ' . $e2->getMessage());
                }
            }

            $pdo->exec("DROP TABLE [dbo].[{$table}]");
        }

        try {
            $pdo->exec("EXEC sp_rename N'[dbo].[{$temp}]', N'{$table}'");
        } catch (Throwable $e) {
            $pdo->exec("IF OBJECT_ID(N'[dbo].[{$table}]', N'U') IS NOT NULL DROP TABLE [dbo].[{$table}]");
            $pdo->exec("EXEC sp_rename N'[dbo].[{$temp}]', N'{$table}'");
        }

        $this->dropLegacyAccessTables($pdo);
    }

    /**
     * Keep only dbo.sap_report_userascesscontrol — drop old names and any _bak / _new leftovers.
     */
    private function dropLegacyAccessTables(PDO $pdo): void
    {
        $keep = strtolower(self::ACCESS_TABLE);
        try {
            $rows = $pdo->query("
                SELECT name
                FROM sys.tables
                WHERE schema_id = SCHEMA_ID(N'dbo')
                  AND (
                        name IN (
                            N'sap_report_userAccessControl',
                            N'tblSapUseracessControl',
                            N'tblSapUserAccessControl'
                        )
                     OR name LIKE N'sap_report_userAccessControl_bak_%'
                     OR name LIKE N'sap_report_userAccessControl_new_%'
                     OR name LIKE N'sap_report_userascesscontrol_bak_%'
                     OR name LIKE N'sap_report_userascesscontrol_new_%'
                     OR name LIKE N'uac_tmp_%'
                  )
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log('dropLegacyAccessTables list: ' . $e->getMessage());
            return;
        }

        foreach ($rows as $name) {
            $name = (string) $name;
            if ($name === '' || strtolower($name) === $keep) {
                continue;
            }
            try {
                $pdo->exec('DROP TABLE [dbo].[' . str_replace([']', '['], '', $name) . ']');
            } catch (Throwable $e) {
                error_log("dropLegacyAccessTables {$name}: " . $e->getMessage());
            }
        }
    }

    private function syncZcostPermissions(string $username, int $userId, int $canView, int $canAdd, int $canEdit, int $canApprove, int $canCopy = 0): void
    {
        $file = base_path('app/zcost/Database.php');
        if (!is_file($file)) {
            return;
        }
        require_once $file;
        if (!class_exists('\\App\\Database', false)) {
            return;
        }
        try {
            \App\Database::saveUserPermissions($this->clip($username, 100), [
                'can_view' => $canView,
                'can_add' => $canAdd,
                'can_edit' => $canEdit,
                'can_delete' => $canEdit,
                'can_approve' => $canApprove,
                'can_copy' => $canCopy,
            ], $userId);
        } catch (Throwable $e) {
            error_log('syncZcostPermissions: ' . $e->getMessage());
        }
    }

    /**
     * Create/migrate dbo.sap_report_userascesscontrol (single source of truth).
     */
    private function ensureTableExists(): void
    {
        try {
            $pdo = Database::connection();
            $table = self::ACCESS_TABLE;

            $pdo->exec("
                IF OBJECT_ID(N'[dbo].[{$table}]', N'U') IS NULL
                BEGIN
                    CREATE TABLE [dbo].[{$table}] (
                        [id] INT IDENTITY(1,1) NOT NULL,
                        [user_id] INT NOT NULL,
                        [username] NVARCHAR(255) NULL,
                        [page_no] INT NOT NULL,
                        [page_name] NVARCHAR(255) NOT NULL,
                        [page_route] NVARCHAR(255) NULL,
                        [can_add] BIT NOT NULL CONSTRAINT [DF_{$table}_add] DEFAULT 0,
                        [can_view] BIT NOT NULL CONSTRAINT [DF_{$table}_view] DEFAULT 0,
                        [can_edit] BIT NOT NULL CONSTRAINT [DF_{$table}_edit] DEFAULT 0,
                        [can_approve] BIT NOT NULL CONSTRAINT [DF_{$table}_approve] DEFAULT 0,
                        [can_copy] BIT NOT NULL CONSTRAINT [DF_{$table}_copy] DEFAULT 0,
                        [can_assign] BIT NOT NULL CONSTRAINT [DF_{$table}_assign] DEFAULT 0,
                        [can_validate] BIT NOT NULL CONSTRAINT [DF_{$table}_validate] DEFAULT 0,
                        [created_at] DATETIME NOT NULL CONSTRAINT [DF_{$table}_created] DEFAULT GETDATE(),
                        [updated_at] DATETIME NOT NULL CONSTRAINT [DF_{$table}_updated] DEFAULT GETDATE(),
                        CONSTRAINT [PK_{$table}] PRIMARY KEY ([id]),
                        CONSTRAINT [uq_{$table}_user_page] UNIQUE ([user_id], [page_no])
                    );
                END
            ");

            $this->repairAccessTableSchema($pdo);
            $this->ensureStringColumnsWide($pdo);

            // One-time migrate from older tables when the canonical table is empty, then drop them
            try {
                $count = (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$table}]")->fetchColumn();
                if ($count === 0) {
                    foreach (['sap_report_userAccessControl', 'tblSapUseracessControl'] as $src) {
                        $legacy = $pdo->query("SELECT OBJECT_ID(N'[dbo].[{$src}]', N'U')")->fetchColumn();
                        if (!$legacy) {
                            continue;
                        }
                        try {
                            $pdo->exec("
                                INSERT INTO [dbo].[{$table}]
                                (user_id, username, page_no, page_name, page_route,
                                 can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate,
                                 created_at, updated_at)
                                SELECT
                                    user_id,
                                    LEFT(CAST(ISNULL(username, '') AS NVARCHAR(100)), 100),
                                    page_no,
                                    LEFT(CAST(ISNULL(page_name, 'Page') AS NVARCHAR(150)), 150),
                                    LEFT(CAST(ISNULL(page_route, '') AS NVARCHAR(100)), 100),
                                    CAST(ISNULL(can_add, 0) AS BIT),
                                    CAST(ISNULL(can_view, 0) AS BIT),
                                    CAST(ISNULL(can_edit, 0) AS BIT),
                                    CAST(ISNULL(can_approve, 0) AS BIT),
                                    CAST(ISNULL(can_copy, 0) AS BIT),
                                    CAST(ISNULL(can_assign, 0) AS BIT),
                                    CAST(ISNULL(can_validate, 0) AS BIT),
                                    ISNULL(created_at, GETDATE()),
                                    ISNULL(updated_at, GETDATE())
                                FROM [dbo].[{$src}]
                            ");
                        } catch (Throwable $eFull) {
                            $pdo->exec("
                                INSERT INTO [dbo].[{$table}]
                                (user_id, username, page_no, page_name, page_route,
                                 can_add, can_view, can_edit, can_approve, created_at, updated_at)
                                SELECT
                                    user_id,
                                    LEFT(CAST(ISNULL(username, '') AS NVARCHAR(100)), 100),
                                    page_no,
                                    LEFT(CAST(ISNULL(page_name, 'Page') AS NVARCHAR(150)), 150),
                                    LEFT(CAST(ISNULL(page_route, '') AS NVARCHAR(100)), 100),
                                    CAST(ISNULL(can_add, 0) AS BIT),
                                    CAST(ISNULL(can_view, 0) AS BIT),
                                    CAST(ISNULL(can_edit, 0) AS BIT),
                                    CAST(ISNULL(can_approve, 0) AS BIT),
                                    ISNULL(created_at, GETDATE()),
                                    ISNULL(updated_at, GETDATE())
                                FROM [dbo].[{$src}]
                            ");
                        }
                        break;
                    }
                }
            } catch (Throwable $e) {
                error_log('migrate legacy access table: ' . $e->getMessage());
            }

            $this->dropLegacyAccessTables($pdo);
        } catch (Throwable $e) {
            error_log('ensureTableExists ' . self::ACCESS_TABLE . ' error: ' . $e->getMessage());
        }
    }
}
