# Phase 1 — Foundation Implementation Checklist

**Do not begin until Phase 0 is reviewed and approved.**

Ordered so that each step's dependencies are already in place. Migrations precede business
logic throughout, per the project rules.

---

## Step 0 — Environment prerequisites ✅ *(complete 2026-09-01)*

- [x] Enable PHP `curl` extension
- [x] Enable PHP `zip` extension
- [x] Enable PHP `intl` and `exif`
- [x] Install Composer — 2.10.3, signature-verified
- [x] Database **MariaDB 12.3.2** — `SKIP LOCKED` verified working
- [x] Confirm Hostinger offers PHP 8.3+ — **confirmed by the user**
- [x] `git init`; `.gitignore` verified to exclude `.env` before any commit

**Deviations from plan, and why:**

- **PHP 8.4.25 installed to `C:\php84`, alongside the existing 8.2** rather than
  replacing it, so the user's other project (`newshub_cms`) keeps working. Select it per
  shell with `export PATH="/c/php84:$PATH"`.
- **Laravel 13.29, not 12.** Laravel 13 requires PHP `^8.3`; Laravel 12 is already in its
  security-fix-only window and reaches EOL in early 2027. Starting a greenfield product
  there was the worse trade, so PHP was upgraded instead.
- **An isolated MariaDB instance on port 3307** (`D:\mariadb-smm\data`), initialised
  fresh rather than using the existing instance on 3306, because the existing instance's
  root password was not available. It touches neither that instance nor `newshub_cms`.
  To consolidate later: run the provisioning SQL on 3306 and change `DB_PORT`.
- **Neither instance is registered as a Windows service**, so both stop on reboot.
  Start the project one with:
  `"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --defaults-file=D:\mariadb-smm\data\my.ini --console`

## Step 1 — Scaffold ✅ *(complete)*

- [x] Verified current stable Laravel release and PHP floor, then `composer create-project`
- [x] Installed: Fortify 1.39, spatie/laravel-permission 8.3 (**teams enabled**),
      Livewire **4.4** (not 3 — 4 is current), Pest **4.7.8** (not 5 — Pest 5 requires
      PHPUnit 13 while the Laravel 13 skeleton pins 12), Larastan 3.10, Pint 1.30
- [x] Configured `.env` / `.env.example` — **verified no secret in the example**
- [x] `config/tenancy.php`, `entitlements.php`, `permissions.php`
- [ ] `config/media.php`, `billing.php`, `audit.php`, `branding.php` *(Steps 7–11)*
- [ ] `config/social.php`, `publishing.php`, `ai.php` *(Phases 2–4)*
- [x] `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`
- [x] Pint passing; Larastan configured at level 5 and **passing with 0 errors**
- [ ] CI: install, Pint, Larastan, Pest, `composer audit`
- [x] Tailwind — ships with the Laravel 13 skeleton

## Step 2 — Migrations ✅ *(complete — 18 migrations, 45 tables)*

- [x] `users` (2FA columns, ULID, `is_super_admin`), `tenants`
- [x] `ALTER tenants ADD FK owner_user_id` *(separate migration — circular dependency)*
- [x] `tenant_user`, `invitations`
- [x] spatie permission tables, **teams enabled**, `UNIQUE (team_id, name, guard_name)` verified
- [x] `customers`, `customer_user`
- [x] `customer_portal_users`, `customer_portal_user_customer`
- [x] `media_folders`, `media`; then `ALTER customers ADD FK logo_media_id`
- [x] `plans`, `plan_prices`, `plan_features`, `coupons`
- [x] `subscriptions`, `subscription_overrides`, `invoices`, `invoice_lines`, `payments`, `coupon_redemptions`
- [x] `ai_credit_accounts`, `ai_credit_transactions`
- [x] `audit_logs`, `login_histories`, `impersonation_sessions`
- [x] `webhook_events`, `feature_flags`, `feature_flag_tenant`
- [x] `domains`, `branding_settings`, `notification_preferences`, `notifications`
- [x] `customer_password_reset_tokens` *(added — portal users need their own broker table)*
- [x] Every `tenant_id` indexed; composite uniques verified against the live schema
- [x] `migrate:fresh` runs clean; `migrate:rollback` returns to zero tables

