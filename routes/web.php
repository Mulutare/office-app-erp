<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;

$router = new Router();

$homeController = new HomeController();
$authController = new AuthController();
$dashboardController =
    new DashboardController();

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

$router->post(
    '/login',
    [$authController, 'login']
);
$router->get(
    '/change-password',
    [$authController, 'showChangePassword']
);

$router->post(
    '/change-password',
    [$authController, 'changePassword']
);

$router->get(
    '/dashboard',
    [$dashboardController, 'index']
);

$router->post(
    '/logout',
    [$authController, 'logout']
);

$router->get(
    '/diagnostics/user-model',
    [$homeController, 'userModelHealth']
);