<?php

declare(strict_types=1);

/**
 * Bounded HTTP regression checks for the central access gate.
 *
 * Run against the isolated test database and test fixture accounts.
 */

$baseUrl = rtrim(
    (string) (
        $argv[1]
        ?? 'http://127.0.0.1:8080/office_app/public'
    ),
    '/'
);
$password = (string) (
    getenv('TEST_ADMIN_PASSWORD')
    ?: 'OfficeApp-Test!2026'
);
$results = [];
$failures = 0;

$check = static function (
    bool $condition,
    string $description
) use (&$results, &$failures): void {
    $results[] = [
        'passed' => $condition,
        'description' => $description,
    ];

    if (!$condition) {
        $failures++;
    }
};

/**
 * @param array<string, string> $cookies
 * @param array<string, string> $form
 *
 * @return array{
 *     status: int,
 *     location: string,
 *     body: string
 * }
 */
function httpRequest(
    string $baseUrl,
    string $path,
    array &$cookies,
    string $method = 'GET',
    array $form = []
): array {
    $headers = [
        'Accept: text/html,application/json',
        'Connection: close',
    ];

    if ($cookies !== []) {
        $cookiePairs = [];

        foreach ($cookies as $name => $value) {
            $cookiePairs[] = $name . '=' . $value;
        }

        $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
    }

    $content = '';

    if ($method === 'POST') {
        $content = http_build_query($form);
        $headers[] =
            'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: '
            . strlen($content);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 8,
            'protocol_version' => 1.1,
        ],
    ]);

    $body = @file_get_contents(
        $baseUrl . $path,
        false,
        $context
    );
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $location = '';

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $matches
            ) === 1
        ) {
            $status = (int) $matches[1];
        }

        if (
            stripos($header, 'Location:') === 0
        ) {
            $location = trim(substr($header, 9));
        }

        if (
            preg_match(
                '/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i',
                $header,
                $matches
            ) === 1
        ) {
            $cookies[$matches[1]] = $matches[2];
        }
    }

    return [
        'status' => $status,
        'location' => $location,
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * @param array<string, string> $cookies
 *
 * @return array{
 *     status: int,
 *     location: string,
 *     body: string
 * }
 */
function login(
    string $baseUrl,
    string $username,
    string $password,
    array &$cookies
): array {
    $loginPage = httpRequest(
        $baseUrl,
        '/login',
        $cookies
    );

    if (
        preg_match(
            '/name="_token"\s+value="([^"]+)"/',
            $loginPage['body'],
            $matches
        ) !== 1
    ) {
        return [
            'status' => 0,
            'location' => '',
            'body' => 'CSRF token was not found.',
        ];
    }

    return httpRequest(
        $baseUrl,
        '/login',
        $cookies,
        'POST',
        [
            '_token' => html_entity_decode(
                $matches[1],
                ENT_QUOTES,
                'UTF-8'
            ),
            'login' => $username,
            'password' => $password,
        ]
    );
}

function csrfTokenFromBody(string $body): string
{
    if (
        preg_match(
            '/name="_token"\s+value="([^"]+)"/',
            $body,
            $matches
        ) !== 1
    ) {
        return '';
    }

    return html_entity_decode(
        $matches[1],
        ENT_QUOTES,
        'UTF-8'
    );
}

$publicCookies = [];
$diagnostics = httpRequest(
    $baseUrl,
    '/diagnostics/user-model',
    $publicCookies
);
$check(
    $diagnostics['status'] === 404,
    'Public diagnostics route returns 404'
);

$publicCookies = [];
$root = httpRequest(
    $baseUrl,
    '/',
    $publicCookies
);
$check(
    $root['status'] === 302
    && str_ends_with(
        $root['location'],
        '/login'
    ),
    'Public root redirects to login'
);
$check(
    stripos($root['body'], 'database') === false
    && stripos($root['body'], 'office_app') === false,
    'Public root exposes no database metadata'
);

$restrictedCookies = [];
$restrictedLogin = login(
    $baseUrl,
    'test_no_dashboard',
    $password,
    $restrictedCookies
);
$check(
    $restrictedLogin['status'] === 302
    && str_ends_with(
        $restrictedLogin['location'],
        '/dashboard'
    ),
    'Restricted test user authenticates'
);
$restrictedDashboard = httpRequest(
    $baseUrl,
    '/dashboard',
    $restrictedCookies
);
$check(
    $restrictedDashboard['status'] === 403,
    'Dashboard denies a user without dashboard.view'
);

$passwordCookies = [];
$passwordLogin = login(
    $baseUrl,
    'test_password_change',
    $password,
    $passwordCookies
);
$check(
    $passwordLogin['status'] === 302
    && str_ends_with(
        $passwordLogin['location'],
        '/dashboard'
    ),
    'Password-change test user authenticates'
);
$passwordDashboard = httpRequest(
    $baseUrl,
    '/dashboard',
    $passwordCookies
);
$check(
    $passwordDashboard['status'] === 302
    && str_ends_with(
        $passwordDashboard['location'],
        '/change-password'
    ),
    'Forced-password account is redirected by the access gate'
);
$passwordForm = httpRequest(
    $baseUrl,
    '/change-password',
    $passwordCookies
);
$check(
    $passwordForm['status'] === 200,
    'Forced-password account can open the change-password form'
);

$platformAdminCookies = [];
$platformAdminLogin = login(
    $baseUrl,
    'test_platform_admin',
    $password,
    $platformAdminCookies
);
$check(
    $platformAdminLogin['status'] === 302
    && str_ends_with(
        $platformAdminLogin['location'],
        '/dashboard'
    ),
    'Platform administrator authenticates'
);
$platformOrganizationSetup = httpRequest(
    $baseUrl,
    '/organization/setup',
    $platformAdminCookies
);
$check(
    $platformOrganizationSetup['status'] === 403,
    'Platform administrator is denied the tenant organization setup center'
);
$platformBranches = httpRequest(
    $baseUrl,
    '/organization/branches',
    $platformAdminCookies
);
$check(
    $platformBranches['status'] === 403,
    'Platform administrator is denied tenant branch routes'
);
$platformJobTitles = httpRequest(
    $baseUrl,
    '/organization/job-titles',
    $platformAdminCookies
);
$check(
    $platformJobTitles['status'] === 403,
    'Platform administrator is denied tenant job-title routes'
);
$platformDepartments = httpRequest(
    $baseUrl,
    '/organization/departments',
    $platformAdminCookies
);
$check(
    $platformDepartments['status'] === 403,
    'Platform administrator is denied tenant department routes'
);
$platformPositions = httpRequest(
    $baseUrl,
    '/organization/positions',
    $platformAdminCookies
);
$check(
    $platformPositions['status'] === 403,
    'Platform administrator is denied tenant position routes'
);
$platformEmployeePosition = httpRequest(
    $baseUrl,
    '/hr/employees/position?id=920001',
    $platformAdminCookies
);
$check(
    $platformEmployeePosition['status'] === 403,
    'Platform administrator is denied tenant employee-position routes'
);
$companyDirectory = httpRequest(
    $baseUrl,
    '/administration/companies?search=Test%20Tenant%20B',
    $platformAdminCookies
);
$vendorCompanyId = 0;

if (preg_match(
    '/administration\/companies\/view\?id=(\d+)/',
    $companyDirectory['body'],
    $matches
) === 1) {
    $vendorCompanyId = (int) $matches[1];
}

$companyEdit = httpRequest(
    $baseUrl,
    '/administration/companies/edit?id='
        . $vendorCompanyId,
    $platformAdminCookies
);
$check(
    $companyDirectory['status'] === 200
    && $vendorCompanyId > 0
    && $companyEdit['status'] === 200
    && str_contains(
        $companyEdit['body'],
        'Save company'
    ),
    'Platform administrator can open vendor company editing'
);

$ownerCompanyDirectory = httpRequest(
    $baseUrl,
    '/administration/companies?search=Test%20Tenant%20A',
    $platformAdminCookies
);
$ownerCompanyId = 0;

if (preg_match(
    '/administration\/companies\/view\?id=(\d+)/',
    $ownerCompanyDirectory['body'],
    $matches
) === 1) {
    $ownerCompanyId = (int) $matches[1];
}

$ownerPasswordResetForm = httpRequest(
    $baseUrl,
    '/administration/companies/reset-owner-password?id='
        . $ownerCompanyId,
    $platformAdminCookies
);
$check(
    $ownerCompanyDirectory['status'] === 200
    && $ownerCompanyId > 0
    && $ownerPasswordResetForm['status'] === 200
    && str_contains(
        $ownerPasswordResetForm['body'],
        'Generate temporary password'
    )
    && str_contains(
        $ownerPasswordResetForm['body'],
        'Test Tenant A Administrator'
    ),
    'Platform administrator can open company-owner recovery for the selected tenant'
);

$invalidOwnerPasswordReset = httpRequest(
    $baseUrl,
    '/administration/companies/reset-owner-password',
    $platformAdminCookies,
    'POST',
    [
        'company_id' =>
            (string) $ownerCompanyId,
        '_token' => str_repeat('0', 64),
    ]
);
$check(
    $invalidOwnerPasswordReset['status'] === 302
    && str_contains(
        $invalidOwnerPasswordReset['location'],
        '/administration/companies/reset-owner-password?id='
    ),
    'Company-owner recovery rejects an invalid CSRF token'
);

$invalidLifecycle = httpRequest(
    $baseUrl,
    '/administration/companies/lifecycle',
    $platformAdminCookies,
    'POST',
    [
        'company_id' =>
            (string) $vendorCompanyId,
        'action' => 'suspend',
        'reason' =>
            'HTTP test must not change lifecycle state.',
        '_token' => str_repeat('0', 64),
    ]
);
$check(
    $invalidLifecycle['status'] === 302
    && str_contains(
        $invalidLifecycle['location'],
        '/administration/companies/view?id='
    ),
    'Company lifecycle mutation rejects an invalid CSRF token'
);

$companyAdminCookies = [];
$companyAdminLogin = login(
    $baseUrl,
    'test_company_admin',
    $password,
    $companyAdminCookies
);
$check(
    $companyAdminLogin['status'] === 302
    && str_ends_with(
        $companyAdminLogin['location'],
        '/dashboard'
    ),
    'Company-administrator test user authenticates'
);
$tenantCompanyEdit = httpRequest(
    $baseUrl,
    '/administration/companies/edit?id='
        . $vendorCompanyId,
    $companyAdminCookies
);
$tenantOwnerPasswordReset = httpRequest(
    $baseUrl,
    '/administration/companies/reset-owner-password?id='
        . $ownerCompanyId,
    $companyAdminCookies
);
$tenantLifecycleMutation = httpRequest(
    $baseUrl,
    '/administration/companies/lifecycle',
    $companyAdminCookies,
    'POST',
    [
        'company_id' =>
            (string) $vendorCompanyId,
        'action' => 'suspend',
        'reason' =>
            'Tenant must never control vendor lifecycle.',
        '_token' => csrfTokenFromBody(
            $platformAdminLogin['body']
        ),
    ]
);
$check(
    $tenantCompanyEdit['status'] === 403
    && $tenantOwnerPasswordReset['status']
        === 403
    && $tenantLifecycleMutation['status']
        === 403,
    'Tenant administrators cannot access vendor company lifecycle or owner-recovery routes'
);
$platformSearch = httpRequest(
    $baseUrl,
    '/administration/users?search=test_platform_admin',
    $companyAdminCookies
);
$platformUserId = 0;

if (
    preg_match(
        '/administration\/users\/view\?id=(\d+)/',
        $platformSearch['body'],
        $matches
    ) === 1
) {
    $platformUserId = (int) $matches[1];
}

if (
    $platformSearch['status'] !== 200
    || $platformUserId < 1
) {
    $bodySummary = preg_replace(
        '/\s+/',
        ' ',
        strip_tags($platformSearch['body'])
    );

    echo 'DEBUG platform_search status=';
    echo $platformSearch['status'];
    echo ' location=';
    echo $platformSearch['location'];
    echo ' body=';
    echo substr(
        is_string($bodySummary)
            ? $bodySummary
            : '',
        0,
        500
    );
    echo PHP_EOL;
}

$check(
    $platformSearch['status'] === 200
    && $platformUserId > 0,
    'Company administrator can identify the platform-account test target'
);

$protectedPlatformPaths = [
    '/administration/users/edit?id='
        . $platformUserId,
    '/administration/users/reset-password?id='
        . $platformUserId,
    '/administration/users/account-status?id='
        . $platformUserId,
    '/administration/users/unlock?id='
        . $platformUserId,
];

foreach ($protectedPlatformPaths as $path) {
    $response = httpRequest(
        $baseUrl,
        $path,
        $companyAdminCookies
    );

    $check(
        $response['status'] === 403,
        'Company administrator is denied platform-account route: '
            . $path
    );
}

$lifecycleAccounts = [
    'test_company_pending_user' =>
        'Pending company login is rejected',
    'test_company_inactive_user' =>
        'Inactive company login is rejected',
    'test_company_suspended_user' =>
        'Suspended company login is rejected',
    'test_company_expired_user' =>
        'Expired company login is rejected',
];

foreach (
    $lifecycleAccounts
    as $lifecycleUsername => $description
) {
    $lifecycleCookies = [];
    $lifecycleLogin = login(
        $baseUrl,
        $lifecycleUsername,
        $password,
        $lifecycleCookies
    );

    $check(
        $lifecycleLogin['status'] === 302
        && str_ends_with(
            $lifecycleLogin['location'],
            '/login'
        ),
        $description
    );
}

$tenantAAdminCookies = [];
$tenantAAdminLogin = login(
    $baseUrl,
    'test_tenant_a_admin',
    $password,
    $tenantAAdminCookies
);
$check(
    $tenantAAdminLogin['status'] === 302
    && str_ends_with(
        $tenantAAdminLogin['location'],
        '/dashboard'
    ),
    'Tenant A administrator authenticates'
);

$tenantAWorkforceCalendars = httpRequest(
    $baseUrl,
    '/attendance/calendars',
    $tenantAAdminCookies
);
$check(
    $tenantAWorkforceCalendars['status'] === 200
    && str_contains(
        $tenantAWorkforceCalendars['body'],
        'Workforce calendars'
    )
    && str_contains(
        $tenantAWorkforceCalendars['body'],
        'Create calendar'
    )
    && str_contains(
        $tenantAWorkforceCalendars['body'],
        'All employees (company default)'
    )
    && str_contains(
        $tenantAWorkforceCalendars['body'],
        'Specific employee override'
    )
    && !str_contains(
        $tenantAWorkforceCalendars['body'],
        'Tenant B Confidential'
    ),
    'Tenant attendance administrator can manage only the active company workforce calendars'
);

$tenantAOrganizationSetup = httpRequest(
    $baseUrl,
    '/organization/setup',
    $tenantAAdminCookies
);
$check(
    $tenantAOrganizationSetup['status'] === 200
    && str_contains(
        $tenantAOrganizationSetup['body'],
        'Organization Setup Center'
    )
    && str_contains(
        $tenantAOrganizationSetup['body'],
        '100%'
    )
    && str_contains(
        $tenantAOrganizationSetup['body'],
        'Operationally ready'
    )
    && str_contains(
        $tenantAOrganizationSetup['body'],
        'Tenant A'
    )
    && !str_contains(
        $tenantAOrganizationSetup['body'],
        'Tenant B Confidential'
    ),
    'Tenant organization setup center is ready and isolated to Tenant A'
);

$tenantABranches = httpRequest(
    $baseUrl,
    '/organization/branches',
    $tenantAAdminCookies
);
$check(
    $tenantABranches['status'] === 200
    && str_contains(
        $tenantABranches['body'],
        'Tenant A Headquarters'
    )
    && !str_contains(
        $tenantABranches['body'],
        'Tenant B Confidential Branch'
    ),
    'Tenant A branch directory exposes only Tenant A locations'
);

$foreignBranch = httpRequest(
    $baseUrl,
    '/organization/branches/edit?id=930002',
    $tenantAAdminCookies
);
$check(
    $foreignBranch['status'] === 404
    && !str_contains(
        $foreignBranch['body'],
        'Tenant B Confidential Branch'
    ),
    'Tenant A receives 404 for Tenant B branch'
);

$tenantAJobTitles = httpRequest(
    $baseUrl,
    '/organization/job-titles',
    $tenantAAdminCookies
);
$check(
    $tenantAJobTitles['status'] === 200
    && str_contains(
        $tenantAJobTitles['body'],
        'Tenant A Security Analyst'
    )
    && !str_contains(
        $tenantAJobTitles['body'],
        'Tenant B Confidential Manager'
    ),
    'Tenant A job-title catalogue exposes only Tenant A records'
);

$foreignJobTitle = httpRequest(
    $baseUrl,
    '/organization/job-titles/edit?id=940002',
    $tenantAAdminCookies
);
$check(
    $foreignJobTitle['status'] === 404
    && !str_contains(
        $foreignJobTitle['body'],
        'Tenant B Confidential Manager'
    ),
    'Tenant A receives 404 for Tenant B job title'
);

$tenantADepartments = httpRequest(
    $baseUrl,
    '/organization/departments',
    $tenantAAdminCookies
);
$check(
    $tenantADepartments['status'] === 200
    && str_contains(
        $tenantADepartments['body'],
        'Tenant A Security'
    )
    && str_contains(
        $tenantADepartments['body'],
        'Tenant A Security Operations'
    )
    && !str_contains(
        $tenantADepartments['body'],
        'Tenant B Confidential'
    ),
    'Tenant A department catalogue exposes only Tenant A records'
);

$foreignOrganizationDepartment = httpRequest(
    $baseUrl,
    '/organization/departments/edit?id=9202',
    $tenantAAdminCookies
);
$check(
    $foreignOrganizationDepartment['status']
        === 404
    && !str_contains(
        $foreignOrganizationDepartment['body'],
        'Tenant B Confidential'
    ),
    'Tenant A receives 404 for Tenant B organization department'
);

$tenantAPositions = httpRequest(
    $baseUrl,
    '/organization/positions',
    $tenantAAdminCookies
);
$check(
    $tenantAPositions['status'] === 200
    && str_contains(
        $tenantAPositions['body'],
        'Tenant A Security Analyst Position'
    )
    && !str_contains(
        $tenantAPositions['body'],
        'Tenant B Confidential Position'
    ),
    'Tenant A position catalogue exposes only Tenant A records'
);

$foreignPosition = httpRequest(
    $baseUrl,
    '/organization/positions/edit?id=950002',
    $tenantAAdminCookies
);
$check(
    $foreignPosition['status'] === 404
    && !str_contains(
        $foreignPosition['body'],
        'Tenant B Confidential Position'
    ),
    'Tenant A receives 404 for Tenant B position'
);

$tenantADashboard = httpRequest(
    $baseUrl,
    '/dashboard',
    $tenantAAdminCookies
);
$check(
    $tenantADashboard['status'] === 200
    && str_contains(
        $tenantADashboard['body'],
        'href="/office_app/public/hr"'
    )
    && str_contains(
        $tenantADashboard['body'],
        'href="/office_app/public/attendance"'
    )
    && !str_contains(
        $tenantADashboard['body'],
        'href="/office_app/public/finance"'
    ),
    'Tenant navigation shows licensed HR and Attendance but hides unlicensed Finance'
);

$licensedHr = httpRequest(
    $baseUrl,
    '/hr',
    $tenantAAdminCookies
);
$check(
    $licensedHr['status'] === 200,
    'Licensed HR direct route is accessible'
);

$licensedAttendance = httpRequest(
    $baseUrl,
    '/attendance?date=2026-07-28',
    $tenantAAdminCookies
);
$check(
    $licensedAttendance['status'] === 200
    && str_contains(
        $licensedAttendance['body'],
        'Alice TenantA'
    )
    && !str_contains(
        $licensedAttendance['body'],
        'Bob TenantB'
    ),
    'Licensed Attendance register exposes only Tenant A employees'
);

$tenantALeave = httpRequest(
    $baseUrl,
    '/hr/leave',
    $tenantAAdminCookies
);
$check(
    $tenantALeave['status'] === 200
    && str_contains(
        $tenantALeave['body'],
        'Annual Leave'
    )
    && str_contains(
        $tenantALeave['body'],
        'Alice TenantA'
    )
    && !str_contains(
        $tenantALeave['body'],
        'Bob TenantB'
    ),
    'HR leave workspace exposes only Tenant A people and policy types'
);

$tenantALeaveBalances = httpRequest(
    $baseUrl,
    '/hr/leave/balances?employee=920001&year=2026&policy=970001',
    $tenantAAdminCookies
);
$check(
    $tenantALeaveBalances['status'] === 200
    && str_contains(
        $tenantALeaveBalances['body'],
        'Allocate, reconcile and audit leave days.'
    )
    && str_contains(
        $tenantALeaveBalances['body'],
        'Alice TenantA'
    )
    && str_contains(
        $tenantALeaveBalances['body'],
        '/hr/leave/balances/allocation'
    )
    && str_contains(
        $tenantALeaveBalances['body'],
        '/hr/leave/balances/adjustment'
    )
    && !str_contains(
        $tenantALeaveBalances['body'],
        'Bob TenantB'
    ),
    'Leave-balance workspace is tenant-scoped and exposes controlled management forms'
);

$tenantAManagerWorkspace = httpRequest(
    $baseUrl,
    '/hr/team',
    $tenantAAdminCookies
);
$check(
    $tenantAManagerWorkspace['status'] === 200
    && str_contains(
        $tenantAManagerWorkspace['body'],
        'Alice TenantA'
    )
    && str_contains(
        $tenantAManagerWorkspace['body'],
        'Direct reports'
    )
    && !str_contains(
        $tenantAManagerWorkspace['body'],
        'Bob TenantB'
    ),
    'Manager workspace exposes only assigned users in the active company'
);

$employeeCookies = [];
$employeeLogin = login(
    $baseUrl,
    'test_tenant_a_target',
    $password,
    $employeeCookies
);
$employeeHr = httpRequest(
    $baseUrl,
    '/hr',
    $employeeCookies
);
$employeeLeave = httpRequest(
    $baseUrl,
    '/hr/leave',
    $employeeCookies
);
$employeeLeaveBalances = httpRequest(
    $baseUrl,
    '/hr/leave/balances?employee=920001&year=2026',
    $employeeCookies
);
$employeeTeam = httpRequest(
    $baseUrl,
    '/hr/team',
    $employeeCookies
);
$employeeAttendanceEntry = httpRequest(
    $baseUrl,
    '/attendance',
    $employeeCookies
);
$employeeAttendance = httpRequest(
    $baseUrl,
    '/attendance/me?month=2026-07',
    $employeeCookies
);
$employeeAttendanceTeam = httpRequest(
    $baseUrl,
    '/attendance/team?month=2026-07',
    $employeeCookies
);
$employeeWorkforceCalendars = httpRequest(
    $baseUrl,
    '/attendance/calendars',
    $employeeCookies
);
$employeeDirectoryAttempt = httpRequest(
    $baseUrl,
    '/hr/employees/view?id=920002',
    $employeeCookies
);
$check(
    $employeeLogin['status'] === 302
    && $employeeHr['status'] === 200
    && str_contains(
        $employeeHr['body'],
        'Leave management'
    )
    && str_contains(
        $employeeHr['body'],
        'Attendance self service'
    )
    && !str_contains(
        $employeeHr['body'],
        'Search employees'
    ),
    'Normal employee sees HR self service without the company directory'
);
$check(
    $employeeTeam['status'] === 200
    && str_contains(
        $employeeTeam['body'],
        'My leave balance'
    )
    && str_contains(
        $employeeTeam['body'],
        'No direct reports assigned'
    )
    && !str_contains(
        $employeeTeam['body'],
        'Bob TenantB'
    ),
    'Normal employee can open personal team workspace without cross-company visibility'
);
$check(
    $employeeLeave['status'] === 200
    && str_contains(
        $employeeLeave['body'],
        'Request my leave'
    )
    && str_contains(
        $employeeLeave['body'],
        'Alice TenantA'
    )
    && !str_contains(
        $employeeLeave['body'],
        'Bob TenantB'
    )
    && !str_contains(
        $employeeLeave['body'],
        'Submit employee leave'
    ),
    'Normal employee leave page is limited to the linked employee'
);
$check(
    $employeeDirectoryAttempt['status'] === 403
    && !str_contains(
        $employeeDirectoryAttempt['body'],
        'Bob TenantB'
    ),
    'Employee self service cannot open the company employee directory'
);
$check(
    $employeeWorkforceCalendars['status'] === 403
    && !str_contains(
        $employeeWorkforceCalendars['body'],
        'Create calendar'
    ),
    'Employee self service cannot open workforce-calendar administration'
);
$check(
    $employeeLeaveBalances['status'] === 403
    && !str_contains(
        $employeeLeaveBalances['body'],
        'Alice TenantA'
    ),
    'Employee self service cannot open leave-balance management'
);
$check(
    $employeeAttendanceEntry['status'] === 302
    && str_ends_with(
        $employeeAttendanceEntry['location'],
        '/attendance/me'
    )
    && $employeeAttendance['status'] === 200
    && str_contains(
        $employeeAttendance['body'],
        'Employee self service'
    )
    && str_contains(
        $employeeAttendance['body'],
        'Alice TenantA'
    )
    && str_contains(
        $employeeAttendance['body'],
        'Personal attendance notification'
    )
    && str_contains(
        $employeeAttendance['body'],
        'Configure personal attendance reminders'
    )
    && str_contains(
        $employeeAttendance['body'],
        'Send test alert'
    )
    && str_contains(
        $employeeAttendance['body'],
        'Background push while the browser'
    )
    && !str_contains(
        $employeeAttendance['body'],
        'Bob TenantB'
    ),
    'Normal employee attendance entry redirects to tenant-scoped personal history'
);

$employeeAttendanceCsrf = csrfTokenFromBody(
    $employeeAttendance['body']
);
$employeeReminderSave = httpRequest(
    $baseUrl,
    '/attendance/me/reminders',
    $employeeCookies,
    'POST',
    [
        '_token' => $employeeAttendanceCsrf,
        'timezone' => 'Africa/Nairobi',
        'workdays' => [
            '1',
            '2',
            '3',
            '4',
            '5',
        ],
        'check_in_enabled' => '1',
        'check_in_time' => '08:15',
        'check_out_enabled' => '1',
        'check_out_time' => '17:15',
        'reminder_lead_minutes' => '10',
        'browser_notifications_enabled' => '1',
        'user_id' => '910002',
    ]
);
$employeeReminderPage = httpRequest(
    $baseUrl,
    '/attendance/me',
    $employeeCookies
);
$check(
    $employeeAttendanceCsrf !== ''
    && $employeeReminderSave['status'] === 302
    && str_ends_with(
        $employeeReminderSave['location'],
        '/attendance/me'
    )
    && $employeeReminderPage['status'] === 200
    && str_contains(
        $employeeReminderPage['body'],
        'Your personal attendance reminders were updated.'
    )
    && str_contains(
        $employeeReminderPage['body'],
        'value="08:15"'
    )
    && str_contains(
        $employeeReminderPage['body'],
        'value="17:15"'
    ),
    'Employee can update only the signed-in account attendance reminder preferences'
);
$check(
    $employeeAttendanceTeam['status'] === 200
    && str_contains(
        $employeeAttendanceTeam['body'],
        'No direct reports assigned'
    )
    && !str_contains(
        $employeeAttendanceTeam['body'],
        'Bob TenantB'
    ),
    'Normal employee team attendance has no company-wide visibility'
);

$tenantAManagerAttendance = httpRequest(
    $baseUrl,
    '/attendance/team?month=2026-07',
    $tenantAAdminCookies
);
$check(
    $tenantAManagerAttendance['status'] === 200
    && str_contains(
        $tenantAManagerAttendance['body'],
        'Alice TenantA'
    )
    && !str_contains(
        $tenantAManagerAttendance['body'],
        'Bob TenantB'
    ),
    'Manager attendance includes only same-company direct reports'
);

$tenantAEmployeeProfile = httpRequest(
    $baseUrl,
    '/hr/employees/view?id=920001',
    $tenantAAdminCookies
);
$check(
    $tenantAEmployeeProfile['status'] === 200
    && str_contains(
        $tenantAEmployeeProfile['body'],
        'Tenant A Security Analyst Position'
    )
    && str_contains(
        $tenantAEmployeeProfile['body'],
        'Position assignment history'
    )
    && !str_contains(
        $tenantAEmployeeProfile['body'],
        'Tenant B Confidential Position'
    ),
    'Tenant A employee profile shows only its position assignment history'
);

$tenantAAssignmentForm = httpRequest(
    $baseUrl,
    '/hr/employees/position?id=920001',
    $tenantAAdminCookies
);
$check(
    $tenantAAssignmentForm['status'] === 200
    && str_contains(
        $tenantAAssignmentForm['body'],
        'Tenant A Security Analyst Position'
    )
    && !str_contains(
        $tenantAAssignmentForm['body'],
        'Tenant B Confidential Position'
    )
    && str_contains(
        $tenantAAssignmentForm['body'],
        'Create open position'
    )
    && str_contains(
        $tenantAAssignmentForm['body'],
        'assign_employee_id=920001'
    ),
    'Tenant A position assignment form exposes only Tenant A headcount and a guided create path'
);

$guidedPositionCreate = httpRequest(
    $baseUrl,
    '/organization/positions/create'
        . '?assign_employee_id=920001',
    $tenantAAdminCookies
);
$check(
    $guidedPositionCreate['status'] === 200
    && str_contains(
        $guidedPositionCreate['body'],
        'Employee assignment workflow'
    )
    && str_contains(
        $guidedPositionCreate['body'],
        'name="assign_employee_id"'
    )
    && str_contains(
        $guidedPositionCreate['body'],
        'value="920001"'
    )
    && preg_match(
        '/<option\s+value="open"\s+selected/s',
        $guidedPositionCreate['body']
    ) === 1,
    'Guided position creation preserves the employee return context'
);

$foreignHrPaths = [
    '/hr/employees/view?id=920002',
    '/hr/employees/activity?id=920002',
    '/hr/employees/edit?id=920002',
    '/hr/employees/position?id=920002',
    '/hr/departments/edit?id=9202',
];

foreach ($foreignHrPaths as $path) {
    $response = httpRequest(
        $baseUrl,
        $path,
        $tenantAAdminCookies
    );

    $check(
        $response['status'] === 404
        && !str_contains(
            $response['body'],
            'Tenant B Confidential'
        ),
        'Tenant A receives 404 for Tenant B HR route: '
            . $path
    );
}

$unlicensedFinance = httpRequest(
    $baseUrl,
    '/finance',
    $tenantAAdminCookies
);
$check(
    $unlicensedFinance['status'] === 404
    && str_contains(
        $unlicensedFinance['body'],
        'Module unavailable'
    ),
    'Unlicensed Finance direct route is denied server-side'
);

$tenantBUserId = 910002;
$tenantBSearch = httpRequest(
    $baseUrl,
    '/administration/users?search=test_tenant_b_user',
    $tenantAAdminCookies
);
$check(
    $tenantBSearch['status'] === 200
    && !str_contains(
        $tenantBSearch['body'],
        '/administration/users/view?id='
            . $tenantBUserId
    )
    && !str_contains(
        $tenantBSearch['body'],
        'tenant-b-user@example.test'
    ),
    'Tenant A directory does not reveal Tenant B user'
);

$foreignTenantPaths = [
    '/administration/users/view?id='
        . $tenantBUserId,
    '/administration/users/edit?id='
        . $tenantBUserId,
    '/administration/users/reset-password?id='
        . $tenantBUserId,
    '/administration/users/account-status?id='
        . $tenantBUserId,
    '/administration/users/unlock?id='
        . $tenantBUserId,
];

foreach ($foreignTenantPaths as $path) {
    $response = httpRequest(
        $baseUrl,
        $path,
        $tenantAAdminCookies
    );

    $check(
        $response['status'] === 404,
        'Tenant A receives 404 for Tenant B route: '
            . $path
    );
}

$tenantACreateForm = httpRequest(
    $baseUrl,
    '/administration/users/create',
    $tenantAAdminCookies
);
$tenantACsrf = csrfTokenFromBody(
    $tenantACreateForm['body']
);
$check(
    $tenantACreateForm['status'] === 200
    && $tenantACsrf !== '',
    'Tenant A state-changing test obtains a valid CSRF token'
);

$foreignHrMutations = [
    [
        'path' =>
            '/organization/departments/update',
        'form' => [
            '_token' => $tenantACsrf,
            'department_id' => '9202',
        ],
        'label' => 'organization department update',
    ],
    [
        'path' =>
            '/organization/job-titles/update',
        'form' => [
            '_token' => $tenantACsrf,
            'job_title_id' => '940002',
        ],
        'label' => 'job-title update',
    ],
    [
        'path' =>
            '/organization/positions/update',
        'form' => [
            '_token' => $tenantACsrf,
            'position_id' => '950002',
        ],
        'label' => 'position update',
    ],
    [
        'path' => '/organization/branches/update',
        'form' => [
            '_token' => $tenantACsrf,
            'branch_id' => '930002',
        ],
        'label' => 'branch update',
    ],
    [
        'path' => '/hr/employees/update',
        'form' => [
            '_token' => $tenantACsrf,
            'employee_id' => '920002',
        ],
        'label' => 'employee update',
    ],
    [
        'path' => '/hr/employees/position',
        'form' => [
            '_token' => $tenantACsrf,
            'employee_id' => '920002',
            'position_id' => '950001',
            'effective_from' => '2026-07-28',
        ],
        'label' => 'employee position assignment',
    ],
    [
        'path' => '/hr/departments/update',
        'form' => [
            '_token' => $tenantACsrf,
            'department_id' => '9202',
        ],
        'label' => 'department update',
    ],
];

foreach ($foreignHrMutations as $mutation) {
    $response = httpRequest(
        $baseUrl,
        $mutation['path'],
        $tenantAAdminCookies,
        'POST',
        $mutation['form']
    );

    $check(
        $response['status'] === 404
        && !str_contains(
            $response['body'],
            'Tenant B Confidential'
        ),
        'Tenant A cross-company '
            . $mutation['label']
            . ' is rejected with 404'
    );
}

$foreignTenantMutations = [
    [
        'path' => '/administration/users/update',
        'form' => [
            '_token' => $tenantACsrf,
            'user_id' => (string) $tenantBUserId,
            'username' => 'test_tenant_b_user',
            'email' => 'tenant-b-user@example.test',
            'display_name' => 'Test Tenant B User',
            'active' => '1',
        ],
        'label' => 'profile and role update',
    ],
    [
        'path' =>
            '/administration/users/reset-password',
        'form' => [
            '_token' => $tenantACsrf,
            'user_id' => (string) $tenantBUserId,
        ],
        'label' => 'password reset',
    ],
    [
        'path' =>
            '/administration/users/account-status',
        'form' => [
            '_token' => $tenantACsrf,
            'user_id' => (string) $tenantBUserId,
            'active' => '0',
        ],
        'label' => 'account status change',
    ],
    [
        'path' => '/administration/users/unlock',
        'form' => [
            '_token' => $tenantACsrf,
            'user_id' => (string) $tenantBUserId,
        ],
        'label' => 'account unlock',
    ],
];

foreach ($foreignTenantMutations as $mutation) {
    $response = httpRequest(
        $baseUrl,
        $mutation['path'],
        $tenantAAdminCookies,
        'POST',
        $mutation['form']
    );

    $check(
        $response['status'] === 404,
        'Tenant A cross-tenant '
            . $mutation['label']
            . ' is rejected'
    );
}

$tenantBUserCookies = [];
$tenantBUserLogin = login(
    $baseUrl,
    'test_tenant_b_user',
    $password,
    $tenantBUserCookies
);
$check(
    $tenantBUserLogin['status'] === 302
    && str_ends_with(
        $tenantBUserLogin['location'],
        '/dashboard'
    ),
    'Tenant B credentials remain valid after rejected attacks'
);

$tenantBUnlicensedAttendance = httpRequest(
    $baseUrl,
    '/attendance',
    $tenantBUserCookies
);
$check(
    $tenantBUnlicensedAttendance['status'] === 404
    && str_contains(
        $tenantBUnlicensedAttendance['body'],
        'Module unavailable'
    ),
    'Tenant B cannot open unlicensed Attendance directly'
);

$tenantBBranches = httpRequest(
    $baseUrl,
    '/organization/branches',
    $tenantBUserCookies
);
$check(
    $tenantBBranches['status'] === 200
    && str_contains(
        $tenantBBranches['body'],
        'Tenant B Confidential Branch'
    )
    && !str_contains(
        $tenantBBranches['body'],
        'Tenant A Headquarters'
    ),
    'Tenant B viewer sees only its own branch directory'
);

$tenantBBranchCreate = httpRequest(
    $baseUrl,
    '/organization/branches/create',
    $tenantBUserCookies
);
$check(
    $tenantBBranchCreate['status'] === 403,
    'Tenant branch viewer cannot open branch management'
);

$tenantBJobTitles = httpRequest(
    $baseUrl,
    '/organization/job-titles',
    $tenantBUserCookies
);
$check(
    $tenantBJobTitles['status'] === 200
    && str_contains(
        $tenantBJobTitles['body'],
        'Tenant B Confidential Manager'
    )
    && !str_contains(
        $tenantBJobTitles['body'],
        'Tenant A Security Analyst'
    ),
    'Tenant B viewer sees only its own job-title catalogue'
);

$tenantBJobTitleCreate = httpRequest(
    $baseUrl,
    '/organization/job-titles/create',
    $tenantBUserCookies
);
$check(
    $tenantBJobTitleCreate['status'] === 403,
    'Tenant job-title viewer cannot open management forms'
);

$tenantBDepartments = httpRequest(
    $baseUrl,
    '/organization/departments',
    $tenantBUserCookies
);
$check(
    $tenantBDepartments['status'] === 200
    && str_contains(
        $tenantBDepartments['body'],
        'Tenant B Confidential'
    )
    && !str_contains(
        $tenantBDepartments['body'],
        'Tenant A Security'
    ),
    'Tenant B viewer sees only its own department catalogue'
);

$tenantBDepartmentCreate = httpRequest(
    $baseUrl,
    '/organization/departments/create',
    $tenantBUserCookies
);
$check(
    $tenantBDepartmentCreate['status'] === 403,
    'Tenant department viewer cannot open management forms'
);

$tenantBPositions = httpRequest(
    $baseUrl,
    '/organization/positions',
    $tenantBUserCookies
);
$check(
    $tenantBPositions['status'] === 200
    && str_contains(
        $tenantBPositions['body'],
        'Tenant B Confidential Position'
    )
    && !str_contains(
        $tenantBPositions['body'],
        'Tenant A Security Analyst Position'
    ),
    'Tenant B viewer sees only its own position catalogue'
);

$tenantBPositionCreate = httpRequest(
    $baseUrl,
    '/organization/positions/create',
    $tenantBUserCookies
);
$check(
    $tenantBPositionCreate['status'] === 403,
    'Tenant position viewer cannot open management forms'
);

$tenantBEmployeePosition = httpRequest(
    $baseUrl,
    '/hr/employees/position?id=920002',
    $tenantBUserCookies
);
$check(
    $tenantBEmployeePosition['status'] === 404,
    'Tenant HR viewer cannot open employee-position management'
);

$tenantBOwnFinance = httpRequest(
    $baseUrl,
    '/finance?search=TB-EXP-CONFIDENTIAL',
    $tenantBUserCookies
);
$check(
    $tenantBOwnFinance['status'] === 200
    && str_contains(
        $tenantBOwnFinance['body'],
        'Tenant B Confidential Expense'
    ),
    'Tenant B can find its licensed Finance record'
);

$tenantBForeignFinance = httpRequest(
    $baseUrl,
    '/finance?search=TA-EXP-SEC',
    $tenantBUserCookies
);
$check(
    $tenantBForeignFinance['status'] === 200
    && !str_contains(
        $tenantBForeignFinance['body'],
        'Tenant A Security Expense'
    ),
    'Tenant B Finance search does not reveal Tenant A records'
);

$delegatedAdminCookies = [];
$delegatedAdminLogin = login(
    $baseUrl,
    'test_tenant_a_delegated_admin',
    $password,
    $delegatedAdminCookies
);
$check(
    $delegatedAdminLogin['status'] === 302
    && str_ends_with(
        $delegatedAdminLogin['location'],
        '/dashboard'
    ),
    'Delegated Tenant A administrator authenticates'
);

$delegatedCreateForm = httpRequest(
    $baseUrl,
    '/administration/users/create',
    $delegatedAdminCookies
);
$delegatedCsrf = csrfTokenFromBody(
    $delegatedCreateForm['body']
);
$check(
    $delegatedCreateForm['status'] === 200
    && $delegatedCsrf !== '',
    'Delegated administrator obtains a valid CSRF token'
);

$privilegedCreate = httpRequest(
    $baseUrl,
    '/administration/users',
    $delegatedAdminCookies,
    'POST',
    [
        '_token' => $delegatedCsrf,
        'username' =>
            'test_http_illegal_privileged_user',
        'email' =>
            'http-illegal-privileged@example.test',
        'display_name' =>
            'HTTP Illegal Privileged User',
        'active' => '1',
        'role_ids' => ['9101'],
    ]
);
$privilegedCreateError = httpRequest(
    $baseUrl,
    '/administration/users/create',
    $delegatedAdminCookies
);
$check(
    $privilegedCreate['status'] === 302
    && str_ends_with(
        $privilegedCreate['location'],
        '/administration/users/create'
    )
    && str_contains(
        $privilegedCreateError['body'],
        'cannot assign a role containing permissions'
    ),
    'HTTP user creation rejects a role above the actor authority'
);

$illegalUserSearch = httpRequest(
    $baseUrl,
    '/administration/users'
        . '?search=test_http_illegal_privileged_user',
    $delegatedAdminCookies
);
$check(
    $illegalUserSearch['status'] === 200
    && !str_contains(
        $illegalUserSearch['body'],
        'http-illegal-privileged@example.test'
    ),
    'Rejected HTTP escalation creates no user'
);

$tenantATargetUserId = 910004;
$privilegedAssignment = httpRequest(
    $baseUrl,
    '/administration/users/update',
    $delegatedAdminCookies,
    'POST',
    [
        '_token' => $delegatedCsrf,
        'user_id' => (string) $tenantATargetUserId,
        'username' => 'test_tenant_a_target',
        'email' => 'tenant-a-target@example.test',
        'display_name' => 'Test Tenant A Target',
        'active' => '1',
        'role_ids' => ['9101'],
    ]
);
$privilegedAssignmentError = httpRequest(
    $baseUrl,
    '/administration/users/edit?id='
        . $tenantATargetUserId,
    $delegatedAdminCookies
);
$check(
    $privilegedAssignment['status'] === 302
    && str_ends_with(
        $privilegedAssignment['location'],
        '/administration/users/edit?id='
            . $tenantATargetUserId
    )
    && str_contains(
        $privilegedAssignmentError['body'],
        'cannot assign a role containing permissions'
    ),
    'HTTP user update rejects a role above the actor authority'
);

$permissionEscalation = httpRequest(
    $baseUrl,
    '/administration/roles/update-permissions',
    $delegatedAdminCookies,
    'POST',
    [
        '_token' => $delegatedCsrf,
        'role_id' => '9102',
        'permission_ids' => ['9101'],
    ]
);
$permissionEscalationError = httpRequest(
    $baseUrl,
    '/administration/roles/edit-permissions?id=9102',
    $delegatedAdminCookies
);
$check(
    $permissionEscalation['status'] === 302
    && str_ends_with(
        $permissionEscalation['location'],
        '/administration/roles/edit-permissions?id=9102'
    )
    && str_contains(
        $permissionEscalationError['body'],
        'cannot grant or modify permissions'
    ),
    'HTTP role update rejects an unowned permission'
);

$targetAfterEscalation = httpRequest(
    $baseUrl,
    '/administration/users/view?id='
        . $tenantATargetUserId,
    $delegatedAdminCookies
);
$check(
    $targetAfterEscalation['status'] === 200
    && str_contains(
        $targetAfterEscalation['body'],
        'Employee Self Service'
    )
    && !str_contains(
        $targetAfterEscalation['body'],
        'Security Test Privileged Role'
    ),
    'Rejected HTTP escalation leaves target roles unchanged'
);

foreach ($results as $result) {
    echo $result['passed'] ? 'PASS ' : 'FAIL ';
    echo $result['description'];
    echo PHP_EOL;
}

echo PHP_EOL;
echo sprintf(
    '%d checks, %d failures',
    count($results),
    $failures
);
echo PHP_EOL;

exit($failures === 0 ? 0 : 1);
