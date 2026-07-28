<?php

declare(strict_types=1);

$quote = static function (string $value): string {
    return '\''
        . str_replace('\'', '\'\'', $value)
        . '\'';
};

$statements = [
    <<<'SQL'
INSERT INTO companies (
    code,
    name,
    default_currency,
    timezone,
    subscription_status,
    approval_status,
    approved_at,
    active
) VALUES (
    'default',
    'Default Company',
    'KES',
    'Africa/Nairobi',
    'active',
    'approved',
    SYSTIMESTAMP,
    1
)
SQL,
];

$roles = [
    [
        'System Administrator',
        'system_administrator',
        'Full OfficeApp administration access',
    ],
    [
        'Executive Viewer',
        'executive_viewer',
        'Read-only management dashboard access',
    ],
    [
        'HR Administrator',
        'hr_administrator',
        'Manage authorized HR operations',
    ],
    [
        'IT Administrator',
        'it_administrator',
        'Manage IT assets, incidents and systems',
    ],
    [
        'Finance Officer',
        'finance_officer',
        'Create and manage authorized finance records',
    ],
    [
        'Finance Approver',
        'finance_approver',
        'Approve authorized financial requests',
    ],
    [
        'Business Development Officer',
        'business_development_officer',
        'Manage customers, leads and opportunities',
    ],
    [
        'Auditor',
        'auditor',
        'Read-only audit and compliance access',
    ],
    [
        'Company Owner',
        'company_owner',
        'Owns company users, access and licensed ERP operations',
    ],
];

foreach ($roles as [$name, $code, $description]) {
    $statements[] = sprintf(
        'INSERT INTO roles '
        . '(name, code, description, is_system, active) '
        . 'VALUES (%s, %s, %s, 1, 1)',
        $quote($name),
        $quote($code),
        $quote($description)
    );
}

$permissions = [
    [
        'View Dashboard',
        'dashboard.view',
        'dashboard',
        'View the application dashboard',
    ],
    [
        'Manage Users',
        'administration.users.manage',
        'administration',
        'Create, update and disable users',
    ],
    [
        'Manage Roles',
        'administration.roles.manage',
        'administration',
        'Manage roles and permissions',
    ],
    [
        'View Audit Logs',
        'audit.logs.view',
        'audit',
        'View system audit records',
    ],
    [
        'View HR Records',
        'hr.records.view',
        'hr',
        'View authorized HR records',
    ],
    [
        'Manage HR Records',
        'hr.records.manage',
        'hr',
        'Create and update authorized HR records',
    ],
    [
        'View IT Records',
        'it.records.view',
        'it',
        'View IT assets and incidents',
    ],
    [
        'Manage IT Records',
        'it.records.manage',
        'it',
        'Create and update IT assets and incidents',
    ],
    [
        'View Finance Records',
        'finance.records.view',
        'finance',
        'View authorized finance records',
    ],
    [
        'Manage Finance Records',
        'finance.records.manage',
        'finance',
        'Create and update authorized finance records',
    ],
    [
        'Approve Finance Requests',
        'finance.requests.approve',
        'finance',
        'Approve authorized finance requests',
    ],
    [
        'View Business Records',
        'business.records.view',
        'business',
        'View customers, leads and opportunities',
    ],
    [
        'Manage Business Records',
        'business.records.manage',
        'business',
        'Create and update customers, leads and opportunities',
    ],
    [
        'Manage Company Modules',
        'administration.modules.manage',
        'administration',
        'Enable or disable licensed ERP modules for the company',
    ],
    [
        'Manage Customer Companies',
        'administration.companies.manage',
        'administration',
        'Provision customer companies and module subscriptions',
    ],
];

foreach (
    $permissions
    as [$name, $code, $module, $description]
) {
    $statements[] = sprintf(
        'INSERT INTO permissions '
        . '(name, code, module, description, active) '
        . 'VALUES (%s, %s, %s, %s, 1)',
        $quote($name),
        $quote($code),
        $quote($module),
        $quote($description)
    );
}

