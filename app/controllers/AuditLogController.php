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
    public function page(): void
    {
        if (!$this->isAdmin()) {
            $_SESSION['flash_error'] = 'Admin access required for Audit Log.';
            $this->redirect(url('/'));
            return;
        }

        $records = ApiAuditLogService::latest(200);
        $this->view('reports/audit_log', [
            'pageTitle' => 'Audit Log',
            'appShell'  => true,
            'records'   => $records,
            'total'     => count($records),
        ]);
    }

    public function index(): void
    {
        ApiAuditLogService::withoutLogging(function () {
            if (!$this->isAdmin()) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Admin access required.',
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
