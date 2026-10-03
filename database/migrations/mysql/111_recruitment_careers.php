<?php

declare(strict_types=1);

/*
 * Recruitment public-careers foundation.
 *
 * Migration 110 remains immutable.
 *
 * This migration adds:
 * - public vacancy publication metadata
 * - vacancy-specific structured screening criteria
 * - structured applicant answers
 * - explainable screening results
 * - durable external website submission identities
 * - ERP <-> careers publication state
 *
 * Screening results are decision-support records. They do not themselves
 * perform a final employment rejection.
 */

$statements = [];

/*
 * Public careers metadata belongs to the ERP vacancy.
 *
 * The public website receives only deliberately published vacancy data.
 */
$statements[] = <<<'SQL'
ALTER TABLE recruitment_vacancies
    ADD COLUMN public_slug VARCHAR(190) NULL AFTER status,
    ADD COLUMN public_status VARCHAR(30) NOT NULL DEFAULT 'private' AFTER public_slug,
    ADD COLUMN published_at DATETIME NULL AFTER public_status,
    ADD COLUMN public_revision BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER published_at,
    ADD UNIQUE KEY uq_rec_vac_public_slug(company_id, public_slug),
    ADD INDEX ix_rec_vac_public(company_id, public_status, closes_on)
SQL;

/*
 * Each vacancy can define its own structured questions/rules.
 *
 * criterion_type examples:
 *   experience_years
 *   education_level
 *   field_of_study
 *   boolean
 *   single_choice
 *   multiple_choice
 *   text
 *   location
 *   document_required
 *
 * operator examples:
 *   eq
 *   gte
 *   lte
 *   in
 *   contains
 *
 * decision_mode:
 *   minimum  -> explicit failure means minimum_not_met
 *   review   -> always requires human review
 *   preference -> informational only
 */
