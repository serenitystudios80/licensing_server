-- Migration 001: create `licenses` table
--
-- All tables use InnoDB, utf8mb4, and BIGINT UNSIGNED AUTO_INCREMENT primary keys unless noted.
-- Timestamps are DATETIME (UTC) except where a Unix-epoch integer is explicitly required by the
-- API contract.
--
-- Source: design.md, "Data Models" section.

CREATE TABLE licenses (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key              VARCHAR(29)     NOT NULL,   -- SERB-XXXXX-XXXXX-XXXXX-XXXXX
    email                    VARCHAR(255)    NOT NULL,
    customer_name            VARCHAR(255)    NOT NULL,
    product                  VARCHAR(100)    NOT NULL,   -- opaque; no code branches on specific values
    tier                     ENUM('annual','lifetime') NOT NULL,
    status                   ENUM('active','grace','expired','revoked') NOT NULL DEFAULT 'active',
    purchased_at             DATETIME        NOT NULL,
    expires_at               DATETIME        NULL,
    grace_start_at           DATETIME        NULL,        -- persisted grace-start anchor (Req 11)
    razorpay_subscription_id VARCHAR(64)     NULL,
    activation_limit         INT UNSIGNED    NOT NULL,
    price_amount             DECIMAL(10,2)   NULL,
    currency                 CHAR(3)         NULL,
    notes                    VARCHAR(2000)   NOT NULL DEFAULT '',
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_license_key (license_key),
    KEY idx_status_tier (status, tier),
    KEY idx_expires_at (expires_at),
    KEY idx_razorpay_sub (razorpay_subscription_id),
    CONSTRAINT chk_activation_limit_positive CHECK (activation_limit >= 1),
    CONSTRAINT chk_price_nonneg CHECK (price_amount IS NULL OR price_amount >= 0)
    -- lifetime => expires_at NULL is enforced in the application layer (LicenseRepository::create/updateFields)
    -- rather than a DB CHECK, since MariaDB CHECK constraints referencing two columns with ENUM
    -- comparisons are awkward to express portably; the invariant is covered by Correctness Property 1.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
