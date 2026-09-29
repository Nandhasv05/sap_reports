<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Router
 */
class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable|array}> */
    private array $routes = [];

    /*
     * Add a GET route
     */
    public function get(string $pattern, $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /*
     * Add a POST route
     */
    public function post(string $pattern, $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /*
     * Add a PUT route
     */
    public function put(string $pattern, $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /*
     * Add a DELETE route
     */
    public function delete(string $pattern, $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /*
     * Add a route
     */
    private function add(string $method, string $pattern, $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => trim($pattern, '/'),
            'handler' => $handler,
        ];
    }

    /*
     * Dispatch the request
     */
    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = str_replace('\\', '/', (string) $uri);
        if (str_contains($path, '://') || str_contains($path, '?')) {
            $parsed = parse_url($path, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                $path = $parsed;
            }
        }
        if (str_starts_with($path, '/sap_reports')) {
            $path = substr($path, strlen('/sap_reports')) ?: '/';
        }
        $path = preg_replace('#/index\.php(/|$)#i', '/', $path) ?: '/';
        $uri = trim($path, '/');
        if ($uri === '' || strcasecmp($uri, 'index.php') === 0) {
            $uri = '';
        }

        /*
         * Dispatch the request
         */
        $allowed = [];
        foreach ($this->routes as $route) {
            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';

            if (!preg_match($regex, $uri, $matches)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler = $route['handler'];

            /*
             * Dispatch the request
             */
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                call_user_func_array([$controller, $action], $params);
                return;
            }

            call_user_func_array($handler, $params);
            return;
        }

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            sap_reports_json_error(405, 'Method not allowed.', 'method_not_allowed');
        }
        if (sap_reports_wants_json()) {
            sap_reports_json_error(404, 'Not found.', 'not_found');
        }

        require_once base_path('app/controllers/ErrorController.php');
        (new ErrorController())->notFound();
    }
}
