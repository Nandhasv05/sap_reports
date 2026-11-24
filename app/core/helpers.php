<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP Reports helpers
 */

/*
 * Get the base path
 */
function base_path(string $path = ''): string
{
    $root = dirname(__DIR__, 2);
    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

/**
 * Resolve controller path: app/controllers/{Module}/{Module}Controller.php
 * Example: AuditLogController → app/controllers/AuditLog/AuditLogController.php
 */
function sap_reports_controller_path(string $classOrFile): string
{
    $name = basename(str_replace('\\', '/', $classOrFile));
    $name = preg_replace('/\.php$/i', '', $name) ?: $name;
    if (!str_ends_with($name, 'Controller')) {
        $name .= 'Controller';
    }
    $module = substr($name, 0, -strlen('Controller'));
    if ($module === '') {
        $module = $name;
    }
    return base_path('app/controllers/' . $module . '/' . $name . '.php');
}

/**
 * require_once a controller by class name (folder layout).
 */
function sap_reports_require_controller(string $classOrFile): void
{
    $path = sap_reports_controller_path($classOrFile);
    if (!is_file($path)) {
        throw new RuntimeException('Controller not found: ' . $path);
    }
    require_once $path;
}

// Autoload app/controllers/{Module}/{Class}.php
spl_autoload_register(static function (string $class): void {
    if (!str_ends_with($class, 'Controller') || str_contains($class, '\\')) {
        return;
    }
    $path = sap_reports_controller_path($class);
    if (is_file($path)) {
        require_once $path;
    }
});

// Shared cross-module utilities
$generalFile = base_path('app/common/general.php');
if (is_file($generalFile)) {
    require_once $generalFile;
}

/*
 * Get the configuration file
 */
function config(string $file): array
{
    static $cache = [];
    if (!isset($cache[$file])) {
        $path = base_path('config/' . $file . '.php');
        $cache[$file] = file_exists($path) ? require $path : [];
    }
    return $cache[$file];
}

/*
 * Get the URL
 */
function url(string $path = ''): string
{
    $base = $GLOBALS['app_base'] ?? '';
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base === '' ? '/' : rtrim($base, '/') . '/';
    }
    return rtrim($base, '/') . '/' . $path;
}

/*
 * Get the asset (resolves module folders via sap_reports_resolve_public_path)
 */
function asset(string $path): string
{
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    if (function_exists('sap_reports_resolve_public_path')) {
        $rel = sap_reports_resolve_public_path($rel);
    }
    $href = url($rel);
    $file = base_path('public/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
    if (is_file($file)) {
        $href .= (str_contains($href, '?') ? '&' : '?') . 'v=' . filemtime($file);
    }
    return $href;
}

/**
 * Absolute path under public/ for a logical asset (module-aware).
 */
function sap_reports_public_file(string $path): string
{
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    if (function_exists('sap_reports_resolve_public_path')) {
        $rel = sap_reports_resolve_public_path($rel);
    }
    return base_path('public/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
}

/*
 * Escape the value
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
 * Get the EVOL URL
 */
function sap_reports_evol_url(string $path = 'portal_dashboard.php'): string
{
    return '/EVOL/' . ltrim($path, '/');
}

/*
 * Get the SAP Reports home URL
 */
function sap_reports_home_url(): string
{
    $base = rtrim((string) ($GLOBALS['app_base'] ?? '/sap_reports'), '/');
    return ($base === '' ? '/sap_reports' : $base) . '/';
}

/*
 * True for the background data calls (…/data) and /api/ calls that the report pages fetch as JSON
 */
function sap_reports_wants_json(): bool
{
    $path = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
    return str_ends_with($path, '/data')
        || str_contains($path, '/api/')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/*
 * Cache php://input once so audit logging can still read the request body.
 */
function sap_reports_request_raw_body(): string
{
    if (!array_key_exists('sap_reports_raw_request_body', $GLOBALS) || !is_string($GLOBALS['sap_reports_raw_request_body'])) {
        $raw = file_get_contents('php://input');
        $GLOBALS['sap_reports_raw_request_body'] = is_string($raw) ? $raw : '';
    }
    return (string) $GLOBALS['sap_reports_raw_request_body'];
}

/*
 * Persist full API activity row into dbo.sap_report_auditLog
 */
function sap_reports_audit_api_response(array|string|null $payload, int $status = 200, ?string $url = null): void
{
    static $loaded = false;
    if (!$loaded) {
        $serviceFile = base_path('app/services/ApiAuditLogService.php');
        if (!is_file($serviceFile)) {
            return;
        }
        require_once $serviceFile;
        $loaded = true;
    }
    if (!class_exists('ApiAuditLogService', false)) {
        return;
    }
    ApiAuditLogService::log($status, $payload, $url);
}

/*
 * JSON error for data calls (a login redirect would reach the page as HTML)
 */
function sap_reports_json_error(int $status, string $message, string $code): void
{
    $payload = ['success' => false, 'code' => $code, 'message' => $message, 'error' => $message, 'data' => []];
    sap_reports_audit_api_response($payload, $status);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * JSON success/error helper for controllers that do not extend Controller::jsonResponse.
 */
function sap_reports_json_out(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    sap_reports_audit_api_response($payload, $status);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function sap_reports_logo_url(): string
{
    foreach (['assets/logo.png', 'assets/evolv-logo.png'] as $rel) {
        $file = base_path('public/' . str_replace('/', DIRECTORY_SEPARATOR, $rel));
        if (is_file($file) && filesize($file) > 0) {
            return asset($rel);
        }
    }
    return sap_reports_evol_url('logo.png');
}

/*
 * Boot the application base
 */
function evolv_boot_app_base(): void
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = dirname($scriptName);
    if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '\\' || !str_starts_with($scriptDir, '/')) {
        $GLOBALS['app_base'] = '';
    } else {
        $GLOBALS['app_base'] = rtrim($scriptDir, '/');
    }

    $uri = str_replace('\\', '/', (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''));
    if (str_starts_with($uri, '/sap_reports')) {
        $GLOBALS['app_base'] = '/sap_reports';
    }
}

/**
 * App display timezone — Mumbai / India Standard Time.
 */
function sap_reports_app_timezone(): string
{
    return 'Asia/Kolkata';
}

/**
 * Normalize a DB datetime (stored as UTC via SYSUTCDATETIME) to Mumbai local wall time.
 * Returns Y-m-d H:i:s or empty string.
 */
function sap_reports_to_mumbai($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    try {
        if ($value instanceof DateTimeInterface) {
            $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        } else {
            $s = trim((string) $value);
            if ($s === '') {
                return '';
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d+)?/', $s, $m)) {
                $s = $m[1] . ' ' . $m[2];
            }
            $utc = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        }
        return $utc->setTimezone(new DateTimeZone(sap_reports_app_timezone()))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return is_string($value) ? trim($value) : '';
    }
}

/**
 * Format Mumbai local datetime for UI (e.g. 06/11/2026, 12:18:49 pm IST).
 */
function sap_reports_format_mumbai($value): string
{
    $s = sap_reports_to_mumbai($value);
    if ($s === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($s, new DateTimeZone(sap_reports_app_timezone()));
        return $dt->format('d/m/Y, h:i:s a') . ' IST';
    } catch (Throwable $e) {
        return $s;
    }
}
?>