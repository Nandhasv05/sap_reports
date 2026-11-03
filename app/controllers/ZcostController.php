<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/11/2026
 * DESCRIPTION : SAP Zcost Sales Costing Sheet Controller & API Gateway
 */
declare(strict_types=1);

require_once base_path('app/zcost/SapClient.php');
require_once base_path('app/zcost/AuditLog.php');
require_once base_path('app/zcost/PortalAuth.php');
require_once base_path('app/zcost/Database.php');

class ZcostController extends Controller
{
    public function page(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('zcost')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view the Zcost Sales Costing Sheet.';
            $this->redirect(url('/'));
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $this->view('reports/Zcost', [
            'pageTitle' => 'Sales Costing Sheet',
        ], null);
    }

    public function audit(): void
    {
        if (function_exists('sap_reports_user_can_access') && !sap_reports_user_can_access('zcost')) {
            $_SESSION['flash_error'] = 'Access restricted: You do not have permission to view Zcost Audit.';
            $this->redirect(url('/'));
            return;
        }

        $user = \App\PortalAuth::currentUser();
        if (empty($user['is_admin'])) {
            $this->redirect(url('zcost'));
            return;
        }

        $audit = new \App\AuditLog();
        $vbeln = trim((string) ($_GET['vbeln'] ?? ''));
        $limit = max(20, min(200, (int) ($_GET['limit'] ?? 100)));
        $entries = $audit->recent($limit, $vbeln !== '' ? $vbeln : null);

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $this->view('reports/zcost_audit', [
            'entries' => $entries,
            'vbeln'   => $vbeln,
            'limit'   => $limit,
            'user'    => $user,
        ], null);
    }

    public function api(): void
    {
        // Set headers
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        $client = new \App\SapClient();
        $audit = new \App\AuditLog();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);

