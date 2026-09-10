<?php
declare(strict_types=1);

return [
    'version' => '083',
    'description' => 'In-app user notifications and rejected document correction history',
    'statements' => [
        <<<'SQL'
CREATE TABLE user_notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    reference_type VARCHAR(80) NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    action_url VARCHAR(500) NOT NULL,
    event_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_notification_member FOREIGN KEY (company_id,user_id) REFERENCES company_users(company_id,user_id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_notification_event (company_id,user_id,event_key),
    KEY ix_user_notification_unread (company_id,user_id,read_at,created_at),
    KEY ix_user_notification_recent (company_id,user_id,created_at,notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE purchase_requisition_status_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    requisition_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(24) NOT NULL,
    to_status VARCHAR(24) NOT NULL,
    action VARCHAR(40) NOT NULL,
    reason TEXT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_requisition_history (company_id,requisition_id,history_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
