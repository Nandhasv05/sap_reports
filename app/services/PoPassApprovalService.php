<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : PASS PO → SAP POApprovalSet + POPDFSet + save under upload/PoZcostValidation
 */
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/PassPoValidationLogService.php');
require_once base_path('app/services/UserEsignService.php');

class PoPassApprovalService
{
    private SapODataClient $odata;
    /** @var array<string,mixed> */
    private array $cfg;
    private PassPoValidationLogService $passLog;
    private UserEsignService $userEsign;

    public function __construct(?SapODataClient $odata = null, ?array $cfg = null)
    {
        $this->cfg = $cfg ?? (config('sap') ?: []);
        $this->odata = $odata ?? new SapODataClient($this->cfg);
        $this->passLog = new PassPoValidationLogService();
        $this->userEsign = new UserEsignService();
    }

    /**
     * Write one row to dbo.Pass_po_validtion (never throws to caller).
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $approvalResult from SapODataClient::postJson
     */
    private function logPassApi(
        string $displayPo,
        string $requestId,
        string $valueFlag,
        bool $isApprove,
        array $payload,
        array $approvalResult,
        ?string $extraNote = null,
        ?string $actionOverride = null
    ): void {
        try {
            $user = function_exists('portal_user') ? portal_user() : null;
            $ok = !empty($approvalResult['success']);
            $action = trim((string) ($actionOverride ?? ''));
            if ($action === '') {
                $action = $isApprove ? 'approve' : 'revert';
            }
            $status = PassPoValidationLogService::statusFor($action, $ok, $valueFlag);
            $response = [
                'success' => $ok,
                'status' => $status,
                'http_code' => $approvalResult['http_code'] ?? null,
                'error' => $approvalResult['error'] ?? null,
                'body' => $approvalResult['body'] ?? null,
                'raw' => isset($approvalResult['raw'])
                    ? (strlen((string) $approvalResult['raw']) > 8000
                        ? substr((string) $approvalResult['raw'], 0, 8000) . '…'
                        : $approvalResult['raw'])
                    : null,
            ];
            if ($extraNote !== null && $extraNote !== '') {
                $response['note'] = $extraNote;
            }
            $this->passLog->save([
                'user_id' => $user['id'] ?? ($_SESSION['user_id'] ?? null),
                'username' => $user['username'] ?? ($_SESSION['username'] ?? null),
                'purchase_order' => $displayPo,
                'request_id' => $requestId,
                'action' => $action,
                'status' => $status,
                'value_flag' => $valueFlag,
                'method' => 'POST',
                'url' => (string) ($approvalResult['url'] ?? ''),
                'payload' => $payload,
                'response' => $response,
                'http_code' => isset($approvalResult['http_code']) ? (int) $approvalResult['http_code'] : null,
                'success' => $ok,
            ]);
        } catch (Throwable $ignored) {
            // Logging must not break PASS/REVERT flow
        }
    }

    public function uploadRoot(): string
    {
        return base_path('public/upload/PoZcostValidation');
    }

    /**
     * @return array<string,mixed>
     */
    private function esignStorageCfg(): array
    {
        $cfg = $this->cfg['po_esign_storage'] ?? null;
        if (is_array($cfg) && trim((string) ($cfg['path'] ?? '')) !== '') {
            return $cfg;
        }
        // Fallback: older po_pdf_share.unc used as a local relative/absolute path
        $share = is_array($this->cfg['po_pdf_share'] ?? null) ? $this->cfg['po_pdf_share'] : [];
        return [
            'path' => (string) ($share['unc'] ?? 'EVOL/uploads/po_esign/PO_New_Module'),
            'use_date_folder' => true,
            'date_format' => (string) ($share['esign_date_format'] ?? 'Y-m-d'),
        ];
    }

    /**
     * Absolute EVOL e-sign root, e.g. C:\MAMP\htdocs\EVOL\uploads\po_esign\PO_New_Module
     */
    public function esignStorageRoot(): ?string
    {
        $cfg = $this->esignStorageCfg();
        $raw = trim(str_replace(['\\'], '/', (string) ($cfg['path'] ?? '')));
        $raw = trim($raw, '/');
        if ($raw === '') {
            return null;
        }

        // Absolute Windows / Unix path
        if (preg_match('#^[A-Za-z]:/#', $raw) || str_starts_with($raw, '/')) {
            return str_replace('/', DIRECTORY_SEPARATOR, $raw);
        }

        // Relative: EVOL/uploads/... → resolve via EVOL dir or htdocs sibling
        $norm = str_replace('\\', '/', $raw);
        $underEvol = preg_replace('#^EVOL/#i', '', $norm) ?? $norm;
        $candidates = [];

        if (function_exists('sap_reports_evol_dir')) {
            $evolDir = sap_reports_evol_dir();
            if ($evolDir !== '') {
                $candidates[] = $evolDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $underEvol);
            }
        }

