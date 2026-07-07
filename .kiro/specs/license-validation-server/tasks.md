# Implementation Plan: License Validation Server

## Overview

This plan builds the framework-less PHP 8.2 / PHP-FPM / MariaDB license server incrementally: project scaffolding and config first, then the database schema, then the shared domain layer (repositories and the pure `StatusCalculator`), then the security/rate-limiting middleware, then the signed JSON API endpoints, then the Sweep_Job cron, then the admin panel, finishing with the cross-cutting invariant that ties lifetime-license handling together across every code path. Property-based tests (using Eris, PHPUnit-integrated, minimum 100 iterations) are added as sub-tasks immediately after the component that makes each correctness property meaningful to test, per the design's 36 Correctness Properties. Unit/integration tests cover everything the design's Testing Strategy classifies as not suited to PBT (config smoke checks, cron wiring, page rendering, CSV headers, etc.).

**Cross-cutting error-handling policy (applies to every task below that produces an error path — API handlers, the Sweep_Job, and admin controllers):** no generic "Something went wrong"/"Error" messages anywhere. Server-side logs (via `Support\Logger`/`Support\ErrorContext`) must capture what failed, why, and where (class/method/file/line + relevant input values), with secrets always redacted. API responses (`Http\ErrorResponder`) must be specific and dynamic (built from actual request/state values) while never leaking internals (no SQL, file paths, stack traces, or secret values) to the calling WordPress site. Admin panel error messages may show full diagnostic detail since the admin is the sole trusted operator. See design.md's "Error message and logging specificity policy" section for the full rationale and the two-tier detail model.

## Tasks

- [ ] 1. Set up project scaffolding and environment configuration
  - Create the directory structure from the design's File/module structure section: `/config`, `/public`, `/public/admin`, `/src/Config`, `/src/Http`, `/src/Security`, `/src/RateLimit`, `/src/Domain`, `/src/Repository`, `/src/Api`, `/src/Cron`, `/src/Admin`, `/src/Audit`, `/src/Support`, `/templates`, `/migrations`, `/tests/Unit`, `/tests/Property`
  - Create `composer.json` with PSR-4 autoloading (`src/` → root namespace), `require-dev` entries for `phpunit/phpunit` and `giorgiosironi/eris`
  - _Requirements: 21.1_

  - [x] 1.1 Implement `Config\Config` and `Config\ConfigException`
    - `src/Config/Config.php`: `Config::load(): Config` reads `.env` (tiny parser, no dependency) merged with real env vars, validates every required key (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `RAZORPAY_KEY_ID`, `RAZORPAY_WEBHOOK_SECRET`, `HMAC_SHARED_SECRET`, `TRUSTED_PROXY_RANGES`, `RATE_LIMIT_IP_MAX`, `RATE_LIMIT_IP_WINDOW_SECONDS`, `RATE_LIMIT_KEY_MAX`, `RATE_LIMIT_KEY_WINDOW_SECONDS`, `SESSION_SECRET`) is present and non-empty
    - `src/Config/ConfigException.php`: thrown when a required key is missing or empty, carrying only the missing key name (never other values)
    - _Requirements: 21.1, 21.4_

  - [x]* 1.2 Write unit tests for `Config`
    - Test: all required keys present → loads successfully
    - Test: each individual required key missing/empty → throws `ConfigException` naming only that key
    - Test: exception message never includes other configuration values
    - _Requirements: 21.1, 21.4_

  - [x] 1.3 Create `.env.example` and `.gitignore`
    - `.env.example` with placeholder values for every key enumerated in Requirement 21 AC1
    - `.gitignore` rule preventing `.env` from being committed
    - _Requirements: 21.2, 21.3_

- [ ] 2. Create database schema and migrations
  - [x] 2.1 Write migration SQL for all six tables
    - `migrations/001_create_licenses.sql`, `002_create_license_activations.sql`, `003_create_license_events.sql`, `004_create_admin_users.sql`, `005_create_admin_login_attempts.sql`, `006_create_rate_limit_store.sql`, using the exact DDL from the design's Data Models section (InnoDB, utf8mb4, constraints, indexes, the `license_events.webhook_event_id` generated column)
    - _Requirements: 1.1, 1.3, 1.8, 1.10, 2.1_

  - [x] 2.2 Write a small migration runner script
    - `migrations/run.php`: applies `.sql` files in filename order against the configured MariaDB connection, tracking applied migrations in a `schema_migrations` table
    - _Requirements: 21.1_

  - [x]* 2.3 Write a schema smoke test
    - Applies all migrations against a disposable/in-memory test database and asserts all six tables and their key constraints (unique `license_key`, FK constraints, `chk_activation_limit_positive`, `chk_price_nonneg`) exist as expected
    - _Requirements: 1.1, 1.3, 1.8, 1.10_

