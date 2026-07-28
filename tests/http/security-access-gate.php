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
    && !str_contains(
        $tenantADashboard['body'],
        'href="/office_app/public/finance"'
    ),
    'Tenant navigation shows HR but hides unlicensed Finance'
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

$foreignHrPaths = [
    '/hr/employees/view?id=920002',
    '/hr/employees/activity?id=920002',
    '/hr/employees/edit?id=920002',
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
        'Executive Viewer'
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