        $htdocs = dirname(base_path());
        $candidates[] = $htdocs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $norm);
        $candidates[] = $htdocs . DIRECTORY_SEPARATOR . 'EVOL' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $underEvol);
        $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docRoot !== '') {
            $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/' . $norm);
            $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $docRoot . '/EVOL/' . $underEvol);
        }

        foreach ($candidates as $cand) {
            $parent = dirname($cand);
            if (@is_dir($cand) || @is_dir($parent) || @is_dir(dirname($parent))) {
                return $cand;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Raw UNC root from legacy config (optional; unused for e-sign).
     */
    public function networkShareUnc(): ?string
    {
        $share = is_array($this->cfg['po_pdf_share'] ?? null) ? $this->cfg['po_pdf_share'] : [];
        $unc = rtrim(str_replace('/', '\\', (string) ($share['unc'] ?? '')), '\\');
        if ($unc === '' || !str_starts_with($unc, '\\\\')) {
            return null;
        }
        return $unc;
    }

    /**
     * Network share root from legacy config (optional).
     */
    public function networkUploadRoot(): ?string
    {
        $unc = $this->networkShareUnc();
        if ($unc === null) {
            return null;
        }
        $share = is_array($this->cfg['po_pdf_share'] ?? null) ? $this->cfg['po_pdf_share'] : [];
        $subdir = trim(str_replace(['/', '\\'], '\\', (string) ($share['subdir'] ?? '')), '\\');
        return $subdir !== '' ? ($unc . '\\' . $subdir) : $unc;
    }

    /**
     * Today's e-sign folder date (app TZ), default YYYY-MM-DD → 2026-11-10.
     */
    public function esignDateFolder(?string $format = null): string
    {
        $cfg = $this->esignStorageCfg();
        $share = is_array($this->cfg['po_pdf_share'] ?? null) ? $this->cfg['po_pdf_share'] : [];
        $fmt = $format
            ?: (string) ($cfg['date_format'] ?? '')
            ?: (string) ($share['esign_date_format'] ?? 'Y-m-d');
        if ($fmt === '') {
            $fmt = 'Y-m-d';
        }
        $tz = function_exists('sap_reports_app_timezone') ? sap_reports_app_timezone() : 'Asia/Kolkata';
        try {
            return (new DateTimeImmutable('now', new DateTimeZone($tz)))->format($fmt);
        } catch (Throwable $e) {
            return date($fmt);
        }
    }

    /** @deprecated use esignDateFolder() */
    public function esignDateYmd(): string
    {
        return $this->esignDateFolder('Y-m-d');
    }

    /**
     * E-sign folder: EVOL/uploads/po_esign/PO_New_Module[/{YYYY-MM-DD}]
     */
    public function networkEsignFolder(?string $dateFolder = null): ?string
    {
        $root = $this->esignStorageRoot();
        if ($root === null) {
            return null;
        }
        $cfg = $this->esignStorageCfg();
        $useDate = array_key_exists('use_date_folder', $cfg) ? (bool) $cfg['use_date_folder'] : true;
        if (!$useDate) {
            return $root;
        }
        if ($dateFolder === null || $dateFolder === '') {
            $dateFolder = $this->esignDateFolder();
        }
        return $root . DIRECTORY_SEPARATOR . $dateFolder;
    }

    public function poFolder(string $po): string
    {
        $po = preg_replace('/\D/', '', $po) ?? '';
        $po = ltrim($po, '0');
        if ($po === '') {
            $po = '0';
        }
        return $this->uploadRoot() . DIRECTORY_SEPARATOR . 'Po-' . $po;
    }

    public function networkPoFolder(string $po): ?string
    {
        $root = $this->networkUploadRoot();
        if ($root === null) {
            return null;
        }
        $po = preg_replace('/\D/', '', $po) ?? '';
        $po = ltrim($po, '0');
        if ($po === '') {
            $po = '0';
        }
        return $root . '\\Po-' . $po;
    }

    /** @var bool|null cached reachability for this request */
    private ?bool $networkReachable = null;

    /**
     * Quick TCP probe so unreachable UNC shares do not hang PHP (is_dir/net use).
     */
    private function networkHostReachable(string $unc, int $timeoutSec = 2): bool
    {
        if (!preg_match('#^\\\\\\\\([^\\\\]+)#', $unc, $m)) {
            return false;
        }
        $host = $m[1];
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, 445, $errno, $errstr, max(1, $timeoutSec));
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        // Fallback SMB-alternate / ping-less: try port 139 briefly
        $fp2 = @fsockopen($host, 139, $errno, $errstr, 1);
        if (is_resource($fp2)) {
            fclose($fp2);
            return true;
        }
        return false;
    }

    /**
     * Create EVOL/uploads/po_esign/PO_New_Module[/{YYYY-MM-DD}].
     *
     * @return array{folder:?string,error:?string}
     */
    private function ensureEsignNetworkFolder(?string $dateFolder = null): array
    {
        $target = $this->networkEsignFolder($dateFolder);
        if ($target === null) {
            return ['folder' => null, 'error' => 'po_esign_storage.path is not configured.'];
        }
        if (@is_dir($target)) {
            return ['folder' => $target, 'error' => null];
        }
        $ok = @mkdir($target, 0775, true);
        if ($ok || @is_dir($target)) {
            return ['folder' => $target, 'error' => null];
        }
        $phpErr = error_get_last();
        return [
            'folder' => $target,
            'error' => 'Could not create folder: ' . $target
                . (!empty($phpErr['message']) ? (' (' . $phpErr['message'] . ')') : ''),
        ];
    }

    public function displayPoNumber(string $po): string
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $display = ltrim($digits, '0');
        return $display !== '' ? $display : ($digits !== '' ? $digits : '');
    }

    /** Original / SAP-cached PDF: PO-{po}.pdf */
    public function originalPdfFileName(string $po): string
    {
        $display = $this->displayPoNumber($po);
        return $display !== '' ? ('PO-' . $display . '.pdf') : 'PO-0.pdf';
    }

    /**
     * Canonical signed PDF name: PO-{po}.pdf (e.g. PO-60003156.pdf).
     * Legacy *_signed.pdf names are still discovered for reading.
     */
    public function signedPdfFileName(string $po): string
    {
        return $this->originalPdfFileName($po);
    }

    /**
     * Canonical save folder: EVOL/uploads/po_esign (not date / PO_New_Module subfolders).
     */
    public function poEsignSaveDir(): ?string
    {
        $root = $this->esignStorageRoot();
        if ($root === null || $root === '') {
            // Hard fallback under htdocs/EVOL
            $htdocs = dirname(base_path());
            $fallback = $htdocs . DIRECTORY_SEPARATOR . 'EVOL' . DIRECTORY_SEPARATOR
                . 'uploads' . DIRECTORY_SEPARATOR . 'po_esign';
            if (!@is_dir($fallback)) {
                @mkdir($fallback, 0775, true);
            }
            return @is_dir($fallback) ? $fallback : null;
        }
        $base = basename(rtrim($root, '\\/'));
        // Config often points at .../po_esign/PO_New_Module — save in parent po_esign
        if (strcasecmp($base, 'PO_New_Module') === 0) {
            $parent = dirname($root);
            if (!@is_dir($parent)) {
                @mkdir($parent, 0775, true);
            }
            return @is_dir($parent) ? $parent : $root;
        }
        if (!@is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        return @is_dir($root) ? $root : null;
    }

    /**
     * Save signed PDF as EVOL/uploads/po_esign/PO-{po}.pdf (overwrite same name — no duplicates).
     * Does not create unsigned cache files.
     *
     * @return array{path:?string,folder:?string,file:?string,error:?string,verified:bool}
     */
    private function writeEsignNetworkPdf(string $displayPo, string $contents): array
    {
        $displayPo = $this->displayPoNumber($displayPo);
        if ($displayPo === '') {
            $displayPo = '0';
        }
        $fileName = $this->signedPdfFileName($displayPo); // PO-{po}.pdf
        $folder = $this->poEsignSaveDir();
        if ($folder === null || !@is_dir($folder)) {
            return [
                'path' => null,
                'folder' => $folder,
                'file' => $fileName,
                'error' => 'Could not create folder EVOL/uploads/po_esign',
                'verified' => false,
            ];
        }
        $folder = rtrim((string) $folder, '\\/');
        $path = $folder . DIRECTORY_SEPARATOR . $fileName;
        // Single canonical file — overwrite if re-signing (prevents PO-xxx (1).pdf duplicates)
        if (@file_put_contents($path, $contents) === false) {
            $phpErr = error_get_last();
            return [
                'path' => null,
                'folder' => $folder,
                'file' => $fileName,
                'error' => 'Could not write signed PDF to ' . $path
                    . (!empty($phpErr['message']) ? (' (' . $phpErr['message'] . ')') : ''),
                'verified' => false,
            ];
        }
        // Verify on disk before caller updates DB
        $verified = @is_file($path) && (@filesize($path) ?: 0) > 8;
        if ($verified) {
            $fh = @fopen($path, 'rb');
            $head = $fh ? (string) @fread($fh, 4) : '';
            if ($fh) {
                @fclose($fh);
            }
            $verified = $head === '%PDF';
        }
        if (!$verified) {
            @unlink($path);
            return [
                'path' => null,
                'folder' => $folder,
                'file' => $fileName,
                'error' => 'Signed PDF write verification failed at ' . $path,
                'verified' => false,
            ];
        }
        @file_put_contents($folder . DIRECTORY_SEPARATOR . ('PO-' . $displayPo . '.signed'), date('c'));
        // Remove legacy *_signed.pdf duplicate if present (same folder)
        $legacy = $folder . DIRECTORY_SEPARATOR . ('PO-' . $displayPo . '_signed.pdf');
        if (@is_file($legacy) && realpath($legacy) !== realpath($path)) {
            @unlink($legacy);
        }
        return ['path' => $path, 'folder' => $folder, 'file' => $fileName, 'error' => null, 'verified' => true];
    }

    /**
     * Locate signed PDF under EVOL/uploads/po_esign (canonical PO-{po}.pdf + legacy names).
     *
     * @return array{has_file:bool,path:?string,pdf_file:?string,folder:?string,network_path:?string,is_signed:bool}
     */
    public function findEsignNetworkPdf(string $po): array
    {
        $displayPo = $this->displayPoNumber($po);
        if ($displayPo === '') {
            return ['has_file' => false, 'path' => null, 'pdf_file' => null, 'folder' => null, 'network_path' => null, 'is_signed' => false];
        }
        $root = $this->esignStorageRoot();
        $saveDir = $this->poEsignSaveDir();
        $primary = $this->networkEsignFolder();
        $signedName = $this->signedPdfFileName($displayPo); // PO-{po}.pdf
        $origName = $this->originalPdfFileName($displayPo);
        $legacyNames = [
            $signedName,
            'PO-' . $displayPo . '_signed.pdf',
            'po-' . $displayPo . '_signed.pdf',
            'PO_' . $displayPo . '_signed.pdf',
            'po-' . $displayPo . '.pdf',
        ];
        $candidates = [];
        $bases = array_values(array_unique(array_filter([$saveDir, $primary, $root])));
        foreach ($bases as $base) {
            foreach ($legacyNames as $name) {
                $isLegacySignedName = str_contains(strtolower($name), 'signed');
                $path = $base . DIRECTORY_SEPARATOR . $name;
                $marker = $base . DIRECTORY_SEPARATOR . ('PO-' . $displayPo . '.signed');
                // PO-{n}.pdf counts as signed only with .signed marker (or *_signed.pdf name)
                $candidates[] = [
                    'path' => $path,
                    'signed' => $isLegacySignedName || @is_file($marker),
                    'file' => $name,
                ];
            }
        }
        if ($root !== null) {
            try {
                $tz = function_exists('sap_reports_app_timezone') ? sap_reports_app_timezone() : 'Asia/Kolkata';
                $baseDt = new DateTimeImmutable('now', new DateTimeZone($tz));
            } catch (Throwable $e) {
                $baseDt = new DateTimeImmutable('now');
            }
            for ($i = 0; $i <= 60; $i++) {
                $day = $baseDt->modify('-' . $i . ' days');
                foreach ([$day->format('Y-m-d'), $day->format('d-m-Y')] as $df) {
                    foreach ($legacyNames as $name) {
                        $isLegacySignedName = str_contains(strtolower($name), 'signed');
                        $dayDir = $root . DIRECTORY_SEPARATOR . $df;
                        $marker = $dayDir . DIRECTORY_SEPARATOR . ('PO-' . $displayPo . '.signed');
                        // PO-{n}.pdf in dated folders counts as signed only with marker
                        $candidates[] = [
                            'path' => $dayDir . DIRECTORY_SEPARATOR . $name,
                            'signed' => $isLegacySignedName || @is_file($marker),
                            'file' => $name,
                        ];
                    }
                }
            }
        }

        $seen = [];
        foreach ($candidates as $cand) {
            $path = (string) ($cand['path'] ?? '');
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            if (!@is_file($path)) {
                continue;
            }
            if (empty($cand['signed']) && !str_contains(strtolower(basename($path)), 'signed')) {
                continue; // unsigned original — handled by findLocalPoPdf
            }
            return [
                'has_file' => true,
                'path' => $path,
                'pdf_file' => (string) ($cand['file'] ?? basename($path)),
                'folder' => dirname($path),
                'network_path' => $path,
                'is_signed' => true,
            ];
        }
        return [
            'has_file' => false,
            'path' => null,
            'pdf_file' => $signedName,
            'folder' => $primary,
            'network_path' => null,
            'is_signed' => false,
        ];
    }

    /**
     * Find any local PDF for viewing (signed preferred, then original cache).
     *
     * @return array{has_file:bool,path:?string,pdf_file:?string,folder:?string,is_signed:bool,source:string}
     */
    public function findLocalPoPdf(string $po): array
    {
        $signed = $this->findEsignNetworkPdf($po);
        if (!empty($signed['has_file']) && !empty($signed['path'])) {
            return [
                'has_file' => true,
                'path' => $signed['path'],
                'pdf_file' => $signed['pdf_file'],
                'folder' => $signed['folder'],
                'is_signed' => true,
                'source' => 'signed',
            ];
        }
        $displayPo = $this->displayPoNumber($po);
        if ($displayPo === '') {
            return ['has_file' => false, 'path' => null, 'pdf_file' => null, 'folder' => null, 'is_signed' => false, 'source' => 'none'];
        }
        $origName = $this->originalPdfFileName($displayPo);
        $root = $this->esignStorageRoot();
        $folder = $this->networkEsignFolder() ?: $root;
        $candidates = [];
        if ($folder) {
            $candidates[] = $folder . DIRECTORY_SEPARATOR . $origName;
            $candidates[] = $folder . DIRECTORY_SEPARATOR . ('po-' . $displayPo . '.pdf');
            $candidates[] = $folder . DIRECTORY_SEPARATOR . ('PO_' . $displayPo . '.pdf');
        }
        if ($root && $root !== $folder) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . $origName;
        }
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                $marker = dirname($path) . DIRECTORY_SEPARATOR . ('PO-' . $displayPo . '.signed');
                return [
                    'has_file' => true,
                    'path' => $path,
                    'pdf_file' => basename($path),
                    'folder' => dirname($path),
                    'is_signed' => @is_file($marker),
                    'source' => @is_file($marker) ? 'signed' : 'cache',
                ];
            }
        }
        return [
            'has_file' => false,
            'path' => null,
            'pdf_file' => $origName,
            'folder' => $folder,
            'is_signed' => false,
            'source' => 'none',
        ];
    }

    /**
     * Fetch PO PDF bytes from SAP POPDFSet — never writes to disk.
     *
     * @return array{success:bool,body:?string,error:?string,pdf_file:string}
     */
    public function fetchSapPoPdfBytes(string $po): array
    {
        $displayPo = $this->displayPoNumber($po);
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $fileName = $this->originalPdfFileName($displayPo !== '' ? $displayPo : $digits);
        if ($digits === '' || !preg_match('/^\d{1,10}$/', $digits)) {
            return ['success' => false, 'body' => null, 'error' => 'Purchase order must be a number of up to 10 digits.', 'pdf_file' => $fileName];
        }
        $ebeln = str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
        $base = rtrim((string) ($this->cfg['base_url'] ?? ''), '/');
        $pdfService = (string) ($this->cfg['po_pdf_service'] ?? '/sap/opu/odata/sap/ZCOST_SRV/POPDFSet');
        $pdfUrl = $base . $pdfService . "(EBELN='" . $ebeln . "')/\$value";
        $raw = $this->odata->fetchRaw($pdfUrl, (int) ($this->cfg['timeout'] ?? 60));
        $body = $raw['body'] ?? null;
        if (is_string($body) && str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $ctype = strtolower((string) ($raw['content_type'] ?? ''));
        $isPdf = is_string($body) && (
            strncmp($body, '%PDF', 4) === 0
            || str_contains($ctype, 'application/pdf')
        );
        if ($raw['error'] !== null || !is_string($body) || $body === '' || !$isPdf) {
            return [
                'success' => false,
                'body' => null,
                'error' => $raw['error'] ?? 'SAP did not return a PDF for this purchase order.',
                'pdf_file' => $fileName,
            ];
        }
        return ['success' => true, 'body' => $body, 'error' => null, 'pdf_file' => $fileName];
    }

    /**
     * DB says e-sign completed for this PO (Pass_po_validtion).
     */
    public function isEsignCompletedInDb(string $po): bool
    {
        try {
            $display = $this->displayPoNumber($po);
            if ($display === '') {
                return false;
            }
            $map = $this->passLog->latestStatusesForPos([$display]);
            $st = strtolower(trim((string) ($map[$display]['esign_status'] ?? '')));
            if ($st === '') {
                foreach ($map as $k => $row) {
                    $kd = ltrim(preg_replace('/\D/', '', (string) $k) ?? '', '0');
                    if ($kd === $display) {
                        $st = strtolower(trim((string) ($row['esign_status'] ?? '')));
                        break;
                    }
                }
            }
            if ($st === '') {
                return false;
            }
            return str_contains($st, 'e-sign') || str_contains($st, 'esign') || str_contains($st, 'signed') || str_contains($st, 'completed');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Open-PDF resolver: verified signed file (DB + disk) OR SAP bytes in memory (no save).
     *
     * @return array{success:bool,path:?string,body:?string,pdf_file:?string,is_signed:bool,source:string,error:?string,fetched_sap:bool}
     */
    public function resolvePoPdfForView(string $po): array
    {
        $displayPo = $this->displayPoNumber($po);
        if ($displayPo === '' || !preg_match('/^\d{1,10}$/', preg_replace('/\D/', '', $po) ?? '')) {
            return [
                'success' => false,
                'path' => null,
                'body' => null,
                'pdf_file' => null,
                'is_signed' => false,
                'source' => 'none',
                'error' => 'Purchase order must be a number of up to 10 digits.',
                'fetched_sap' => false,
            ];
        }

        $signed = $this->findEsignNetworkPdf($displayPo);
        $dbOk = $this->isEsignCompletedInDb($displayPo);
        // File alone is not enough — require DB e-sign success (or legacy .signed marker + file)
        $markerOk = false;
        if (!empty($signed['path'])) {
            $marker = dirname((string) $signed['path']) . DIRECTORY_SEPARATOR . ('PO-' . $displayPo . '.signed');
            $markerOk = @is_file($marker) || str_contains(strtolower((string) ($signed['pdf_file'] ?? '')), 'signed');
        }
        if (!empty($signed['has_file']) && !empty($signed['path']) && @is_file((string) $signed['path'])
            && ($dbOk || $markerOk)) {
            return [
                'success' => true,
                'path' => $signed['path'],
                'body' => null,
                'pdf_file' => $signed['pdf_file'],
                'is_signed' => true,
                'source' => 'signed',
                'error' => null,
                'fetched_sap' => false,
            ];
        }

        $sap = $this->fetchSapPoPdfBytes($displayPo);
        if (empty($sap['success']) || empty($sap['body'])) {
            return [
                'success' => false,
                'path' => null,
                'body' => null,
                'pdf_file' => $sap['pdf_file'] ?? $this->originalPdfFileName($displayPo),
                'is_signed' => false,
                'source' => 'sap',
                'error' => (string) ($sap['error'] ?? 'SAP did not return a PDF for this purchase order.'),
                'fetched_sap' => true,
            ];
        }

        return [
            'success' => true,
            'path' => null,
            'body' => $sap['body'],
            'pdf_file' => $sap['pdf_file'],
            'is_signed' => false,
            'source' => 'sap',
            'error' => null,
            'fetched_sap' => true,
        ];
    }

    /**
     * @deprecated Use resolvePoPdfForView() — no longer caches unsigned SAP PDFs to disk.
     *
     * @return array{success:bool,path:?string,pdf_file:?string,folder:?string,is_signed:bool,source:string,error:?string,fetched_sap:bool,body:?string}
     */
    public function ensureLocalPoPdf(string $po): array
    {
        $view = $this->resolvePoPdfForView($po);
        return [
            'success' => !empty($view['success']),
            'path' => $view['path'] ?? null,
            'pdf_file' => $view['pdf_file'] ?? null,
            'folder' => !empty($view['path']) ? dirname((string) $view['path']) : $this->poEsignSaveDir(),
            'is_signed' => !empty($view['is_signed']),
            'source' => (string) ($view['source'] ?? 'none'),
            'error' => $view['error'] ?? null,
            'fetched_sap' => !empty($view['fetched_sap']),
            'body' => $view['body'] ?? null,
        ];
    }

    /**
     * Map UNC share with credentials (Windows). Never blocks > ~3s.
     */
    private function connectNetworkShare(): bool
    {
        if ($this->networkReachable !== null) {
            return $this->networkReachable;
        }

        $share = is_array($this->cfg['po_pdf_share'] ?? null) ? $this->cfg['po_pdf_share'] : [];
        $unc = rtrim(str_replace('/', '\\', (string) ($share['unc'] ?? '')), '\\');
        $user = (string) ($share['username'] ?? '');
        $pass = (string) ($share['password'] ?? '');
        if ($unc === '') {
            return $this->networkReachable = false;
        }

        // Soft TCP probe only — do not abort; share may still map via net use / domain auth
        $this->networkHostReachable($unc, 2);

        if (@is_dir($unc)) {
            return $this->networkReachable = true;
        }

        $isWin = (defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS) === 'Windows'
            || stripos((string) PHP_OS, 'WIN') !== false;
        if (!$isWin || $user === '') {
            // One more try after brief wait (SMB settle)
            usleep(200000);
            return $this->networkReachable = (bool) @is_dir($unc);
        }

        // Timed net use — never hang the e-sign request
        $cmd = 'net use ' . escapeshellarg($unc)
            . ' /user:' . escapeshellarg($user)
            . ' ' . escapeshellarg($pass)
            . ' /persistent:no';
        $ok = $this->runCommandWithTimeout($cmd, 4);
        if ($ok || @is_dir($unc)) {
            return $this->networkReachable = true;
        }
        // Retry is_dir once — net use can succeed while first is_dir is still false
        usleep(250000);
        return $this->networkReachable = (bool) @is_dir($unc);
    }

    /**
     * Run a shell command with a hard timeout (Windows-safe).
     */
    private function runCommandWithTimeout(string $command, int $timeoutSec = 4): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $status = proc_get_status($proc);
        while (!empty($status['running'])) {
            if ((microtime(true) - $start) >= $timeoutSec) {
                @proc_terminate($proc);
                break;
            }
            usleep(100000);
            $status = proc_get_status($proc);
        }
        @stream_get_contents($pipes[1]);
        @stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return $code === 0 || (!empty($status) && isset($status['exitcode']) && (int) $status['exitcode'] === 0);
    }

    /**
     * Ensure local (+ optional network) PO folders exist.
     *
     * @return array{0:string,1:?string}
     */
    private function ensurePoFolders(string $displayPo, bool $withNetwork = true): array
    {
        $local = $this->poFolder($displayPo);
        if (!is_dir($local)) {
            @mkdir($local, 0775, true);
        }
        $network = null;
        if ($withNetwork && $this->connectNetworkShare()) {
            $network = $this->networkPoFolder($displayPo);
            if ($network !== null && !@is_dir($network)) {
                @mkdir($network, 0775, true);
            }
            if ($network !== null && !@is_dir($network)) {
                $network = null;
            }
        }
        return [$local, $network];
    }

    /**
     * Write bytes to local folder and (when available) legacy network Po-{po} folder.
     *
     * @return array{local:?string,network:?string,error:?string}
     */
    private function writePoFile(string $displayPo, string $fileName, string $contents, bool $toNetwork = true): array
    {
        // Local first — network is optional and must never block e-sign
        [$localFolder, $networkFolder] = $this->ensurePoFolders($displayPo, $toNetwork);
        $localPath = null;
        $networkPath = null;
        $error = null;

        if (is_dir($localFolder)) {
            $path = $localFolder . DIRECTORY_SEPARATOR . $fileName;
            if (@file_put_contents($path, $contents) !== false) {
                $localPath = $path;
            } else {
                $error = 'Could not write file to local upload folder.';
            }
        } else {
            $error = 'Could not create local upload folder: ' . $localFolder;
        }

        if ($toNetwork && $networkFolder !== null) {
            $nPath = $networkFolder . '\\' . $fileName;
            if (@file_put_contents($nPath, $contents) !== false) {
                $networkPath = $nPath;
            } elseif ($error === null) {
                $error = 'Could not write file to network share ' . $networkFolder;
            }
        }

        return ['local' => $localPath, 'network' => $networkPath, 'error' => ($localPath || $networkPath) ? null : $error];
    }

    /**
     * Deep-find MessageType / Message in SAP POApprovalSet payload.
     * Sample:
     *   { "RequestId":"…", "Ebeln":"…", "Value":"", "MessageType":"E", "Message":"PO is not approved in SAP" }
     *   { "MessageType":"S", "Message":"PO is approved in SAP" }
     *
     * @param mixed $node
     * @return array{0:?string,1:?string} [messageType, message]
     */
    private function findSapMessageFields(mixed $node, int $depth = 0): array
    {
        if ($depth > 8 || !is_array($node)) {
            return [null, null];
        }
        $typeKeys = ['MessageType', 'messageType', 'MsgType', 'TYPE', 'Type'];
        $msgKeys = ['Message', 'message', 'MsgText', 'MESSAGE', 'Msg'];
        $foundType = null;
        $foundMsg = null;
        foreach ($typeKeys as $k) {
            if (isset($node[$k]) && trim((string) $node[$k]) !== '') {
                $foundType = strtoupper(trim((string) $node[$k]));
                break;
            }
        }
        foreach ($msgKeys as $k) {
            if (isset($node[$k]) && trim((string) $node[$k]) !== '') {
                $foundMsg = trim((string) $node[$k]);
                break;
            }
        }
        if ($foundType !== null || $foundMsg !== null) {
            return [$foundType, $foundMsg];
        }
        foreach ($node as $child) {
            if (!is_array($child)) {
                continue;
            }
            [$t, $m] = $this->findSapMessageFields($child, $depth + 1);
            if ($t !== null || $m !== null) {
                return [$t, $m];
            }
        }
        return [null, null];
    }

    /**
     * Read SAP POApprovalSet business result from OData body.
     * MessageType S = approved; E = unapproved / error. Message = UI text.
     *
     * @param array<string,mixed> $approvalResult from SapODataClient::postJson
     * @return array{message_type:string,message:string,is_success:bool,is_approved:bool,is_unapproved:bool}
     */
    public function parseApprovalMessage(array $approvalResult): array
    {
        $body = $approvalResult['body'] ?? null;
        if (!is_array($body) && !empty($approvalResult['raw'])) {
            $decoded = json_decode((string) $approvalResult['raw'], true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        [$foundType, $foundMsg] = $this->findSapMessageFields(is_array($body) ? $body : []);
        $messageType = $foundType !== null ? $foundType : '';
        $message = $foundMsg !== null ? $foundMsg : '';

        // Fallbacks from transport / OData error envelope
        if ($message === '' && !empty($approvalResult['error'])) {
            $message = trim((string) $approvalResult['error']);
        }
        if ($messageType === '' && isset($approvalResult['message_type'])) {
            $messageType = strtoupper(trim((string) $approvalResult['message_type']));
        }
        if ($message === '' && isset($approvalResult['message'])) {
            $message = trim((string) $approvalResult['message']);
        }

        // Prefer explicit MessageType when present — do NOT invent S from HTTP 200
        if ($messageType !== '') {
            $letter = strtoupper(substr($messageType, 0, 1));
            $isSuccess = $letter === 'S';
            $isError = $letter === 'E';
        } else {
            // No MessageType in body → fall back to transport success
            $letter = !empty($approvalResult['success']) ? 'S' : 'E';
            $isSuccess = $letter === 'S';
            $isError = !$isSuccess;
        }

        return [
            'message_type' => $letter,
            'message' => $message,
            'is_success' => $isSuccess,
            'is_approved' => $isSuccess,
            'is_unapproved' => $isError || !$isSuccess,
        ];
    }

    /**
     * Post POApprovalSet only (no PDF fetch / save).
     * Value "X" = approve; Value " " = revert.
     * SAP MessageType S = approved, E = unapproved — Message is returned for UI.
     *
     * @return array{success:bool,request_id:string,po:string,value:string,error:?string,approval:?array,payload?:array,message?:string,message_type?:string}
     */
    public function approveOnly(string $po, ?string $requestId = null, string $value = 'X'): array
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        if ($digits === '' || !preg_match('/^\d{1,10}$/', $digits)) {
            return [
                'success' => false,
                'request_id' => '',
                'po' => $digits,
                'value' => $value,
                'error' => 'Purchase order must be a number of up to 10 digits.',
                'message' => 'Purchase order must be a number of up to 10 digits.',
                'message_type' => 'E',
                'approval' => null,
            ];
        }

        $ebeln = str_pad($digits, 10, '0', STR_PAD_LEFT);
        $displayPo = ltrim($digits, '0');
        if ($displayPo === '') {
            $displayPo = '0';
        }
        $requestId = trim((string) $requestId);
        if ($requestId === '') {
            $requestId = (string) (time() % 1000000000);
        }

        $valueFlag = strtoupper(trim($value)) === 'X' ? 'X' : ' ';
        $isApprove = $valueFlag === 'X';
        $approvalPath = (string) ($this->cfg['po_approval_service'] ?? '/sap/opu/odata/sap/ZCOST_SRV/POApprovalSet');
        $payload = [
            'RequestId' => $requestId,
            'ApprovalToItems' => [
                [
                    'RequestId' => $requestId,
                    'Ebeln'     => $ebeln,
                    'Value'     => $valueFlag,
                ],
            ],
        ];

        // 1) SAP Approve API — then interpret MessageType (S=approved, E=unapproved)
        $approval = $this->odata->postJson($approvalPath, $payload, (int) ($this->cfg['timeout'] ?? 60));
        $parsed = $this->parseApprovalMessage($approval);
        $logAction = $isApprove ? 'approve' : 'revert';

        // Business failure: HTTP may still be 200 with MessageType=E
        $transportOk = !empty($approval['success']);
        $businessOk = $transportOk && !empty($parsed['is_success']);
        $uiMessage = $parsed['message'] !== ''
            ? $parsed['message']
            : ($businessOk
                ? ($isApprove ? 'PO approved in SAP.' : 'PO reverted in SAP.')
                : (string) ($approval['error'] ?? ($isApprove ? 'SAP POApprovalSet failed.' : 'SAP PO revert failed.')));

        if (!$businessOk) {
            $failApproval = $approval;
            $failApproval['success'] = false;
            $failApproval['error'] = $uiMessage;
            $failApproval['message_type'] = $parsed['message_type'];
            $failApproval['message'] = $uiMessage;
            $this->logPassApi(
                $displayPo,
                $requestId,
                $valueFlag,
                $isApprove,
                $payload,
                $failApproval,
                'Approved API failed (MessageType=' . ($parsed['message_type'] ?: '?') . ')',
                $logAction
            );
            return [
                'success' => false,
                'request_id' => $requestId,
                'po' => $displayPo,
                'value' => $valueFlag,
                'error' => $uiMessage,
                'message' => $uiMessage,
                'message_type' => $parsed['message_type'] !== '' ? $parsed['message_type'] : 'E',
                'sap_approved' => false,
                'approval' => $failApproval,
                'payload' => $payload,
                'status' => PassPoValidationLogService::statusFor($logAction, false, $valueFlag),
            ];
        }

        // 2) MessageType S → store Approved / Reverted
        $okApproval = $approval;
        $okApproval['message_type'] = $parsed['message_type'];
        $okApproval['message'] = $uiMessage;
        $this->logPassApi(
            $displayPo,
            $requestId,
            $valueFlag,
            $isApprove,
            $payload,
            $okApproval,
            $isApprove ? 'Approved API success' : 'Revert API success',
            $logAction
        );

        return [
            'success' => true,
            'request_id' => $requestId,
            'po' => $displayPo,
            'value' => $valueFlag,
            'error' => null,
            'message' => $uiMessage,
            'message_type' => $parsed['message_type'] !== '' ? $parsed['message_type'] : 'S',
            'approval' => $okApproval,
            'payload' => $payload,
            'sap_approved' => $isApprove,
            'status' => PassPoValidationLogService::statusFor($logAction, true, $valueFlag),
        ];
    }

    /**
     * Post POApprovalSet.
     * Value "X" = approve / PASS PO; Value " " (space) = revert.
     *
     * @return array{success:bool,request_id:string,po:string,value:string,pdf_path:?string,pdf_url:?string,folder:?string,error:?string,approval:?array}
     */
    public function approveAndFetchPdf(string $po, ?string $requestId = null, string $value = 'X', bool $fetchPdf = true): array
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        if ($digits === '' || !preg_match('/^\d{1,10}$/', $digits)) {
            return [
                'success' => false,
                'request_id' => '',
                'po' => $digits,
                'value' => $value,
                'pdf_path' => null,
                'pdf_url' => null,
                'folder' => null,
                'error' => 'Purchase order must be a number of up to 10 digits.',
                'approval' => null,
            ];
        }

        $ebeln = str_pad($digits, 10, '0', STR_PAD_LEFT);
        $displayPo = ltrim($digits, '0');
        if ($displayPo === '') {
            $displayPo = '0';
        }
        $requestId = trim((string) $requestId);
        if ($requestId === '') {
            $requestId = (string) (time() % 1000000000);
        }

        // SAP flag: 'X' = approved, single space = revert / un-approve
        $valueFlag = strtoupper(trim($value)) === 'X' ? 'X' : ' ';
        $isApprove = $valueFlag === 'X';

        // Approve API only first (shared with Approved button)
        $only = $this->approveOnly($po, $requestId, $valueFlag);
        $approval = $only['approval'] ?? null;
        $sapMessage = (string) ($only['message'] ?? $only['error'] ?? '');
        $sapMessageType = strtoupper(substr(trim((string) ($only['message_type'] ?? '')), 0, 1));
        if (empty($only['success'])) {
            return [
                'success' => false,
                'request_id' => (string) ($only['request_id'] ?? $requestId),
                'po' => $displayPo,
                'value' => $valueFlag,
                'pdf_path' => null,
                'pdf_url' => null,
                'folder' => null,
                'error' => $sapMessage !== ''
                    ? $sapMessage
                    : (string) ($only['error'] ?? ($isApprove ? 'PO approval failed.' : 'PO revert failed.')),
                'message' => $sapMessage !== ''
                    ? $sapMessage
                    : (string) ($only['error'] ?? ($isApprove ? 'PO approval failed.' : 'PO revert failed.')),
                'message_type' => $sapMessageType !== '' ? $sapMessageType : 'E',
                'approval' => $approval,
            ];
        }
        $requestId = (string) ($only['request_id'] ?? $requestId);

        // Approved button path: stop after POApprovalSet
        if (!$fetchPdf) {
            return [
                'success' => true,
                'request_id' => $requestId,
                'po' => $displayPo,
                'value' => $valueFlag,
                'pdf_path' => null,
                'pdf_url' => null,
                'folder' => null,
                'error' => null,
                'message' => $sapMessage,
                'message_type' => $sapMessageType !== '' ? $sapMessageType : 'S',
                'approval' => $approval,
            ];
        }

        // Revert only posts Value=" " — no PDF / e-sign step
        if (!$isApprove) {
            $folder = $this->poFolder($displayPo);
            if (is_dir($folder)) {
                @file_put_contents(
                    $folder . DIRECTORY_SEPARATOR . 'revert.json',
                    json_encode([
                        'request_id' => $requestId,
                        'po' => $displayPo,
                        'ebeln' => $ebeln,
                        'value' => $valueFlag,
                        'reverted_at' => date('c'),
                        'message' => $sapMessage,
                        'message_type' => $sapMessageType,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }
            return [
                'success' => true,
                'request_id' => $requestId,
                'po' => $displayPo,
                'value' => $valueFlag,
                'pdf_path' => null,
                'pdf_url' => null,
                'folder' => is_dir($folder) ? $folder : null,
                'error' => null,
                'message' => $sapMessage,
                'message_type' => $sapMessageType !== '' ? $sapMessageType : 'S',
                'approval' => $approval,
            ];
        }

        // Approval succeeded — do NOT fetch/cache unsigned SAP PDF.
        // Open PDF / e-Sign stream via resolvePoPdfForView (signed file or SAP in memory).
        $meta = [
            'request_id' => $requestId,
            'po' => $displayPo,
            'ebeln' => $ebeln,
            'value' => $valueFlag,
            'approved_at' => date('c'),
            'pdf_file' => null,
            'local_path' => null,
            'network_path' => null,
            'approval_http' => $approval['http_code'] ?? null,
            'message' => $sapMessage,
            'message_type' => $sapMessageType,
            'note' => 'Unsigned PDF not cached; use purchase-order/pdf for viewing.',
        ];
        $this->writePoFile(
            $displayPo,
            'approval.json',
            (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $folder = $this->poFolder($displayPo);

        return [
            'success' => true,
            'request_id' => $requestId,
            'po' => $displayPo,
            'value' => $valueFlag,
            'pdf_path' => null,
            'pdf_url' => null,
            'folder' => $folder,
            'network_path' => null,
            'error' => null,
            'message' => $sapMessage,
            'message_type' => $sapMessageType !== '' ? $sapMessageType : 'S',
            'approval' => $approval,
        ];
    }

    /**
     * Save e-sign signature + metadata next to the PO PDF,
     * upsert the signing user's reusable signature, and log Pass_po_validtion.
     *
     * @param array<string,mixed> $payload
     * @return array{success:bool,path:?string,error:?string,meta?:array,db_id?:int|null}
     */
    public function saveEsign(string $po, array $payload): array
    {
        @set_time_limit(120);
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $displayPo = ltrim($digits, '0');
        if ($displayPo === '') {
            $displayPo = $digits !== '' ? $digits : '';
        }
        if ($displayPo === '') {
            return ['success' => false, 'path' => null, 'error' => 'PO is required.'];
        }

        $rawSig = (string) ($payload['signature_data'] ?? '');
        $dataUrl = $rawSig;
        $sigData = $rawSig;
        if (preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $sigData, $m)) {
            $sigData = substr($sigData, strlen($m[0]));
        } else {
            $dataUrl = 'data:image/png;base64,' . $sigData;
        }
        $bin = base64_decode($sigData, true);
        if ($bin === false || $bin === '') {
            return ['success' => false, 'path' => null, 'error' => 'Signature image is required.'];
        }

        $sigFile = 'signature_' . date('Ymd_His') . '.png';
        $user = function_exists('portal_user') ? portal_user() : null;
        $userId = (string) ($user['id'] ?? ($_SESSION['user_id'] ?? ''));
        $signedBy = trim((string) ($payload['signed_by'] ?? ''));
        if ($signedBy === '') {
            $signedBy = (string) ($user['username'] ?? ($_SESSION['username'] ?? 'system'));
        }
        $requestId = trim((string) ($payload['request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = (string) (time() % 1000000000);
        }

        // Require client-stamped PDF up front (signature burned in)
        $signedPdfB64 = trim((string) ($payload['signed_pdf'] ?? $payload['signed_pdf_base64'] ?? ''));
        if (preg_match('#^data:application/pdf;base64,#i', $signedPdfB64)) {
            $signedPdfB64 = substr($signedPdfB64, strlen('data:application/pdf;base64,'));
        }
        $signedBin = $signedPdfB64 !== '' ? base64_decode($signedPdfB64, true) : false;
        if (!is_string($signedBin) || $signedBin === '' || strncmp($signedBin, '%PDF', 4) !== 0) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Signed PDF is missing. Place the signature on the PDF and try Save again.',
            ];
        }

        // SAP EBELN is CHAR10 — keep digits, pad left only when shorter
        $ebeln = str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
        $pdfName = $this->signedPdfFileName($displayPo);
        $fileUrl = function_exists('url')
            ? url('purchase-order/signed-file?po=' . rawurlencode($displayPo))
            : ('purchase-order/signed-file?po=' . rawurlencode($displayPo));

        // 1) REQUIRED: save signed PDF to EVOL/uploads/po_esign/PO-{po}.pdf (verify before DB)
        try {
            $esignNet = $this->writeEsignNetworkPdf($displayPo, $signedBin);
        } catch (Throwable $netErr) {
            $esignNet = [
                'path' => null,
                'folder' => $this->poEsignSaveDir(),
                'file' => $pdfName,
                'error' => 'E-sign storage write failed: ' . $netErr->getMessage(),
                'verified' => false,
            ];
        }
        $signedNetworkPath = $esignNet['path'] ?? null;
        $esignNetworkFolder = $esignNet['folder'] ?? $this->poEsignSaveDir();
        if ($signedNetworkPath === null || empty($esignNet['verified'])) {
            return [
                'success' => false,
                'path' => null,
                'error' => (string) ($esignNet['error'] ?? 'Could not save signed PDF to EVOL/uploads/po_esign.'),
                'network_folder' => $esignNetworkFolder,
                'pdf_url' => null,
                'file_url' => null,
            ];
        }

        // Best-effort: also store signature PNG next to the PDF
        $sigNetworkPath = null;
        if ($esignNetworkFolder !== null && @is_dir($esignNetworkFolder)) {
            $sigNetworkPath = $esignNetworkFolder . DIRECTORY_SEPARATOR . $sigFile;
            if (@file_put_contents($sigNetworkPath, $bin) === false) {
                $sigNetworkPath = null;
            }
            @file_put_contents($esignNetworkFolder . DIRECTORY_SEPARATOR . 'signature_latest.png', $bin);
        }

        // Save signed PDF only — POApprovalSet runs from the Approved button
        try {
            $this->userEsign->saveForUser($userId !== '' ? $userId : null, $signedBy, $dataUrl);
        } catch (Throwable $ignored) {
            // DB signature upsert must not fail e-sign
        }

        $meta = [
            'po' => $displayPo,
            'ebeln' => $ebeln,
            'signed_at' => date('c'),
            'signed_by' => $signedBy,
            'signature_file' => $sigFile,
            'assigned_user_id' => (string) ($payload['assigned_user_id'] ?? ''),
            'assigned_username' => (string) ($payload['assigned_username'] ?? ''),
            'assigned_email' => (string) ($payload['assigned_email'] ?? ''),
            'remarks' => (string) ($payload['remarks'] ?? ''),
            'placement' => [
                'x_pct' => (float) ($payload['x_pct'] ?? 0),
                'y_pct' => (float) ($payload['y_pct'] ?? 0),
                'w_pct' => (float) ($payload['w_pct'] ?? 0),
                'h_pct' => (float) ($payload['h_pct'] ?? 0),
            ],
            'request_id' => $requestId,
            'pdf_file' => $pdfName,
            'signed_pdf' => true,
            'signed_network' => $signedNetworkPath,
            'esign_network_folder' => $esignNetworkFolder,
            'signature_network' => $sigNetworkPath,
            'approve_api' => false,
        ];
        if ($esignNetworkFolder !== null) {
            @file_put_contents(
                $esignNetworkFolder . DIRECTORY_SEPARATOR . 'esign_' . $displayPo . '.json',
                (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        $mumbaiNow = $this->mumbaiLocalNow();
        $dbId = null;
        try {
            $log = $this->passLog->save([
                'user_id' => $userId !== '' ? $userId : null,
                'username' => $signedBy,
                'purchase_order' => $displayPo,
                'request_id' => $requestId,
                'action' => 'esign',
                'status' => 'E-Sign Completed',
                'value_flag' => '',
                'method' => 'POST',
                'url' => 'local://po_esign/' . $pdfName,
                'payload' => [
                    'signature_file' => $sigFile,
                    'placement' => $meta['placement'],
                    'network_folder' => $esignNetworkFolder,
                    'network_file' => $pdfName,
                    'approve_api' => false,
                    'signed_at_mumbai' => $mumbaiNow,
                ],
                'response' => [
                    'success' => true,
                    'status' => 'E-Sign Completed',
                    'note' => 'E-Sign Completed',
                    'path' => $signedNetworkPath,
                    'network_path' => $signedNetworkPath,
                    'esign_network_folder' => $esignNetworkFolder,
                    'pdf_file' => $pdfName,
                    'signed_pdf' => true,
                    'pdf_url' => $fileUrl,
                    'file_url' => $fileUrl,
                    'signed_at_mumbai' => $mumbaiNow,
                ],
                'http_code' => 200,
                'success' => true,
            ]);
            $dbId = $log['id'] ?? null;
        } catch (Throwable $ignored) {
            // DB log must not block file save
        }

        return [
            'success' => true,
            'path' => $signedNetworkPath,
            'error' => null,
            'meta' => $meta,
            'db_id' => $dbId,
            'request_id' => $requestId,
            'pdf_url' => $fileUrl,
            'file_url' => $fileUrl,
            'pdf_file' => $pdfName,
            'signed_pdf' => true,
            'folder' => $esignNetworkFolder,
            'network_path' => $signedNetworkPath,
            'network_folder' => $esignNetworkFolder,
            'signed_at_mumbai' => $mumbaiNow,
            'status' => 'E-Sign Completed',
            'approval' => null,
            'approval_payload' => null,
        ];
    }

    /** Asia/Kolkata (Mumbai) local — matches PDF stamp: 26/05/2026 4:07 PM */
    private function mumbaiLocalNow(): string
    {
        try {
            $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
            // g = hour 1-12 without leading zero (sample style)
            return $dt->format('d/m/Y g:i A');
        } catch (Throwable $e) {
            return gmdate('d/m/Y g:i A') . ' UTC';
        }
    }
}
?>