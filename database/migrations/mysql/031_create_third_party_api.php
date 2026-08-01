<?php

declare(strict_types=1);

return [
    'version' => '031',
    'description' => 'Create secure third-party API and webhook delivery foundation',
    'preflight' => static function (\PDO $connection): string {
        $tables = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (
                'api_clients','api_client_scopes','api_access_tokens',
                'api_idempotency_keys','api_request_logs',
                'api_webhook_subscriptions','api_webhook_deliveries'
             )"
        )->fetchColumn();
        $column = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'sales_orders'
               AND column_name = 'external_reference'"
        )->fetchColumn();
        if ($tables === 0 && $column === 0) {
            return 'apply';
        }
        if ($tables === 7 && $column === 1) {
            return 'baseline';
        }
        throw new \RuntimeException('Migration 031 found a partial third-party API schema.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_orders
    ADD COLUMN external_reference VARCHAR(120) NULL AFTER order_number,
    ADD CONSTRAINT uq_sales_order_external_ref UNIQUE (company_id, external_reference)
SQL,
        <<<'SQL'
CREATE TABLE api_clients (
    api_client_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    service_user_id BIGINT UNSIGNED NOT NULL,
    client_identifier CHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    secret_prefix VARCHAR(12) NOT NULL,
    ip_allowlist_json JSON NULL,
    token_ttl_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
    rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 60,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    secret_rotated_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    CONSTRAINT uq_api_client_identifier UNIQUE (client_identifier),
    CONSTRAINT ck_api_client_ttl CHECK (token_ttl_seconds BETWEEN 300 AND 86400),
    CONSTRAINT ck_api_client_rate CHECK (rate_limit_per_minute BETWEEN 1 AND 10000),
    CONSTRAINT fk_api_client_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_api_client_user FOREIGN KEY (service_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_api_client_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_api_client_company (company_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE api_client_scopes (
    api_client_id BIGINT UNSIGNED NOT NULL,
    scope_code VARCHAR(80) NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    granted_at DATETIME NOT NULL,
    PRIMARY KEY (api_client_id, scope_code),
    CONSTRAINT fk_api_scope_client FOREIGN KEY (api_client_id) REFERENCES api_clients(api_client_id) ON DELETE CASCADE,
    CONSTRAINT fk_api_scope_grantor FOREIGN KEY (granted_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE api_access_tokens (
    api_token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_client_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefix VARCHAR(12) NOT NULL,
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    CONSTRAINT uq_api_token_hash UNIQUE (token_hash),
    CONSTRAINT fk_api_token_client FOREIGN KEY (api_client_id) REFERENCES api_clients(api_client_id) ON DELETE CASCADE,
    INDEX idx_api_token_expiry (token_hash, expires_at, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE api_idempotency_keys (
    api_client_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'processing',
    response_status SMALLINT UNSIGNED NULL,
    response_json JSON NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (api_client_id, idempotency_key),
    CONSTRAINT ck_api_idempotency_status CHECK (status IN ('processing','completed')),
    CONSTRAINT fk_api_idempotency_client FOREIGN KEY (api_client_id) REFERENCES api_clients(api_client_id) ON DELETE CASCADE,
    INDEX idx_api_idempotency_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE api_request_logs (
    api_request_log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correlation_id CHAR(36) NOT NULL,
    api_client_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    method VARCHAR(10) NOT NULL,
    route VARCHAR(180) NOT NULL,
    response_status SMALLINT UNSIGNED NOT NULL,
    remote_ip VARCHAR(45) NULL,
    duration_ms INT UNSIGNED NOT NULL,
    error_code VARCHAR(60) NULL,
    requested_at DATETIME NOT NULL,
    CONSTRAINT uq_api_request_correlation UNIQUE (correlation_id),
    CONSTRAINT fk_api_log_client FOREIGN KEY (api_client_id) REFERENCES api_clients(api_client_id) ON DELETE SET NULL,
    CONSTRAINT fk_api_log_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE SET NULL,
    INDEX idx_api_log_company_time (company_id, requested_at),
    INDEX idx_api_log_client_time (api_client_id, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE api_webhook_subscriptions (
    webhook_subscription_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    api_client_id BIGINT UNSIGNED NOT NULL,
    endpoint_url VARCHAR(500) NOT NULL,
    events_json JSON NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    secret_ciphertext TEXT NOT NULL,
    secret_prefix VARCHAR(12) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    secret_rotated_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_webhook_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    CONSTRAINT fk_webhook_client FOREIGN KEY (api_client_id) REFERENCES api_clients(api_client_id) ON DELETE CASCADE,
    CONSTRAINT fk_webhook_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_webhook_company_active (company_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE api_webhook_deliveries (
    delivery_id CHAR(36) PRIMARY KEY,
    webhook_subscription_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    dead_lettered_at DATETIME NULL,
    response_status SMALLINT UNSIGNED NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT uq_webhook_event_subscription UNIQUE (webhook_subscription_id, event_id),
    CONSTRAINT ck_webhook_delivery_status CHECK (status IN ('pending','processing','delivered','failed','dead_letter')),
    CONSTRAINT fk_webhook_delivery_subscription FOREIGN KEY (webhook_subscription_id) REFERENCES api_webhook_subscriptions(webhook_subscription_id) ON DELETE CASCADE,
    INDEX idx_webhook_delivery_pending (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
