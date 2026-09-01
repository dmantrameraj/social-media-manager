# 01 — Architecture

## 1. Stack decision

| Layer | V1 choice | Rationale |
|---|---|---|
| Framework | **Laravel 13.29** on PHP 8.4.25 | Verified 2026-09-01 — see the note below. |
| Database | **MariaDB 12.3** local; MariaDB 10.6+ / MySQL 8.0+ in production | `SELECT … FOR UPDATE SKIP LOCKED` is required by the database queue driver; MariaDB has it since 10.6, MySQL since 8.0. |
| Queue | **database** driver V1 → **redis** on VPS | No Redis guarantee on shared hosting. |
| Cache | **database**/`file` V1 → **redis** on VPS | Same reason. |
| Sessions | **database** | Enables "terminate other sessions" and device listing. |
| Storage | **local private disk** V1 → **S3-compatible** later | `Storage` facade only; no writes to `public_path()`. |
| Frontend | Blade + **Livewire 4.4** + Alpine + Tailwind | Server-rendered fits shared hosting and avoids maintaining a separate SPA plus a public API surface in V1, while the service layer still keeps a future API possible. |
| Auth scaffolding | Laravel Fortify (headless) | Provides 2FA/TOTP, password confirmation and reset flows without an opinionated UI. |
| RBAC | `spatie/laravel-permission` with **teams** enabled | Battle-tested; `team_id` maps to `tenant_id`. |
| Calendar UI | FullCalendar | Drag/drop with server-side re-validation. |

