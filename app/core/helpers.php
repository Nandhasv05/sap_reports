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
 * Get the asset
 */
function asset(string $path): string
{
    $rel = ltrim($path, '/');
    $href = url($rel);
    $file = base_path('public/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
    if (is_file($file)) {
        $href .= (str_contains($href, '?') ? '&' : '?') . 'v=' . filemtime($file);
    }
    return $href;
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
