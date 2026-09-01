# Phase 1 — Completion Report

**Date:** 2026-09-01
**Status:** Foundation complete and verified. Two steps remain (see §7).

---

## 1. Verified state

| Gate | Result |
|---|---|
| Test suite | **201 passing**, 429 assertions |
| Tenant isolation suite | **26 passing** — the merge gate |
| Static analysis | **PHPStan level 5, 0 errors** |
| Formatting | **Pint clean** |
| Dependency audit | `composer audit` clean |
| Migrations | 18 migrations, 45 tables; `migrate:fresh` and full rollback both clean |
| Secrets in VCS | **0** — verified by scanning staged content |

Run everything with:

```bash
export PATH="/c/php84:$PATH"
php artisan test && ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test
```

## 2. What was built

**Foundation** — Laravel 13.29 on PHP 8.4.25, MariaDB 12.3 (isolated dev instance on
port 3307), Fortify, spatie/laravel-permission with teams, Livewire 4.4, Pest 4.7.

**Tenancy** — `TenantContext` (scoped singleton), `TenantScope`, `BelongsToTenant` with
auto-stamping, a reassignment guard and the named `acrossTenants()` bypass; `ResolveTenant`
and `EnsureTenantActive` middleware.

**Identity & access** — two guards (`web`, `customer`) with separate tables; registration
that provisions a user, agency, roles and credit account in one transaction; TOTP 2FA;
throttling; login history; a 61-permission catalogue; per-tenant roles; four policies on a
shared `TenantScopedPolicy` base.

**Customers** — brand create/update/archive/unarchive, system media folders, team and
portal-user invitations with hashed single-use tokens.

**Billing** — `EntitlementResolver` (override → plan → default), `PaymentGatewayInterface`
with Razorpay and Manual implementations, webhook inbox with HMAC verification and
deduplication, and a full trial → grace → suspended lifecycle on an hourly command.

**AI credits** — append-only ledger with reserve/commit/release, monthly reset with
rollover caps, and reconciliation against the cached balance.

**Media** — upload pipeline with content-sniffed MIME verification, extension/MIME
cross-checking, server-generated paths, and quota enforcement before bytes are written.

**Audit** — append-only log with a `SecretRedactor` that walks nested structures.

## 3. Bugs found during implementation

Recorded because each was caught by a test or by static analysis rather than by review,
and several would have shipped silently.

| # | Bug | Why it mattered |
|---|---|---|
| 1 | **Entitlement keys contain dots** (`brands.max`), colliding with Laravel's config dot-notation. Every usage counter returned `0`. | **Plan limits were not enforced at all.** No error, no exception — a 5-brand plan permitted unlimited brands. |
| 2 | `spatie`'s `assignRole(null)` is silently ignored | A missing owner-role template produced a tenant whose owner held no permissions — unadministrable, and it looked fine until used. |
| 3 | Auth events recorded **twice** — Laravel auto-discovers `app/Listeners` alongside the explicit `Event::subscribe()` | Duplicate security-log rows. Fixed by moving the listener into its domain. |
| 4 | `login_histories.authenticatable_*` were NOT NULL | A failed login against an unknown address — the event most worth recording — crashed on insert. |
| 5 | `EntitlementResolver` joined subscriptions with **no ordering** | Non-deterministic billing limits if a tenant ever held two subscription rows. |
| 6 | `AuditLogger::logChanges()` read `getRawOriginal()` after save, which Laravel has already synced | The log recorded the new value **as the old one** — a log that lies. Now records `null` when originals are unrecoverable. |
| 7 | `coupon_redemptions UNIQUE (coupon_id, tenant_id)` | Would have broken renewals for repeating coupons. |
| 8 | `customer_user` pivot never populated its `tenant_id` | Every brand assignment crashed. |
| 9 | `Tenant::query()->acrossTenants()` in the lifecycle service | Latent runtime crash on the period-expiry path. **PHPStan caught it; the tests did not** — the path had no coverage. A test was added. |

Two assertions were corrected rather than the code, because the code was right: login
throttling returns a hard **429** (not a validation error), and an injected `tenant_id`
*throws* in dev while being silently discarded in production — both refuse the write.

