# 02 — Database Design / ERD

MySQL 8.0, InnoDB, `utf8mb4_unicode_ci`. All timestamps UTC.

## 1. Conventions

| Convention | Decision | Rationale |
|---|---|---|
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` | InnoDB clusters on the PK. Random UUID PKs cause page splits and bloat every secondary index. At 10k tenants this matters. |
| Public identifiers | `ulid CHAR(26)` unique column on tenant-facing entities | Used in URLs so sequential IDs are not enumerable. Sortable, unlike UUIDv4. |
| Tenant column | `tenant_id BIGINT UNSIGNED NOT NULL`, FK cascade | Present on every tenant-owned table without exception. |
| Uniqueness | Always composite with `tenant_id` | A bare unique key leaks the existence of other tenants' rows through constraint violations. |
| Enums | `VARCHAR` + PHP backed enum + app validation | MySQL `ENUM` requires a DDL migration to add a value and orders by definition position. |
| Money | `amount_minor BIGINT` + `currency CHAR(3)` | No floats. |
| Soft deletes | `deleted_at` on user-controlled entities only | Not on ledgers, attempts, or audit rows — those are immutable history. |
| JSON columns | Provider metadata, API snapshots, flexible settings, capabilities | Never for relational core data. |

Laravel framework tables assumed present: `migrations`, `password_reset_tokens`,
`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications`.

## 2. High-level map

```
tenants ──< tenant_user >── users
   │                          │
   │                          └──< login_histories, impersonation_sessions
   │
   ├──< customers ──< customer_user >── users        (team member assignment)
   │        │
   │        ├──< customer_portal_user_customer >── customer_portal_users
   │        ├──1 brand_brains
   │        ├──< media_folders ──< media
   │        ├──< social_accounts
   │        └──< posts ──< post_targets ──< publication_attempts
   │                 │           └──< post_media
   │                 ├──< post_versions
   │                 ├──< post_approvals
   │                 └──< post_comments
   │
   ├──< social_app_credentials ──< social_connections ──< social_accounts
   ├──< subscriptions ──< invoices ──< invoice_lines
   │         └──< payments
   ├──1 ai_credit_accounts ──< ai_credit_transactions
   ├──< ai_generations
   ├──< audit_logs
   ├──1 branding_settings
   └──< domains

