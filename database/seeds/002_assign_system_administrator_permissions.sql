INSERT INTO role_permissions
    (
        role_id,
        permission_id
    )
SELECT
    r.role_id,
    p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'system_administrator'
  AND r.active = TRUE
  AND p.active = TRUE
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.role_id
        AND rp.permission_id = p.permission_id
  );