ALTER TABLE companies
    ADD COLUMN contact_email VARCHAR(190) NULL
        AFTER legal_name,
    ADD COLUMN contact_phone VARCHAR(40) NULL
        AFTER contact_email,
    ADD COLUMN country_code CHAR(2) NOT NULL
        DEFAULT 'KE'
        AFTER contact_phone,
    ADD COLUMN subscription_status VARCHAR(30) NOT NULL
        DEFAULT 'active'
        AFTER timezone,
    ADD COLUMN subscription_expires_at DATETIME NULL
        AFTER subscription_status,
    ADD COLUMN brand_primary_color CHAR(7) NOT NULL
        DEFAULT '#2563EB'
        AFTER subscription_expires_at,
    ADD COLUMN provisioned_by BIGINT UNSIGNED NULL
        AFTER active,

    ADD CONSTRAINT fk_companies_provisioned_by
        FOREIGN KEY (provisioned_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    ADD INDEX idx_companies_subscription (
        subscription_status,
        subscription_expires_at,
        active,
        deleted_at
    );
