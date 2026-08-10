<?php

declare(strict_types=1);

return [
    'version' => '045',
    'description' => 'Create tenant-safe data exchange identities and permissions',
    'preflight' => static function (PDO $connection): string {
        $table = (int) $connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='data_external_ids'")->fetchColumn();
        $permissions = (int) $connection->query("SELECT COUNT(*) FROM permissions WHERE code IN ('sales.import','sales.export','inventory.import','inventory.export','finance.import','finance.export')")->fetchColumn();
        if ($table === 1 && $permissions === 6) return 'baseline';
        return 'apply';
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS data_external_ids (
    external_id_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_data_external_ids_company FOREIGN KEY (company_id) REFERENCES companies(company_id),
    CONSTRAINT uq_data_external_ids_scope UNIQUE (company_id, entity_type, external_id),
    CONSTRAINT uq_data_external_ids_entity UNIQUE (company_id, entity_type, entity_id),
    INDEX idx_data_external_ids_entity (company_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('Import Sales Data','sales.import','sales','Import authorized Sales master data and draft documents',TRUE),
('Export Sales Data','sales.export','sales','Export authorized Sales data',TRUE),
('Import Inventory Data','inventory.import','inventory','Import authorized Inventory master data and draft operations',TRUE),
('Export Inventory Data','inventory.export','inventory','Export authorized Inventory data',TRUE),
('Import Finance Data','finance.import','finance','Import authorized Finance master data and draft documents',TRUE),
('Export Finance Data','finance.export','finance','Export authorized Finance data and reports',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module),description=VALUES(description),active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p
WHERE r.code IN ('company_owner','system_administrator') AND p.code IN ('inventory.view','sales.import','sales.export','inventory.import','inventory.export','finance.import','finance.export') AND p.active=TRUE
SQL,
        <<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT cm.company_id,rp.role_id,rp.permission_id,c.provisioned_by
FROM company_modules cm
INNER JOIN erp_modules m ON m.module_id=cm.module_id
INNER JOIN companies c ON c.company_id=cm.company_id AND c.deleted_at IS NULL
INNER JOIN permissions p ON p.module=m.code AND p.active=TRUE
INNER JOIN role_permissions rp ON rp.permission_id=p.permission_id
INNER JOIN roles r ON r.role_id=rp.role_id
WHERE cm.enabled=TRUE
  AND cm.license_status IN ('active','trial')
  AND (cm.expires_at IS NULL OR cm.expires_at>NOW())
  AND r.code IN ('company_owner','system_administrator')
  AND p.code IN ('inventory.view','sales.import','sales.export','inventory.import','inventory.export','finance.import','finance.export')
SQL,
    ],
];
