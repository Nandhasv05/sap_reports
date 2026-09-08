<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Web routes
 */
require_once base_path('app/controllers/ReportsController.php');
require_once base_path('app/controllers/ErrorController.php');

/*
 * Get the router
 */
/** @var Router $router */
$router->get('/', [ReportsController::class, 'index']);
/*
 * Get the sales
 */
$router->get('sales', function () {
    (new ReportsController())->show('sales');
});
/*
 * Get the open
 */
$router->get('open', function () {
    (new ReportsController())->show('open');
});
/*
 * Get the division
 */
$router->get('division', function () {
    (new ReportsController())->show('division');
});
/*
 * Get the fabric
 */
$router->get('fabric', function () {
    (new ReportsController())->show('fabric');
});
/*
 * Get the trims
 */
$router->get('trims', function () {
    (new ReportsController())->show('trims');
});