plans ──< plan_prices, plan_features
coupons ──< coupon_redemptions
feature_flags ──< feature_flag_tenant
webhook_events   (provider-agnostic inbox)
oauth_states     (short-lived CSRF/PKCE state)
```

## 3. Deviations from the suggested table list

The brief supplied a candidate list and asked us to improve it. Four deliberate changes:

1. **`scheduled_publications` is merged into `post_targets`.** Two tables would both own
   "when does this go out and what happened" — duplicated state that will drift. A
   `post_target` *is* the scheduled publication: one row per (post, social account) holding
   its own status, schedule, retry counter and external post ID.
2. **`social_connections` and `social_accounts` are both kept, with distinct meanings.**
   A connection is one OAuth grant (one authorised identity, holds the tokens). An account
   is one publishable destination derived from it (a Page, an IG Business account, a
   channel). One Meta connection commonly yields several Pages and IG accounts. Collapsing
   them would duplicate tokens across rows and make reconnect ambiguous.
3. **`plan_prices` is split out of `plans`.** Monthly/yearly, multi-currency, and gateway
   plan IDs are price attributes. Without the split, a yearly INR price requires a
   duplicate plan row and the entitlement logic has to dedupe them.
4. **`customer_agency_relationships` is not created in V1.** See `03-TENANCY.md` §8 —
   `customers` *is* the agency-scoped workspace. A nullable `customer_identity_id` reserves
   the future path at zero cost.

## 4. Tenancy and identity

### `tenants`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| ulid | char(26) | unique |
| parent_tenant_id | bigint null | FK tenants — reseller hierarchy, unused in V1 |
| type | varchar(20) | `agency`\|`reseller` |
| name | varchar(160) | |
| slug | varchar(80) | unique; validated against `config('tenancy.reserved_slugs')` |
| status | varchar(20) | `trialing`\|`active`\|`grace`\|`suspended`\|`cancelled` |
| owner_user_id | bigint null | FK users |
| timezone | varchar(64) | IANA, default `UTC` |
| locale | varchar(10) | |
| currency | char(3) | |
| trial_ends_at | timestamp null | |
| suspended_at / cancelled_at / purge_after | timestamp null | retention clock |
| settings | json | |
| timestamps, deleted_at | | |

Indexes: `(status)`, `(parent_tenant_id)`, `(purge_after)`.

### `users`
Agency team members and Super Admins. Guard `web`.

| Column | Type | Notes |
|---|---|---|
| id, ulid | | |
| name, email, password | | `UNIQUE (email)` — global, users are cross-tenant principals |
| email_verified_at | timestamp null | |
| is_super_admin | boolean | default false |
| phone, avatar_path, timezone, locale | | |
| two_factor_secret | text null | **encrypted** |
| two_factor_recovery_codes | text null | **encrypted** |
| two_factor_confirmed_at | timestamp null | |
| status | varchar(20) | `active`\|`disabled` |
| last_login_at | timestamp null | |
| timestamps, deleted_at | | |

A user may belong to several tenants (an agency contractor). Membership lives in
`tenant_user`; `users` itself has **no** `tenant_id`.

### `tenant_user`
`(tenant_id, user_id)` unique. Columns: `status` (`invited`\|`active`\|`suspended`),
`invited_by_user_id`, `invited_at`, `joined_at`, timestamps.

### `invitations`
`tenant_id`, `email`, `role_id`, `token_hash` (sha256 — never the raw token),
`customer_ids` json, `expires_at`, `accepted_at`, `invited_by_user_id`.
Index `(tenant_id, email)`, unique `(token_hash)`.

### `customer_portal_users`
Client logins. Guard `customer`. Separate table so a portal session can never resolve to a
`User` model.

`id, ulid, tenant_id, name, email, password, email_verified_at, status, two_factor_secret
(encrypted), two_factor_recovery_codes (encrypted), two_factor_confirmed_at, last_login_at,
invited_by_user_id, timestamps, deleted_at`.
Unique `(tenant_id, email)`.

### `customer_portal_user_customer`
`customer_portal_user_id`, `customer_id`, `tenant_id`, `role` (`approver`\|`viewer`),
timestamps. Unique `(customer_portal_user_id, customer_id)`.

## 5. Customers / brands

### `customers`
| Column | Type | Notes |
|---|---|---|
| id, ulid | | |
| tenant_id | bigint | FK cascade |
| customer_identity_id | bigint null | reserved; no table in V1 |
| name, legal_name | varchar | |
| slug | varchar(80) | `UNIQUE (tenant_id, slug)` |
| industry, website | varchar null | |
| timezone | varchar(64) | defaults to tenant timezone; drives scheduling |
| status | varchar(20) | `active`\|`archived` |
| logo_media_id | bigint null | FK media, nullable |
| contact_name / contact_email / contact_phone | | |
| settings | json | approval requirements, default targets |
| timestamps, deleted_at | | |

Indexes: `(tenant_id, status)`, unique `(tenant_id, slug)`.

### `customer_user`
Team-member-to-brand assignment. `tenant_id`, `customer_id`, `user_id`, timestamps.
Unique `(customer_id, user_id)`.

A user with no rows here and the `customers.view_all` permission sees all brands; a user
without that permission sees only assigned brands. Both paths are enforced in policies, not
only in queries.

## 6. Access control (spatie/laravel-permission, teams on)

`permissions`, `roles`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

- `roles.team_id` = `tenant_id`. `NULL` marks a system role template.
- `roles` unique on `(name, guard_name, team_id)`.
- Two guards: `web` and `customer`.
- Permission catalogue is code-owned in `config/permissions.php` and synced by a seeder, so
  adding a permission is a code change, not a manual DB edit.

## 7. Social layer

### `social_app_credentials`
Per-tenant developer app credentials.

`id, tenant_id, provider_key, label, client_id (encrypted), client_secret (encrypted),
extra (encrypted json), redirect_override, is_active, verified_at, last_verify_error,
created_by_user_id, timestamps, deleted_at`.
Unique `(tenant_id, provider_key, label)`.

Secrets use Laravel `encrypted` casts, are listed in `$hidden`, are excluded from every API
resource, and are write-only in the UI (never re-rendered). Super Admin has **no** screen
that displays them.

### `social_connections`
One OAuth grant.

| Column | Notes |
|---|---|
| id, ulid, tenant_id | |
| customer_id | nullable — set when the connection is assigned to a brand |
| provider_key | `facebook`, `instagram`, `linkedin`, `x`, `youtube` |
| social_app_credential_id | nullable — null means platform-default app |
| external_user_id, name, email, avatar_url | identity on the provider |
| scopes | json — granted scopes as returned, not as requested |
| access_token | text, **encrypted** |
| refresh_token | text null, **encrypted** |
| token_type, expires_at, refresh_expires_at | |
| status | `active`\|`expired`\|`revoked`\|`needs_reconnect`\|`error` |
| last_refreshed_at, last_checked_at, last_error_code | |
| connected_by_user_id, meta json, timestamps, deleted_at | |

Unique `(tenant_id, provider_key, external_user_id)`. Index `(status, expires_at)` to drive
the refresh sweeper.

### `social_accounts`
One publishable destination.

| Column | Notes |
|---|---|
| id, ulid, tenant_id, customer_id | |
| social_connection_id | FK |
| provider_key | denormalised for query convenience |
| account_type | `page`\|`ig_business`\|`profile`\|`channel`\|`organization` |
| external_id, name, username, avatar_url | |
| page_access_token | text null, **encrypted** — Facebook Page tokens differ from the user token |
| token_expires_at | |
| capabilities | json — resolved at connect time from `config/social.php` plus granted scopes |
| scopes | json |
| timezone | |
| status | `active`\|`paused`\|`disconnected` |
| health | `healthy`\|`warning`\|`failed` |
| last_published_at, last_error_code, last_error_at | |
| meta json, timestamps | |

Unique `(tenant_id, provider_key, external_id)`.
Index `(tenant_id, customer_id, status)`, `(health)`.

**Not soft-deleted.** Disconnect sets `status = disconnected` and nulls the tokens. A
soft-deleted row would collide with the unique key on reconnect, which is exactly the
common path.

### `oauth_states`
Short-lived CSRF and PKCE state. `state_hash` unique, `tenant_id`, `user_id`,
`provider_key`, `customer_id` null, `code_verifier` (encrypted), `redirect_to`,
`expires_at`, `consumed_at`, `created_at`.
Single-use: consumption is an atomic conditional update. Expired rows are pruned nightly.

## 8. Publishing

### `posts` — master content
| Column | Notes |
|---|---|
| id, ulid, tenant_id, customer_id | |
| created_by_user_id | |
| title | internal label, nullable |
| status | workflow state — see `06-PUBLISHING-ENGINE.md` §4 |
| content_type | `text`\|`image`\|`carousel`\|`video`\|`reel`\|`story`\|`link`\|`document`\|`poll` |
| body | text |
| link_url, first_comment | |
| scheduled_at | timestamp null, **UTC** |
| timezone | snapshot of the timezone the author scheduled in |
| publish_mode | `now`\|`scheduled`\|`queue` |
| approval_required | boolean |
| source | `manual`\|`ai`\|`csv`\|`api`\|`recurring` |
| recurring_rule_id | nullable, Phase 3 |
| submitted_at, approved_at, published_at | |
| meta json, timestamps, deleted_at | |

Indexes: `(tenant_id, customer_id, status)`, `(tenant_id, scheduled_at)`,
`(tenant_id, status, scheduled_at)`.

### `post_versions`
`post_id, tenant_id, version, body, meta json, created_by_user_id, created_at`.
Unique `(post_id, version)`. Written on each edit after first submission, so an approval
always refers to content that can be reconstructed.

### `post_targets` — one row per (post, social account)
This is the engine's unit of work.

| Column | Notes |
|---|---|
| id, ulid, tenant_id, post_id, social_account_id, provider_key | |
| status | `pending`\|`scheduled`\|`processing`\|`published`\|`failed`\|`cancelled`\|`skipped`\|`paused_reconnect`\|`paused_billing`\|`needs_verification` |
| body_override | text null — null means inherit `posts.body` |
| meta_override | json — title, privacy, thumbnail, first comment, per-platform fields |
| scheduled_at | UTC; normally mirrors the post, may be staggered |
| dispatched_at, published_at | |
| external_post_id, external_url | provider's ID — the idempotency anchor |
| attempts, max_attempts, next_attempt_at | |
| last_error_class, last_error_code, last_error_message | sanitized |
| idempotency_key | char(64), **unique** |
| locked_at, locked_by | claim lock |
| timestamps | |

Unique `(post_id, social_account_id)` and `(idempotency_key)`.
Index `(status, scheduled_at)` — the due-work query.
Index `(tenant_id, status)`, `(status, locked_at)` for stale-lock recovery.

### `post_media`
`tenant_id, post_id, media_id, post_target_id (null = applies to all targets), sort_order,
role (primary|thumbnail|cover), meta json`.
Index `(post_id, post_target_id, sort_order)`.

### `publication_attempts` — immutable
`tenant_id, post_target_id, attempt_no, started_at, finished_at, outcome
(success|retryable_failure|permanent_failure), http_status, error_class, error_code,
error_message, provider_request_id, response_snapshot json (redacted), created_at`.
Index `(post_target_id, attempt_no)`. No updates, no soft deletes.

### `post_approvals` — immutable
`tenant_id, post_id, stage (internal|client), action
(submitted|approved|rejected|changes_requested), actor_type
(user|customer_portal_user|system), actor_id, comment, from_status, to_status, created_at`.
Index `(post_id, created_at)`.

### `post_comments`
`tenant_id, post_id, author_type, author_id, parent_id, body, is_internal, timestamps,
deleted_at`. `is_internal = true` is never exposed to the portal — enforced in the query
*and* in the portal policy.

## 9. Media

### `media_folders`
`tenant_id, customer_id, parent_id, name, system_key (logos|products|reels|testimonials|
brand_assets|campaign_assets, nullable), timestamps, deleted_at`.
Unique `(customer_id, parent_id, name)`.

### `media`
`id, ulid, tenant_id, customer_id, folder_id, disk, path, original_name, mime_type,
extension, size_bytes, width, height, duration_seconds, checksum (sha256), thumbnail_path,
variants json, status (uploading|processing|ready|failed), uploaded_by_user_id, meta json,
timestamps, deleted_at`.

Index `(tenant_id, customer_id, status)`, `(tenant_id, checksum)` for dedupe and for
storage-quota accounting.

`disk` is stored per row so a migration from local to S3 can proceed file-by-file without a
flag day.

## 10. AI

### `brand_brains`
One per customer. Unique `(customer_id)`.

Scalar columns: `tenant_id, customer_id, business_description, industry, website,
brand_tone, brand_voice_notes, primary_language`.

JSON columns: `target_audience, locations, products, services, usps, competitors, ctas,
forbidden_words, preferred_keywords, brand_colors, goals, content_themes, languages, extra`.

JSON is appropriate here: these are list-shaped, free-form, only ever read as a whole to
build a prompt, and never joined or aggregated. If forbidden-word enforcement later needs
cross-brand reporting, promote that one field to a table.

### `ai_credit_accounts`
One per tenant. `tenant_id (unique), balance, reserved, monthly_allowance, period_start,
period_end, rollover_enabled, rollover_cap, timestamps`.

`balance` is a **cache** of the ledger, not the source of truth. A reconciliation command
recomputes it from `ai_credit_transactions` and reports drift.

### `ai_credit_transactions` — immutable ledger
`id, ulid, tenant_id, ai_credit_account_id, type (grant|reset|reserve|release|consume|
refund|adjustment), amount (signed bigint), balance_after, reference_type, reference_id,
idempotency_key (unique, nullable), user_id, customer_id, actor_type, note, meta json,
created_at`.

Index `(tenant_id, created_at)`, unique `(idempotency_key)`.

### `ai_generations`
`id, ulid, tenant_id, customer_id, user_id, feature, provider, model, status
(pending|succeeded|failed), prompt_tokens, completion_tokens, credits_charged, latency_ms,
error_code, request_snapshot json, response_snapshot json, created_at`.

Snapshots are subject to a retention window (`config('ai.snapshot_retention_days')`) and are
purged on schedule — they contain customer business content.

## 11. Billing

### `plans`
`id, ulid, name, slug (unique), description, is_public, is_active, trial_days, sort_order,
timestamps, deleted_at`.

### `plan_prices`
`plan_id, billing_period (monthly|yearly), currency, amount_minor, gateway,
gateway_plan_id, is_active, timestamps`.
Unique `(plan_id, billing_period, currency, gateway)`.

### `plan_features`
`plan_id, key, value_type (boolean|limit|unlimited), value bigint null, timestamps`.
Unique `(plan_id, key)`. `key` is validated against `config('entitlements.keys')`.

### `subscriptions`
`id, ulid, tenant_id, plan_id, plan_price_id, status (trialing|active|past_due|grace|
cancelled|expired), gateway (razorpay|manual), gateway_subscription_id,
gateway_customer_id, quantity, trial_ends_at, current_period_start, current_period_end,
grace_ends_at, cancelled_at, ends_at, cancel_at_period_end, created_by_user_id, notes,
meta json, timestamps, deleted_at`.

Index `(tenant_id, status)`, unique `(gateway, gateway_subscription_id)`.
A tenant has at most one non-terminal subscription; enforced in the service layer and
asserted by a test.

### `subscription_overrides`
Super Admin entitlement overrides, tenant-scoped and independent of plan.
`tenant_id, key, value_type, value, reason, expires_at, created_by_user_id, timestamps`.
Unique `(tenant_id, key)`. Every write emits an audit log entry.

### `payments`
`id, ulid, tenant_id, subscription_id, invoice_id, gateway, gateway_payment_id,
gateway_order_id, amount_minor, currency, status (created|authorized|captured|failed|
refunded), method, failure_code, failure_reason, paid_at, meta json (redacted), timestamps`.
Unique `(gateway, gateway_payment_id)`.

### `invoices` / `invoice_lines`
`invoices`: `id, ulid, tenant_id, subscription_id, number (unique), status (draft|open|paid|
void|uncollectible), currency, subtotal_minor, discount_minor, tax_minor, total_minor,
coupon_id, issued_at, due_at, paid_at, pdf_path, billing_details json, timestamps`.

`invoice_lines`: `invoice_id, tenant_id, description, quantity, unit_amount_minor,
amount_minor, period_start, period_end, meta json`.

Invoice numbering is sequential per financial year, allocated inside a transaction with a
row lock. Gaps are unacceptable for accounting; `AUTO_INCREMENT` produces gaps on rollback.

### `coupons` / `coupon_redemptions`
`coupons`: `code (unique), type (percent|fixed), value, currency, duration
(once|repeating|forever), duration_months, max_redemptions, redemptions_count, applies_to
json, starts_at, expires_at, is_active, timestamps`.

`coupon_redemptions`: `coupon_id, tenant_id, subscription_id, invoice_id, redeemed_at`.
Unique `(coupon_id, tenant_id)` when the coupon is once-per-tenant.

### `webhook_events` — provider-agnostic inbox
`provider (razorpay|meta|linkedin|…), event_id, event_type, signature_verified boolean,
payload json, status (pending|processed|failed|ignored), attempts, processed_at,
error_message, received_at`.
Unique `(provider, event_id)` — this is the deduplication guarantee.

## 12. Platform and operations

### `audit_logs` — append-only
`tenant_id (nullable for platform actions), actor_type (user|customer_portal_user|system),
actor_id, impersonator_user_id, action, auditable_type, auditable_id, old_values json,
new_values json, ip, user_agent, request_id, created_at`.

Index `(tenant_id, created_at)`, `(auditable_type, auditable_id)`, `(actor_type, actor_id)`.

Values pass through a redaction filter keyed on `config('audit.redacted_attributes')` —
passwords, tokens, secrets, recovery codes and OTP secrets are replaced with `[redacted]`
before write. No `updated_at`, no `deleted_at`, no update path in the model.

### `login_histories`
`tenant_id, authenticatable_type, authenticatable_id, event (login|logout|failed|
two_factor_failed|password_reset|locked), ip, user_agent, device, platform, browser,
session_id, created_at`. Index `(authenticatable_type, authenticatable_id, created_at)`.

### `impersonation_sessions`
`super_admin_user_id, target_type, target_id, tenant_id, reason, started_at, ended_at, ip,
user_agent`. Start and end are both audited.

### `feature_flags` / `feature_flag_tenant`
`feature_flags`: `key (unique), name, description, is_enabled_globally, rollout json,
timestamps`.
`feature_flag_tenant`: `feature_flag_id, tenant_id, is_enabled`. Unique pair.

Resolution order: tenant override, then plan feature, then global flag, then config default.

### `domains`
`tenant_id, hostname (unique), type (subdomain|custom), is_primary, verification_token,
verified_at, ssl_status, timestamps`. Schema only in V1; no host-based resolution ships.

### `branding_settings`
`tenant_id (unique), app_name, logo_media_id, favicon_media_id, primary_color,
secondary_color, login_background_media_id, email_from_name, email_from_address,
support_email, custom_css, timestamps`. Schema and a `BrandingResolver` ship in V1 so no
Blade template hardcodes platform branding; the editing UI does not.

### `notification_preferences`
`tenant_id, user_id, event_key, channel (database|mail), enabled`.
Unique `(user_id, event_key, channel)`.

## 13. Migration ordering

Foreign keys require this order:

```
1. users, tenants (tenants.owner_user_id FK added in a later ALTER — circular)
2. tenant_user, invitations
3. spatie permission tables
4. customers, customer_user
5. customer_portal_users, customer_portal_user_customer
6. media_folders, media   (then ALTER customers ADD FK logo_media_id)
7. social_app_credentials, social_connections, social_accounts, oauth_states
8. plans, plan_prices, plan_features, coupons
9. subscriptions, subscription_overrides, invoices, invoice_lines, payments,
   coupon_redemptions