> **Version decision, resolved 2026-09-01.** Laravel 13.29 was current and required PHP
> `^8.3`; the machine had PHP 8.2.33, which caps at Laravel 12.68. Laravel 12 shipped in
> Feb 2025 and is already in its security-fix-only window, reaching EOL in early 2027 — an
> unacceptable starting point for a greenfield product. **Resolution:** PHP 8.4.25 was
> installed alongside 8.2 (at `C:\php84`, leaving the existing 8.2 install untouched for
> the user's other projects) and the app scaffolded on Laravel 13.29.
>
> **Production dependency:** Hostinger must offer PHP 8.3+. Confirm in hPanel before the
> first deploy. If only 8.2 is available, either upgrade the plan or fall back to Laravel
> 12 — do not deploy a PHP 8.4 codebase onto an 8.2 host.

### Installed packages (verified 2026-09-01)

| Package | Version | Role |
|---|---|---|
| `laravel/framework` | 13.29.0 | Framework |
| `laravel/fortify` | 1.39.0 | Headless auth, 2FA/TOTP |
| `laravel/passkeys` | — | Pulled in by Fortify; unused in V1 |
| `livewire/livewire` | 4.4.3 | Interactive UI |
| `spatie/laravel-permission` | 8.3.0 | RBAC with teams |
| `pestphp/pest` | 4.7.8 | Test runner |
| `phpunit/phpunit` | 12.5.33 | Underlying test framework |
| `larastan/larastan` | 3.10.0 | Static analysis |
| `laravel/pint` | 1.30.5 | Formatting (ships with skeleton) |

Still to add in later phases: `guzzlehttp/guzzle` (via HTTP client), `razorpay/razorpay`
(Phase 1 Step 9), `intervention/image` (Phase 1 Step 7), `league/flysystem-aws-s3-v3`
(storage migration, not V1).

Deliberately **not** used in V1:

- `stancl/tenancy` — see `03-TENANCY.md` §2.
- Laravel Cashier — no Razorpay support; we implement `PaymentGatewayInterface` instead.
- Horizon — Redis-only, and Redis is not available on the launch host.
- `spatie/laravel-activitylog` — evaluated and rejected for the audit trail: we need
  tenant scoping, actor polymorphism across two guards, impersonator attribution, and
  guaranteed secret redaction. A purpose-built `audit_logs` writer is smaller than
  bending the package to fit.

## 2. Layering

```
HTTP / Livewire / Console / Webhook          entry points, no business logic
        |
FormRequest / Livewire rules                 shape + syntactic validation
        |
Policy / Gate                                authorization, tenant ownership
        |
Application Service  (App\Domain\...)        use case orchestration, transactions
        |
Domain                                       models, state machines, value objects,
        |                                    provider adapters, validators
Infrastructure                               Eloquent, HTTP clients, Storage, Queue
```

Hard rules:

- Controllers and Livewire components **orchestrate only**: authorize, validate, call a
  service, return a view or redirect. No conditional business rules, no query building.
- Services are the transaction boundary. A service either fully succeeds or rolls back.
- Services never read `auth()`, `request()`, or the session. Actor and tenant are passed
  in explicitly. This is what makes them reusable from Jobs, Console commands, and a
  future API.
- Jobs are thin: resolve a service, call it, handle retry semantics. Business rules live
  in the service so they are testable without the queue.
- Models hold relationships, casts, scopes and trivial accessors. Not workflows.

We are **not** introducing a repository layer. Eloquent is the persistence abstraction and
wrapping it would be abstraction for its own sake. Query reuse goes into model scopes, and
genuinely complex queries get a dedicated Query class.

## 3. Module boundaries

Code is grouped by domain module, not by artefact type. Modules communicate through
services and events — never by reaching into another module's models to write.

```
app/
├── Domain/
│   ├── Tenancy/           tenants, membership, tenant context, invitations
│   ├── Identity/          users, portal users, 2FA, sessions, login history
│   ├── Access/            roles, permissions, permission catalogue
│   ├── Customers/         customer workspaces, assignments
│   ├── Media/             media library, folders, upload pipeline, variants
│   ├── Social/
│   │   ├── Contracts/     SocialProviderInterface, capability contracts
│   │   ├── Providers/     Facebook/, Instagram/, LinkedIn/, X/, YouTube/
│   │   ├── OAuth/         state issuing/consumption, callback handling
│   │   ├── Credentials/   per-tenant developer app credentials
│   │   └── Connections/   connections, accounts, health, reconnect
│   ├── Publishing/
│   │   ├── Composer/      master content + per-target overrides
│   │   ├── Validation/    per-provider content rules
│   │   ├── Workflow/      status state machine, approvals
│   │   ├── Scheduling/    calendar, timezone resolution, recurrence
│   │   └── Dispatch/      claiming, jobs, attempts, retry classification
│   ├── AI/
│   │   ├── Contracts/     AiProviderInterface
│   │   ├── Providers/     Anthropic/
│   │   ├── BrandBrain/    brand context assembly
│   │   ├── Features/      caption, hashtags, ideas, rewrite, translate
│   │   └── Credits/       ledger, reservations, monthly reset
│   ├── Billing/
│   │   ├── Contracts/     PaymentGatewayInterface
│   │   ├── Gateways/      Razorpay/
│   │   ├── Plans/         plans, prices, features
│   │   ├── Entitlements/  resolver + enforcement
│   │   └── Subscriptions/ lifecycle, trial, grace, expiry
│   ├── Audit/             audit log writer, redaction
│   └── Platform/          feature flags, branding, domains, announcements
├── Http/
│   ├── Controllers/{Agency,Portal,Admin,Webhook,OAuth}/
│   ├── Livewire/{Agency,Portal,Admin}/
│   ├── Middleware/
│   └── Requests/
├── Jobs/{Publishing,Media,Ai,Notifications,Billing}/
├── Policies/
├── Support/               tenant context, encryption casts, redaction helpers
└── Providers/
```

When a module needs something to happen in another module it dispatches a **domain event**
(`PostClientApproved`, `SocialConnectionExpired`, `SubscriptionEnteredGrace`) and the other
module listens. This is what makes the analytics, inbox and CRM modules addable later
without editing publishing code.

## 4. Request lifecycle (agency web request)

```
Request
 -> EncryptCookies, StartSession, VerifyCsrfToken
 -> Authenticate:web
 -> EnsureEmailVerified
 -> ResolveTenant              from session/route/host; NEVER from the request body
 -> EnsureTenantActive         trial / grace / suspended gating
 -> SetPermissionsTeamId       binds spatie team context to the tenant
 -> EnsureTwoFactorConfirmed   when tenant policy requires it
 -> Route -> Controller/Livewire
      -> authorize()           policy asserts tenant ownership of the target record
      -> validate()
      -> Service               global scope adds `tenant_id = ?` to every query
 -> Response
```

`ResolveTenant` is the single place tenant context is established. Everything downstream
reads it from `App\Support\TenantContext`, a scoped singleton.

## 5. Three separated surfaces

| Surface | Prefix | Guard | Layout | Notes |
|---|---|---|---|---|
| Agency app | `/app` | `web` | agency | Tenant-scoped; the main product |
| Customer portal | `/portal` | `customer` | portal | Approval-only; deliberately minimal |
| Super Admin | `/admin` | `web` + `is_super_admin` | admin | Cross-tenant, no global scopes |

Separate route files, middleware groups, layouts and Livewire namespaces. The portal does
**not** share a layout or component namespace with the agency app, so a mis-scoped Blade
include cannot leak an agency screen into the portal.

The Super Admin surface is the one place global scopes are bypassed. That bypass is
explicit (`Model::acrossTenants()`), policy-gated, and audited — never implicit.

## 6. Configuration over hardcoding

| File | Holds |
|---|---|
| `config/tenancy.php` | Tenant statuses, grace days, retention days, reserved slugs |
| `config/social.php` | Provider registry, capabilities, content limits, scopes, API versions |
| `config/publishing.php` | Retry counts, backoff schedule, claim lock TTL, batch sizes |
| `config/ai.php` | Providers, models, credit cost per feature, token-to-credit ratios |
| `config/billing.php` | Gateways, currency, grace/retention windows, invoice numbering |
| `config/entitlements.php` | Entitlement key catalogue and system defaults |
| `config/permissions.php` | Permission catalogue and default role templates |
| `config/audit.php` | Auditable actions and the redacted-attribute list |
| `config/media.php` | Allowed MIME types, size caps, variant definitions, SVG toggle |
| `config/branding.php` | Default platform branding (never hardcoded in Blade) |

No plan limit, retry count, rate limit, character cap or credit cost may appear as a
literal inside a controller, service or Blade file.

## 7. Naming and code standards

- PSR-12 via Pint; static analysis via Larastan, starting at level 5 and ratcheting up.
- Services are named `VerbNounService` (`PublishPostTargetService`) with one public entry
  point, `execute()`, used consistently across the codebase.
- Events are past tense (`PostPublished`); Jobs are imperative (`PublishPostTarget`).
- Enums are PHP backed enums in `Domain/*/Enums`, persisted as strings, never integers.
  String values survive reordering; integer values silently corrupt on insertion.
- All money is an integer in minor units plus a currency code. No floats, anywhere.
- All timestamps are stored in UTC. Conversion happens at the presentation boundary only.
