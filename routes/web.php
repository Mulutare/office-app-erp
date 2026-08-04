<?php

declare(strict_types=1);
use App\Controllers\AuditLogController;
use App\Controllers\AttendanceController;
use App\Controllers\AttendanceSelfServiceController;
use App\Controllers\UserAdministrationController;
use App\Controllers\AdministrationController;
use App\Controllers\AuthController;
use App\Controllers\BranchController;
use App\Controllers\CompanyAdministrationController;
use App\Controllers\CompanyContextController;
use App\Controllers\DashboardController;
use App\Controllers\DepartmentController;
use App\Controllers\EmployeeActivityController;
use App\Controllers\EmployeePositionController;
use App\Controllers\FinanceController;
use App\Controllers\InventoryController;
use App\Controllers\SalesController;
use App\Controllers\ApiV1SalesController;
use App\Controllers\HomeController;
use App\Controllers\HrController;
use App\Controllers\JobTitleController;
use App\Controllers\LeaveController;
use App\Controllers\LeaveBalanceController;
use App\Controllers\LeavePolicyController;
use App\Controllers\ManagerWorkspaceController;
use App\Controllers\ModuleAdministrationController;
use App\Controllers\OrganizationSetupController;
use App\Controllers\PositionController;
use App\Controllers\RoleAdministrationController;
use App\Controllers\UserActivityController;
use App\Controllers\WorkforceCalendarController;

$userAdministrationController =
    new UserAdministrationController();
$roleAdministrationController =
    new RoleAdministrationController();
$userActivityController =
    new UserActivityController();
$auditLogController =
    new AuditLogController();
$attendanceController =
    new AttendanceController();
$attendanceSelfServiceController =
    new AttendanceSelfServiceController();
$workforceCalendarController =
    new WorkforceCalendarController();
$leaveController = new LeaveController();
$leaveBalanceController =
    new LeaveBalanceController();
$leavePolicyController =
    new LeavePolicyController();
$managerWorkspaceController =
    new ManagerWorkspaceController();
$hrController = new HrController();
$employeeActivityController =
    new EmployeeActivityController();
$employeePositionController =
    new EmployeePositionController();
$financeController = new FinanceController();
$inventoryController = new InventoryController();
$salesController = new SalesController();
$apiV1SalesController = new ApiV1SalesController();
$moduleAdministrationController =
    new ModuleAdministrationController();
$companyAdministrationController =
    new CompanyAdministrationController();
$companyContextController =
    new CompanyContextController();
$branchController = new BranchController();
$jobTitleController = new JobTitleController();
$departmentController = new DepartmentController();
$positionController = new PositionController();
$organizationSetupController =
    new OrganizationSetupController();

$router = new Router();