- [ ] 3. Implement shared support utilities
  - [x] 3.1 Implement `Support\Clock`, `Support\Logger`, and `Support\ErrorContext`
    - `src/Support/Clock.php`: injectable "now" (`now(): int` epoch seconds), with a `FixedClock`/settable test implementation
    - `src/Support/Logger.php`: file/`error_log` wrapper with a key-name-pattern secret-redaction rule (HMAC shared secret, Razorpay keys/webhook secret, DB password, session secret, password hashes are never written in any form, regardless of what a caller passes); every call site wrapped so a logging failure never propagates
    - `src/Support/ErrorContext.php`: `ErrorContext::describe(Throwable $e, array $context = []): string` builds a detailed, consistently formatted log string — `"{class}::{method} failed at {file}:{line}: {message} (context: {redacted-context})"` — passing `$context` through the same redaction rule as `Logger`, so every logged failure across the codebase (API handlers, Sweep_Job, admin controllers) identifies what failed, why, and exactly where, per the error-handling policy in the plan overview and design.md
    - _Requirements: 21.4, 22.4_

  - [x]* 3.2 Write unit tests for `Clock`, `Logger`, and `ErrorContext`
    - Test: `Clock` returns injected fixed time deterministically
    - Test: `Logger` swallows internal write failures without throwing
    - Test: `Logger`/`ErrorContext` redact known secret key names (e.g. `hmac_shared_secret`, `db_pass`, `password_hash`) from logged context even when a caller passes them in by mistake
    - Test: `ErrorContext::describe()` output includes the exception class, message, file, and line, and renders passed-in context values (non-secret) for diagnosability
    - _Requirements: 21.4, 22.4_

- [ ] 4. Implement domain models and repository layer
  - [x] 4.1 Implement domain value objects
    - `src/Domain/License.php`, `src/Domain/Activation.php`, `src/Domain/LicenseEvent.php`: plain value objects / row hydration, `src/Domain/LicenseKeyGenerator.php` producing keys matching `SERB-XXXXX-XXXXX-XXXXX-XXXXX`
    - _Requirements: 1.1, 1.3, 1.8_

  - [ ] 4.2 Implement `Repository\Db` and `Repository\LicenseRepository`
    - `src/Repository/Db.php`: thin PDO factory/wrapper (prepared statements only)
    - `src/Repository/LicenseRepository.php`: `findByKey`, `findById`, `create`, `updateFields` (targeted field-list update only), enforcing at creation/update time that tier `lifetime` implies `expires_at = NULL`, plus admin query helpers (`search`, `filter`, `countBy...`, `expiringWithin`) built from an allow-list of filter keys
    - _Requirements: 1.1, 1.2, 1.9, 1.10_

  - [ ]* 4.3 Write property test for License_key uniqueness
    - **Property 2: License_key uniqueness**
    - **Validates: Requirements 1.10**

  - [ ] 4.4 Implement `Repository\ActivationRepository`
    - `src/Repository/ActivationRepository.php`: `findActiveByHash`, `findAnyByHash` (including deactivated), `create`, `reactivate`, `deactivate`, `countActiveForLicense`
    - _Requirements: 1.3, 1.4, 1.5, 1.6_

  - [ ] 4.5 Implement `Repository\LicenseEventRepository`
    - `src/Repository/LicenseEventRepository.php`: exposes only `append(licenseId, eventType, payload)` — no update/delete method exists on the class, structurally enforcing append-only behavior
    - _Requirements: 1.7, 1.8, 22.3_

  - [ ]* 4.6 Write property test for License_Events append-only and immutable
    - **Property 3: License_Events append-only and immutable**
    - **Validates: Requirements 1.7, 22.3**

  - [ ] 4.7 Implement `Repository\AdminUserRepository`
    - `src/Repository/AdminUserRepository.php`: `findByUsername`, `create`
    - _Requirements: 13.4_

