<?php
declare(strict_types=1);
return ['version'=>'059','description'=>'Add configurable company maker-checker approval policies','statements'=>[
<<<'SQL'
CREATE TABLE company_approval_policies (
 approval_policy_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 action_type VARCHAR(80) NOT NULL, maker_checker_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 minimum_amount DECIMAL(18,2) NOT NULL DEFAULT 0, maximum_amount DECIMAL(18,2) NULL,
 required_permission VARCHAR(150) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_company_approval_policy UNIQUE(company_id,action_type,minimum_amount),
 CONSTRAINT ck_company_approval_policy_range CHECK(minimum_amount>=0 AND (maximum_amount IS NULL OR maximum_amount>=minimum_amount)),
 CONSTRAINT fk_company_approval_policy_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_company_approval_policy_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE SET NULL,
 CONSTRAINT fk_company_approval_policy_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
 INDEX idx_company_approval_policy_match(company_id,action_type,active,minimum_amount,maximum_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE company_approval_decisions (
 approval_decision_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 approval_policy_id BIGINT UNSIGNED NOT NULL, action_type VARCHAR(80) NOT NULL,
 subject_type VARCHAR(80) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL, amount DECIMAL(18,2) NOT NULL,
 maker_user_id BIGINT UNSIGNED NOT NULL, checker_user_id BIGINT UNSIGNED NOT NULL,
 decision VARCHAR(20) NOT NULL, reason VARCHAR(500) NULL, decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT uq_company_approval_decision UNIQUE(company_id,action_type,subject_type,subject_id,decision),
 CONSTRAINT ck_company_approval_decision CHECK(decision IN('approved','rejected')),
 CONSTRAINT ck_company_approval_separation CHECK(maker_user_id<>checker_user_id),
 CONSTRAINT fk_company_approval_decision_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_company_approval_decision_policy FOREIGN KEY(approval_policy_id) REFERENCES company_approval_policies(approval_policy_id),
 CONSTRAINT fk_company_approval_decision_maker FOREIGN KEY(maker_user_id) REFERENCES users(user_id),
 CONSTRAINT fk_company_approval_decision_checker FOREIGN KEY(checker_user_id) REFERENCES users(user_id),
 INDEX idx_company_approval_decision_subject(company_id,subject_type,subject_id,decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
INSERT INTO permissions(name,code,module,description,active) VALUES
('View Approval Policies','administration.approval_policies.view','administration','View company maker/checker thresholds',TRUE),
('Manage Approval Policies','administration.approval_policies.manage','administration','Configure company maker/checker thresholds',TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=TRUE
SQL,
<<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.code IN('system_administrator','company_owner') AND p.code IN('administration.approval_policies.view','administration.approval_policies.manage') AND p.active=TRUE
SQL,
<<<'SQL'
INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by)
SELECT c.company_id,rp.role_id,rp.permission_id,c.owner_user_id FROM companies c CROSS JOIN role_permissions rp INNER JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.code IN('administration.approval_policies.view','administration.approval_policies.manage') AND p.active=TRUE
SQL
]];
