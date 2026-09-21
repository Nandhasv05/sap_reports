<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Web routes
 */
require_once base_path('app/controllers/ReportsController.php');
require_once base_path('app/controllers/ErrorController.php');

/** @var Router $router */
$router->get('/', [ReportsController::class, 'index']);
$router->get('sales', function () {
    (new ReportsController())->show('sales');
});

$router->get('open', function () {
    (new ReportsController())->show('open');
});
$router->get('division', function () {
    (new ReportsController())->show('division');
});
$router->get('fabric/data', function () {
    (new ReportsController())->data('fabric');
});
$router->get('fabric', function () {
    (new ReportsController())->show('fabric');
});
$router->get('trims/data', function () {
    (new ReportsController())->data('trims');
});
$router->get('trims', function () {
    (new ReportsController())->show('trims');
});
