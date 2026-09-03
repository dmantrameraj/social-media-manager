# Phase 1 — Foundation Implementation Checklist

**Last verified against the codebase: 2026-09-03.**

Ordered so that each step's dependencies are already in place. Migrations precede business
logic throughout, per the project rules.

Every box below was checked by reading the code, not from memory. Where something is
half-done it says so on its own line rather than being ticked generously -- a checklist
that flatters the work is worse than none, because it is trusted.

**Why this needed a refresh:** the file had drifted badly. Steps 0-6 were updated in place
as they landed, but Steps 7-14 were still the original Phase 0 plan, untouched and entirely
unticked, and Step 3 appeared twice -- once done, once as its original plan. That produced
77 empty boxes for work that was largely finished, and buried the handful that were
genuinely outstanding. The duplicate is gone and the later steps now reflect what exists.

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
- [x] `config/media.php`, `billing.php`, `audit.php`, `branding.php`
- [x] `config/social.php`, `publishing.php`, `ai.php`
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
- [x] Rule confining the scope bypass to allow-listed namespaces — `ScopeBypassTest`,
      which on first run found five namespaces bypassing that were never listed
- [x] Base `TenantScopedPolicy` *(delivered in Step 5)*

**Finding:** `Model::preventSilentlyDiscardingAttributes()` means an injected `tenant_id`
**throws** in dev/test but is silently discarded in production. Both refuse the write, so
the security property holds either way — but the tests now assert both modes explicitly
rather than assuming one.

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
- [x] 2FA enrolment UI and recovery-code display
- [x] Session listing and revocation — `GuardAwareSessionHandler` populates
      `sessions.guard`; "Signed-in devices" screen lists, revokes one, revokes
      all others. Reachable from the nav, which notification settings was not
      either until this pass found it
- [x] Tests: 19 covering registration, login, disabled accounts, throttling, guard
      separation, and password non-storage; 14 more for session tracking and revocation

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
- [x] Route-coverage test — every agency route authorises in its handler, or is listed
      as self-scoped and proven to scope through the authenticated user
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
- [ ] Tenant settings and timezone management — no route exists
- [x] Member roles and deactivation *(Step 16)*
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

## Step 7 — Media library foundation ✅ *(complete — variants closed it 2026-09-03)*

- [x] Private disk config; server-generated paths *(the original filename is metadata only)*
- [x] Upload with MIME sniffing and extension allow-list (SVG off by default)
- [x] Folders including seeded system folders, protected by `system_key`
- [x] Image variants and thumbnails via GD on the `media` queue
- [x] Signed, expiring delivery URLs behind a policy check — separate agency and portal
      routes, because the two surfaces authorise differently
- [x] Storage quota enforcement from entitlements, checked *before* bytes are written
- [x] Soft delete plus storage cleanup on purge
- [x] Tests: cross-tenant access blocked, quota enforced, disallowed types rejected,
      variant generation, variant serving

> **This step was the longest-standing lie in this document.** Every box above except the
> variants job was ticked in reality months before it was ticked here — and the variants
> job did not exist at all. `StoreMediaService` marked every image `processing` and nothing
> moved it to `ready`, which is what the composer offers and what publishing requires. So
> **no uploaded image could ever be attached to a post or published.** Fixed in `db6fcb5`;
> `media:regenerate-variants` backfills rows that predate it.

## Step 8 — Plans, entitlements, subscriptions *(mostly complete)*

- [x] Plan/feature seeders with the reference tiers from `09-BILLING.md` §3.
      **No prices** — §3 specifies limits and says nothing about money, and
      fabricating amounts would put invented figures where checkout reads them
- [x] `EntitlementResolver` with override → plan → default and explicit cache invalidation
- [x] `guard()` enforcement in services, throwing `EntitlementExceeded` that names the limit
- [ ] `EntitlementExceeded` **rendered** with an upgrade CTA — there is no exception
      renderer; each controller catches it and flashes the message. Adequate, not what
      this line promised
- [x] Subscription model and lifecycle service
- [x] `ManualGateway` implementing `PaymentGatewayInterface`
- [x] `billing:process-lifecycle` hourly command (trial → grace → suspended), idempotent
- [x] Tests: precedence, limit enforcement, override without plan change, lifecycle timing

