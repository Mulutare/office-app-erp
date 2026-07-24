<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;

$router = new Router();

$homeController = new HomeController();
$authController = new AuthController();

$router->get(
    '/',
    [$homeController, 'index']
);

$router->get(
    '/health',
    [$homeController, 'health']
);

$router->get(
    '/login',
    [$authController, 'showLogin']
);

$router->get(
    '/diagnostics/user-model',
    [$homeController, 'userModelHealth']
);