**Correction made during implementation:** `coupon_redemptions` originally carried
`UNIQUE (coupon_id, tenant_id)` to enforce `once_per_tenant`. That is wrong — the flag is
per-coupon configurable, and a repeating coupon legitimately produces one redemption per
billing period, so the index would have broken renewals. Replaced with
`UNIQUE (coupon_id, invoice_id)`, a guarantee storage can make unconditionally;
`once_per_tenant` is enforced in `RedeemCouponService` under a row lock.

## Step 3 — Tenancy core ✅ *(complete — isolation suite green)*

- [x] `TenantContext` registered as a **scoped** singleton (Octane-safe)
- [x] `TenantScope` global scope
- [x] `BelongsToTenant`: auto-stamp, missing-context exception, reassignment guard,
      `acrossTenants()`, `forTenant()`, `belongsToCurrentTenant()`
- [x] `ResolveTenant` middleware — membership re-read per request; **request-supplied
      tenant ids ignored**
- [x] `EnsureTenantActive` middleware, billing routes excluded
- [x] spatie team binding via `setPermissionsTeamId()`
- [x] Models: `Tenant`, `TenantUser`, `User`, `CustomerPortalUser`, `Customer`, `Media`,
      `MediaFolder` + factories
- [x] `customer` guard, provider and password broker wired in `config/auth.php`
- [x] **26 tenant-isolation tests passing**
- [x] Model-registry test: every `tenant_id` table has a registered model or a documented
      exemption
- [ ] Larastan/CI rule banning `withoutGlobalScopes` outside allow-listed namespaces
      *(config list exists; the enforcing test is outstanding)*
- [ ] Base `TenantPolicy` *(Step 5, with the rest of RBAC)*

**Finding:** `Model::preventSilentlyDiscardingAttributes()` means an injected `tenant_id`
**throws** in dev/test but is silently discarded in production. Both refuse the write, so
the security property holds either way — but the tests now assert both modes explicitly
rather than assuming one.

## Step 3 — Tenancy core *(highest-risk step — do it carefully)*

- [ ] `TenantContext` scoped singleton
- [ ] `TenantScope` global scope
- [ ] `BelongsToTenant` trait: auto-fill, missing-context exception, reassignment guard, `acrossTenants()`
- [ ] `ResolveTenant` middleware — session/membership based; **request-supplied tenant IDs ignored**
- [ ] `EnsureTenantActive` middleware with billing-route exclusions
- [ ] `SetPermissionsTeamId` middleware
- [ ] Tenant switching (regenerates the session ID)
- [ ] Base `TenantPolicy` asserting tenant + assignment + permission
- [ ] **`tests/Feature/Tenancy/` — all 10 cases from `03-TENANCY.md` §7**
- [ ] Model-registry test: every model with `tenant_id` uses `BelongsToTenant`
- [ ] Larastan rule or CI grep banning `withoutGlobalScopes` outside allow-listed namespaces

> Do not proceed to Step 4 until the isolation suite is green. Everything after this point
> assumes it.

## Step 4 — Authentication *(largely complete)*

- [x] Fortify on the `web` guard, with `authenticateUsing` extended so a **disabled
      account cannot log in even with the correct password** (returns null rather than a
      distinct error, so it is indistinguishable from a wrong password)
- [x] `customer` guard, provider and its own password-reset broker table
- [x] Registration → tenant provisioning → trial start, in one transaction
- [x] Email verification enabled; login / logout / password reset routes live
- [x] Minimal auth Blade views *(deliberately unstyled — Step 13 restyles them)*
- [x] 2FA (TOTP) configured with `confirm: true`, so an unproven secret never gates login
- [x] Password confirmation available for sensitive actions
- [x] Login throttling (5/min per email+IP) — returns a hard **429**
- [x] `login_histories` recording, asserted to never contain the attempted password
- [x] `EnsureSuperAdmin` middleware, requiring `is_super_admin` **and** confirmed 2FA
- [x] Middleware groups `agency` / `portal` / `admin` registered in `bootstrap/app.php`
- [ ] 2FA enrolment UI and recovery-code display *(Step 13)*
- [ ] Session listing and revocation *(needs the `guard` column populated by a custom
      session handler — column exists, handler outstanding)*