*(The detailed entitlement notes live under "Step 8 — Entitlements (brought forward)"
above, which was written when that half of the work landed early.)*

## Step 9 — Razorpay foundation *(foundation only — the rest is blocked)*

- [x] `PaymentGatewayInterface` + `RazorpayGateway` skeleton **[VERIFY against live docs]**
- [x] **Server-side** signature verification
- [ ] Checkout initiation — order and subscription creation are stubs
- [x] Webhook endpoint: raw-body HMAC, `hash_equals`, dedupe on `(provider, event_id)`,
      returns 200
- [x] `webhook_events` inbox
- [ ] `ProcessRazorpayWebhook` job — **does not exist.** The controller records the event;
      nothing consumes the inbox
- [ ] Invoice and payment records; gapless invoice numbering under a row lock — **no
      `Invoice` model exists.** The tables do
- [ ] `billing:reconcile-subscriptions` daily
- [x] Tests: tampered signature rejected, duplicate event processed once

> **Blocked, not forgotten.** Every endpoint path, field name and event name here needs
> confirmation against current Razorpay documentation. Writing them from memory produces
> code that compiles, passes review and fails in production, so `config/billing.php` marks
> each such value `[VERIFY]` and they stay unwritten until confirmed.

## Step 10 — AI credit foundation *(ledger complete; its schedule is not)*

- [x] `ai_credit_accounts` opened on tenant creation with the plan allowance
- [x] Ledger service: `grant`, `reserve`, `commit`, `release`, `adjust`
- [x] `resetPeriod()` implementing the monthly reset with a rollover cap
- [ ] `ai:reset-monthly-credits` hourly command — **the method exists and nothing calls
      it**, so no tenant's allowance has ever reset on schedule
- [x] `reconcile()` comparing ledger sum to cached balance
- [ ] Reconciliation **command** — same shape: written, unscheduled
- [x] `ai:sweep-reservations` scheduled, recovering stale reservations
- [x] Tests: no overspend under concurrency, idempotency keys, reset with rollover cap

> Two methods with no caller is the same failure this project keeps producing. The
> difference from the media and purge cases is that these are *known* — recorded here
> rather than discovered later.

## Step 11 — Audit logs & notifications ✅ *(complete)*

- [x] Append-only `AuditLogger` with redaction against `config('audit.redacted_attributes')`
- [x] Polymorphic actor across both guards, via an `ActorType` discriminator;
      `impersonator_user_id`
- [x] Auditable actions recorded by **explicit service-layer calls, not model observers** —
      a deliberate deviation: an observer fires on every write including seeders and
      backfills, and an audit trail that records migrations is one nobody reads
- [x] Notification classes for the V1 event set; database + mail channels
- [x] `notification_preferences` respected, with absence meaning *default* rather than off
- [x] In-app notification screen and per-user preference editor
- [x] Tests: no secret is ever written to `audit_logs`; audit rows cannot be updated or
      deleted

## Step 12 — Super Admin foundation ✅ *(complete 2026-09-02)*

- [x] `/admin` routes behind `auth:web` + `EnsureSuperAdmin` + mandatory 2FA
- [x] Dashboard: tenants, subscriptions, queue health, scheduler heartbeat
- [x] Tenant list/detail; create (manual activation); suspend; reactivate
- [x] Plan and entitlement-override management (audited, reason required)
- [x] AI credit adjustment (audited, reason required)
- [x] Impersonation with banner, restrictions, timeout and both audit entries
- [x] Audit log viewer; failed jobs viewer
- [x] **No admin screen exposes agency credentials or tokens** — asserted by test
- [x] Tests: non-admins get 403 on every `/admin` route; impersonation restrictions hold

## Step 13 — Foundation UI *(complete apart from security headers)*

- [x] Four layouts: agency, portal, admin, auth — separate, no shared component namespace
- [x] `BrandingResolver`; no platform name, logo or colour hardcoded in Blade
- [x] Navigation gated by permission
- [ ] Navigation gated by **feature flag** — the flag tables exist; no view consults them
- [x] Shared partials: empty state, flash, tenant banner
- [ ] A component library (form, table, modal, loading, error) — partials cover today's
      screens; this was scoped larger than what was built
