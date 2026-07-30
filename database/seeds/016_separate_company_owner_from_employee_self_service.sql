UPDATE roles
SET
    name = 'Company Owner',
    description =
        'Administers company users, security, configuration and licensed ERP operations',
    is_system = TRUE,
    active = TRUE
WHERE code = 'company_owner';

DELETE company_grants
FROM company_role_permissions company_grants
INNER JOIN roles
    ON roles.role_id = company_grants.role_id
INNER JOIN permissions
    ON permissions.permission_id =
        company_grants.permission_id
WHERE roles.code = 'company_owner'
  AND permissions.code IN (
      'hr.leave.self.view',
      'hr.leave.self.request',
      'attendance.self.view',
      'attendance.self.record'
  );

DELETE template_grants
FROM role_permissions template_grants
INNER JOIN roles
    ON roles.role_id = template_grants.role_id
INNER JOIN permissions
    ON permissions.permission_id =
        template_grants.permission_id
WHERE roles.code = 'company_owner'
  AND permissions.code IN (
      'hr.leave.self.view',
      'hr.leave.self.request',
      'attendance.self.view',
      'attendance.self.record'
  );
