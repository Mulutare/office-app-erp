-- Standard Fixed Assets role templates.
-- These roles become selectable in User Administration but are NOT assigned
-- to any user automatically.
--
-- Existing company role customizations are preserved. We seed only these new
-- role templates for companies where the Assets module is enabled.

INSERT INTO roles
    (name, code, description, is_system)
VALUES
    (
        'Fixed Asset Viewer',
        'fixed_asset_viewer',
        'View the fixed asset register and asset reports',
        TRUE
    ),
    (
        'Fixed Asset Officer',
        'fixed_asset_officer',
        'Operate the fixed asset register, capitalization, custody, transfers and maintenance',
        TRUE
    ),
    (
        'Fixed Asset Manager',
        'fixed_asset_manager',
        'Full fixed asset operational control including depreciation posting and disposal',
        TRUE
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system);

-- Global role templates. Exact permission codes are intentional:
-- future Assets permissions are not silently granted.

INSERT IGNORE INTO role_permissions
    (role_id, permission_id)
SELECT
    r.role_id,
    p.permission_id
FROM roles r
INNER JOIN permissions p
    ON p.active = TRUE
   AND p.module = 'assets'
WHERE
       (
           r.code = 'fixed_asset_viewer'
           AND p.code IN (
               'assets.view',
               'assets.reports.view'
           )
       )
    OR (
           r.code = 'fixed_asset_officer'
           AND p.code IN (
               'assets.view',
               'assets.manage',
               'assets.activate',
               'assets.inventory.capitalize',
               'assets.transfers.manage',
               'assets.maintenance.manage',
               'assets.custody.manage',
               'assets.reports.view'
           )
       )
    OR (
           r.code = 'fixed_asset_manager'
           AND p.code IN (
               'assets.view',
               'assets.manage',
               'assets.activate',
               'assets.inventory.capitalize',
               'assets.transfers.manage',
               'assets.maintenance.manage',
               'assets.custody.manage',
               'assets.depreciation.post',
               'assets.dispose',
               'assets.reports.view'
           )
       );

-- Make the new role templates operational for existing companies whose
-- Assets module is currently enabled/licensed.
-- This grants permissions TO THE ROLE only; it does not assign the role
-- to any user.

INSERT IGNORE INTO company_role_permissions
    (company_id, role_id, permission_id, granted_by)
SELECT
    c.company_id,
    r.role_id,
    p.permission_id,
    c.provisioned_by
FROM companies c
INNER JOIN company_modules cm
    ON cm.company_id = c.company_id
INNER JOIN erp_modules m
    ON m.module_id = cm.module_id
   AND m.code = 'assets'
CROSS JOIN roles r
INNER JOIN permissions p
    ON p.active = TRUE
   AND p.module = 'assets'
WHERE
    c.deleted_at IS NULL
    AND cm.enabled = TRUE
    AND cm.license_status IN ('active', 'trial')
    AND (cm.expires_at IS NULL OR cm.expires_at > NOW())
    AND (
           (
               r.code = 'fixed_asset_viewer'
               AND p.code IN (
                   'assets.view',
                   'assets.reports.view'
               )
           )
        OR (
               r.code = 'fixed_asset_officer'
               AND p.code IN (
                   'assets.view',
                   'assets.manage',
                   'assets.activate',
                   'assets.inventory.capitalize',
                   'assets.transfers.manage',
                   'assets.maintenance.manage',
                   'assets.custody.manage',
                   'assets.reports.view'
               )
           )
        OR (
               r.code = 'fixed_asset_manager'
               AND p.code IN (
                   'assets.view',
                   'assets.manage',
                   'assets.activate',
                   'assets.inventory.capitalize',
                   'assets.transfers.manage',
                   'assets.maintenance.manage',
                   'assets.custody.manage',
                   'assets.depreciation.post',
                   'assets.dispose',
                   'assets.reports.view'
               )
           )
    );