$statements[] = <<<'SQL'
CREATE TABLE recruitment_vacancy_criteria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    vacancy_id BIGINT UNSIGNED NOT NULL,

    code VARCHAR(80) NOT NULL,
    label VARCHAR(254) NOT NULL,
    help_text TEXT NULL,

    criterion_type VARCHAR(40) NOT NULL,
    operator VARCHAR(30) NOT NULL,
    expected_value TEXT NULL,
    options_json TEXT NULL,

    decision_mode VARCHAR(30) NOT NULL DEFAULT 'review',
    required BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_rec_criterion_tenant(company_id,id),
    UNIQUE KEY uq_rec_criterion_code(company_id,vacancy_id,code),
    INDEX ix_rec_criterion_vacancy(company_id,vacancy_id,active,sort_order),

    FOREIGN KEY(company_id)
        REFERENCES companies(company_id),

    FOREIGN KEY(company_id,vacancy_id)
        REFERENCES recruitment_vacancies(company_id,id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL;

/*
 * Answers captured by the public application form.
 *
 * raw_value preserves exactly what the applicant submitted.
 * normalized_value contains the validated representation used by rules.
 */
$statements[] = <<<'SQL'
CREATE TABLE recruitment_application_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,

    question_snapshot TEXT NOT NULL,
    raw_value TEXT NULL,
    normalized_value TEXT NULL,

    evaluation VARCHAR(30) NOT NULL DEFAULT 'pending',
    evaluation_reason VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_rec_answer_tenant(company_id,id),
    UNIQUE KEY uq_rec_answer(
        company_id,
        application_id,
        criterion_id
    ),
    INDEX ix_rec_answer_application(company_id,application_id),

    FOREIGN KEY(company_id)
        REFERENCES companies(company_id),

    FOREIGN KEY(company_id,application_id)
        REFERENCES recruitment_applications(company_id,id),

    FOREIGN KEY(company_id,criterion_id)
        REFERENCES recruitment_vacancy_criteria(company_id,id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL;

/*
 * Explainable screening summary.
 *
 * outcome is deliberately not "rejected".
 *
 * Expected outcomes:
 *   pending
 *   minimum_met
 *   minimum_not_met
 *   needs_review
 *
 * Final application status remains under the existing Recruitment workflow.
 */
$statements[] = <<<'SQL'
CREATE TABLE recruitment_screening_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,

    outcome VARCHAR(30) NOT NULL DEFAULT 'pending',
    minimum_pass_count INT NOT NULL DEFAULT 0,
    minimum_fail_count INT NOT NULL DEFAULT 0,
    review_count INT NOT NULL DEFAULT 0,
    preference_count INT NOT NULL DEFAULT 0,

    explanation TEXT NULL,
    evaluated_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_rec_screen_tenant(company_id,id),
    UNIQUE KEY uq_rec_screen_application(company_id,application_id),
    INDEX ix_rec_screen_outcome(company_id,outcome,evaluated_at),

    FOREIGN KEY(company_id)
        REFERENCES companies(company_id),

    FOREIGN KEY(company_id,application_id)
        REFERENCES recruitment_applications(company_id,id),

    FOREIGN KEY(company_id,reviewed_by)
        REFERENCES company_users(company_id,user_id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL;

/*
 * Durable identity for applications pulled from passiontechnologiesplc.com.
 *
 * source_submission_id is generated by the public website and provides
 * idempotency: pulling the same submission twice cannot create another
 * Recruitment application.
 *
 * No ERP credentials are stored here.
 */
$statements[] = <<<'SQL'
CREATE TABLE recruitment_external_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,

    source VARCHAR(40) NOT NULL DEFAULT 'careers_website',
    source_submission_id VARCHAR(100) NOT NULL,
    source_vacancy_reference VARCHAR(190) NOT NULL,

    application_id BIGINT UNSIGNED NULL,

    submitted_at DATETIME NOT NULL,
    imported_at DATETIME NULL,

    import_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    payload_checksum CHAR(64) NOT NULL,
    last_error VARCHAR(254) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_rec_external_tenant(company_id,id),
    UNIQUE KEY uq_rec_external_source(
        company_id,
        source,
        source_submission_id
    ),
    INDEX ix_rec_external_pending(
        company_id,
        import_status,
        submitted_at
    ),

    FOREIGN KEY(company_id)
        REFERENCES companies(company_id),

    FOREIGN KEY(company_id,application_id)
        REFERENCES recruitment_applications(company_id,id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL;

/*
 * Tracks what vacancy revision the website has received.
 *
 * This lets ERP publish/update/close a vacancy without exposing ERP itself
 * to the public Internet.
 */
$statements[] = <<<'SQL'
CREATE TABLE recruitment_publication_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    vacancy_id BIGINT UNSIGNED NOT NULL,

    target VARCHAR(40) NOT NULL DEFAULT 'careers_website',
    desired_state VARCHAR(30) NOT NULL DEFAULT 'private',
    desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,

    published_revision BIGINT UNSIGNED NULL,
    last_attempt_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error VARCHAR(254) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_rec_publication_tenant(company_id,id),
    UNIQUE KEY uq_rec_publication_target(
        company_id,
        vacancy_id,
        target
    ),
    INDEX ix_rec_publication_pending(
        company_id,
        desired_state,
        desired_revision,
        published_revision
    ),

    FOREIGN KEY(company_id)
        REFERENCES companies(company_id),

    FOREIGN KEY(company_id,vacancy_id)
        REFERENCES recruitment_vacancies(company_id,id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL;

$statements[] = <<<'SQL'
CREATE TABLE recruitment_publication_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 vacancy_id BIGINT UNSIGNED NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 payload MEDIUMTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_rec_revision(company_id,vacancy_id,revision),
 UNIQUE KEY uq_rec_revision_tenant(company_id,id),
 FOREIGN KEY(company_id,vacancy_id) REFERENCES recruitment_vacancies(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
$statements[] = "INSERT INTO permissions(name,code,module,description,active) VALUES ('Publish Careers vacancies','recruitment.publish','recruitment','Approve external publication of recruitment vacancies',TRUE)";
$statements[] = "INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('company_owner','hr_administrator') AND r.active=1 AND p.code='recruitment.publish'";
$statements[] = "INSERT IGNORE INTO company_role_permissions(company_id,role_id,permission_id,granted_by) SELECT c.company_id,r.role_id,p.permission_id,c.owner_user_id FROM companies c CROSS JOIN roles r CROSS JOIN permissions p WHERE c.deleted_at IS NULL AND r.code IN ('company_owner','hr_administrator') AND r.active=1 AND p.code='recruitment.publish'";
$statements[] = "ALTER TABLE recruitment_applications ADD INDEX ix_rec_attention(company_id,deleted_at,needs_review,received_at), ADD INDEX ix_rec_source(company_id,source,received_at)";

return [
    'version' => '111',

    'description' =>
        'Recruitment public careers, structured screening and secure external submission foundation',

    'preflight' => static function (\PDO $connection): string {
        $expectedTables = [
            'recruitment_vacancy_criteria',
            'recruitment_application_answers',
            'recruitment_screening_results',
            'recruitment_external_submissions',
            'recruitment_publication_state',
            'recruitment_publication_revisions',
        ];

        $quoted = implode(
            ',',
            array_map(
                $connection->quote(...),
                $expectedTables
            )
        );

        $tableCount = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN($quoted)"
        )->fetchColumn();

        $columnCount = (int) $connection->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name='recruitment_vacancies'
               AND column_name IN(
                    'public_slug',
                    'public_status',
                    'published_at',
                    'public_revision'
               )"
        )->fetchColumn();

        if ($tableCount === 0 && $columnCount === 0) {
            return 'apply';
        }

        throw new \RuntimeException(
            'Migration 111 found partially existing Recruitment careers objects. '
            . 'Preserve recruitment data and reconcile the partial migration before retrying.'
        );
    },

    'statements' => $statements,
];