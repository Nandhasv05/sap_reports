<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Admin Audit Log — full page + JSON API
*/
declare(strict_types=1);

require_once base_path('app/services/ApiAuditLogService.php');

class AuditLogController extends Controller
{

    /*
    * Handle Page Function
    */
    public function page(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('audit-log')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view Audit Log.';
            $this->redirect(url('/'));
            return;
        }

        // POST-only activity, last 30 days (older rows purged on read).
        ApiAuditLogService::purgeExpired();
        $records = ApiAuditLogService::latest(2000);
        $this->view('reports/audit_log', [
            'pageTitle'      => 'API Activity Log',
            'appShell'       => true,
            'records'        => $records,
            'total'          => count($records),
            'stats'          => ApiAuditLogService::buildStats($records),
            'actionFilters'  => ApiAuditLogService::ACTION_FILTERS,
            'retentionDays'  => 30,
        ]);
    }

    /*
    * Handle Index Function
    */
    public function index(): void
    {
        ApiAuditLogService::withoutLogging(function () {
            if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('audit-log')) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Access restricted: You do not have permission to view Audit Log.',
                    'data' => [],
                ], 403);
            }

            ApiAuditLogService::purgeExpired();
            $limit = (int) ($_GET['limit'] ?? 2000);
            $rows = ApiAuditLogService::latest($limit);
            $this->jsonResponse([
                'success' => true,
                'message' => 'API audit log (POST only, last 30 days)',
                'data' => [
                    'records' => $rows,
                    'total' => count($rows),
                    'stats' => ApiAuditLogService::buildStats($rows),
                    'action_filters' => ApiAuditLogService::ACTION_FILTERS,
                    'retention_days' => 30,
                    'timezone' => 'Asia/Kolkata',
                ],
            ]);
        });
    }

    /*
    * Handle IsAdmin
    */
    private function isAdmin(): bool
    {
        $user = function_exists('portal_user') ? portal_user() : null;
        $role = strtolower(trim((string) ($user['role'] ?? ($_SESSION['role'] ?? ''))));
        $username = strtolower(trim((string) ($user['username'] ?? ($_SESSION['username'] ?? ''))));
        if ($username === 'kayal') {
            return false;
        }
        if (!empty($user['is_admin'])) {
            return true;
        }
        return in_array($role, ['admin', 'it_admin', 'super_admin', 'administrator', 'director', 'management'], true);
    }
}
?>