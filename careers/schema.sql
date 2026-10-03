-- Install in a NEW dedicated website database, never in the ERP database.
CREATE TABLE careers_vacancies (
 reference VARCHAR(190) PRIMARY KEY,
 revision BIGINT UNSIGNED NOT NULL,
 state VARCHAR(20) NOT NULL,
 payload MEDIUMTEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE careers_submissions (
 id CHAR(32) PRIMARY KEY,
 vacancy_reference VARCHAR(190) NOT NULL,
 payload MEDIUMTEXT NOT NULL,
 payload_checksum CHAR(64) NOT NULL,
 document_keys TEXT NOT NULL,
 acknowledged BOOLEAN NOT NULL DEFAULT FALSE,
 last_served_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX ix_careers_pending(acknowledged,last_served_at,created_at),
 FOREIGN KEY(vacancy_reference) REFERENCES careers_vacancies(reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE careers_nonces (nonce CHAR(64) PRIMARY KEY, expires BIGINT NOT NULL, INDEX ix_nonce_expiry(expires)) ENGINE=InnoDB;
CREATE TABLE careers_limits (bucket CHAR(64) PRIMARY KEY, attempts INT NOT NULL, expires BIGINT NOT NULL, INDEX ix_limit_expiry(expires)) ENGINE=InnoDB;