$router->post('/api/v1/oauth/token', [$apiV1SalesController, 'token']);
$router->get('/api/v1/sales/products', [$apiV1SalesController, 'products']);
$router->get('/api/v1/sales/products/{id}', [$apiV1SalesController, 'product']);
$router->get('/api/v1/sales/customers', [$apiV1SalesController, 'customers']);
$router->post('/api/v1/sales/customers', [$apiV1SalesController, 'createCustomer']);
$router->get('/api/v1/sales/customers/{id}', [$apiV1SalesController, 'customer']);
$router->get('/api/v1/sales/orders', [$apiV1SalesController, 'orders']);
$router->post('/api/v1/sales/orders', [$apiV1SalesController, 'createOrder']);
$router->get('/api/v1/sales/orders/{id}', [$apiV1SalesController, 'order']);
$router->post('/api/v1/sales/orders/{id}/submit', [$apiV1SalesController, 'submitOrder']);
$router->post('/api/v1/sales/orders/{id}/cancel', [$apiV1SalesController, 'cancelOrder']);
$router->post('/api/v1/sales/orders/{id}/payments', [$apiV1SalesController, 'payment']);
$router->get('/api/v1/sales/receivables', [$apiV1SalesController, 'receivables']);
$router->get('/api/v1/sales/receivables/{id}', [$apiV1SalesController, 'receivable']);
$router->get('/api/v1/sales/reports/summary', [$apiV1SalesController, 'reportSummary']);

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
    '/hr/leave',
    [$leaveController, 'index']
);
$router->get(
    '/hr/team',
    [$managerWorkspaceController, 'index']
);
$router->post(
    '/hr/leave',
    [$leaveController, 'store']
);
$router->post(
    '/hr/leave/decision',
    [$leaveController, 'decide']
);
$router->get(
    '/hr/leave/balances',
    [$leaveBalanceController, 'index']
);
$router->post(
    '/hr/leave/balances/allocation',
    [$leaveBalanceController, 'saveAllocation']
);
$router->post(
    '/hr/leave/balances/adjustment',
    [$leaveBalanceController, 'addAdjustment']
);
$router->get(
    '/hr/leave/policies',
    [$leavePolicyController, 'index']
);
$router->get(
    '/hr/leave/policies/create',
    [$leavePolicyController, 'create']
);
$router->post(
    '/hr/leave/policies',
    [$leavePolicyController, 'store']
);
$router->get(
    '/hr/leave/policies/edit',
    [$leavePolicyController, 'edit']
);
$router->post(
    '/hr/leave/policies/update',
    [$leavePolicyController, 'update']
);
$router->get(
    '/attendance',
    [$attendanceController, 'index']
);
$router->get(
    '/attendance/calendars',
    [$workforceCalendarController, 'index']
);
$router->post(
    '/attendance/calendars',
    [$workforceCalendarController, 'storeCalendar']
);
$router->post(
    '/attendance/calendars/week',
    [$workforceCalendarController, 'saveWeek']
);
$router->post(
    '/attendance/calendars/holidays',
    [$workforceCalendarController, 'storeHoliday']
);
$router->post(
    '/attendance/calendars/schedules',
    [$workforceCalendarController, 'assignSchedule']
);
$router->post(
    '/attendance/records',
    [$attendanceController, 'store']
);
$router->get(
    '/attendance/me',
    [$attendanceSelfServiceController, 'index']
);
$router->post(
    '/attendance/me/check-in',
    [$attendanceSelfServiceController, 'checkIn']
);
$router->post(
    '/attendance/me/check-out',
    [$attendanceSelfServiceController, 'checkOut']
);
$router->post(
    '/attendance/me/scan',
    [$attendanceSelfServiceController, 'scan']
);
$router->post(
    '/attendance/me/reminders',
    [
        $attendanceSelfServiceController,
        'saveReminders',
    ]
);
$router->post(
    '/attendance/me/notifications/read',
    [
        $attendanceSelfServiceController,
        'markNotificationRead',
    ]
);
$router->post(
    '/attendance/me/push/subscribe',
    [
        $attendanceSelfServiceController,
        'subscribePush',
    ]
);
$router->post(
    '/attendance/me/push/unsubscribe',
    [
        $attendanceSelfServiceController,
        'unsubscribePush',
    ]
);
$router->get(
    '/attendance/team',
    [$attendanceSelfServiceController, 'team']
);
$router->get(
    '/finance',
    [$financeController, 'index']
);
$router->get(
    '/inventory',
    [$inventoryController, 'index']
);
$router->get('/sales', [$salesController, 'index']);
$router->post('/sales/customers', [$salesController, 'storeCustomer']);
$router->post('/sales/products', [$salesController, 'storeProduct']);
$router->post('/sales/territories', [$salesController, 'storeTerritory']);
$router->post('/sales/agents', [$salesController, 'storeAgent']);
$router->post('/sales/targets', [$salesController, 'storeTarget']);
$router->post('/sales/orders', [$salesController, 'storeOrder']);
$router->post('/sales/orders/action', [$salesController, 'transitionOrder']);
$router->post('/sales/serials', [$salesController, 'storeSerialNumbers']);
$router->post('/sales/commissions/action', [$salesController, 'transitionCommission']);
$router->post('/sales/payments', [$salesController, 'recordPayment']);
$router->get('/sales/export', [$salesController, 'export']);
$router->get(
    '/organization/setup',
    [$organizationSetupController, 'index']
);
$router->get(
    '/organization/branches',
    [$branchController, 'index']
);
$router->get(
    '/organization/branches/create',
    [$branchController, 'create']
);
$router->post(
    '/organization/branches',
    [$branchController, 'store']
);
$router->get(
    '/organization/branches/edit',
    [$branchController, 'edit']
);
$router->post(
    '/organization/branches/update',
    [$branchController, 'update']
);
$router->get(
    '/organization/job-titles',
    [$jobTitleController, 'index']
);
$router->get(
    '/organization/job-titles/create',
    [$jobTitleController, 'create']
);
$router->post(
    '/organization/job-titles',
    [$jobTitleController, 'store']
);
$router->get(
    '/organization/job-titles/edit',
    [$jobTitleController, 'edit']
);
$router->post(
    '/organization/job-titles/update',
    [$jobTitleController, 'update']
);
$router->get(
    '/organization/departments',
    [$departmentController, 'index']
);
$router->get(
    '/organization/departments/create',
    [$departmentController, 'create']
);
$router->post(
    '/organization/departments',
    [$departmentController, 'store']
);
$router->get(
    '/organization/departments/edit',
    [$departmentController, 'edit']
);
$router->post(
    '/organization/departments/update',
    [$departmentController, 'update']
);
$router->get(
    '/organization/positions',
    [$positionController, 'index']
);
$router->get(
    '/organization/positions/create',
    [$positionController, 'create']
);
$router->post(
    '/organization/positions',
    [$positionController, 'store']
);
$router->get(
    '/organization/positions/edit',
    [$positionController, 'edit']
);
$router->post(
    '/organization/positions/update',
    [$positionController, 'update']
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
    '/hr/employees/position',
    [$employeePositionController, 'edit']
);
$router->post(
    '/hr/employees/position',
    [$employeePositionController, 'update']
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
    '/administration/modules',
    [$moduleAdministrationController, 'index']
);
$router->post(
    '/administration/modules',
    [$moduleAdministrationController, 'update']
);
$router->get(
    '/administration/companies',
    [$companyAdministrationController, 'index']
);
$router->get(
    '/administration/companies/create',
    [$companyAdministrationController, 'create']
);
$router->get(
    '/administration/companies/view',
    [$companyAdministrationController, 'show']
);
$router->get(
    '/administration/companies/edit',
    [$companyAdministrationController, 'edit']
);
$router->get(
    '/administration/companies/reset-owner-password',
    [
        $companyAdministrationController,
        'showOwnerPasswordReset',
    ]
);
$router->get(
    '/administration/companies/reset-user-password',
    [$companyAdministrationController, 'showCompanyUserPasswordReset']
);
$router->post(
    '/administration/companies',
    [$companyAdministrationController, 'store']
);
$router->post(
    '/administration/companies/update',
    [$companyAdministrationController, 'update']
);
$router->post(
    '/administration/companies/approve',
    [$companyAdministrationController, 'approve']
);
$router->post(
    '/administration/companies/reset-owner-password',
    [
        $companyAdministrationController,
        'resetOwnerPassword',
    ]
);
$router->post(
    '/administration/companies/reset-user-password',
    [$companyAdministrationController, 'resetCompanyUserPassword']
);
$router->post(
    '/administration/companies/lifecycle',
    [
        $companyAdministrationController,
        'changeLifecycle',
    ]
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
$router->post(
    '/company/switch',
    [$companyContextController, 'switch']
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
