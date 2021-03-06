<?php
define('APP_ROOT', dirname(__DIR__));
define('HEADLESSLOUNGE_PUBLIC', __DIR__);
if (PHP_SAPI == 'cli-server') {
    // To help the built-in PHP dev server, check if the request was actually for
    // something which should probably be served as a static file
    $url  = parse_url($_SERVER['REQUEST_URI']);
    $file = __DIR__ . $url['path'];
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

session_set_cookie_params(['samesite' => 'Strict']);
session_start();

// Instantiate the app
$settings = require APP_ROOT . '/src/settings.php';
$app = new \Slim\App($settings);

// Set up dependencies
$dependencies = require APP_ROOT . '/src/dependencies.php';
$dependencies($app);

// Register middleware
$middleware = require APP_ROOT . '/src/middleware.php';
$middleware($app);

// Register routes
$routes = require APP_ROOT . '/src/routes.php';
$routes($app);

// Run app
$app->run();