- [x] Tests: 19 covering registration, login, disabled accounts, throttling, guard
      separation, and password non-storage

**Two findings, both fixed:**

1. **Auth events were recorded twice.** Laravel auto-discovers listeners in
   `app/Listeners`, and that ran *alongside* the explicit `Event::subscribe()`, so every
   login and failure wrote two rows. The listener moved to `app/Domain/Audit/Listeners/`
   — out of the discovery path, and where the module structure says it belongs.
2. **`login_histories.authenticatable_*` were NOT NULL**, so a failed login against an
   unknown address — the event most worth recording — crashed on insert. Now nullable,
   with an `attempted_email` column added so credential stuffing is visible.

**Known limitation:** Fortify throttles via route middleware that aborts with 429 and does
**not** dispatch `Illuminate\Auth\Events\Lockout`, so no `locked` row is written. A lockout
shows as a run of consecutive `failed` events instead. Recording the 429 itself means
wrapping Fortify's throttle middleware; deferred to Step 12, where the admin security
screen actually consumes this.

**Deviation:** `laravel/passkeys` arrived as a Fortify dependency. Its feature is
**disabled** — a second credential type with its own recovery and support burden is not in
V1 scope. The table ships so enabling it later is a config change. Fortify's published
`add_two_factor_columns_to_users_table` migration was **deleted**: those columns are
already declared in the users migration.

## Step 5 — RBAC *(foundation complete; policies outstanding)*

- [x] `config/permissions.php` catalogue — **61 permissions** across tenant, platform and
      portal scopes
- [x] `PermissionCatalogue` reader with `*` / `except` / explicit template resolution, which
      **throws on an unknown permission** rather than silently granting nothing
- [x] `SyncPermissionCatalogueService` + seeder, idempotent and safe on every deploy;
      reports orphans rather than deleting them
- [x] `CreateTenantRolesService` — roles seeded per tenant with `team_id`, on both guards
- [x] `ProvisionTenantService` — the single path shared by signup and manual activation
- [x] `InteractsWithCustomers` trait (assignment checks, per-request cache)
- [x] `TenantScopedPolicy` base encoding the three-part check once — tenant match, brand
      assignment, permission — so no policy can forget a leg
- [x] `CustomerPolicy`, `MediaPolicy`, `MediaFolderPolicy`, `CustomerPortalUserPolicy`
- [x] Policies attached via `#[UsePolicy]` attributes — Laravel's convention discovery
      only looks in `App\Models`, so domain-namespaced models need explicit binding
- [ ] Route-coverage test *(deferred until the agency routes exist in Step 13)*
- [x] Tests: 11 provisioning + 8 policy, covering role isolation between tenants, owner
      permissions, the two-dimensional check, and the assertion that **platform
      permissions never reach a tenant role**

**Policy design notes:** brand deletion requires the brand to be *archived* first — a
deliberate speed bump on an irreversible action. Seeded media folders cannot be renamed or
deleted, because other features reference them by `system_key` and would break silently.

**Finding, fixed:** `spatie`'s `assignRole(null)` is silently ignored. If the owner role
template were missing, provisioning would have produced a tenant whose owner held no
permissions — an account nobody could administer, which would look fine until someone
tried to use it. `ProvisionTenantService` now throws `MissingOwnerRoleException` and rolls
the whole transaction back.

## Step 6 — Agency & brand management *(in progress)*

- [x] `CreateCustomerService` — entitlement-guarded, seeds system folders, assigns the
      creator, unique per-tenant slug, fully transactional
