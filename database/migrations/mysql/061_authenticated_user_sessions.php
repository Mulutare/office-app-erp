<?php
declare(strict_types=1);
return ['version'=>'061','description'=>'Add tenant-scoped authenticated user session registry','statements'=>[
<<<'SQL'
CREATE TABLE authenticated_user_sessions (
 authenticated_user_session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 session_hash CHAR(64) NOT NULL,
 signed_in_at DATETIME NOT NULL,
 last_activity_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 ip_address VARCHAR(45) NOT NULL,
 user_agent VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT uq_authenticated_session_hash UNIQUE(session_hash),
 CONSTRAINT fk_authenticated_session_company FOREIGN KEY(company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
 CONSTRAINT fk_authenticated_session_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE,
 INDEX idx_authenticated_session_user(company_id,user_id),
 INDEX idx_authenticated_session_status(company_id,user_id,revoked_at),
 INDEX idx_authenticated_session_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
]];