- [ ] 5. Implement the shared `StatusCalculator` pure function
  - [ ] 5.1 Implement `Domain\StatusCalculator` and `Domain\StatusComputation`
    - `src/Domain/StatusComputation.php`: readonly `status`, `changed`, `graceStartTimestamp`
    - `src/Domain/StatusCalculator.php`: `compute(License $license, int $now): StatusComputation` implementing the active→grace (past `expires_at`) and grace→expired (≥259,200s since grace-start) logic exactly per the design, assuming callers pre-filter out `lifetime`/`revoked` licenses
    - _Requirements: 5.5, 5.6, 5.7, 10.2, 10.3, 11.1, 11.3, 11.4, 12.2, 12.3_

  - [ ]* 5.2 Write property test for Lazy_Check / Sweep_Job status-computation equivalence
    - **Property 10: Lazy_Check / Sweep_Job status-computation equivalence**
    - **Validates: Requirements 5.5, 5.6, 5.7, 10.2, 10.3, 11.3, 11.4, 12.2, 12.3**

  - [ ]* 5.3 Write property test for Sweep_Job/Lazy_Check exclusion of lifetime and revoked licenses
    - **Property 12: Sweep_Job/Lazy_Check exclusion of lifetime and revoked licenses**
    - **Validates: Requirements 12.5, 12.6**

  - [ ] 5.4 Implement the shared persistence rule for status transitions
    - A small helper (e.g. `Domain\StatusTransitionApplier`) used by both the Lazy_Check and the Sweep_Job: applies `StatusComputation` to a `License` row via `LicenseRepository::updateFields()` using an optimistic `WHERE status = 'active'` guard when setting grace-start, and appends the correct event type (`silent_lapse_grace`, or no extra event for grace→expired)
    - _Requirements: 11.1, 11.2, 11.4_

  - [ ]* 5.5 Write property test for grace-start timestamp anchoring
    - **Property 11: Grace-start timestamp anchoring**
    - **Validates: Requirements 10.1, 10.4, 11.1, 11.2**

- [ ] 6. Implement HTTP infrastructure and uniform error handling
  - [ ] 6.1 Implement `Http\Request`, `Http\Response`, `Http\Router`
    - `src/Http/Request.php`: wraps method, headers, raw body, parsed JSON
    - `src/Http/Response.php`: status code, headers, body
    - `src/Http/Router.php`: `match()`-based dispatch for `/activate`, `/validate`, `/deactivate`, `/webhook/razorpay`
    - _Requirements: 20.2, 20.3_

  - [ ] 6.2 Implement `Http\JsonBodyGuard` and `Http\ErrorResponder`
    - `src/Http/JsonBodyGuard.php`: stages 1-3 (HTTP method == POST, route defined, body ≤64KB and valid JSON)
    - `src/Http/ErrorResponder.php`: single `build(string $code, string $message, int $httpStatus): Response` used by every stage/handler, producing the uniform `{error_code, message}` shape
    - _Requirements: 20.1, 20.2, 20.3, 20.4, 20.9_

  - [ ]* 6.3 Write property test for structured error shape consistency
    - **Property 26: Structured error shape consistency**
    - **Validates: Requirements 20.4**

  - [ ]* 6.4 Write unit tests for `JsonBodyGuard`
    - Test: non-POST method → 405; undefined route → 404; oversized body → 413 without JSON parsing; malformed JSON → 400 `malformed_body`
    - _Requirements: 20.1, 20.2, 20.3, 20.9_

- [ ] 7. Implement HMAC authentication and client IP resolution
  - [ ] 7.1 Implement `Security\HmacAuthenticator`
    - `src/Security/HmacAuthenticator.php`: `verify(Request $r, string $secret, Clock $clock): Result` implementing the fixed 3-step order (field presence/format → timestamp ±300s → `hash_equals()` signature check over `"{timestamp}.{rawBody}"`), never logging/returning the secret
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7_

  - [ ]* 7.2 Write property test for HMAC signature and timestamp acceptance
    - **Property 15: HMAC signature and timestamp acceptance**
    - **Validates: Requirements 3.1, 3.2, 3.3**

  - [ ] 7.3 Implement `Security\ClientIpResolver` and `Security\TrustedProxyRanges`
    - `src/Security/TrustedProxyRanges.php`: `fromConfig(string $csv): self`, empty/unparseable input yields an empty range set
    - `src/Security/ClientIpResolver.php`: `resolve(array $serverVars, TrustedProxyRanges $ranges): string` implementing the Requirement 8 decision tree, including invalid-IP-header fallback to `REMOTE_ADDR`
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7_

  - [ ]* 7.4 Write property test for client IP resolution decision tree
    - **Property 20: Client IP resolution decision tree**
    - **Validates: Requirements 8.1, 8.2, 8.3, 8.6, 8.7**

