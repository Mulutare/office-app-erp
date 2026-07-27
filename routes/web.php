<?php

declare(strict_types=1);
use App\Controllers\AuditLogController;
use App\Controllers\UserAdministrationController;
use App\Controllers\AdministrationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EmployeeActivityController;
use App\Controllers\HomeController;
use App\Controllers\HrController;
use App\Controllers\RoleAdministrationController;
use App\Controllers\UserActivityController;

$userAdministrationController =
    new UserAdministrationController();
$roleAdministrationController =
    new RoleAdministrationController();
$userActivityController =
    new UserActivityController();
$auditLogController =
    new AuditLogController();
$hrController = new HrController();
$employeeActivityController =
    new EmployeeActivityController();

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
    '/hr',
    [$hrController, 'index']
);
$router->get(
    '/hr/employees/view',
    [$hrController, 'show']
);
$router->get(
    '/hr/employees/activity',
    [$employeeActivityController, 'index']
);
$router->get(
    '/hr/employees/create',
    [$hrController, 'createEmployee']
);
$router->get(
    '/hr/employees/edit',
    [$hrController, 'editEmployee']
);
$router->post(
    '/hr/employees',
    [$hrController, 'storeEmployee']
);
$router->post(
    '/hr/employees/update',
    [$hrController, 'updateEmployee']
);
$router->get(
    '/hr/departments',
    [$hrController, 'departments']
);
$router->get(
    '/hr/departments/create',
    [$hrController, 'createDepartment']
);
$router->get(
    '/hr/departments/edit',
    [$hrController, 'editDepartment']
);
$router->post(
    '/hr/departments',
    [$hrController, 'storeDepartment']
);
$router->post(
    '/hr/departments/update',
    [$hrController, 'updateDepartment']
);
$router->get(
    '/administration/audit-logs',
    [$auditLogController, 'index']
);
$router->get(
    '/administration/audit-logs/view',
    [$auditLogController, 'show']
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
