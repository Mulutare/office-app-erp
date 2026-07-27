SET NAMES utf8mb4;

-- MariaDB may rebuild the users table while migration 008 adds the
-- platform-administrator column. On affected installations, inbound
-- foreign keys can remain linked to the previous internal table object.
-- Recreate every users reference so authentication and auditing use the
-- current users table.

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE audit_logs
    DROP FOREIGN KEY fk_audit_logs_user;

ALTER TABLE audit_logs
    ADD CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE companies
    DROP FOREIGN KEY fk_companies_approved_by,
    DROP FOREIGN KEY fk_companies_owner_user,
    DROP FOREIGN KEY fk_companies_provisioned_by;

ALTER TABLE companies
    ADD CONSTRAINT fk_companies_approved_by
        FOREIGN KEY (approved_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_companies_owner_user
        FOREIGN KEY (owner_user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_companies_provisioned_by
        FOREIGN KEY (provisioned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE company_modules
    DROP FOREIGN KEY fk_company_modules_updated_by;

ALTER TABLE company_modules
    ADD CONSTRAINT fk_company_modules_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE company_role_permissions
    DROP FOREIGN KEY fk_company_role_permissions_granted_by;

ALTER TABLE company_role_permissions
    ADD CONSTRAINT fk_company_role_permissions_granted_by
        FOREIGN KEY (granted_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE company_users
    DROP FOREIGN KEY fk_company_users_assigned_by,
    DROP FOREIGN KEY fk_company_users_user;

ALTER TABLE company_users
    ADD CONSTRAINT fk_company_users_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_company_users_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE;

ALTER TABLE company_user_roles
    DROP FOREIGN KEY fk_company_user_roles_assigned_by;

ALTER TABLE company_user_roles
    ADD CONSTRAINT fk_company_user_roles_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE finance_expense_categories
    DROP FOREIGN KEY fk_finance_categories_created_by,
    DROP FOREIGN KEY fk_finance_categories_updated_by;

ALTER TABLE finance_expense_categories
    ADD CONSTRAINT fk_finance_categories_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_finance_categories_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE finance_expense_requests
    DROP FOREIGN KEY fk_finance_expense_created_by,
    DROP FOREIGN KEY fk_finance_expense_reviewer,
    DROP FOREIGN KEY fk_finance_expense_updated_by;

ALTER TABLE finance_expense_requests
    ADD CONSTRAINT fk_finance_expense_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_finance_expense_reviewer
        FOREIGN KEY (reviewed_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_finance_expense_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE hr_departments
    DROP FOREIGN KEY fk_hr_departments_created_by,
    DROP FOREIGN KEY fk_hr_departments_updated_by;

ALTER TABLE hr_departments
    ADD CONSTRAINT fk_hr_departments_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_hr_departments_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE hr_employees
    DROP FOREIGN KEY fk_hr_employees_created_by,
    DROP FOREIGN KEY fk_hr_employees_updated_by;

ALTER TABLE hr_employees
    ADD CONSTRAINT fk_hr_employees_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_hr_employees_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE login_attempts
    DROP FOREIGN KEY fk_login_attempts_user;

ALTER TABLE login_attempts
    ADD CONSTRAINT fk_login_attempts_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL;

ALTER TABLE user_roles
    DROP FOREIGN KEY fk_user_roles_assigned_by,
    DROP FOREIGN KEY fk_user_roles_user;

ALTER TABLE user_roles
    ADD CONSTRAINT fk_user_roles_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_user_roles_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
