-- Migration 003: create `license_events` table
--
-- Source: design.md, "Data Models" section.

CREATE TABLE license_events (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id   BIGINT UNSIGNED NULL,   -- nullable only for webhook_unmatched (Req 7 AC7)
    event_type   VARCHAR(64)     NOT NULL,
    payload      JSON            NOT NULL,
    webhook_event_id VARCHAR(128) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.webhook_event_id'))) STORED,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_license_id_created (license_id, created_at),
    KEY idx_event_type (event_type),
    KEY idx_webhook_event_id (webhook_event_id),
    CONSTRAINT fk_event_license FOREIGN KEY (license_id) REFERENCES licenses(id)
    -- No UPDATE/DELETE is ever issued against this table by application code (Repository exposes
    -- only append()); this is the enforcement mechanism for the append-only requirement, since a
    -- MariaDB trigger to block UPDATE/DELETE would need SUPER privileges not guaranteed on shared VPS
    -- hosting -- application-level enforcement is deliberate here, not an oversight.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
