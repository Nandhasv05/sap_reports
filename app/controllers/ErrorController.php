<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Error controller
 */
class ErrorController extends Controller
{
    public function notFound(string $message = 'The page you are looking for does not exist or has been moved.'): void
    {
        http_response_code(404);
        $this->view('errors/404', [
            'pageTitle' => 'Page not found',
            'message'   => $message,
        ]);
    }
}
