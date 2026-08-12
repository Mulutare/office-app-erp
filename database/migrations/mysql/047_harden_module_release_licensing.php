<?php

declare(strict_types=1);

return [
    'version' => '047',
    'description' => 'Separate module release, licensing, enablement and dependency gates',

    'preflight' => static function (\PDO $connection): string {
        $column = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name='erp_modules'
               AND column_name='release_status'"
        )->fetchColumn();

        $table = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name='erp_module_dependencies'"
        )->fetchColumn();

        if ($column === 0 && $table === 0) {
            return 'apply';
        }

        if ($column === 1 && $table === 1) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 047 found a partial module release schema.'
        );
    },

    'statements' => [
        <<<'SQL'
ALTER TABLE erp_modules
    ADD COLUMN release_status VARCHAR(20) NOT NULL DEFAULT 'roadmap' AFTER sort_order,
    ADD COLUMN first_release_version VARCHAR(60) NULL AFTER release_status,
    ADD COLUMN introduced_migration VARCHAR(10) NULL AFTER first_release_version,
    ADD CONSTRAINT ck_erp_module_release_status
        CHECK(release_status IN('roadmap','released')),
    ADD INDEX idx_erp_module_release(active,release_status,sort_order)
SQL,

        <<<'SQL'
UPDATE erp_modules
SET release_status = CASE
    WHEN available = TRUE THEN 'released'
    ELSE 'roadmap'
END
SQL,

        <<<'SQL'
UPDATE erp_modules
SET release_status='released',
    introduced_migration='046'
WHERE code='assets'
SQL,

        <<<'SQL'
CREATE TABLE erp_module_dependencies (
    module_dependency_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id INT UNSIGNED NOT NULL,
    required_module_id INT UNSIGNED NOT NULL,
    dependency_type VARCHAR(20) NOT NULL DEFAULT 'required',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_erp_module_dependency
        UNIQUE(module_id,required_module_id),

    CONSTRAINT ck_erp_module_dependency_type
        CHECK(dependency_type IN('required','optional')),

    CONSTRAINT ck_erp_module_dependency_self
        CHECK(module_id<>required_module_id),

    CONSTRAINT fk_erp_module_dependency_module
        FOREIGN KEY(module_id)
        REFERENCES erp_modules(module_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_erp_module_dependency_required
        FOREIGN KEY(required_module_id)
        REFERENCES erp_modules(module_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL,

        <<<'SQL'
INSERT INTO erp_module_dependencies(
    module_id,
    required_module_id,
    dependency_type
)
SELECT
    assets.module_id,
    finance.module_id,
    'required'
FROM erp_modules assets
CROSS JOIN erp_modules finance
WHERE assets.code='assets'
  AND finance.code='finance'
ON DUPLICATE KEY UPDATE
    dependency_type=VALUES(dependency_type)
SQL,

        <<<'SQL'
UPDATE company_modules cm
INNER JOIN erp_modules m
    ON m.module_id=cm.module_id
SET cm.enabled=FALSE
WHERE m.release_status<>'released'
SQL,
    ],
];