- [ ] 8. Implement rate limiting
  - [ ] 8.1 Implement `RateLimit\RateLimitRepository`
    - `src/RateLimit/RateLimitRepository.php`: `record()` (catches its own PDO exceptions, logs, never throws), `countSince()` (throws `RateLimitStoreException` on read failure), `cleanup(maxWindowSeconds, now)` (deletes rows older than the boundary)
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [ ] 8.2 Implement `RateLimit\RateLimiter`
    - `src/RateLimit/RateLimiter.php`: `check(string $ip, ?string $licenseKey, int $now): RateLimitDecision` evaluating per-IP and per-license-key checks independently, catching a store exception on one scope as "not exceeded" for that scope while still applying the other scope's successfully-evaluated result
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.8_

  - [ ]* 8.3 Write property test for rate limiting fail-open behavior
    - **Property 17: Rate limiting fails open on store errors, still enforces when data is available**
    - **Validates: Requirements 2.3, 9.5, 9.8**

  - [ ]* 8.4 Write property test for sliding-window threshold enforcement
    - **Property 18: Sliding-window threshold enforcement**
    - **Validates: Requirements 9.1, 9.2, 9.3, 9.4**

  - [ ]* 8.5 Write property test for rate-limit cleanup boundary
    - **Property 19: Rate-limit cleanup boundary**
    - **Validates: Requirements 2.4**

- [ ] 9. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 10. Wire the API front controller pipeline
  - [ ] 10.1 Implement `public/index.php` as the ordered pipeline
    - Wires stages 1-8 in the fixed order (method → route → size/JSON → rate limit → HMAC → required fields → field format → business rules) as an ordered array of pipeline steps, catching `ConfigException` at the top level and returning a 500 structured error with only the missing key name logged
    - _Requirements: 3.7, 9.7, 20.8, 21.4_

  - [ ]* 10.2 Write property test for global fixed validation-ordering
    - **Property 16: Global fixed validation-ordering**
    - **Validates: Requirements 3.7, 4.11, 9.7, 20.8**

- [ ] 11. Implement the `/activate` endpoint
  - [ ] 11.1 Implement `Api\FieldNames` and `Api\ActivateHandler`
    - `src/Api/FieldNames.php`: provisional field name constants for all endpoints
    - `src/Api/ActivateHandler.php`: implements stages 6-8 for `/activate` — required-field check, `license_key` format check, unknown-license check, revoked/expired status check, activation-limit check, dedup/reactivation logic, in the fixed sub-order from Requirement 4 AC11
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.6, 4.7, 4.8, 4.9, 4.10, 4.11_

  - [ ]* 11.2 Write property test for activation dedup, mismatch handling, and reactivation
    - **Property 4: Activation dedup, mismatch handling, and reactivation**
    - **Validates: Requirements 1.4, 1.5, 1.6, 4.5, 4.10**

  - [ ]* 11.3 Write property test for activation limit enforcement
    - **Property 5: Activation limit is never exceeded**
    - **Validates: Requirements 4.6**

  - [ ]* 11.4 Write property test for revoked/expired licenses rejecting activation
    - **Property 8: Revoked/expired licenses reject activation**
    - **Validates: Requirements 4.9**

- [ ] 12. Implement the `/validate` endpoint
  - [ ] 12.1 Implement `Api\ValidateHandler`
    - `src/Api/ValidateHandler.php`: required-field check, unknown-license check, site-not-found check (site_hash lookup before any mutation), Lazy_Check invocation via `StatusCalculator`/`StatusTransitionApplier` for annual active/grace licenses, `last_validated_at` update, response building matching stored/corrected `status` and `expires_at`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.7, 5.8, 5.9_

  - [ ]* 12.2 Write property test for /validate response exactness and site-not-found safety
    - **Property 9: /validate response exactness and site-not-found safety**
    - **Validates: Requirements 5.1, 5.3, 5.8, 5.9**

