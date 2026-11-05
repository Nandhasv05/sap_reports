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

        $records = ApiAuditLogService::latest(300);
        $this->view('reports/audit_log', [
            'pageTitle' => 'API Activity Log',
            'appShell'  => true,
            'records'   => $records,
            'total'     => count($records),
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

            $limit = (int) ($_GET['limit'] ?? 200);
            $rows = ApiAuditLogService::latest($limit);
            $this->jsonResponse([
                'success' => true,
                'message' => 'API audit log',
                'data' => [
                    'records' => $rows,
                    'total' => count($rows),
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