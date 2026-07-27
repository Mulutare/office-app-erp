<?php

declare(strict_types=1);
use App\Controllers\UserAdministrationController;
use App\Controllers\AdministrationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\RoleAdministrationController;
use App\Controllers\UserActivityController;

$userAdministrationController =
    new UserAdministrationController();
$roleAdministrationController =
    new RoleAdministrationController();
$userActivityController =
    new UserActivityController();

$router = new Router();

$homeController = new HomeController();
$authController = new AuthController();
$dashboardController =
    new DashboardController();
    $administrationController =
    new AdministrationController();
$router->get(
    '/administration/users',
    [$userAdministrationController, 'index']
);
$router->get(
    '/administration/users/activity',
    [$userActivityController, 'index']
);
$router->get(
    '/administration/roles',
    [$roleAdministrationController, 'index']
);
$router->get(
    '/administration/roles/view',
    [$roleAdministrationController, 'show']
);
$router->get(
    '/administration/roles/edit-permissions',
    [
        $roleAdministrationController,
        'editPermissions',
    ]
);
$router->post(
    '/administration/roles/update-permissions',
    [
        $roleAdministrationController,
        'updatePermissions',
    ]
);
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
$router->get(
    '/administration',
    [$administrationController, 'index']
);
$router->post(
    '/logout',
    [$authController, 'logout']
);

$router->get(
    '/diagnostics/user-model',
    [$homeController, 'userModelHealth']
);
$router->get(
    '/administration/users/create',
    [$userAdministrationController, 'create']
);

$router->get(
    '/administration/users/view',
    [$userAdministrationController, 'show']
);

$router->get(
    '/administration/users/edit',
    [$userAdministrationController, 'edit']
);

$router->post(
    '/administration/users/update',
    [$userAdministrationController, 'update']
);

$router->get(
    '/administration/users/reset-password',
    [
        $userAdministrationController,
        'showResetPassword',
    ]
);

$router->post(
    '/administration/users/reset-password',
    [
        $userAdministrationController,
        'resetPassword',
    ]
);

$router->get(
    '/administration/users/account-status',
    [
        $userAdministrationController,
        'showAccountStatus',
    ]
);

$router->post(
    '/administration/users/account-status',
    [
        $userAdministrationController,
        'changeAccountStatus',
    ]
);

$router->get(
    '/administration/users/unlock',
    [
        $userAdministrationController,
        'showUnlockAccount',
    ]
);

$router->post(
    '/administration/users/unlock',
    [
        $userAdministrationController,
        'unlockAccount',
    ]
);

$router->post(
    '/administration/users',
    [$userAdministrationController, 'store']
);
