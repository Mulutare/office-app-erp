INSERT INTO roles
    (name, code, description, is_system)
VALUES
    (
        'System Administrator',
        'system_administrator',
        'Full OfficeApp administration access',
        TRUE
    ),
    (
        'Executive Viewer',
        'executive_viewer',
        'Read-only management dashboard access',
        TRUE
    ),
    (
        'HR Administrator',
        'hr_administrator',
        'Manage authorized HR operations',
        TRUE
    ),
    (
        'IT Administrator',
        'it_administrator',
        'Manage IT assets, incidents and systems',
        TRUE
    ),
    (
        'Finance Officer',
        'finance_officer',
        'Create and manage authorized finance records',
        TRUE
    ),
    (
        'Finance Approver',
        'finance_approver',
        'Approve authorized financial requests',
        TRUE
    ),
    (
        'Business Development Officer',
        'business_development_officer',
        'Manage customers, leads and opportunities',
        TRUE
    ),
    (
        'Auditor',
        'auditor',
        'Read-only audit and compliance access',
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    active = TRUE;


INSERT INTO permissions
    (name, code, module, description)
VALUES
    (
        'View Dashboard',
        'dashboard.view',
        'dashboard',
        'View the application dashboard'
    ),
    (
        'Manage Users',
        'administration.users.manage',
        'administration',
        'Create, update and disable users'
    ),
    (
        'Manage Roles',
        'administration.roles.manage',
        'administration',
        'Manage roles and permissions'
    ),
    (
        'View Audit Logs',
        'audit.logs.view',
        'audit',
        'View system audit records'
    ),
    (
        'View HR Records',
        'hr.records.view',
        'hr',
        'View authorized HR records'
    ),
    (
        'Manage HR Records',
        'hr.records.manage',
        'hr',
        'Create and update authorized HR records'
    ),
    (
        'View IT Records',
        'it.records.view',
        'it',
        'View IT assets and incidents'
    ),
    (
        'Manage IT Records',
        'it.records.manage',
        'it',
        'Create and update IT assets and incidents'
    ),
    (
        'View Finance Records',
        'finance.records.view',
        'finance',
        'View authorized finance records'
    ),
    (
        'Manage Finance Records',
        'finance.records.manage',
        'finance',
        'Create and update authorized finance records'
    ),
    (
        'Approve Finance Requests',
        'finance.requests.approve',
        'finance',
        'Approve authorized finance requests'
    ),
    (
        'View Business Records',
        'business.records.view',
        'business',
        'View customers, leads and opportunities'
    ),
    (
        'Manage Business Records',
        'business.records.manage',
        'business',
        'Create and update customers, leads and opportunities'
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    module = VALUES(module),
    description = VALUES(description),
    active = TRUE;
