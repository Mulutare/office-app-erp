<?php

declare(strict_types=1);
return [
    'version'=>'096',
    'description'=>'Company-user allow/deny overrides in the authoritative permission resolver',
    'statements'=>[
        <<<'SQL'
CREATE TABLE company_user_permission_overrides (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 permission_id INT UNSIGNED NOT NULL,
 allowed BOOLEAN NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,user_id,permission_id),
 CONSTRAINT fk_user_permission_membership FOREIGN KEY(company_id,user_id) REFERENCES company_users(company_id,user_id) ON DELETE CASCADE,
 CONSTRAINT fk_user_permission_definition FOREIGN KEY(permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE,
 CONSTRAINT fk_user_permission_actor FOREIGN KEY(updated_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];

