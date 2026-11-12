<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Controller
 */
class Controller
{
    /*
     * View the page
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/main'): void
    {
        extract($data, EXTR_SKIP);

        $viewFile = base_path('app/views/' . str_replace('.', '/', $view) . '.php');
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'View not found: ' . e($view);
            return;
        }

        if ($layout === null) {
            require $viewFile;
            return;
        }

        $layoutFile = base_path('app/views/' . str_replace('.', '/', $layout) . '.php');
        if (!is_file($layoutFile)) {
            require $viewFile;
            return;
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require $layoutFile;
    }

    protected function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }

    /*
     * Not found
     */
    protected function notFound(string $message = 'Page not found.'): void
    {
        require_once base_path('app/controllers/ErrorController.php');
        (new ErrorController())->notFound($message);
    }

    /*
     * JSON API response
     */
    protected function jsonResponse(array $payload, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (function_exists('sap_reports_audit_api_response')) {
            try {
                sap_reports_audit_api_response($payload, $status);
            } catch (Throwable $ignored) {
                // Audit must never blank the API response
            }
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        $json = json_encode($payload, $flags);
        if ($json === false) {
            $json = json_encode([
                'success' => $status >= 200 && $status < 300,
                'message' => 'Response encoding failed',
                'error'   => function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_encode failed',
                'data'    => null,
            ], JSON_UNESCAPED_SLASHES);
        }
        echo is_string($json) && $json !== '' ? $json : '{"success":false,"message":"Empty JSON response","error":"Empty JSON response"}';
        exit;
    }
}
?>