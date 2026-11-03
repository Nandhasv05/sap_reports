<?php
declare(strict_types=1);

/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/11/2026
 * DESCRIPTION : SAP Reports User Access Control Controller
 *               Restricted to: admin, saravan, nandhakumar
 *               Manages granular permissions (Add, View, Edit, Approve) across all 12 SAP report pages
 *               Persisted to dbo.tblSapUseracessControl
 */

require_once base_path('app/core/Controller.php');
require_once base_path('app/core/Database.php');
require_once base_path('app/core/portal_auth.php');
require_once base_path('app/core/access.php');

class UserAccessControlController extends Controller
{
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
        return [
            [
                'page_no'     => 1,
                'name'        => 'Purchase Order Report',
                'route'       => 'purchase-order',
                'category'    => 'Orders',
                'icon'        => 'fa-receipt',
                'icon_color'  => '#3b82f6',
                'icon_bg'     => '#eff6ff',
                'description' => 'PO line items, supplier breakdown, conditions, delivery status & pricing'
            ],
            [
                'page_no'     => 2,
                'name'        => 'PO Costing Validation',
                'route'       => 'po-costing',
                'category'    => 'Costing',
                'icon'        => 'fa-file-invoice-dollar',
                'icon_color'  => '#10b981',
                'icon_bg'     => '#ecfdf5',
                'description' => 'Bulk PO validation, dual matrix workbench, discrepancy audit & variance log'
            ],
            [
                'page_no'     => 3,
                'name'        => 'Sales Dashboard',
                'route'       => 'sales',
                'category'    => 'Analytics',
                'icon'        => 'fa-chart-line',
                'icon_color'  => '#8b5cf6',
                'icon_bg'     => '#f5f3ff',
                'description' => 'Sales KPIs, order fulfillment charts, revenue velocity & trends'
            ],
            [
                'page_no'     => 4,
                'name'        => 'Fabric Utilization',
                'route'       => 'fabric',
                'category'    => 'Materials',
                'icon'        => 'fa-layer-group',
                'icon_color'  => '#0ea5e9',
                'icon_bg'     => '#e0f2fe',
                'description' => 'Fabric roll logs, cutting yield, wastage %, shrinkage & meter reconciliation'
            ],
            [
                'page_no'     => 5,
                'name'        => 'Fabric Sale Order',
                'route'       => 'fabric-sale',
                'category'    => 'Sales',
                'icon'        => 'fa-tags',
                'icon_color'  => '#059669',
                'icon_bg'     => '#ecfdf5',
                'description' => 'Fabric sale orders, stock reservations, pricing & dispatch tracking'
            ],
            [
                'page_no'     => 6,
                'name'        => 'Production Report',
                'route'       => 'production',
                'category'    => 'Manufacturing',
                'icon'        => 'fa-industry',
                'icon_color'  => '#d97706',
                'icon_bg'     => '#fffbeb',
                'description' => 'Assembly line efficiency, plant targets vs actuals, sewing output & WIP'
            ],
            [
                'page_no'     => 7,
                'name'        => 'Procurement Report',
                'route'       => 'procurement',
                'category'    => 'Sourcing',
                'icon'        => 'fa-dolly',
                'icon_color'  => '#f97316',
                'icon_bg'     => '#fff7ed',
                'description' => 'Open PR status, vendor lead times, purchase orders & material lead metrics'
            ],
            [
                'page_no'     => 8,
                'name'        => 'Trims Utilization',
                'route'       => 'trims',
                'category'    => 'Materials',
                'icon'        => 'fa-scissors',
                'icon_color'  => '#ec4899',
                'icon_bg'     => '#fdf2f8',
                'description' => 'Buttons, zippers, badges, packaging stock balance & line issue ledger'
            ],
            [
                'page_no'     => 9,
                'name'        => 'Overall Utilization',
                'route'       => 'utilization',
                'category'    => 'Efficiency',
                'icon'        => 'fa-chart-pie',
                'icon_color'  => '#6366f1',
                'icon_bg'     => '#eef2ff',
                'description' => 'Consolidated material balance, global yield benchmarks & scrap analysis'
            ],
            [
                'page_no'     => 10,
                'name'        => 'Audit Log',
                'route'       => 'audit-log',
                'category'    => 'Security',
                'icon'        => 'fa-clock-rotate-left',
                'icon_color'  => '#475569',
                'icon_bg'     => '#f1f5f9',
                'description' => 'Live user actions, critical data alterations, SAP sync transactions & trail'
            ],
            [
                'page_no'     => 11,
                'name'        => 'User Access Control',
                'route'       => 'user-access-control',
                'category'    => 'Administration',
                'icon'        => 'fa-user-lock',
                'icon_color'  => '#e11d48',
                'icon_bg'     => '#ffe4e6',
                'description' => 'Granular permission matrix (Add, View, Edit, Approve) per user and page'
            ],
            [
                'page_no'     => 12,
                'name'        => 'Zcost Costing Sheet',
                'route'       => 'zcost',
                'category'    => 'Costing',
                'icon'        => 'fa-calculator',
                'icon_color'  => '#ea580c',
                'icon_bg'     => '#ffedd5',
                'description' => 'Garment costing sheet, pre-costing matrix, approval flow & budget variance'
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

        $users = [];
        try {
            $pdo = Database::connection();
            $stmt = $pdo->query("
                SELECT Id, UserName, Email, Role, Department, IsActive 
                FROM [dbo].[users] 
                WHERE IsActive = 1 
                ORDER BY UserName ASC
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('UserAccessControl fetch users error: ' . $e->getMessage());
        }

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

        header('Content-Type: application/json; charset=UTF-8');

        $userId = (int) ($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("
                SELECT page_no, page_name, page_route, can_add, can_view, can_edit, can_approve 
                FROM [dbo].[tblSapUseracessControl] 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $permissions = [];
            foreach ($rows as $row) {
                $pNo = (int) $row['page_no'];
                $permissions[$pNo] = [
                    'add'     => (int) $row['can_add'],
                    'view'    => (int) $row['can_view'],
                    'edit'    => (int) $row['can_edit'],
                    'approve' => (int) $row['can_approve'],
                ];
            }

            echo json_encode([
                'success'     => true,
                'user_id'     => $userId,
                'permissions' => $permissions
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * API: Save permissions for a specific user
     */
    public function saveUserPermissions(): void
    {
        $this->ensureAuthorized();
        $this->ensureTableExists();

        header('Content-Type: application/json; charset=UTF-8');

        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            exit;
        }

        $userId = (int) ($input['user_id'] ?? 0);
        $username = trim((string) ($input['username'] ?? ''));
        $permissions = $input['permissions'] ?? [];

        if ($userId <= 0 || !is_array($permissions)) {
            echo json_encode(['success' => false, 'error' => 'Missing user_id or permissions array']);
            exit;
        }

        try {
            $pdo = Database::connection();

            // If username wasn't passed, resolve from users table
            if ($username === '') {
                $uStmt = $pdo->prepare("SELECT UserName FROM [dbo].[users] WHERE Id = ?");
                $uStmt->execute([$userId]);
                $username = (string) ($uStmt->fetchColumn() ?: '');
            }

            $savedCount = 0;
            $pagesCatalog = self::getPagesCatalog();
            $catalogMap = [];
            foreach ($pagesCatalog as $p) {
                $catalogMap[$p['page_no']] = $p;
            }

            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM [dbo].[tblSapUseracessControl] WHERE user_id = ? AND page_no = ?");
            $updateStmt = $pdo->prepare("
                UPDATE [dbo].[tblSapUseracessControl]
                SET can_add = ?, can_view = ?, can_edit = ?, can_approve = ?, username = ?, page_name = ?, page_route = ?, updated_at = GETDATE()
                WHERE user_id = ? AND page_no = ?
            ");
            $insertStmt = $pdo->prepare("
                INSERT INTO [dbo].[tblSapUseracessControl]
                (user_id, username, page_no, page_name, page_route, can_add, can_view, can_edit, can_approve, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ");

            foreach ($permissions as $p) {
                $pageNo = (int) ($p['page_no'] ?? 0);
                if ($pageNo <= 0) {
                    continue;
                }

                $meta = $catalogMap[$pageNo] ?? null;
                $pageName = (string) ($p['page_name'] ?? ($meta['name'] ?? 'Page ' . $pageNo));
                $pageRoute = (string) ($p['page_route'] ?? ($meta['route'] ?? ''));

                $canAdd = !empty($p['can_add']) ? 1 : 0;
                $canView = !empty($p['can_view']) ? 1 : 0;
                $canEdit = !empty($p['can_edit']) ? 1 : 0;
                $canApprove = !empty($p['can_approve']) ? 1 : 0;

                $checkStmt->execute([$userId, $pageNo]);
                $exists = (int) $checkStmt->fetchColumn();

                if ($exists > 0) {
                    $updateStmt->execute([
                        $canAdd, $canView, $canEdit, $canApprove,
                        $username, $pageName, $pageRoute,
                        $userId, $pageNo
                    ]);
                } else {
                    $insertStmt->execute([
                        $userId, $username, $pageNo, $pageName, $pageRoute,
                        $canAdd, $canView, $canEdit, $canApprove
                    ]);
                }
                $savedCount++;
            }

            echo json_encode([
                'success' => true,
                'message' => "Successfully saved permissions for user {$username} ({$savedCount} pages updated)",
                'user_id' => $userId,
                'saved'   => $savedCount
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Self-healing table existence verification
     */
    private function ensureTableExists(): void
    {
        try {
            $pdo = Database::connection();
            $pdo->exec("
                IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[tblSapUseracessControl]') AND type in (N'U'))
                BEGIN
                    CREATE TABLE [dbo].[tblSapUseracessControl] (
                        [id] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                        [user_id] INT NOT NULL,
                        [username] NVARCHAR(100) NULL,
                        [page_no] INT NOT NULL,
                        [page_name] NVARCHAR(150) NOT NULL,
                        [page_route] NVARCHAR(100) NULL,
                        [can_add] BIT NOT NULL DEFAULT 0,
                        [can_view] BIT NOT NULL DEFAULT 0,
                        [can_edit] BIT NOT NULL DEFAULT 0,
                        [can_approve] BIT NOT NULL DEFAULT 0,
                        [created_at] DATETIME NOT NULL DEFAULT GETDATE(),
                        [updated_at] DATETIME NOT NULL DEFAULT GETDATE(),
                        CONSTRAINT [uq_sap_user_page] UNIQUE ([user_id], [page_no])
                    );
                END
            ");
        } catch (Throwable $e) {
            error_log('ensureTableExists tblSapUseracessControl error: ' . $e->getMessage());
        }
    }
}
