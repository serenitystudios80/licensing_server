-- Migration 006: create `rate_limit_store` table
--
-- Source: design.md, "Data Models" section.

CREATE TABLE rate_limit_store (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope      ENUM('ip','license_key') NOT NULL,
    scope_value VARCHAR(255) NOT NULL,
    endpoint   VARCHAR(32)  NOT NULL,     -- 'activate' | 'validate' | 'deactivate'
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_scope_value_time (scope, scope_value, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
