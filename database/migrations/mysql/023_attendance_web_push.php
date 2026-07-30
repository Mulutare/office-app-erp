<?php

declare(strict_types=1);

return [
    'version' => '023',
    'description' =>
        'Create tenant-scoped attendance Web Push subscriptions and delivery outbox',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $tables = [
            'attendance_push_subscriptions',
            'attendance_push_deliveries',
        ];
        $placeholders = implode(
            ', ',
            array_fill(0, count($tables), '?')
        );
        $statement = $connection->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('
                . $placeholders . ')'
        );
        $statement->execute($tables);
        $count = (int) $statement->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }

        if ($count === count($tables)) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 023 found a partial attendance Web Push schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE attendance_push_subscriptions (
    subscription_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint VARCHAR(2048) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth_secret VARCHAR(255) NOT NULL,
    content_encoding VARCHAR(32) NOT NULL
        DEFAULT 'aes128gcm',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    disabled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_attendance_push_endpoint
        UNIQUE (endpoint_hash),
    CONSTRAINT ck_attendance_push_encoding
        CHECK (
            content_encoding IN (
                'aes128gcm',
                'aesgcm'
            )
        ),
    CONSTRAINT fk_attendance_push_user
        FOREIGN KEY (company_id, user_id)
        REFERENCES company_users(company_id, user_id)
        ON DELETE CASCADE,
    INDEX idx_attendance_push_owner (
        company_id,
        user_id,
        active
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE attendance_push_deliveries (
    notification_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    delivery_status VARCHAR(20) NOT NULL
        DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_status_code SMALLINT UNSIGNED NULL,
    failure_reason VARCHAR(500) NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, subscription_id),
    CONSTRAINT ck_attendance_push_delivery_status
        CHECK (
            delivery_status IN (
                'pending',
                'delivered',
                'retry',
                'failed'
            )
        ),
    CONSTRAINT fk_attendance_push_delivery_notice
        FOREIGN KEY (notification_id)
        REFERENCES attendance_notifications(notification_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_push_delivery_subscription
        FOREIGN KEY (subscription_id)
        REFERENCES attendance_push_subscriptions(subscription_id)
        ON DELETE CASCADE,
    INDEX idx_attendance_push_delivery_queue (
        delivery_status,
        next_attempt_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
