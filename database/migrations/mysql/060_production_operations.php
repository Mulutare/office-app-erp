<?php
declare(strict_types=1);
return ['version'=>'060','description'=>'Add production task health and integration event operations permissions','statements'=>[
<<<'SQL'
CREATE TABLE operations_task_runs (
 task_run_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_name VARCHAR(80) NOT NULL,
 status VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL,
 processed_count INT UNSIGNED NOT NULL DEFAULT 0, failed_count INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(500) NULL, runner_id VARCHAR(120) NOT NULL,
 CONSTRAINT ck_operations_task_status CHECK(status IN('running','succeeded','failed','skipped')),
 INDEX idx_operations_task_health(task_name,started_at,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Integration Events','administration.integration_events.view','administration','View tenant integration outbox and worker health',TRUE),
('Retry Integration Events','administration.integration_events.retry','administration','Safely requeue failed tenant integration events',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
<<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.code IN('system_administrator','company_owner') AND p.code IN('administration.integration_events.view','administration.integration_events.retry') AND p.active=TRUE
SQL,
<<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.owner_user_id FROM companies c CROSS JOIN role_permissions rp INNER JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.code IN('administration.integration_events.view','administration.integration_events.retry') AND p.active=TRUE
SQL
]];