## 4. Notable design decisions

- **Archiving a brand frees its `brands.max` slot; unarchiving re-checks the limit.** An
  agency that downgraded while a brand slept does not get the slot back free.
- **Team-member limits are checked at both invite and accept time.**
- **Invitations are bound to their email address**, so a forwarded invitation cannot be
  redeemed by whoever opens it; re-inviting revokes the outstanding token.
- **Publishing continues during grace by default** (`billing.publish_during_grace`).
  Cutting off a client's scheduled posts over an agency's expired card damages the
  agency's relationship with their own customer.
- **Suspension and cancellation never delete data** — cancellation starts a 60-day
  retention clock instead.
- **Deleting a brand requires archiving first** — a deliberate speed bump.
- **The manual gateway implements `PaymentGatewayInterface`**, so admin-activated tenants
  traverse identical lifecycle code with no `if manual` branch anywhere.

## 5. Environment and operational notes

- PHP 8.4.25 at `C:\php84`, alongside the existing 8.2 (untouched, so `newshub_cms` still
  works). Select per shell: `export PATH="/c/php84:$PATH"`.
- MariaDB runs as a **plain process on port 3307 and does not survive a reboot** — no
  Windows service is registered. Start it with:
  `"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --defaults-file=D:\mariadb-smm\data\my.ini --console`
- The dev database is tuned for DDL speed (`innodb_flush_log_at_trx_commit=0`,
  `innodb_doublewrite=0`), taking the suite from 848s to ~200s. **These settings are unsafe
  for production data.**
- Test credentials live in a gitignored `.env.testing`; `.env.testing.example` is tracked.

## 6. New environment variables

```
TENANCY_TRIAL_DAYS, TENANCY_GRACE_DAYS, TENANCY_RETENTION_DAYS,
TENANCY_INVITATION_EXPIRY_DAYS
BILLING_TRIAL_DAYS, BILLING_GRACE_DAYS, BILLING_PUBLISH_DURING_GRACE,
BILLING_CURRENCY, BILLING_GATEWAY, BILLING_INVOICE_PREFIX
ENTITLEMENT_CACHE_ENABLED, ENTITLEMENT_CACHE_TTL
MEDIA_DISK, MEDIA_SIGNED_URL_TTL, MEDIA_ALLOW_SVG, MEDIA_MAX_UPLOAD_BYTES
RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, RAZORPAY_WEBHOOK_SECRET
```

Cron requirement — one entry, on which the whole product depends:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Not done — outstanding Phase 1 work

Stated plainly rather than marked complete.

**Step 12 — Super Admin.** No `/admin` surface exists. `EnsureSuperAdmin` middleware and
the `admin` middleware group are in place; the dashboard, tenant management, impersonation
(with its banner, restrictions and audit entries) and the audit viewer are not built.

**Step 13 — UI shell.** Only the minimal unstyled auth views exist. No agency dashboard,
brand screens, media library UI, team management or billing screens. No Livewire components.

**Also outstanding:**

- **Route-coverage test** — cannot be written until agency routes exist. Moves to Step 13.
- **Session listing/revocation** — the `sessions.guard` column exists; the custom session
  handler that populates it does not.
- **Razorpay subscription API calls** — only signature verification and the webhook inbox
  are implemented. Order/subscription creation and the webhook *handlers* are stubs, and
  all endpoint and field names remain **[VERIFY]**.
- **Invoice generation and numbering** — schema and config exist; the numbering service does not.
- **Media variants** — the `media` queue job that generates thumbnails is not written, so
  images remain in `processing`.
- **Notifications** — no notification classes yet.
- **Data purge job** — `purge_after` is set correctly; nothing consumes it.
- **CI pipeline** — commands verified locally, not wired to a runner.

## 8. Recommended next step

Build Step 13's agency shell before Step 12. The route-coverage test — every route gated by
a policy or permission — is a Phase 1 exit criterion that cannot be satisfied until routes
exist, and it is the check most likely to expose an authorization gap.

**Phase 2 (social connections) should not start until the Meta App Review submission is
under way.** It gates Facebook and Instagram publishing, can take weeks, and needs a
working demo — it is calendar risk that no amount of code quality mitigates.