$modules = [
    [
        'hr',
        'Human Resources',
        'Human Resources',
        'Employee records, departments and workforce administration.',
        '/hr',
        'hr',
        'HR',
        10,
        1,
    ],
    [
        'finance',
        'Finance',
        'Finance',
        'Expense requests, approvals and financial operations.',
        '/finance',
        'finance',
        'FI',
        20,
        1,
    ],
    [
        'procurement',
        'Procurement',
        'Procurement',
        'Suppliers, purchase requests and purchasing workflows.',
        '/procurement',
        'procurement',
        'PR',
        30,
        0,
    ],
    [
        'inventory',
        'Inventory',
        'Inventory',
        'Stock, warehouses, transfers and inventory controls.',
        '/inventory',
        'inventory',
        'IN',
        40,
        0,
    ],
    [
        'sales',
        'Sales',
        'Sales',
        'Quotes, orders, invoicing and sales operations.',
        '/sales',
        'sales',
        'SA',
        50,
        0,
    ],
    [
        'crm',
        'Customer Relationship Management',
        'CRM',
        'Customers, leads, opportunities and relationship management.',
        '/crm',
        'crm',
        'CR',
        60,
        0,
    ],
    [
        'projects',
        'Projects',
        'Projects',
        'Projects, tasks, milestones, teams and delivery tracking.',
        '/projects',
        'projects',
        'PJ',
        70,
        0,
    ],
    [
        'help_desk',
        'Help Desk',
        'Help Desk',
        'Service requests, incidents, queues and support operations.',
        '/help-desk',
        'help_desk',
        'HD',
        80,
        0,
    ],
    [
        'it_assets',
        'IT Assets',
        'IT Assets',
        'Technology assets, assignment, lifecycle and maintenance.',
        '/it-assets',
        'it',
        'IT',
        90,
        0,
    ],
    [
        'payroll',
        'Payroll',
        'Payroll',
        'Payroll periods, earnings, deductions and payslips.',
        '/payroll',
        'payroll',
        'PY',
        100,
        0,
    ],
    [
        'attendance',
        'Attendance',
        'Attendance',
        'Time, attendance, shifts and leave integration.',
        '/attendance',
        'attendance',
        'AT',
        110,
        0,
    ],
    [
        'documents',
        'Documents',
        'Documents',
        'Company files, records, access and document workflows.',
        '/documents',
        'documents',
        'DC',
        120,
        0,
    ],
];

foreach (
    $modules
    as [
        $code,
        $name,
        $navigationLabel,
        $description,
        $routePath,
        $permissionNamespace,
        $iconText,
        $sortOrder,
        $available,
    ]
) {
    $statements[] = sprintf(
        'INSERT INTO erp_modules ('
        . 'code, name, navigation_label, description, '
        . 'route_path, permission_namespace, icon_text, '
        . 'sort_order, available, active'
        . ') VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %d, 1)',
        $quote($code),
        $quote($name),
        $quote($navigationLabel),
        $quote($description),
        $quote($routePath),
        $quote($permissionNamespace),
        $quote($iconText),
        $sortOrder,
        $available
    );
}

$standardRolePermissions = [
    'executive_viewer' => [
        'dashboard.view',
        'hr.records.view',
        'it.records.view',
        'finance.records.view',
        'business.records.view',
    ],
    'hr_administrator' => [
        'dashboard.view',
        'hr.records.view',
        'hr.records.manage',
    ],
    'it_administrator' => [
        'dashboard.view',
        'it.records.view',
        'it.records.manage',
    ],
    'finance_officer' => [
        'dashboard.view',
        'finance.records.view',
        'finance.records.manage',
    ],
    'finance_approver' => [
        'dashboard.view',
        'finance.records.view',
        'finance.requests.approve',
    ],
    'business_development_officer' => [
        'dashboard.view',
        'business.records.view',
        'business.records.manage',
    ],
    'auditor' => [
        'dashboard.view',
        'audit.logs.view',
        'hr.records.view',
        'it.records.view',
        'finance.records.view',
        'business.records.view',
    ],
];

foreach (
    $standardRolePermissions
    as $roleCode => $permissionCodes
) {
    foreach ($permissionCodes as $permissionCode) {
        $statements[] = sprintf(
            'INSERT INTO role_permissions '
            . '(role_id, permission_id) '
            . 'SELECT roles.role_id, permissions.permission_id '
            . 'FROM roles CROSS JOIN permissions '
            . 'WHERE roles.code = %s '
            . 'AND permissions.code = %s',
            $quote($roleCode),
            $quote($permissionCode)
        );
    }
}

$statements[] = <<<'SQL'
INSERT INTO role_permissions (
    role_id,
    permission_id
)
SELECT
    roles.role_id,
    permissions.permission_id
FROM roles
CROSS JOIN permissions
WHERE roles.code = 'system_administrator'
SQL;

$statements[] = <<<'SQL'
INSERT INTO role_permissions (
    role_id,
    permission_id
)
SELECT
    roles.role_id,
    permissions.permission_id
FROM roles
CROSS JOIN permissions
WHERE roles.code = 'company_owner'
  AND permissions.code NOT IN (
      'administration.companies.manage',
      'administration.modules.manage'
  )
SQL;

$statements[] = <<<'SQL'
INSERT INTO company_modules (
    company_id,
    module_id,
    enabled,
    license_status,
    licensed_at
)
SELECT
    companies.company_id,
    erp_modules.module_id,
    erp_modules.available,
    CASE
        WHEN erp_modules.available = 1
            THEN 'active'
        ELSE 'not_licensed'
    END,
    CASE
        WHEN erp_modules.available = 1
            THEN SYSTIMESTAMP
        ELSE NULL
    END
FROM companies
CROSS JOIN erp_modules
WHERE companies.code = 'default'
SQL;

$statements[] = <<<'SQL'
INSERT INTO company_role_permissions (
    company_id,
    role_id,
    permission_id,
    granted_by
)
SELECT
    companies.company_id,
    role_permissions.role_id,
    role_permissions.permission_id,
    NULL
FROM companies
CROSS JOIN role_permissions
WHERE companies.code = 'default'
SQL;

return [
    'version' => '060',
    'description' =>
        'Seed stable roles, permissions and module catalog',
    'statements' => $statements,
];