- [ ] 13. Implement the `/deactivate` endpoint
  - [ ] 13.1 Implement `Api\DeactivateHandler`
    - `src/Api/DeactivateHandler.php`: required-field check, unknown-license check, site-not-found check (including already-deactivated case), deactivation, slots-available computation (`activation_limit` minus post-deactivation non-deactivated count)
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [ ]* 13.2 Write property test for deactivation correctness and slot accounting
    - **Property 14: Deactivation correctness and slot accounting**
    - **Validates: Requirements 6.1, 6.3, 6.5**

  - [ ]* 13.3 Write property test for unknown license_key uniform rejection
    - **Property 6: Unknown license_key uniformly rejected**
    - **Validates: Requirements 4.2, 5.2, 6.2**

  - [ ]* 13.4 Write property test for malformed license_key rejected before database access
    - **Property 7: Malformed license_key rejected before database access**
    - **Validates: Requirements 4.3, 20.6, 20.7**

- [ ] 14. Implement audit logging
  - [ ] 14.1 Implement `Audit\AuditLogger`
    - `src/Audit/AuditLogger.php`: `record(licenseId, eventType, payload)` called after the primary state mutation commits, wraps `LicenseEventRepository::append()` in try/catch, logs failures via `Support\Logger`, never throws to the caller
    - Wire `AuditLogger` into `ActivateHandler`, `ValidateHandler`, `DeactivateHandler` for `activation`/`deactivation`/`silent_lapse_grace` events
    - _Requirements: 22.1, 22.2, 22.4_

  - [ ]* 14.2 Write property test for audit logging not blocking state changes on failure
    - **Property 33: Audit logging does not block state changes on failure**
    - **Validates: Requirements 22.4**

- [ ] 15. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 16. Implement the Razorpay webhook endpoint
  - [ ] 16.1 Implement `Api\RazorpayWebhookHandler` signature verification and dispatch
    - `src/Api/RazorpayWebhookHandler.php`: verifies `X-Razorpay-Signature` (HMAC-SHA256 over raw body, constant-time compare) before processing; rejects non-JSON/missing-event-type bodies; looks up prior `webhook_event_id` via `license_events.webhook_event_id` for idempotency; dispatches on `event.type`
    - _Requirements: 7.1, 7.2, 7.3, 7.8, 7.9_

  - [ ]* 16.2 Write property test for Razorpay webhook signature acceptance
    - **Property 21: Razorpay webhook signature acceptance**
    - **Validates: Requirements 7.1, 7.2**

  - [ ] 16.3 Implement `subscription.charged` success/failure effects
    - Success: sets `price_amount`/`currency`, extends `expires_at` by one year from the later of prior `expires_at` or webhook-processing time, transitions `grace`/`expired` → `active`, appends `webhook_charged`; validates amount/currency/billing-period presence
    - Failure: transitions `active` → `grace` with grace-start at webhook-processing time via the shared `StatusTransitionApplier`, appends `webhook_charge_failed`; duplicate failure against non-`active` status appends `webhook_charge_failed_duplicate` without resetting grace-start
    - Revoked-license guard: any charged event (success or failure) against a `revoked` License appends `webhook_ignored_revoked` and changes nothing else
    - _Requirements: 7.4, 7.5, 7.10, 7.11, 10.1, 10.4_

  - [ ]* 16.4 Write property test for webhook charged-success effects
    - **Property 22: Webhook charged-success effects**
    - **Validates: Requirements 7.4**

  - [ ]* 16.5 Write property test for revoked licenses' immunity to charged webhooks
    - **Property 24: Revoked licenses are immune to charged webhooks**
    - **Validates: Requirements 7.10**

  - [ ] 16.6 Implement `subscription.cancelled`, unmatched, and unhandled event effects
    - `subscription.cancelled` appends `webhook_cancelled` without status change; unmatched `razorpay_subscription_id` responds with a structured error and appends `webhook_unmatched` (null `license_id`, raw payload); any other event type appends `webhook_unhandled` and responds success without mutation
    - _Requirements: 7.6, 7.7, 7.12_

  - [ ]* 16.7 Write property test for unmatched and unhandled webhooks never mutating a License
    - **Property 25: Unmatched and unhandled webhooks never mutate a License**
    - **Validates: Requirements 7.6, 7.7, 7.12**

  - [ ]* 16.8 Write property test for webhook idempotent replay
    - **Property 23: Webhook idempotent replay**
    - **Validates: Requirements 7.8**

