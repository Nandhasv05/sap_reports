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

    /*
     * Not found
     */
    protected function notFound(string $message = 'Page not found.'): void
    {
        require_once base_path('app/controllers/ErrorController.php');
        (new ErrorController())->notFound($message);
    }
}