            if (!$payload || !is_array($payload)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON body provided in POST request'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $action = (string) ($payload['action'] ?? 'save');

            if ($action === 'audit') {
                \App\PortalAuth::requireLiveAdmin();
                $vbeln = trim((string) ($payload['vbeln'] ?? ''));
                $limit = (int) ($payload['limit'] ?? 40);
                echo json_encode([
                    'success' => true,
                    'data' => $audit->recent($limit, $vbeln !== '' ? $vbeln : null),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($action === 'save_user_permissions') {
                \App\PortalAuth::requireLiveAdmin();
                $targetUser = trim((string) ($payload['username'] ?? ''));
                $targetUserId = isset($payload['user_id']) && $payload['user_id'] !== '' ? (int) $payload['user_id'] : null;
                $perms = $payload['permissions'] ?? $payload;
                $ok = \App\Database::saveUserPermissions($targetUser, $perms, $targetUserId);
                echo json_encode([
                    'success' => $ok,
                    'message' => $ok ? "Permissions updated for {$targetUser}" : "Failed to update permissions in database",
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($action === 'bulk_save_permissions') {
                \App\PortalAuth::requireLiveAdmin();
                $users = $payload['users'] ?? [];
                $savedCount = 0;
                foreach ($users as $u) {
                    $un = trim((string) ($u['username'] ?? ''));
                    $uid = isset($u['user_id']) && $u['user_id'] !== '' ? (int) $u['user_id'] : null;
                    if ($un !== '') {
                        if (\App\Database::saveUserPermissions($un, $u, $uid)) {
                            $savedCount++;
                        }
                    }
                }
                echo json_encode([
                    'success' => true,
                    'saved' => $savedCount,
                    'message' => "Successfully saved permissions for {$savedCount} users.",
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            $dataToSave = $payload['data'] ?? ($payload['d'] ?? $payload);
            if (!is_array($dataToSave)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid save payload'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $auditAction = !empty($dataToSave['Approve']) ? 'approve_save' : 'save';
            if ($action === 'approve') {
                $auditAction = 'approve_save';
            }

            $approveFlag = strtoupper(trim((string) ($dataToSave['Approve'] ?? '')));
            $isApproveAction = ($action === 'approve' || $auditAction === 'approve_save' || $approveFlag === 'X');

            if ($isApproveAction) {
                $approverCandidate = trim((string) ($dataToSave['Approvedby'] ?? ''));
                if (!\App\PortalAuth::canApprove($approverCandidate !== '' ? $approverCandidate : null)) {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Approval denied: Your account is not authorized to approve costing sheets.',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $user = \App\PortalAuth::currentUser();
                $dataToSave['Approve'] = 'X';

                $approver = trim((string) ($dataToSave['Approvedby'] ?? ''));
                if ($approver === '' || strtolower($approver) === 'guest' || strtolower($approver) === 'approver') {
                    $approver = (string) ($user['loginuserid'] ?? ($user['username'] ?? ($user['user_id'] ?? 'ADMIN')));
                }
                $dataToSave['Approvedby'] = strtoupper(substr($approver, 0, 12));
            } else {
                if (!\App\PortalAuth::canEdit()) {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Permission denied: Your account does not have permission to edit or save costing sheets.',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            $result = $client->saveCostSheet($dataToSave);
            $auditEntry = $audit->logSave($dataToSave, is_array($result) ? $result : [], $auditAction);
            if (is_array($result)) {
                $result['audit'] = $auditEntry;
            }

            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // GET handlers
        $action = trim((string) ($_GET['action'] ?? ''));

        if ($action === 'session') {
            $user = \App\PortalAuth::currentUser();
            echo json_encode([
                'success' => true,
                'user' => $user,
                'permissions' => $user['permissions'],
                'can_view' => $user['can_view'],
                'can_add' => $user['can_add'],
                'can_edit' => $user['can_edit'],
                'can_delete' => $user['can_delete'],
                'can_approve' => $user['can_approve'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'get_users_permissions') {
            \App\PortalAuth::requireLiveAdmin();
            $users = \App\Database::getAllUserPermissions();
            echo json_encode([
                'success' => true,
                'users' => $users,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'audit') {
            \App\PortalAuth::requireLiveAdmin();
            $vbeln = trim((string) ($_GET['vbeln'] ?? ''));
            $limit = (int) ($_GET['limit'] ?? 40);
            echo json_encode([
                'success' => true,
                'data' => $audit->recent($limit, $vbeln !== '' ? $vbeln : null),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'copy') {
            if (!\App\PortalAuth::canAdd()) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Permission denied: Your account does not have permission to copy or create costing sheets.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $srcVbeln = trim((string) ($_GET['source_vbeln'] ?? $_POST['source_vbeln'] ?? ''));
            $srcPosnr = trim((string) ($_GET['source_posnr'] ?? $_POST['source_posnr'] ?? '000010'));
            $targetVbeln = trim((string) ($_GET['target_vbeln'] ?? $_POST['target_vbeln'] ?? ''));
            $targetPosnr = trim((string) ($_GET['target_posnr'] ?? $_POST['target_posnr'] ?? '000010'));

            if (empty($srcVbeln) || empty($targetVbeln)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'source_vbeln and target_vbeln are required'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $sourceSheet = $client->getCostSheet($srcVbeln, $srcPosnr);
            if (!$sourceSheet['success'] || !isset($sourceSheet['data'])) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => "Source quotation {$srcVbeln} (Item {$srcPosnr}) not found in SAP"
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $data = $sourceSheet['data'];
            $data['Vbeln'] = $targetVbeln;
            $data['Posnr'] = str_pad($targetPosnr, 6, '0', STR_PAD_LEFT);
            $data['Approve'] = '';
            $data['Approvedby'] = '';

            foreach (['to_Fabric', 'to_Trim', 'to_Packing', 'to_Special'] as $nav) {
                if (isset($data[$nav]['results']) && is_array($data[$nav]['results'])) {
                    foreach ($data[$nav]['results'] as &$row) {
                        unset($row['__metadata']);
                        $row['Vbeln'] = $data['Vbeln'];
                        $row['Posnr'] = $data['Posnr'];
                    }
                    unset($row);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Costing copied from {$srcVbeln} to {$targetVbeln}",
                'data' => $data
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $vbeln = trim((string) ($_GET['vbeln'] ?? $_POST['vbeln'] ?? ''));
        $posnr = trim((string) ($_GET['posnr'] ?? $_POST['posnr'] ?? '000010'));
        $create = isset($_GET['create']) && ($_GET['create'] === '1' || $_GET['create'] === 'true')
               || (isset($_GET['action']) && $_GET['action'] === 'create')
               || (isset($_POST['action']) && $_POST['action'] === 'create');

        if (empty($posnr)) {
            $posnr = '000010';
        }

        if ($create) {
            if (!\App\PortalAuth::canAdd()) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Permission denied: Your account does not have permission to create new costing sheets.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $result = $client->createQuotationMaster((string) $vbeln, (string) $posnr);
        } else {
            if (!\App\PortalAuth::canView()) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Permission denied: Your account does not have permission to view costing sheets.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $result = $client->getCostSheet((string) $vbeln, (string) $posnr);
        }

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

?>