- [ ] 17. Implement the Sweep_Job cron script
  - [ ] 17.1 Implement `Cron\SweepLock` and `Cron\SweepJob`
    - `src/Cron/SweepLock.php`: MariaDB advisory lock (`GET_LOCK('serb_sweep_job', 0)`); if already held, exit immediately with zero transitions
    - `src/Cron/SweepJob.php`: pages through annual, non-revoked Licenses, calls `StatusCalculator::compute()`/`StatusTransitionApplier` per row inside a per-row try/catch (logs + `sweep_error` event on failure, continues), appends `sweep_grace_transition`/`sweep_expiry_transition` events, then calls `RateLimitRepository::cleanup()` inside its own try/catch
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 2.5, 2.6_

  - [ ]* 17.2 Write property test for Sweep_Job per-item resilience
    - **Property 13: Sweep_Job per-item resilience**
    - **Validates: Requirements 12.7, 2.6**

  - [ ]* 17.3 Write unit tests for cron overlap and lock behavior
    - Test: a second `SweepJob` run started while the lock is held exits immediately with zero DB writes
    - Test: the lock is released when the holding connection closes, allowing the next scheduled run to proceed
    - _Requirements: 12.8_

- [ ] 18. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 19. Implement admin authentication
  - [ ] 19.1 Implement `Admin\SessionAuth` and `Admin\LoginController`
    - `src/Admin/SessionAuth.php`: `requireAuthenticated()` checks `$_SESSION['admin_user_id']`/`last_activity` against the 30-minute inactivity window via `Clock`, redirects to login otherwise, touches `last_activity` on success
    - `src/Admin/LoginController.php`: validates credentials against `password_verify()`, generic error on mismatch, establishes session on success, enforces the 5-failures/15-minutes lockout via `admin_login_attempts`, logout terminates the session
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5, 13.6, 13.7, 13.8, 13.9_

  - [ ]* 19.2 Write property test for admin password hashing round-trip
    - **Property 34: Admin password hashing round-trip**
    - **Validates: Requirements 13.4**

  - [ ]* 19.3 Write property test for admin session inactivity boundary
    - **Property 35: Admin session inactivity boundary**
    - **Validates: Requirements 13.8**

  - [ ]* 19.4 Write property test for admin login lockout window
    - **Property 36: Admin login lockout window**
    - **Validates: Requirements 13.9**

  - [ ]* 19.5 Write unit tests for login validation and generic error responses
    - Test: missing/empty/oversized username or password → structured validation error without checking credentials
    - Test: invalid credentials → generic error not revealing which field was wrong; locked-out username → same generic error shape
    - _Requirements: 13.3, 13.5, 13.7, 13.9_

> **⚠️ STOP BEFORE STARTING TASK 20 — UI direction confirmation required.**
> The user wants a fully modern, custom-styled admin UI (no native browser checkboxes/selects/alert()/confirm(), no default focus outlines, custom buttons/toggles/modals/toast notifications, card-based mobile-responsive layout, 2026 SaaS-dashboard feel — not a dense old-school admin table dump or Bootstrap-default look). Plain CSS or a lightweight framework is fine; no jQuery-UI. **Before implementing task 20 (or any admin template work in 20-25), pause and ask the user to confirm the exact visual direction — they may share design references.** Do not default to plain/unstyled HTML forms and tables for the admin panel.

- [ ] 20. Implement the admin dashboard
  - [ ] 20.1 Implement `Admin\DashboardController` and dashboard template
    - `src/Admin/DashboardController.php`: computes active-license count, MRR (sum of `price_amount` for active/annual/INR/non-null-price Licenses ÷ 12), non-revoked lifetime count, licenses expiring within `[now, now+168h]`, and `grace`-status licenses
    - `templates/admin/dashboard.php`: renders the above behind `SessionAuth::requireAuthenticated()`
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5_

  - [ ]* 20.2 Write property test for dashboard aggregate accuracy
    - **Property 29: Dashboard aggregate accuracy**
    - **Validates: Requirements 14.1, 14.2, 14.3, 14.4, 14.5**

