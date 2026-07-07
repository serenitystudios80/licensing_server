-- Migration 005: create `admin_login_attempts` table
--
-- Source: design.md, "Data Models" section.

CREATE TABLE admin_login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(64) NOT NULL,
    succeeded    TINYINT(1)  NOT NULL,
    attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_username_time (username, attempted_at)
    -- Supports the 5-failures/15-minutes lockout (Req 13 AC9) via a sliding-window COUNT query,
    -- the same pattern as rate_limit_store; kept as a separate table (not reusing rate_limit_store)
    -- because its scope key (username) and semantics (success vs. failure) differ.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
