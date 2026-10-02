<?php
declare(strict_types=1);

// Additive, company-scoped recruitment register. Documents are never dropped by this migration.
$tables = [
'vacancies' => "reference VARCHAR(80) NOT NULL, title VARCHAR(190) NOT NULL, department VARCHAR(190) NULL, location VARCHAR(190) NULL, description TEXT NULL, opens_on DATE NULL, closes_on DATE NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', UNIQUE KEY uq_rec_vac_ref(company_id,reference)",
'applicants' => "name VARCHAR(190) NOT NULL, email_original VARCHAR(254) NULL, email_normalized VARCHAR(254) NULL, phone VARCHAR(80) NULL, contact_details TEXT NULL, identity_verified BOOLEAN NOT NULL DEFAULT FALSE, email_verified BOOLEAN NOT NULL DEFAULT FALSE, merged_into BIGINT UNSIGNED NULL, INDEX ix_rec_email(company_id,email_normalized), INDEX ix_rec_phone(company_id,phone), FOREIGN KEY(company_id,merged_into) REFERENCES recruitment_applicants(company_id,id)",
'mailboxes' => "host VARCHAR(254) NOT NULL, port INT NOT NULL DEFAULT 993, username VARCHAR(254) NOT NULL, secret TEXT NOT NULL, folder VARCHAR(254) NOT NULL DEFAULT 'INBOX', initial_date DATE NOT NULL, interval_minutes INT NOT NULL DEFAULT 5, enabled BOOLEAN NOT NULL DEFAULT FALSE, uidvalidity BIGINT UNSIGNED NULL, last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0, last_sync DATETIME NULL, last_error VARCHAR(254) NULL, preview_token CHAR(64) NULL, preview_at DATETIME NULL, preview_validity BIGINT UNSIGNED NULL, preview_max_uid BIGINT UNSIGNED NULL, preview_count INT NULL, retention_days INT NULL",
'applications' => "applicant_id BIGINT UNSIGNED NOT NULL, vacancy_id BIGINT UNSIGNED NULL, received_at DATETIME NOT NULL, source VARCHAR(40) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'New', reviewer_id BIGINT UNSIGNED NULL, notes TEXT NULL, needs_review BOOLEAN NOT NULL DEFAULT TRUE, deleted_at DATETIME NULL, INDEX ix_rec_list(company_id,status,received_at), FOREIGN KEY(company_id,applicant_id) REFERENCES recruitment_applicants(company_id,id), FOREIGN KEY(company_id,vacancy_id) REFERENCES recruitment_vacancies(company_id,id), FOREIGN KEY(company_id,reviewer_id) REFERENCES company_users(company_id,user_id)",
'emails' => "mailbox_id BIGINT UNSIGNED NOT NULL, provider_identity CHAR(64) NOT NULL, uidvalidity BIGINT UNSIGNED NOT NULL, uid BIGINT UNSIGNED NOT NULL, message_id VARCHAR(998) NULL, sender VARCHAR(998) NULL, recipients TEXT NULL, subject TEXT NULL, received_at DATETIME NULL, body_text MEDIUMTEXT NULL, raw_storage CHAR(64) NULL, raw_checksum CHAR(64) NULL, processing_status VARCHAR(30) NOT NULL DEFAULT 'pending', application_id BIGINT UNSIGNED NULL, error_code VARCHAR(80) NULL, UNIQUE KEY uq_rec_import(company_id,mailbox_id,provider_identity), INDEX ix_rec_retry(company_id,processing_status), FOREIGN KEY(company_id,mailbox_id) REFERENCES recruitment_mailboxes(company_id,id), FOREIGN KEY(company_id,application_id) REFERENCES recruitment_applications(company_id,id)",
'attachments' => "application_id BIGINT UNSIGNED NULL, email_id BIGINT UNSIGNED NULL, part_key VARCHAR(80) NULL, original_name VARCHAR(254) NOT NULL, storage_key CHAR(64) NOT NULL, size_bytes BIGINT UNSIGNED NOT NULL, detected_type VARCHAR(120) NOT NULL, checksum CHAR(64) NOT NULL, scan_status VARCHAR(30) NOT NULL DEFAULT 'quarantine', validation_status VARCHAR(30) NOT NULL DEFAULT 'accepted', UNIQUE KEY uq_rec_part(company_id,email_id,part_key), INDEX ix_rec_checksum(company_id,checksum), FOREIGN KEY(company_id,application_id) REFERENCES recruitment_applications(company_id,id), FOREIGN KEY(company_id,email_id) REFERENCES recruitment_emails(company_id,id)",
'history' => "application_id BIGINT UNSIGNED NOT NULL, from_status VARCHAR(30) NULL, to_status VARCHAR(30) NOT NULL, actor_id BIGINT UNSIGNED NULL, FOREIGN KEY(company_id,application_id) REFERENCES recruitment_applications(company_id,id), FOREIGN KEY(actor_id) REFERENCES users(user_id)",
'events' => "actor_id BIGINT UNSIGNED NULL, action VARCHAR(80) NOT NULL, record_id BIGINT UNSIGNED NULL, detail VARCHAR(500) NULL, FOREIGN KEY(actor_id) REFERENCES users(user_id), INDEX ix_rec_event(company_id,created_at)",
'runs' => "mailbox_id BIGINT UNSIGNED NOT NULL, status VARCHAR(30) NOT NULL, processed INT NOT NULL DEFAULT 0, failed INT NOT NULL DEFAULT 0, error_code VARCHAR(80) NULL, finished_at DATETIME NULL, FOREIGN KEY(company_id,mailbox_id) REFERENCES recruitment_mailboxes(company_id,id)"
];
$statements = [];
foreach ($tables as $name => $columns) {
    $statements[] = "CREATE TABLE IF NOT EXISTS recruitment_{$name} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, {$columns}, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_rec_{$name}_tenant(company_id,id), FOREIGN KEY(company_id) REFERENCES companies(company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
$statements[] = <<<'SQL'
INSERT INTO erp_modules
    (
        code,
        name,
        navigation_label,
        description,
        route_path,
        permission_namespace,
        icon_text,
        sort_order,
        available,
        active,
        release_status,
        first_release_version,
        introduced_migration
    )
VALUES
    (
        'recruitment',
        'Recruitment',
        'Recruitment',
        'Vacancies, applicants, applications, recruitment documents and controlled mailbox imports.',
        '/hr/recruitment',
        'recruitment',
        'RC',
        15,
        TRUE,
        TRUE,
        'released',
        NULL,
        '110'
    )
ON DUPLICATE KEY UPDATE
    name=VALUES(name),
    navigation_label=VALUES(navigation_label),
    description=VALUES(description),
    permission_namespace=VALUES(permission_namespace),
    icon_text=VALUES(icon_text),
    sort_order=VALUES(sort_order),
    available=TRUE,
    active=TRUE,
    release_status='released',
    introduced_migration='110'
SQL;

$statements[] = <<<'SQL'
INSERT INTO erp_module_dependencies
    (
        module_id,
        required_module_id,
        dependency_type
    )
SELECT
    recruitment.module_id,
    hr.module_id,
    'required'
FROM erp_modules recruitment
CROSS JOIN erp_modules hr
WHERE recruitment.code='recruitment'
  AND hr.code='hr'
ON DUPLICATE KEY UPDATE
    dependency_type=VALUES(dependency_type)
SQL;

$statements[] = <<<'SQL'
INSERT INTO permissions
    (name,code,module,description,active)
VALUES
    ('Enable Recruitment','recruitment.module.enabled','recruitment','Enable the Recruitment workspace; individual function grants and record scope still apply',TRUE),
    ('View Recruitment','recruitment.view','recruitment','View recruitment vacancies, applicants and applications',TRUE),
    ('Edit Recruitment','recruitment.edit','recruitment','Create and update recruitment records',TRUE),
    ('Export Recruitment','recruitment.export','recruitment','Export authorized recruitment records',TRUE),
    ('Hire Recruitment Candidate','recruitment.hire','recruitment','Approve deliberate recruitment hiring transitions',TRUE),
    ('Manage Recruitment Mailboxes','recruitment.mailboxes','recruitment','Configure and operate recruitment mailbox imports',TRUE),
    ('Merge Recruitment Applicants','recruitment.merge','recruitment','Merge reviewed duplicate recruitment applicants',TRUE),
    ('Archive Recruitment Applications','recruitment.delete','recruitment','Archive recruitment applications while preserving evidence',TRUE),
    ('Review Recruitment Quarantine','recruitment.quarantine','recruitment','Access explicitly acknowledged quarantined recruitment evidence',TRUE)
ON DUPLICATE KEY UPDATE
    name=VALUES(name),
    module=VALUES(module),
    description=VALUES(description),
    active=TRUE
SQL;

$statements[] = <<<'SQL'
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.role_id,p.permission_id
FROM roles r
CROSS JOIN permissions p
WHERE r.code IN ('company_owner','hr_administrator')
  AND r.active=TRUE
  AND p.active=TRUE
  AND p.module='recruitment'
SQL;

$statements[] = <<<'SQL'
INSERT IGNORE INTO company_role_permissions
    (company_id,role_id,permission_id,granted_by)
SELECT
    c.company_id,
    r.role_id,
    p.permission_id,
    c.owner_user_id
FROM companies c
CROSS JOIN roles r
CROSS JOIN permissions p
WHERE c.deleted_at IS NULL
  AND r.code IN ('company_owner','hr_administrator')
  AND r.active=TRUE
  AND p.active=TRUE
  AND p.module='recruitment'
SQL;

return [
    'version'=>'110',
    'description'=>'Licensed company-scoped recruitment and durable read-only email imports',
    'preflight'=>static function(\PDO $connection) use($tables): string {
        $names=array_map(fn($name)=>'recruitment_'.$name,array_keys($tables));
        $quoted=implode(',',array_map($connection->quote(...),$names));
        $count=(int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN($quoted)"
        )->fetchColumn();

        if($count===0) return 'apply';

        throw new \RuntimeException(
            'Migration 110 found existing recruitment objects without its ledger entry. Preserve recruitment data and private documents and reconcile the partial migration before retrying.'
        );
    },
    'statements'=>$statements,
];