- [ ] 21. Implement the admin license list, search, filter, and pagination
  - [ ] 21.1 Implement the shared list query builder and `Admin\LicenseListController`
    - Extend `LicenseRepository` with a single parameterized query-builder method combining status/tier/product filters (AND semantics, allow-listed keys), case-insensitive email/license_key substring search, expiring-soon window (clamped 1-365 days, default 30), and pagination (max 50 rows/page)
    - `src/Admin/LicenseListController.php`: renders the sortable table, applies the query builder, shows an explicit empty-results indication when an active search/filter/pagination request yields zero rows
    - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6_

  - [ ]* 21.2 Write property test for license list filter, search, and pagination correctness
    - **Property 27: License list filter, search, and pagination correctness**
    - **Validates: Requirements 15.2, 15.3, 15.4, 15.5**

  - [ ]* 21.3 Write unit test for the empty-results indication
    - Test: an active filter/search/pagination request matching zero Licenses shows an explicit empty-results message, distinct from the initial-load-with-no-data-at-all case
    - _Requirements: 15.6_

- [ ] 22. Implement CSV export
  - [ ] 22.1 Implement `Admin\CsvExportController` and `CsvExporter`
    - Reuses the exact query-builder from `LicenseListController` so list and export can never disagree; generates RFC 4180-compliant CSV with header `email,customer_name,product,tier,status,purchased_at`; returns a downloadable response with a CSV content type; on generation failure, returns no partial file and displays a structured error while preserving filter state
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5_

  - [ ]* 22.2 Write property test for CSV export fidelity and filter consistency
    - **Property 28: CSV export fidelity and filter consistency**
    - **Validates: Requirements 16.1, 16.2, 16.3**

  - [ ]* 22.3 Write unit test for CSV content-type header and failure handling
    - Test: successful export response has a CSV content type; a simulated generation failure returns a structured error and preserves current filters instead of a partial file
    - _Requirements: 16.4, 16.5_

- [ ] 23. Implement the admin license detail view
  - [ ] 23.1 Implement `Admin\LicenseDetailController` and detail template
    - Displays license fields, all Activations (descending by `activated_at`), all License_Events (ascending by `created_at`); non-existent License id → structured "not found", no partial render
    - _Requirements: 17.1, 17.2, 17.3, 17.4_

  - [ ]* 23.2 Write unit tests for the license detail view
    - Test: existing License renders all required fields, Activations sorted descending, Events sorted ascending
    - Test: non-existent License id → "not found" indication with no partial data leaked
    - _Requirements: 17.1, 17.2, 17.3, 17.4_

- [ ] 24. Implement admin license management actions
  - [ ] 24.1 Implement `Admin\LicenseActionController`
    - Extend-expiry, revoke, force-deactivate, regenerate-key, add-note actions; validates payload presence/format per action; rejects nonexistent License/Activation ids without appending an event; treats revoke-on-already-revoked and force-deactivate-on-already-deactivated as no-ops (success, zero duplicate events); rejects extend-expiry against `lifetime` tier without modification
    - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.8, 18.9_

  - [ ]* 24.2 Write property test for admin action correctness and idempotent no-ops
    - **Property 30: Admin action correctness and idempotent no-ops**
    - **Validates: Requirements 18.1, 18.2, 18.3, 18.4, 18.6, 18.9**

  - [ ]* 24.3 Write property test for extend-expiry rejecting lifetime licenses
    - **Property 31: Extend-expiry rejects lifetime licenses**
    - **Validates: Requirements 18.8**

  - [ ]* 24.4 Write unit tests for admin action payload validation
    - Test: missing/malformed payload values (missing `expires_at`, unparseable date, note >2000 chars) → structured validation error, no event appended
    - _Requirements: 18.5, 18.7_