- [x] `config/media.php` — disks, MIME allow-list, SVG off by default, system folders
- [x] `UpdateCustomerService` — update, archive, unarchive, with an explicit allow-list so
      slug, status and `tenant_id` stay lifecycle-owned
- [x] `InviteTeamMemberService` / `AcceptInvitationService` — token hashed with SHA-256 and
      never stored raw, single-use via an atomic claim, bound to the invited address
- [x] `InvitePortalUserService` — brand-scoped client logins with per-brand roles
- [ ] Tenant settings and timezone management
- [ ] Member roles and deactivation (invitation assigns a role; editing one later is next)
- [x] Tests: 10 brand creation, 6 lifecycle, 13 invitation, 8 portal user

**Design decisions worth recording:**

- **Archiving frees a `brands.max` slot; unarchiving re-checks it.** An agency that
  downgraded while a brand slept does not get the slot back for free.
- **Team-member limits are checked at BOTH invite and accept time.** Checking only at accept
  would let an agency send five invitations against one seat and produce four confusing
  failures later; checking only at invite would ignore seats filled in the interim.
- **Re-inviting an address revokes the outstanding invitation.** Two live tokens for one
  seat is a hole.
- **An invitation is bound to its email address**, so a forwarded invitation email cannot be
  redeemed by whoever opens it.
- **Brand ids from a request are proven to belong to the tenant** before any grant is
  written, in both invitation services — the global scope cannot see ids that arrive in a
  payload.
- **A portal login must be granted at least one brand.** One with none sees nothing, which
  reads as a broken account rather than a restricted one.

**Bug found and fixed:** `EntitlementResolver` joined subscriptions without an ordering, so
a tenant holding more than one subscription row resolved a limit non-deterministically. The
one-subscription invariant is enforced in the service layer, but a bad migration or manual
fix could violate it, so the query now orders by most recent.

## Step 8 — Entitlements *(brought forward — Step 6 depends on it)*

- [x] `Entitlement` value object carrying its own provenance (`override` / `plan` /
      `default`), so "why can this tenant do that?" is answerable in support
- [x] `EntitlementResolver` with override → plan → default precedence and explicit cache
      invalidation
- [x] `guard()` enforcement in the service layer, throwing `EntitlementExceeded` that names
      the limit hit so the UI can offer a specific upgrade
- [x] Entitlements still granted while `past_due` / `grace` — revoking product the moment a
      card fails turns a recoverable lapse into a cancellation
- [x] Tests: 15 covering precedence, expiry, terminal statuses, unlimited, and per-tenant
      usage counting

> **Bug found and fixed — this one mattered.** Entitlement keys contain dots
> (`brands.max`), which collide with Laravel's config dot-notation:
> `config('entitlements.keys.brands.max')` traverses into nested arrays that do not exist
> and returns `null`. Every usage counter therefore fell through to `0`, and **limits were
> silently not enforced at all**. Lookups now index the array by literal key. The tests
> that caught it assert real counts rather than just "no exception".

## Step 7 — Media library foundation

- [ ] Private disk config; server-generated paths
- [ ] Upload with MIME sniffing and extension allow-list (SVG off by default)
- [ ] Folders including seeded system folders
- [ ] Image variants and thumbnails via GD on the `media` queue
- [ ] Signed, expiring delivery URLs behind a policy check
- [ ] Storage quota enforcement from entitlements
- [ ] Soft delete plus storage cleanup on purge
- [ ] Tests: cross-tenant media access blocked; quota enforced; disallowed types rejected

## Step 8 — Plans, entitlements, subscriptions

- [ ] Plan/price/feature seeders with the reference tiers from `09-BILLING.md` §3
- [ ] `EntitlementResolver` with override → plan → default and explicit cache invalidation
- [ ] `guard()` enforcement in services; `EntitlementExceeded` rendered with an upgrade CTA
- [ ] Subscription model and lifecycle service
- [ ] `ManualGateway` implementing `PaymentGatewayInterface`
- [ ] `billing:process-lifecycle` hourly command (trial → grace → suspended), idempotent
- [ ] Tests: precedence, limit enforcement, override without plan change, lifecycle timing

