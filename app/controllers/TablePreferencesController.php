<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : /api/user/table-preferences/{tableKey} - the signed-in user's column order / visibility per table
 */
require_once base_path('app/services/TablePreferenceService.php');

class TablePreferencesController extends Controller
{
    private const MAX_BODY_BYTES = 16384;

    public function show(string $tableKey): void
    {
        $this->handle(fn (int $userId): array => (new TablePreferenceService())->get($userId, $tableKey));
    }

    public function update(string $tableKey): void
    {
        $this->handle(function (int $userId) use ($tableKey): array {
            $this->requireSameOriginAjax();
            return (new TablePreferenceService())->save($userId, $tableKey, $this->jsonBody());
        });
    }

    public function destroy(string $tableKey): void
    {
        $this->handle(function (int $userId) use ($tableKey): array {
            $this->requireSameOriginAjax();
            return (new TablePreferenceService())->reset($userId, $tableKey);
        });
    }

    /*
     * The user id always comes from the portal session (already started and validated at boot), never from the request
     */
    private function handle(callable $action): void
    {
        $id = (string) ($_SESSION['user_id'] ?? '');
        if (!ctype_digit($id) || (int) $id <= 0) {
            $this->error(new TablePreferenceException(401, 'session_expired', 'Your portal session has expired. Please log in again.'));
        }

        try {
            $this->jsonResponse($action((int) $id));
        } catch (TablePreferenceException $e) {
            $this->error($e);
        } catch (Throwable $e) {
            $detail = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            error_log('[sap_reports] table preferences: ' . $detail);
            $this->error(new TablePreferenceException(500, 'server_error', 'Column preferences are temporarily unavailable.'));
        }
    }

    /*
     * Cross-site forms cannot set custom headers, so this blocks CSRF on the state-changing verbs
     */
    private function requireSameOriginAjax(): void
    {
        $fetchSite = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest' || !in_array($fetchSite, ['', 'same-origin'], true)) {
            throw new TablePreferenceException(403, 'forbidden', 'Request rejected.');
        }
    }

    private function jsonBody(): mixed
    {
        if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            throw new TablePreferenceException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new TablePreferenceException(413, 'payload_too_large', 'The request body is too large.');
        }
        try {
            return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TablePreferenceException(400, 'malformed_json', 'The request body is not valid JSON.');
        }
    }

    private function error(TablePreferenceException $e): void
    {
        $this->jsonResponse([
            'success' => false,
            'code'    => $e->errorCode,
            'message' => $e->getMessage(),
            'errors'  => (object) $e->errors,
        ], $e->status);
    }
}
