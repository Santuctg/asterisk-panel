<?php
/**
 * Front controller.
 *
 * Point your web server's document root at this `public/` directory.
 * Everything under app/, themes/, resources/ and storage/ stays outside
 * the web root and is never served directly.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);

require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();

// Dispatch the request through the router defined in resources/routes.php.
$router = new App\Core\Router();
require BASE_PATH . '/resources/routes.php';
$router->dispatch(App\Core\Request::capture())->send();