## Step 9 — Razorpay foundation

- [ ] `PaymentGatewayInterface` + `RazorpayGateway` skeleton **[VERIFY against live docs]**
- [ ] Checkout initiation and **server-side** signature verification
- [ ] Webhook endpoint: raw-body HMAC, `hash_equals`, dedupe on `(provider, event_id)`, queue, return 200
- [ ] `webhook_events` inbox + `ProcessRazorpayWebhook` job
- [ ] Invoice and payment records; gapless invoice numbering under a row lock
- [ ] `billing:reconcile-subscriptions` daily
- [ ] Tests: tampered signature rejected, duplicate event processed once, reconciliation corrects drift

## Step 10 — AI credit foundation *(ledger only; features are Phase 4)*

- [ ] `ai_credit_accounts` opened on tenant creation with the plan allowance
- [ ] Ledger service: `grant`, `reserve`, `commit`, `release`, `adjustment`
- [ ] `ai:reset-monthly-credits` hourly, per-tenant anniversary, `ShouldBeUnique`
- [ ] Reconciliation command comparing ledger sum to cached balance
- [ ] Tests: no overspend under concurrency, idempotency keys, reset with rollover cap

## Step 11 — Audit logs & notifications

- [ ] Append-only `AuditLogger` with redaction against `config('audit.redacted_attributes')`
- [ ] Polymorphic actor across both guards; `impersonator_user_id`
- [ ] Model observers for the auditable actions in `10-SECURITY.md` §10
- [ ] Notification classes for the V1 event set; database + mail channels
- [ ] `notification_preferences` respected
- [ ] Tests: no secret is ever written to `audit_logs`; audit rows cannot be updated or deleted

## Step 12 — Super Admin foundation

- [ ] `/admin` routes behind `auth:web` + `EnsureSuperAdmin` + mandatory 2FA
- [ ] Dashboard: tenants, subscriptions, queue health, scheduler heartbeat
- [ ] Tenant list/detail; create (manual activation); suspend; reactivate
- [ ] Plan and entitlement-override management (audited, reason required)
- [ ] AI credit adjustment (audited, reason required)
- [ ] Impersonation with banner, restrictions, timeout and both audit entries
- [ ] Audit log viewer; failed jobs viewer
- [ ] **Confirm no admin screen exposes agency credentials or tokens** — assert with a test
- [ ] Tests: non-admins get 403 on every `/admin` route; impersonation restrictions hold

## Step 13 — Foundation UI

- [ ] Three layouts: agency, portal, admin — separate, no shared component namespace
- [ ] `BrandingResolver`; no platform name, logo or colour hardcoded in Blade
- [ ] Navigation gated by permission **and** feature flag
- [ ] Reusable form, table, modal, empty-state, loading and error components
- [ ] Responsive shell; accessible focus and keyboard handling
- [ ] Security headers middleware; CSP in report-only mode

## Step 14 — Phase 1 sign-off

- [ ] `php artisan test` fully green
- [ ] **Tenancy suite green** — hard gate
- [ ] Larastan clean at the configured level; Pint clean
- [ ] `composer audit` / `npm audit` clean of high and critical findings
- [ ] `migrate:fresh --seed` produces a working demo tenant
- [ ] Manual smoke test of the full Definition-of-Done path available at this phase
- [ ] `/docs` updated where implementation diverged from Phase 0 design
- [ ] `PHASE-1-COMPLETION.md` written: features, schema changes, decisions, env vars,
      commands, cron/queue requirements, known limitations, TODOs, test instructions

---

## Phase 1 exit gate

Non-negotiable:

1. Every tenant-isolation test passes.
2. Every authorization test passes.
3. No secret appears in any serialised model output, log, or audit record — proven by test.
4. A tenant can be created both self-serve and manually, and both follow the same lifecycle.
5. Entitlement limits are enforced from configuration and database, with zero hardcoded
   limits in application code.