10. ai_credit_accounts, ai_credit_transactions, ai_generations, brand_brains
11. posts, post_versions, post_targets, post_media, publication_attempts,
    post_approvals, post_comments
12. audit_logs, login_histories, impersonation_sessions, webhook_events,
    feature_flags, feature_flag_tenant, domains, branding_settings,
    notification_preferences
```

The `tenants.owner_user_id` / `users` circularity is resolved by creating both tables
first and adding that one foreign key in a follow-up migration.

## 14. Index review checkpoints

Before Phase 3 sign-off, `EXPLAIN` these and confirm no full scans:

- Due-work sweep: `post_targets WHERE status='scheduled' AND scheduled_at <= NOW()`
  — must use `(status, scheduled_at)`.
- Calendar month view: `posts WHERE tenant_id=? AND customer_id=? AND scheduled_at BETWEEN ? AND ?`
- Token refresh sweep: `social_connections WHERE status='active' AND expires_at <= ?`
- Portal queue: `posts WHERE tenant_id=? AND customer_id IN (?) AND status='CLIENT_REVIEW'`
- Credit balance reconciliation: `SUM(amount) FROM ai_credit_transactions WHERE tenant_id=?`
- Stale lock recovery: `post_targets WHERE status='processing' AND locked_at < ?`