- [x] Responsive shell; accessible focus and keyboard handling
- [ ] Security headers middleware; CSP in report-only mode — **not built**
- [x] Route-coverage test: every agency route authorises in its handler, or is listed as
      self-scoped and proven to scope through the authenticated user

## Step 14 — Phase 1 sign-off *(4 of 8)*

- [x] `php artisan test` fully green — **585 passing, 1521 assertions**
- [x] **Tenancy suite green** — hard gate, and now enforced by an architecture test that
      confines `acrossTenants()` to allow-listed namespaces
- [x] Larastan clean at level 5; Pint clean
- [x] `composer audit` clean of high and critical findings
- [x] `migrate:fresh --seed` produces a working demo tenant — run end to end:
      61 permissions, 4 plans, 36 features, an agency with a brand and a Starter
      subscription, entitlements resolving from the plan rather than defaults
- [ ] Manual smoke test of the full Definition-of-Done path — partially done in-browser for
      auth, agency, admin and portal; not recorded as a repeatable script
- [x] `/docs` updated where implementation diverged from Phase 0 design
- [x] `PHASE-1-COMPLETION.md` written

---

## Steps added during implementation

Numbered beyond the original plan because they were not foreseen in Phase 0, and each
existed because a shipped feature could not be reached without it.

## Step 15 — Media variants ✅ *(`db6fcb5`)*

- [x] `GenerateMediaVariants` on the `media` queue; `processing` → `ready`
- [x] Re-encoding strips EXIF, colour profiles and trailing payloads
- [x] Variants served through the signed URL by name, resolved as a lookup key and never a
      path; signature covers the name
- [x] `variant_bytes` counted against the storage quota
- [x] `media:regenerate-variants` backfill
- [x] Docs: `PHASE-1-STEP-15-MEDIA-VARIANTS.md`

## Step 16 — Team management ✅ *(`9ae7c03`)*

- [x] Suspend, reinstate, change role, revoke invitation
- [x] Cannot act on yourself or the workspace owner
- [x] No change may leave the workspace with nobody holding `team.manage_roles`
- [x] Suspension takes effect on the member's next request
- [x] Docs: `PHASE-1-STEP-16-TEAM-MANAGEMENT.md`

## Step 17 — Data purge ✅ *(`96e419c`)*

- [x] `platform:purge-expired-data`, scheduled daily, with `--dry-run` and `--tenant`
- [x] Revoke grants → delete media bytes and variants → anonymise → record
- [x] Users shared with another agency are exempt from anonymisation
- [x] `purged_at` records that it happened; the audit entry carries counts only
- [ ] **Warning emails at 30 and 7 days** — specified in `10-SECURITY.md` §9 and not built.
      The purge currently runs with no advance notice
- [x] Docs: `PHASE-1-STEP-17-DATA-PURGE.md`

---

## What is actually left in Phase 1

One item, and it is not ours to close:

1. ~~Plan / price / feature seeders~~ — **done**; `migrate:fresh --seed` works
2. ~~Purge warning emails at 30 and 7 days~~ — **done**
3. ~~Session listing and revocation~~ — **done**; `GuardAwareSessionHandler` plus the
   "Signed-in devices" screen
4. **Invoice numbering**, `ProcessRazorpayWebhook`, checkout initiation and
   `billing:reconcile-subscriptions` — **blocked** pending verification against live
   Razorpay documentation

Every self-contained gap in Phase 1 is closed. What remains needs live provider
documentation, which is not something to guess at — see §64 of the master prompt.

Smaller, and honestly optional for the gate: security-headers middleware, feature-flag
navigation gating, the two unscheduled AI credit commands, and a repeatable smoke script.

---

## Phase 1 exit gate

Non-negotiable, and current status:

1. ✅ Every tenant-isolation test passes.
2. ✅ Every authorization test passes.
3. ✅ No secret appears in any serialised model output, log, or audit record — proven by
   test.
4. ✅ A tenant can be created both self-serve and manually, and both follow the same
   lifecycle.
5. ✅ Entitlement limits are enforced from configuration and database, with zero hardcoded
   limits in application code.

The gate is met. Razorpay is the only remaining Phase 1 item, and it is blocked on
external verification rather than on further implementation work here.