- [ ] 25. Implement manual license issuance
  - [ ] 25.1 Implement `Admin\ManualIssuanceController`
    - Validates `customer_name`, `email`, `product`, `tier`, `activation_limit` presence; `activation_limit` must be a positive integer; `tier` must be `annual` or `lifetime`; `annual` requires `expires_at`, `lifetime` silently clears any submitted `expires_at`; `price_amount`/`currency` must be both-present-or-both-absent; on success creates the License with status `active`, a generated unique `license_key`, null `razorpay_subscription_id`, and appends `admin_issue`
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7, 19.8, 19.9_

  - [ ]* 25.2 Write property test for manual issuance correctness
    - **Property 32: Manual issuance correctness**
    - **Validates: Requirements 19.1, 19.2, 19.3, 19.4**

  - [ ]* 25.3 Write unit tests for manual issuance validation rules
    - Test: missing required field, inconsistent price/currency pairing, non-positive `activation_limit`, invalid `tier` value, missing `expires_at` for `annual` tier → each rejected with a structured validation error
    - _Requirements: 19.5, 19.6, 19.7, 19.8_

- [ ] 26. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 27. Verify the cross-cutting lifetime-license invariant
  - [ ] 27.1 Audit every expires_at write path for the lifetime invariant
    - Review `LicenseRepository::create`/`updateFields`, the extend-expiry action, manual issuance, and the Sweep_Job/Lazy_Check exclusion filters to confirm every path enforces "tier `lifetime` ⇒ `expires_at` is NULL, and is excluded from grace/expiry evaluation" identically; add any missing guard found during the audit
    - _Requirements: 1.2, 1.10, 12.5, 18.8, 19.9_

  - [ ]* 27.2 Write property test for the lifetime license expires_at invariant
    - **Property 1: Lifetime license expires_at invariant**
    - **Validates: Requirements 1.2, 1.10, 12.5, 18.8, 19.9**

- [ ] 28. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP; core implementation tasks are never marked optional.
- Property tests use Eris (`giorgiosironi/eris`), PHPUnit-integrated, with a minimum of 100 iterations per property, and the exact docblock tag format from the design's Testing Strategy (e.g. `Feature: license-validation-server, Property N: <title>`).
- Properties requiring injected failures (17, 33, part of 13) use hand-written test doubles (`FailingRateLimitRepository`, `FailingLicenseEventRepository`) parameterized by Eris-generated inputs, per the design's "Properties requiring mocked collaborators" section.
- Infrastructure/presentational concerns the design marks as not suited to PBT (cron wiring, `.env.example` completeness, `.gitignore` rule, page rendering, CSV content-type header) are covered only by unit/integration tests, never property tests.
- Each task references specific requirement acceptance criteria for traceability; checkpoints ensure incremental validation before moving to the next major area.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "2.1"] },
    { "id": 1, "tasks": ["1.2", "1.3", "2.2", "3.1"] },
    { "id": 2, "tasks": ["2.3", "3.2", "4.1"] },
    { "id": 3, "tasks": ["4.2", "4.4", "4.5", "4.7"] },
    { "id": 4, "tasks": ["4.3", "4.6", "5.1", "6.1"] },
    { "id": 5, "tasks": ["5.4", "6.2", "7.1", "7.3"] },
    { "id": 6, "tasks": ["5.2", "5.3", "5.5", "6.3", "6.4", "7.2", "7.4", "8.1"] },
    { "id": 7, "tasks": ["8.2"] },
    { "id": 8, "tasks": ["8.3", "8.4", "8.5", "10.1"] },
    { "id": 9, "tasks": ["10.2", "11.1", "12.1", "13.1"] },
    { "id": 10, "tasks": ["11.2", "11.3", "11.4", "12.2", "13.2", "13.3", "13.4", "14.1"] },
    { "id": 11, "tasks": ["14.2", "16.1"] },
    { "id": 12, "tasks": ["16.2", "16.3", "17.1"] },
    { "id": 13, "tasks": ["16.4", "16.5", "16.6", "17.2", "17.3", "19.1"] },
    { "id": 14, "tasks": ["16.7", "16.8", "19.2", "19.3", "19.4", "19.5", "20.1"] },
    { "id": 15, "tasks": ["20.2", "21.1"] },
    { "id": 16, "tasks": ["21.2", "21.3", "22.1", "23.1", "24.1", "25.1"] },
    { "id": 17, "tasks": ["22.2", "22.3", "23.2", "24.2", "24.3", "24.4", "25.2", "25.3"] },
    { "id": 18, "tasks": ["27.1"] },
    { "id": 19, "tasks": ["27.2"] }
  ]
}
```
