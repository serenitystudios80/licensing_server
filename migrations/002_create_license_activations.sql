-- Migration 002: create `license_activations` table
--
-- Source: design.md, "Data Models" section.

CREATE TABLE license_activations (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id        BIGINT UNSIGNED NOT NULL,
    site_url          VARCHAR(500)    NOT NULL,
    site_hash         CHAR(64)        NOT NULL,   -- lowercase hex SHA-256
    activated_at      DATETIME        NOT NULL,
    last_validated_at DATETIME        NULL,
    deactivated_at    DATETIME        NULL,
    KEY idx_license_site_hash (license_id, site_hash),
    KEY idx_license_id (license_id),
    CONSTRAINT fk_activation_license FOREIGN KEY (license_id) REFERENCES licenses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: no UNIQUE(license_id, site_hash) constraint, because a license may accumulate multiple
-- *deactivated* rows for the same site_hash over time (deactivate then reactivate is modeled as
-- updating the existing row per Req 4 AC10, but historical rows from earlier schema states or
-- manual admin corrections must remain representable). Uniqueness of "non-deactivated" rows per
-- (license_id, site_hash) is an application-level invariant (Correctness Property: Activation dedup),
-- enforced by ActivationRepository::findActiveByHash() + an application-level lock (see design.md)
-- rather than a partial unique index (MariaDB has no native partial/filtered unique index).
