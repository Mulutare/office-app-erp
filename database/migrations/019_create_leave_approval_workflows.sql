SET NAMES utf8mb4;

ALTER TABLE hr_leave_types
    ADD COLUMN approval_workflow VARCHAR(30) NOT NULL
        DEFAULT 'manager'
        AFTER requires_approval,
    ADD COLUMN hr_approver_user_id BIGINT UNSIGNED NULL
        AFTER approval_workflow,
    ADD CONSTRAINT ck_leave_type_workflow
        CHECK (
            approval_workflow IN (
                'none',
                'manager',
                'hr',
                'manager_then_hr'
            )
        ),
    ADD CONSTRAINT fk_leave_type_hr_approver
        FOREIGN KEY (
            company_id,
            hr_approver_user_id
        )
        REFERENCES company_users (
            company_id,
            user_id
        )
        ON DELETE RESTRICT,
    ADD INDEX idx_leave_type_hr_approver (
        company_id,
        hr_approver_user_id,
        active
    );

UPDATE hr_leave_types
SET approval_workflow =
    CASE
        WHEN requires_approval = TRUE
            THEN 'manager'
        ELSE 'none'
    END;

ALTER TABLE hr_leave_requests
    ADD COLUMN approval_workflow VARCHAR(30) NOT NULL
        DEFAULT 'manager'
        AFTER request_status,
    ADD CONSTRAINT uq_leave_request_identity
        UNIQUE (company_id, leave_request_id),
    ADD CONSTRAINT ck_leave_request_workflow
        CHECK (
            approval_workflow IN (
                'none',
                'manager',
                'hr',
                'manager_then_hr'
            )
        );

UPDATE hr_leave_requests requests
INNER JOIN hr_leave_types types
    ON types.company_id = requests.company_id
   AND types.leave_type_id = requests.leave_type_id
SET requests.approval_workflow =
    types.approval_workflow;

CREATE TABLE hr_leave_request_approvals (
    approval_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    leave_request_id BIGINT UNSIGNED NOT NULL,
    approval_stage VARCHAR(20) NOT NULL,
    approval_sequence SMALLINT UNSIGNED NOT NULL,
    approver_user_id BIGINT UNSIGNED NOT NULL,
    approval_status VARCHAR(20) NOT NULL
        DEFAULT 'waiting',
    decision_note VARCHAR(500) NULL,
    decided_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_leave_approval_stage
        UNIQUE (
            company_id,
            leave_request_id,
            approval_stage
        ),
    CONSTRAINT uq_leave_approval_identity
        UNIQUE (company_id, approval_id),
    CONSTRAINT ck_leave_approval_stage
        CHECK (
            approval_stage IN ('manager', 'hr')
        ),
    CONSTRAINT ck_leave_approval_sequence
        CHECK (
            approval_sequence BETWEEN 1 AND 2
        ),
    CONSTRAINT ck_leave_approval_status
        CHECK (
            approval_status IN (
                'waiting',
                'pending',
                'approved',
                'rejected'
            )
        ),
    CONSTRAINT ck_leave_approval_decision
        CHECK (
            (
                approval_status IN ('waiting', 'pending')
                AND decided_at IS NULL
            )
            OR (
                approval_status IN ('approved', 'rejected')
                AND decided_at IS NOT NULL
            )
        ),
    CONSTRAINT fk_leave_approval_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_approval_request
        FOREIGN KEY (company_id, leave_request_id)
        REFERENCES hr_leave_requests(
            company_id,
            leave_request_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_approval_approver
        FOREIGN KEY (company_id, approver_user_id)
        REFERENCES company_users(company_id, user_id)
        ON DELETE RESTRICT,

    INDEX idx_leave_approval_inbox (
        company_id,
        approver_user_id,
        approval_status,
        approval_sequence
    ),
    INDEX idx_leave_approval_request (
        company_id,
        leave_request_id,
        approval_sequence
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_leave_request_approvals
    (
        company_id,
        leave_request_id,
        approval_stage,
        approval_sequence,
        approver_user_id,
        approval_status
    )
SELECT
    requests.company_id,
    requests.leave_request_id,
    'manager',
    1,
    manager_membership.user_id,
    'pending'
FROM hr_leave_requests requests
INNER JOIN hr_employees employees
    ON employees.company_id = requests.company_id
   AND employees.employee_id = requests.employee_id
INNER JOIN company_users employee_membership
    ON employee_membership.company_id =
        employees.company_id
   AND employee_membership.user_id =
        employees.user_id
   AND employee_membership.active = TRUE
INNER JOIN company_users manager_membership
    ON manager_membership.company_id =
        employee_membership.company_id
   AND manager_membership.user_id =
        employee_membership.manager_user_id
   AND manager_membership.active = TRUE
WHERE requests.request_status = 'pending'
  AND requests.approval_workflow = 'manager';
