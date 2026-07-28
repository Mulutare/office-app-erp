SET NAMES utf8mb4;

ALTER TABLE company_users
    ADD COLUMN manager_user_id BIGINT UNSIGNED NULL
        AFTER user_id,
    ADD CONSTRAINT fk_company_users_manager
        FOREIGN KEY (manager_user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD INDEX idx_company_users_manager (
        company_id,
        manager_user_id,
        active
    );
