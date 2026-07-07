# Requirements Document

## Introduction

The License Validation Server is a standalone PHP + MariaDB backend that issues, validates, and manages licenses for the "Serenity Booking" WordPress plugin (and is designed to remain product-agnostic so future products/tiers can share it). It exposes an HMAC-signed HTTP API consumed by the WordPress plugin client (activate, validate, deactivate, and a Razorpay webhook receiver), and it provides a single-admin web panel for license lifecycle management, customer/license reporting, and manual license issuance.

The server runs on plain PHP 8.2+ (no framework) under PHP-FPM behind OpenLiteSpeed, with a local-only MariaDB instance, on a self-managed OVH VPS provisioned via ServerAvatar. Traffic is expected to be proxied through Cloudflare. The system must handle production volumes (potentially thousands of validation calls per day) reliably, with explicit input validation, structured error handling, and MariaDB-backed rate limiting (no Redis/APCu available) from the start.

This specification covers ONLY the license server (API + admin panel). It explicitly excludes the public checkout/selling website, the WordPress plugin client implementation itself, multi-admin roles, and multi-currency support.

## Glossary

- **License_Server**: The PHP + MariaDB backend system specified in this document, comprising the API and the Admin_Panel.
- **License**: A record in the `licenses` table representing a single purchased or admin-issued entitlement, identified by a `license_key`.
- **License_Key**: A license identifier string in the format `SERB-XXXXX-XXXXX-XXXXX-XXXXX` (product-specific prefixes such as `SERC-` for a future Cloud tier are anticipated but not implemented in this spec).
- **Activation**: A record in the `license_activations` table representing a specific site bound to a License, identified by `site_hash`.
- **Site_Hash**: A SHA-256 hash of a site's URL, computed by the WordPress plugin client, used as the canonical identity of a site.
- **Site_URL**: A human-readable display label for a site, stored alongside `site_hash` but never used as an identity key.
- **License_Event**: An immutable audit log entry in the `license_events` table recording a state change or notable action related to a License.
- **Rate_Limit_Store**: The MariaDB table used to implement sliding-window rate limiting per client IP and per license key.
- **Client_IP**: The resolved real IP address of the calling WordPress site, determined per the IP resolution rules in Requirement 8.
- **Trusted_Proxy_Range**: A configured set of IP CIDR ranges (Cloudflare's published ranges) whose proxy headers the License_Server is permitted to trust.
- **HMAC_Request**: An inbound API request signed per the scheme in Requirement 7.
- **Shared_Secret**: The HMAC signing secret shared between the License_Server and the WordPress plugin client, stored in server-side configuration only.
- **Grace_Period**: A 3-day window following detection of a lapsed or failed renewal during which a License's status is `grace` and Pro features remain enabled.
- **Sweep_Job**: The hourly cron-triggered PHP script that performs bulk `active`→`grace` and `grace`→`expired` transitions.
- **Lazy_Check**: The on-the-fly status computation performed during `/validate` that derives the true current status from timestamps, independent of the Sweep_Job.
- **Admin_Panel**: The session-authenticated web interface for the single admin user.
- **Admin_User**: The single authenticated operator of the Admin_Panel, represented by a row in `admin_users`.
- **Razorpay_Webhook**: An inbound HTTP notification from Razorpay reporting subscription billing events.
- **Tier**: The License's plan type, either `annual` or `lifetime`.
- **Status**: The License's lifecycle state: `active`, `grace`, `expired`, or `revoked`.

## Requirements

### Requirement 1: License Key and Activation Data Model

**User Story:** As a license server operator, I want licenses and their site activations stored in a well-defined schema, so that license state and activation history are accurate and auditable.

#### Acceptance Criteria

1. THE License_Server SHALL store each License with fields: id, license_key, email (max 255 characters), customer_name (max 255 characters), product, tier (constrained to `annual` or `lifetime`), status (constrained to `active`, `grace`, `expired`, or `revoked`), purchased_at, expires_at (nullable), razorpay_subscription_id (nullable), activation_limit (a positive integer of 1 or greater), price_amount (nullable, a non-negative decimal value), currency (a 3-letter currency code), notes (max 2000 characters), created_at, updated_at.
2. IF a License's tier is `lifetime`, THEN THE License_Server SHALL store a null expires_at and SHALL exclude that License from grace/expiry sweep and lazy-check logic.
3. THE License_Server SHALL store each Activation with fields: id, license_id, site_url (max 500 characters), site_hash (a 64-character lowercase hexadecimal SHA-256 digest), activated_at, last_validated_at, deactivated_at (nullable).
4. WHEN an Activation is created for a site_hash that has no existing non-deactivated Activation row for that License, THE License_Server SHALL insert a new Activation record.
5. IF an incoming site_url during activation processing does not match the site_url already stored on that site_hash's existing non-deactivated Activation, THEN THE License_Server SHALL silently ignore the mismatched incoming site_url and SHALL leave the stored site_url unmodified.
6. IF an incoming site_hash has no matching non-deactivated Activation row for that License, THEN THE License_Server SHALL treat the request as a new site activation rather than an update to an existing Activation.
7. THE License_Server SHALL retain all License, Activation, and License_Event rows indefinitely regardless of status, and SHALL NOT provide any mechanism that deletes or modifies in place any License, Activation, or License_Event data.
8. THE License_Server SHALL store each License_Event with fields: id, license_id, event_type, payload (JSON), created_at.
9. THE License_Server SHALL keep the product field on License free of hardcoded single-product assumptions, such that additional product values are storable without a schema change.
10. THE License_Server SHALL enforce a database-level uniqueness constraint on license_key across all License rows, and SHALL enforce a validation rule preventing any License with tier `lifetime` from being stored with a non-null expires_at value.

### Requirement 2: Rate Limiting Data Model

**User Story:** As a license server operator, I want a MariaDB-backed rate-limit tracking table, so that I can throttle abusive or excessive API traffic without requiring Redis or APCu.

#### Acceptance Criteria

1. THE License_Server SHALL store rate-limit tracking rows in the Rate_Limit_Store, keyed by a limiter scope (`ip` or `license_key`), a scope value, a request timestamp, and the endpoint invoked.
2. WHEN an API request is received on the `/activate`, `/validate`, or `/deactivate` endpoint, THE License_Server SHALL record a row representing that request attempt in the Rate_Limit_Store before or as part of processing the request.
3. IF the write recording a request attempt in the Rate_Limit_Store fails, THEN THE License_Server SHALL attempt to log the failure and SHALL allow that individual request to proceed rather than rejecting it, while continuing to attempt rate-limit tracking for subsequent requests rather than disabling rate limiting entirely for that client, regardless of whether the failure-logging attempt itself succeeds.
4. THE License_Server SHALL provide a cleanup mechanism that purges Rate_Limit_Store rows older than the current time minus the maximum sliding window duration configured for any limiter.
5. WHEN the Sweep_Job runs, THE Sweep_Job SHALL invoke the rate-limit cleanup mechanism.
6. IF the rate-limit cleanup mechanism fails during a sweep run, THEN THE Sweep_Job SHALL log the failure and continue the run; IF the logging of that failure itself fails, THEN THE Sweep_Job SHALL still continue the run rather than aborting.

### Requirement 3: HMAC Request Authentication

**User Story:** As a license server operator, I want all API requests cryptographically signed, so that client code shipped to customer WordPress sites cannot be inspected to extract a reusable plaintext API key.

#### Acceptance Criteria

1. THE License_Server SHALL require every request to `/activate`, `/validate`, and `/deactivate` to include a timestamp and an HMAC-SHA256 signature, each delivered via a dedicated HTTP header rather than a body field, where the timestamp is an integer Unix epoch value expressed in seconds and the signature is a lowercase hexadecimal-encoded string, computed over the string formed by concatenating the timestamp, a literal `.` character, and the raw request body, using the Shared_Secret.
2. IF a request's signature does not match the value computed by the License_Server using the same inputs and the Shared_Secret, THEN THE License_Server SHALL reject the request with an authentication error and SHALL NOT process the request body.
3. IF a request's timestamp differs from the License_Server's current time by more than 300 seconds (5 minutes) in either direction, THEN THE License_Server SHALL reject the request as expired and SHALL NOT process the request body.
4. IF a request to `/activate`, `/validate`, or `/deactivate` omits the timestamp or signature header, or supplies a timestamp value that is not a well-formed integer, THEN THE License_Server SHALL reject the request with a structured error indicating the missing or malformed field.
5. THE License_Server SHALL store the Shared_Secret only in server-side configuration and SHALL NOT expose it in any API response.
6. THE License_Server SHALL compute HMAC signature verification using a constant-time comparison function.
7. THE License_Server SHALL evaluate HMAC-related checks in the following order, rejecting on the first failure: (1) timestamp and signature field presence and format validation per Criterion 4, (2) timestamp expiry validation per Criterion 3, (3) signature verification per Criterion 2.

### Requirement 4: License Activation Endpoint

**User Story:** As a WordPress site administrator, I want to activate my purchased license on my site, so that Pro features are enabled and my activation is tracked against my activation limit.

#### Acceptance Criteria

1. WHEN a valid HMAC_Request is received at `/activate` with a license_key matching the format `SERB-XXXXX-XXXXX-XXXXX-XXXXX` and a site_url, THE License_Server SHALL compute the site_hash from the site_url and proceed with activation processing.
2. IF the license_key in a `/activate` request does not match a known License, THEN THE License_Server SHALL respond with a structured "unknown license" error and SHALL NOT create an Activation.
3. IF the license_key format in a `/activate` request does not match `SERB-XXXXX-XXXXX-XXXXX-XXXXX`, THEN THE License_Server SHALL reject the request with a structured validation error before querying the database.
4. IF a `/activate` request is missing license_key or site_url, THEN THE License_Server SHALL reject the request with a structured validation error identifying the missing field.
5. WHEN a `/activate` request's site_hash already has a non-deactivated Activation for the requested License, THE License_Server SHALL treat the request as idempotent, SHALL update last_validated_at, and SHALL respond with the current status and expiry without creating a duplicate Activation.
6. IF a `/activate` request's License already has a number of non-deactivated Activations equal to or greater than activation_limit, and the request's site_hash has no existing non-deactivated Activation for that License, THEN THE License_Server SHALL reject the request with a structured "activation limit exceeded" error and SHALL NOT create an Activation.
7. WHEN activation succeeds, THE License_Server SHALL create an Activation record with activated_at set to the current time and SHALL append a License_Event of type activation.
8. WHEN activation succeeds, THE License_Server SHALL respond with the License's current status and expiry.
9. IF a `/activate` request targets a License with status `revoked` or `expired`, THEN THE License_Server SHALL reject the request with a structured error reflecting the License's current status and SHALL NOT create an Activation.
10. WHEN a `/activate` request's site_hash matches a previously deactivated Activation (deactivated_at IS NOT NULL) for the requested License, THE License_Server SHALL reactivate that existing Activation row by setting deactivated_at to null and updating activated_at and last_validated_at, rather than inserting a new Activation row, subject to the same activation_limit check described in Criterion 6.
11. WHEN a single `/activate` request violates more than one rejection condition simultaneously, THE License_Server SHALL evaluate the conditions in the following order using short-circuit evaluation (stopping as soon as a condition fails, without evaluating subsequent conditions), and reject on that first failure: (1) missing required field (Criterion 4), (2) license_key format (Criterion 3), (3) unknown license_key (Criterion 2), (4) License status `revoked` or `expired` (Criterion 9), (5) activation_limit exceeded (Criterion 6).

### Requirement 5: License Validation Endpoint

**User Story:** As a WordPress plugin running a periodic cron check, I want to validate a license's current status and expiry, so that I can enforce feature access accurately even between server-side sweep runs.

#### Acceptance Criteria

1. WHEN a valid HMAC_Request is received at `/validate` with a license_key and site_hash matching an existing non-deactivated Activation, THE License_Server SHALL update that Activation's last_validated_at to the current time.
2. IF the license_key in a `/validate` request does not match a known License, THEN THE License_Server SHALL respond with a structured "unknown license" error.
3. IF the site_hash in a `/validate` request does not match any non-deactivated Activation for the resolved License, THEN THE License_Server SHALL respond with a structured "site not found" error and SHALL NOT update the last_validated_at timestamp or any other field of any Activation before sending that error response.
4. IF a `/validate` request is missing license_key or site_hash, THEN THE License_Server SHALL reject the request with a structured validation error identifying the missing field.
5. WHEN processing a `/validate` request for a License with tier `annual` and a stored status of `active` or `grace`, THE License_Server SHALL perform a Lazy_Check that computes the License's true current status from expires_at, grace-detection timestamps, and the current time, independent of the Sweep_Job's most recent run.
6. THE Lazy_Check SHALL NOT report a stored or computed status that is more favorable to the customer than the status the Sweep_Job would compute for the same License at the same point in time, using the favorability ordering (from most to least favorable): `active`, then `grace`, then `expired`.
7. WHEN the Lazy_Check computes a status of `grace` or `expired` that differs from the License's currently stored status, THE License_Server SHALL report the Lazy_Check's computed status in the `/validate` response and SHALL set the response's expiry field to exactly match the License's expires_at value.
8. THE License_Server SHALL include the License's status and expires_at in every `/validate` response, and SHALL ensure those two values exactly match the stored (or Lazy_Check-corrected, per Criterion 7) values for that License at the time of response.
9. IF a `/validate` request targets a License with status `revoked`, THEN THE License_Server SHALL respond with status `revoked` and SHALL still update the matching Activation's last_validated_at.

> Open item: Whether the Lazy_Check persists its corrected status back to the License row (self-healing) or only reports the corrected status for that single response was previously an open design decision. RESOLVED during requirement detailing — see Requirement 11 AC1/AC2: the Lazy_Check persists (self-heals) a corrected status when it first detects a grace or expired transition, using the same persistence rule as the Sweep_Job, so both mechanisms converge on the same stored state.

### Requirement 6: License Deactivation Endpoint

**User Story:** As a WordPress site administrator, I want to deactivate my license from a site I no longer use, so that I can free the activation slot for use on another site.

#### Acceptance Criteria

1. WHEN a valid HMAC_Request is received at `/deactivate` with a license_key and site_hash matching an existing, non-deactivated Activation for that License, THE License_Server SHALL set that Activation's deactivated_at to the current time and SHALL append a License_Event of type deactivation, regardless of the License's current status.
2. IF the license_key in a `/deactivate` request does not match a known License, THEN THE License_Server SHALL respond with a structured "unknown license" error.
3. IF the site_hash in a `/deactivate` request does not match any non-deactivated Activation for the resolved License, THEN THE License_Server SHALL respond with a structured "site not found" error and SHALL NOT modify any Activation. This case includes a site_hash whose only matching Activation is already deactivated.
4. IF a `/deactivate` request is missing license_key or site_hash, THEN THE License_Server SHALL reject the request with a structured validation error identifying the missing field.
5. WHEN deactivation succeeds, THE License_Server SHALL compute a confirmation value as activation_limit minus the count of remaining non-deactivated Activations for that License, representing the number of activation slots now available, and SHALL respond with that value (this calculation, including any zero or negative result for a License with a zero activation_limit, is performed only upon successful deactivation).

### Requirement 7: Razorpay Webhook Processing

**User Story:** As a license server operator, I want Razorpay subscription billing events processed automatically, so that license status and pricing data stay in sync with actual billing outcomes without manual intervention.

#### Acceptance Criteria

1. WHEN a request is received at `/webhook/razorpay`, THE License_Server SHALL verify the request's Razorpay webhook signature against the configured webhook secret before processing the payload.
2. IF a `/webhook/razorpay` request's signature does not validate, THEN THE License_Server SHALL reject the request with an authentication error and SHALL NOT process the payload.
3. IF a `/webhook/razorpay` request body is not valid JSON or is missing the event type field, THEN THE License_Server SHALL reject the request with a structured validation error.
4. WHEN a `/webhook/razorpay` request reports a `subscription.charged` event with a successful charge for a known razorpay_subscription_id, THE License_Server SHALL set the corresponding License's price_amount and currency from the charge payload, SHALL extend that License's expires_at by one year from the later of (a) the License's current expires_at or (b) the webhook-processing time, SHALL transition the License's status to `active` if its status was `grace` or `expired`, and SHALL append a License_Event of type webhook_charged.
5. WHEN a `/webhook/razorpay` request reports a `subscription.charged` event indicating charge failure for a known razorpay_subscription_id whose current status is `active`, THE License_Server SHALL transition the corresponding License's status to `grace` with the Grace_Period clock starting at the time the webhook is processed, and SHALL append a License_Event of type webhook_charge_failed.
6. WHEN a `/webhook/razorpay` request reports a `subscription.cancelled` event for a known razorpay_subscription_id, THE License_Server SHALL append a License_Event of type webhook_cancelled and SHALL leave status transition to be handled by the Sweep_Job and Lazy_Check at natural expiry, rather than immediately expiring the License.
7. IF a `/webhook/razorpay` request references a razorpay_subscription_id that does not match any known License, THEN THE License_Server SHALL respond with a structured error and SHALL append a License_Event of type webhook_unmatched with a null license_id and the raw payload for later manual reconciliation.
8. WHEN a `/webhook/razorpay` request is received whose event id has already been recorded in a prior License_Event payload for that License, or in a prior License_Event of type webhook_unmatched, THE License_Server SHALL treat the request as an idempotent retry, SHALL NOT apply the event's effects a second time, and SHALL respond with a success status.
9. THE License_Server SHALL append a License_Event recording every accepted `/webhook/razorpay` request, including its raw payload, regardless of whether the event resulted in a license state change.
10. IF a `subscription.charged` webhook (success or failure) is received for a License whose current status is `revoked`, THEN THE License_Server SHALL append a License_Event of type webhook_ignored_revoked and SHALL NOT change that License's status, expires_at, price_amount, or currency.
11. IF a `subscription.charged` success event's payload is missing the amount, currency, or billing period field, THEN THE License_Server SHALL reject the request with a structured validation error and SHALL NOT modify the License.
12. IF a `/webhook/razorpay` request reports an accepted, signature-valid event whose event type is not `subscription.charged`, `subscription.cancelled`, or otherwise handled by this requirement, THEN THE License_Server SHALL append a License_Event of type webhook_unhandled recording the raw payload, and SHALL respond with a success status without modifying the License.

### Requirement 8: Client IP Resolution for Rate Limiting

**User Story:** As a license server operator, I want the real client IP resolved reliably even behind Cloudflare, so that rate limiting cannot be bypassed by forging proxy headers.

#### Acceptance Criteria

1. IF a request's immediate connecting IP (REMOTE_ADDR) falls within a configured Trusted_Proxy_Range, THEN THE License_Server SHALL resolve Client_IP from the CF-Connecting-IP header if present.
2. IF the connecting IP is within a Trusted_Proxy_Range and CF-Connecting-IP is absent, THEN THE License_Server SHALL resolve Client_IP from the X-Forwarded-For header's rightmost address.
3. IF the connecting IP is not within any configured Trusted_Proxy_Range, THEN THE License_Server SHALL resolve Client_IP from REMOTE_ADDR directly and SHALL disregard the CF-Connecting-IP and X-Forwarded-For headers during processing (without requiring that those headers be stripped from the request).
4. THE License_Server SHALL load the set of Trusted_Proxy_Range values from server-side configuration rather than hardcoding them, so the operator can update Cloudflare's published ranges without a code change.
5. THE License_Server SHALL use the resolved Client_IP as the scope value for per-IP rate limiting on every rate-limited endpoint, and SHALL allow a request to proceed using the resolved Client_IP for logging and monitoring purposes if rate limiting itself fails or is disabled.
6. IF the Trusted_Proxy_Range configuration is missing, empty, or fails to load, THEN THE License_Server SHALL fail closed by resolving Client_IP directly from REMOTE_ADDR only, ignoring CF-Connecting-IP and X-Forwarded-For headers.
7. IF the value in the CF-Connecting-IP header, or the selected entry from the X-Forwarded-For header, is not a syntactically valid IP address, THEN THE License_Server SHALL fall back to resolving Client_IP from REMOTE_ADDR.

### Requirement 9: Rate Limiting Enforcement

**User Story:** As a license server operator, I want per-IP and per-license-key rate limits enforced on the API, so that a single abusive source cannot degrade service for legitimate customers.

#### Acceptance Criteria

1. WHEN a request is received at `/activate`, `/validate`, or `/deactivate`, THE License_Server SHALL count prior requests from the same Client_IP recorded in the Rate_Limit_Store within a configured sliding window measured back from the current request's timestamp, before processing the request.
2. IF the count of prior requests from the same Client_IP within the configured window meets or exceeds the configured per-IP limit, THEN THE License_Server SHALL reject the request with a structured rate-limit error and an HTTP 429 status, and SHALL NOT process the request body.
3. WHEN a request at `/validate` or `/deactivate` includes a license_key, THE License_Server SHALL count prior requests bearing the same license_key recorded in the Rate_Limit_Store within a configured sliding window measured back from the current request's timestamp, before processing the request.
4. IF the count of prior requests bearing the same license_key within the configured window meets or exceeds the configured per-license-key limit, THEN THE License_Server SHALL reject the request with a structured rate-limit error and an HTTP 429 status, and SHALL NOT process the request body.
5. THE License_Server SHALL apply per-IP and per-license-key rate-limit checks independently, such that a request is rejected if either limit is exceeded; IF one of the two checks cannot be evaluated due to an error, THEN THE License_Server SHALL still reject the request if the other, successfully evaluated check shows the limit exceeded.
6. THE License_Server SHALL load per-IP and per-license-key rate limit thresholds and window durations from server-side configuration, and SHALL require each configured limit to be an integer greater than zero.
7. THE License_Server SHALL perform rate-limit checks (per-IP and per-license-key) before HMAC signature verification (Requirement 3), so that unsigned or invalid-signature requests cannot bypass rate limiting.
8. IF a failure occurs, or inconsistent data is returned, while reading or counting prior requests from the Rate_Limit_Store during a rate-limit check, THEN THE License_Server SHALL log the anomaly and allow the request to proceed rather than rejecting it, prioritizing availability over rate limiting in that instance.

### Requirement 10: Grace Transition on Explicit Payment Failure

**User Story:** As a license server operator, I want a license to enter grace immediately when Razorpay reports a failed renewal charge, so that the customer has a bounded window to fix payment before losing access.

#### Acceptance Criteria

1. WHEN a `/webhook/razorpay` `subscription.charged` failure event is processed for a License with tier `annual` and status `active`, THE License_Server SHALL set that License's status to `grace` and SHALL record a grace-start timestamp equal to the time the webhook was processed.
2. WHILE a License's status is `active`, THE License_Server SHALL keep Pro features enabled regardless of payment status. WHILE a License's status is `grace`, THE License_Server SHALL report status `grace` (not `expired`) in `/validate` responses, keeping Pro features enabled.
3. WHEN a License's status is `grace` and 259,200 seconds (3 days) or more have elapsed since its grace-start timestamp (a greater-than-or-equal-to comparison, so the transition occurs at exactly the 3-day mark), THE License_Server SHALL transition that License's status to `expired`, this transition being enforced by the Sweep_Job on its hourly run (Requirement 12) and by the Lazy_Check in real time during `/validate` (Requirement 5).
4. IF a `subscription.charged` failure webhook is received for a License already in status `grace`, `expired`, or `revoked`, THEN THE License_Server SHALL NOT reset or extend the existing grace-start timestamp, and SHALL append a License_Event of type webhook_charge_failed_duplicate instead of re-triggering the grace transition.

### Requirement 11: Grace Transition on Silent Lapse

**User Story:** As a license server operator, I want a license to enter grace even if Razorpay never sends a failure webhook, so that customers whose subscriptions silently lapse still get a fair grace window instead of being stuck in a stale "active" state indefinitely.

#### Acceptance Criteria

1. WHEN a License with tier `annual` and status `active` has an expires_at timestamp that is in the past, and no `subscription.charged` failure webhook has already transitioned it to `grace`, THE License_Server SHALL transition that License's status to `grace`, and whichever detection mechanism (Sweep_Job or Lazy_Check) first detects this condition SHALL persist a grace-start timestamp equal to the time of detection to the License row and SHALL append a License_Event of type silent_lapse_grace recording the detection.
2. Once a grace-start timestamp has been persisted by whichever mechanism first detects a given lapse, neither the Sweep_Job nor the Lazy_Check SHALL overwrite that grace-start timestamp afterward, so the 3-day Grace_Period countdown is anchored to a single stable point regardless of which mechanism detects it or how many times `/validate` is subsequently called.
3. THE Sweep_Job SHALL detect silent-lapse conditions on every hourly run by comparing each active annual License's expires_at against the current time.
4. THE Lazy_Check SHALL detect silent-lapse conditions at `/validate` time using the same expires_at comparison as the Sweep_Job, so a lapse is reflected even if the Sweep_Job has not yet run, and WHEN the Lazy_Check is the first mechanism to detect the lapse, THE License_Server SHALL durably persist that detection using the same persistence rule described in Criterion 1, rather than only returning it in the `/validate` response.

### Requirement 12: Grace and Expiry Sweep Job

**User Story:** As a license server operator, I want an hourly automated sweep of license statuses, so that grace and expiry transitions happen reliably without requiring a validation call to trigger them.

#### Acceptance Criteria

1. THE Sweep_Job SHALL run every 60 minutes via a server cron trigger.
2. WHEN the Sweep_Job runs, THE Sweep_Job SHALL identify all annual Licenses with status `active` and expires_at in the past and SHALL transition them to `grace` per Requirement 11.
3. WHEN the Sweep_Job runs, THE Sweep_Job SHALL identify all annual Licenses with status `grace` whose grace-start timestamp is 259,200 seconds (3 days) or more in the past (a greater-than-or-equal-to comparison) and SHALL transition them to `expired`.
4. WHEN the Sweep_Job performs an `active`-to-`grace` transition described in Criterion 2 or a `grace`-to-`expired` transition described in Criterion 3, THE Sweep_Job SHALL append a License_Event recording the prior status, new status, and reason for the transition, using a distinct event_type value (sweep_grace_transition for the `active`-to-`grace` transition, sweep_expiry_transition for the `grace`-to-`expired` transition) rather than relying solely on a free-text reason field.
5. THE Sweep_Job SHALL exclude Licenses with tier `lifetime` from all grace/expiry evaluation.
6. THE Sweep_Job SHALL exclude Licenses with status `revoked` from all grace/expiry evaluation.
7. IF the Sweep_Job encounters an error processing one License, THEN THE Sweep_Job SHALL log the error, append a License_Event describing the failure for that License where possible, and SHALL continue processing remaining Licenses rather than aborting the entire run.
8. IF a Sweep_Job run is still executing when the next hourly trigger fires, THEN THE new run SHALL exit immediately without performing any transitions, leaving the in-progress run unaffected.

### Requirement 13: Admin Authentication

**User Story:** As the sole administrator of the license server, I want a secure username/password login, so that only I can access license management and customer data.

#### Acceptance Criteria

1. WHILE a request targets a protected admin page (defined as any admin page other than the login page), THE Admin_Panel SHALL verify an active authenticated session before granting access.
2. WHEN an Admin_User submits valid credentials (a username and password matching the stored password_hash in admin_users), THE Admin_Panel SHALL establish an authenticated session and redirect to the dashboard.
3. IF an Admin_User submits invalid credentials (a username/password combination that does not match stored values), THEN THE Admin_Panel SHALL reject the login attempt with a generic error that does not reveal whether the username or password was incorrect, and SHALL NOT establish a session.
4. THE Admin_Panel SHALL store passwords only as salted hashes using a password hashing algorithm appropriate for PHP 8.2 (e.g. bcrypt via password_hash), and SHALL NOT store plaintext passwords.
5. WHILE no authenticated session exists, THE Admin_Panel SHALL redirect any request for a protected admin page to the login page, treating the redirect as always attempted and successful.
6. WHEN the Admin_User requests logout, THE Admin_Panel SHALL terminate the authenticated session and redirect to the login page.
7. IF a login form submission is missing, empty, or has an oversized username or password field, THEN THE Admin_Panel SHALL reject the submission with a structured validation error without checking credentials; passing this field validation does not guarantee successful authentication, since a subsequent condition such as rate limiting or an account lockout (Criterion 9) may still cause the login attempt to fail.
8. THE Admin_Panel SHALL expire an authenticated session after 30 minutes of inactivity, requiring re-authentication.
9. IF 5 failed login attempts occur for the same username within a 15-minute window, THEN THE Admin_Panel SHALL lock out further login attempts for that username for 15 minutes, responding with a generic error that does not reveal whether the lockout or a credential mismatch caused the rejection.

### Requirement 14: Admin Dashboard

**User Story:** As the administrator, I want a dashboard summarizing license health and revenue, so that I can see business status at a glance.

#### Acceptance Criteria

1. WHEN the Admin_User views the dashboard, THE Admin_Panel SHALL display the count of Licenses with status `active`.
2. WHEN the Admin_User views the dashboard, THE Admin_Panel SHALL display an estimated MRR computed as the sum of price_amount for active annual Licenses with currency INR and non-null price_amount, divided by 12.
3. WHEN the Admin_User views the dashboard, THE Admin_Panel SHALL display the count of Licenses with tier `lifetime` and status other than `revoked`.
4. WHEN the Admin_User views the dashboard, THE Admin_Panel SHALL display a list of Licenses with status `active` and expires_at within the next 7 days, defined as an inclusive range from the current time to the current time plus 168 hours, with each list entry showing at minimum license_key, email, and expires_at.
5. WHEN the Admin_User views the dashboard, THE Admin_Panel SHALL display a list of Licenses with status `grace`, with each list entry showing at minimum license_key, email, and expires_at.

### Requirement 15: Customer and License Listing

**User Story:** As the administrator, I want to browse and search all licenses, so that I can find and manage a specific customer's license quickly.

#### Acceptance Criteria

1. WHEN the Admin_User opens the license list page, THE Admin_Panel SHALL display Licenses in a sortable table including status, tier, product, and expires_at.
2. WHEN the Admin_User applies a filter for status, tier, or product, THE Admin_Panel SHALL restrict the displayed Licenses to strictly those matching all selected filter values (no non-matching License is displayed), combining multiple simultaneously applied filters with AND semantics; a matching License may still be excluded from the currently displayed page by pagination boundaries (Criterion 5), but SHALL always be included in the underlying matching result set.
3. WHEN the Admin_User submits a search term, THE Admin_Panel SHALL return and display all Licenses whose email or license_key matches the search term as a case-insensitive substring match, subject only to pagination boundaries (Criterion 5).
4. WHEN the Admin_User requests licenses expiring soon, THE Admin_Panel SHALL restrict the displayed Licenses to those with expires_at within a configurable upcoming window, bounded between 1 and 365 days with a default of 30 days.
5. THE Admin_Panel SHALL paginate the license list at a maximum of 50 rows per page, displaying pagination controls when results exceed one page.
6. WHEN an active search term, filter, or pagination request (whether applied interactively by the Admin_User or already active on initial page load from a previous session or URL parameters) would produce zero displayed Licenses, THE Admin_Panel SHALL display an explicit empty-results indication rather than an empty table with no explanation, regardless of whether the search term itself has underlying matches that are excluded by other active filters or pagination. This indication does not apply to the initial license list page load when no search, filter, or pagination request is active and no Licenses exist in the system at all.

### Requirement 16: Customer List CSV Export

**User Story:** As the administrator, I want to export the customer/license list as CSV, so that I can use it for marketing and email campaigns.

#### Acceptance Criteria

1. WHEN the Admin_User requests a CSV export from the license list page, THE Admin_Panel SHALL generate a CSV file containing the fields email, customer_name, product, tier, status, and purchased_at, in that column order, for the exported Licenses, using RFC 4180 style quoting and escaping (double-quoting fields that contain commas, quotes, or newlines, with embedded quotes doubled).
2. WHERE any of the status, tier, product, search-term, or expiring-soon-window filters from Requirement 15 are active on the license list page at the time of export, THE Admin_Panel SHALL apply those same filters to the exported CSV rows, and WHEN the active filters match zero Licenses, THE Admin_Panel SHALL still generate a header-only CSV file with no data rows rather than failing.
3. IF no filters are active at the time of export, THEN THE Admin_Panel SHALL include all Licenses in the exported CSV.
4. WHEN CSV file generation completes, THE Admin_Panel SHALL return the file as a downloadable response with a CSV content type, treating delivery of that response to the Admin_User's browser as a separate concern from generation.
5. IF CSV file generation fails for any reason, THEN THE Admin_Panel SHALL NOT return a partial or corrupted file, SHALL display a structured error to the Admin_User, and SHALL preserve the license list page's current filter state.

### Requirement 17: License Detail View

**User Story:** As the administrator, I want to see full detail on a single license, so that I can understand its history and take informed action when a customer needs support.

#### Acceptance Criteria

1. WHEN the Admin_User opens a License's detail page, THE Admin_Panel SHALL display the License's license_key, email, customer_name, product, tier, status, purchased_at, activation_limit, notes, razorpay_subscription_id, price_amount, and currency.
2. WHEN the Admin_User opens a License's detail page, THE Admin_Panel SHALL display all Activation records for that License, including site_url, activated_at, last_validated_at, and deactivated_at, sorted descending by activated_at (most recent first).
3. WHEN the Admin_User opens a License's detail page, THE Admin_Panel SHALL display all License_Event entries for that License sorted ascending by created_at (oldest first).
4. IF the Admin_User requests a detail page for a License id that does not exist, THEN THE Admin_Panel SHALL reject with a structured "not found" indication and SHALL NOT render any partial License data.

### Requirement 18: Admin License Management Actions

**User Story:** As the administrator, I want to perform manual corrective actions on a license, so that I can resolve customer support cases without direct database access.

#### Acceptance Criteria

1. WHEN the Admin_User submits an extend-expiry action with a new expires_at for a License, THE Admin_Panel SHALL update that License's expires_at and SHALL append a License_Event of type admin_extend recording the prior and new values.
2. WHEN the Admin_User submits a revoke action for a License, THE Admin_Panel SHALL set that License's status to `revoked` and SHALL append a License_Event of type admin_revoke.
3. WHEN the Admin_User submits a force-deactivate action for a specific Activation, THE Admin_Panel SHALL set that Activation's deactivated_at to the current time and SHALL append a License_Event of type admin_force_deactivate.
4. WHEN the Admin_User submits a regenerate-license-key action for a License, THE Admin_Panel SHALL generate a new unique license_key value matching the format `SERB-XXXXX-XXXXX-XXXXX-XXXXX`, SHALL update the License's license_key, and SHALL append a License_Event of type admin_regenerate_key recording the prior and new key.
5. WHEN the Admin_User submits a note addition for a License with note text of 2000 characters or fewer, THE Admin_Panel SHALL append the note to that License's notes field and SHALL append a License_Event of type admin_note. IF the submitted note text exceeds 2000 characters, THEN THE Admin_Panel SHALL reject the submission with a structured validation error.
6. IF an admin action references a License or Activation id that does not exist, THEN THE Admin_Panel SHALL reject the action with a structured error and SHALL NOT append a License_Event.
7. IF an admin action's payload is missing a required value (e.g. missing expires_at on extend, missing note text) or contains a malformed value (e.g. an unparseable expires_at date, or a note exceeding 2000 characters), THEN THE Admin_Panel SHALL reject the action with a structured validation error and SHALL NOT append a License_Event.
8. IF an extend-expiry action targets a License with tier `lifetime`, THEN THE Admin_Panel SHALL reject the action with a structured error and SHALL NOT modify expires_at or append a License_Event.
9. IF a revoke action targets a License already in status `revoked`, or a force-deactivate action targets an Activation already deactivated, THEN THE Admin_Panel SHALL treat the action as a no-op, SHALL respond with a success indication, and SHALL NOT append a duplicate License_Event.

### Requirement 19: Manual License Issuance

**User Story:** As the administrator, I want to issue complimentary or manually-paid licenses without a Razorpay transaction, so that I can support comp accounts, partnerships, and manual payment arrangements.

#### Acceptance Criteria

1. WHEN the Admin_User submits a manual license issuance form with customer_name, email, product, tier, and activation_limit, THE Admin_Panel SHALL create a new License with status `active`, an activation_limit exactly equal to the submitted activation_limit value, and a system-generated unique license_key matching the format `SERB-XXXXX-XXXXX-XXXXX-XXXXX`.
2. WHERE the Admin_User provides both a price_amount and a currency on the manual issuance form, THE Admin_Panel SHALL store those values on the created License exactly as provided.
3. IF the Admin_User omits price_amount on the manual issuance form, THEN THE Admin_Panel SHALL create the License with a null price_amount and a null currency, and SHALL exclude it from MRR calculations.
4. WHEN a manually issued License is created, THE Admin_Panel SHALL set that License's razorpay_subscription_id to null and SHALL append a License_Event of type admin_issue; this rule is conditional on License creation and does not apply as a standing invariant to Licenses created through other means.
5. IF the manual issuance form is missing customer_name, email, product, tier, or activation_limit, or provides price_amount without a corresponding currency, or currency without a corresponding price_amount, THEN THE Admin_Panel SHALL continuously validate the form's field values as they are entered and SHALL reject the submission with a structured validation error identifying the missing or inconsistent field.
6. IF the activation_limit value on the manual issuance form is present but is not a positive integer, THEN THE Admin_Panel SHALL immediately flag the field as invalid and SHALL reject the submission with a structured validation error.
7. IF the tier value on the manual issuance form is not `annual` or `lifetime` at the time of submission, THEN THE Admin_Panel SHALL reject the submission with a structured validation error.
8. WHERE the selected tier is `annual` on the manual issuance form, THE Admin_Panel SHALL require an expires_at value and SHALL reject the submission if it is absent; THE Admin_Panel SHALL NOT require expires_at when the selected tier is `lifetime`.
9. WHERE the tier of a License is `lifetime`, whether at manual issuance or at any subsequent point (including an annual License later converted to lifetime), THE Admin_Panel SHALL immediately clear that License's expires_at to null regardless of any expires_at value submitted, accepting the form submission and silently ignoring the submitted expires_at value rather than rejecting the submission.

### Requirement 20: Structured Input Validation and Error Handling

**User Story:** As a license server operator, I want every API endpoint to validate input and return structured errors, so that malformed or malicious requests fail safely and predictably instead of causing undefined behavior.

#### Acceptance Criteria

1. IF a request body is not valid JSON, THEN THE License_Server SHALL immediately terminate processing of that request and SHALL respond with a structured error indicating a malformed body, SHALL NOT attempt HMAC signature verification, and SHALL NOT proceed to any further validation step.
2. IF a request is made to an API endpoint using an HTTP method other than POST, THEN THE License_Server SHALL reject the request with a structured error and an HTTP 405 status.
3. IF a request is made to an undefined API route, THEN THE License_Server SHALL respond with a structured error and an HTTP 404 status.
4. WHEN the License_Server rejects a request for any validation, authentication, rate-limit, or business-rule reason, THE License_Server SHALL respond with the same error-code-field and message-field structure applied consistently across all endpoints and rejection reasons, and SHALL include both a machine-readable error code field and a human-readable message field in every such structured error response without exception.
5. IF a database connection or query failure occurs while processing a request, THEN THE License_Server SHALL respond with a structured error and an HTTP 500 status, SHALL log the failure with diagnostic detail server-side, and SHALL NOT expose internal error detail (stack traces, query text, credentials) in the API response.
6. THE License_Server SHALL validate that license_key values supplied in any API request match the format `SERB-XXXXX-XXXXX-XXXXX-XXXXX`.
7. IF a license_key format check fails, THEN THE License_Server SHALL reject the request with a structured validation error and SHALL NOT use the value in a database query. WHEN a license_key format check passes, THE License_Server SHALL proceed with normal request processing.
8. THE License_Server SHALL apply the following validation check order to every API request, stopping at the first failure: (1) HTTP method check, (2) route existence check, (3) request body size/JSON-validity check, (4) rate-limit check, (5) HMAC field-presence/format/timestamp/signature check, (6) required-field presence check, (7) field format checks (e.g. license_key format), (8) business-rule checks.
9. IF a request body exceeds 64 KB, THEN THE License_Server SHALL reject the request with a structured error and an HTTP 413 status without parsing the body as JSON.

### Requirement 21: Secrets and Environment Configuration

**User Story:** As a license server operator, I want all credentials and secrets stored outside of source control, so that database access, payment integration, and signing keys cannot leak through the codebase.

#### Acceptance Criteria

1. THE License_Server SHALL read the following configuration keys from environment-based configuration rather than from hardcoded source values: database credentials, the Razorpay API key, the Razorpay webhook secret, the HMAC Shared_Secret, the Trusted_Proxy_Range values (Requirement 8), and the rate-limit thresholds and window durations (Requirement 9).
2. THE License_Server codebase SHALL include a git-ignore rule preventing the environment configuration file from being committed to source control.
3. THE License_Server codebase SHALL include an example environment configuration template containing placeholder values for every required configuration key enumerated in Criterion 1, suitable for committing to source control.
4. WHEN an incoming request is received and a required environment configuration value is missing or empty, THE License_Server SHALL attempt to log a diagnostic error identifying the missing key without logging other configuration values, and SHALL respond to that request with a structured error and an HTTP 500 status regardless of whether that diagnostic logging attempt succeeds.

### Requirement 22: Audit Logging Coverage

**User Story:** As a license server operator, I want every state-changing action logged, so that I can reconstruct the full history of any license for support or dispute resolution.

#### Acceptance Criteria

1. THE License_Server SHALL append a License_Event for every Activation creation, every Activation deactivation, every License status transition, every accepted Razorpay_Webhook request as defined in Requirement 7, and every admin action performed against a License as enumerated in Requirement 18 and Requirement 19.
2. THE License_Server SHALL store, as JSON on each License_Event, the payload data specified by the criterion that creates that event type, including where applicable the prior and new field values, the raw request or webhook payload, or the reason for the transition, captured at the time the event is created.
3. THE License_Server SHALL treat license_events as append-only, providing no API or Admin_Panel action that updates or deletes an existing License_Event row.
4. IF the License_Server attempts to persist a License_Event when performing a state-changing action described in Criterion 1 and that persistence attempt fails, THEN THE License_Server SHALL still persist the associated Activation, License, or status change for that action, SHALL attempt to log the audit-logging failure server-side for later reconciliation, and SHALL proceed with the state change and SHALL NOT reject or roll back the action solely because the License_Event could not be persisted or because that secondary failure-logging attempt itself also fails.

## Provisional / Open Items

The following item is intentionally left open, per explicit user instruction, rather than being silently decided in this document:

1. **Exact API request/response JSON field names** for `/activate`, `/validate`, `/deactivate`, and `/webhook/razorpay` are provisional. They must be cross-checked against the existing WordPress client scaffold (`class-serb-license.php`, `serenity-license-manager` wrapper plugin) before being finalized. This document specifies field *semantics* (e.g. "a license_key value", "a site_hash value") but the design document should treat literal JSON key names as subject to change.

> Note: The Lazy_Check persistence question (self-healing vs. report-only) that was originally flagged as open has been RESOLVED — see Requirement 11 AC1/AC2 and AC4: the Lazy_Check persists (self-heals) a corrected status the first time it detects a grace or expired transition, using the same persistence rule as the Sweep_Job, so both mechanisms converge on the same stored state.
