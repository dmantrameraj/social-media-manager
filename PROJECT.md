# PROJECT.md — everything a session needs before touching this code

Read this first. It exists so a session does not have to rediscover the
environment, the conventions or the traps by exploring — most of what is below
cost real time to learn.

**What this is:** a multi-tenant AI social media management SaaS for agencies
(competitor set: Buffer, Hootsuite, SocialPilot, Metricool). Laravel 13.29 on
PHP 8.4, MariaDB 12.3.

**State at 2026-09-05:** 968 tests passing, 2514 assertions, PHPStan level 5
clean, Pint clean. Pushed to `github.com/dmantrameraj/social-media-manager`,
branch `main`, HEAD `3d9c00d`.

**The one thing that is not done:** no post has ever reached a real social
network. The Meta adapters are written against Meta's documentation and proven
only against `Http::fake()`. See §8.

---

## 1. Environment

Nothing here is on PATH by default. Use the absolute paths.

| Thing | Value |
|---|---|
| PHP | `C:\php84\php.exe` (8.4.25) — **not** the system 8.2 |
| Database | MariaDB 12.3.2 on **port 3307** (isolated instance, not 3306) |
| Dev DB | `smm_dev` |
| Test DB | `smm_test` (set in `phpunit.xml` and `.env.testing`) |
| DB user | `smm` |
| App URL | `http://localhost:8321` when served |
| Repo | `github.com/dmantrameraj/social-media-manager` (private) |

**MariaDB is not a Windows service — it stops on reboot.** Start it before
anything else or every command fails with a connection error:

```bash
"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --defaults-file=D:\mariadb-smm\data\my.ini --console
```

`gh` is installed (`C:\Program Files\GitHub CLI\gh.exe`) but **not
authenticated**, so CI runs cannot be read. This has been true all along and is
the reason no CI result has ever been seen.

## 2. Commands

```bash
# Tests — see the WARNING below before running these
C:/php84/php.exe vendor/bin/pest
C:/php84/php.exe vendor/bin/pest tests/Feature/Publishing

# Static analysis and formatting (always both, before any commit)
C:/php84/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
C:/php84/php.exe vendor/bin/pint --dirty

# Serve (or use the Browser pane's preview_start with name "smm")
C:/php84/php.exe artisan serve --port=8321

# Database
C:/php84/php.exe artisan migrate:fresh --seed
C:/php84/php.exe artisan db:seed --class=DemoContentSeeder
C:/php84/php.exe artisan db:wipe --env=testing --force   # test schema recovery

# Platform
C:/php84/php.exe artisan platform:super-admin <email>
C:/php84/php.exe artisan schedule:list
```

### ⚠ Never run two Pest suites at once

They share `smm_test` and drop each other's tables, producing dozens of
fabricated failures — most memorably `[ReportShare] is registered as
tenant-owned but its table has no tenant_id column`, which is not a real bug.
**This has bitten twice.** Check for a running suite before starting one:

```bash
Get-Process -Name php* -ErrorAction SilentlyContinue   # PowerShell tool
```

Recovery from a corrupted test schema (also needed if a run is killed
mid-migration): `php artisan db:wipe --env=testing --force`, then re-run.

A full run takes **15–20 minutes**. Run it in the background and use `Monitor`
to wait; do not poll.

## 3. Traps that have cost time

**Bash heredocs truncate above ~7KB.** Use the `Write` tool for anything
larger.

**`>` and `'` inside a Bash command's content create stray files or break
quoting.** Writing PHP or Markdown through `python -c "..."` in a double-quoted
Bash string will silently execute backticks and redirect on `>`. Two safe
patterns:

- `python -c '...'` (single-quoted) — but then no apostrophes inside.
- Write the script or fragment to the scratchpad with `Write`, then
  `python <file>`. **Prefer this.** Everything complicated in this session
  ended up doing it.

Afterwards, sweep for zero-byte artifacts before staging — they have been
committed by accident before:

```python
import subprocess, os
out = subprocess.check_output(['git','status','--porcelain','-z']).decode()
for e in out.split('\x00'):
    if e.startswith('?? '):
        p = e[3:]
        if '/' not in p and os.path.isfile(p) and os.path.getsize(p) == 0:
            os.remove(p)
```

**Pest helper functions are global.** A duplicate helper name across two test
files fatals the whole suite while each file passes alone. Shared helpers go in
`tests/Pest.php` — `asAgencyUser()` was moved there for exactly this reason.

**`git add -A` sweeps in shell artifacts.** Stage explicit paths, or
`git add -A -- app config database docs resources routes tests`.

## 4. Layout

```
app/Domain/<Context>/         AI, Analytics, Audit, Billing, Customers,
                              Engagement, Identity, Media, Notifications,
                              Platform, Publishing, Social, Tenancy
  Models/ Services/ Enums/ DTO/ Jobs/ Contracts/ Exceptions/
app/Http/Controllers/{Agency,Portal,Admin}/
routes/                       web.php (agency /app + admin /admin), portal.php, console.php
config/                       tenancy, entitlements, permissions, social, publishing,
                              ai, analytics, media, billing, audit, branding, notifications
docs/                         see §9
```

Three surfaces, deliberately separate, **no shared Blade component namespace**
(`01-ARCHITECTURE.md` §5 forbids it so a mis-scoped include cannot carry a
screen between surfaces):

| Surface | Prefix | Guard |
|---|---|---|
| Agency app | `/app` | `web` (`users`) |
| Client portal | `/portal` | `customer` (`customer_portal_users`) |
| Super Admin | `/admin` | `web` + `is_super_admin` + **confirmed 2FA** |

## 5. The rules that do not bend

From §64 of the brief. These are not style preferences:

1. **Never** store social passwords, expose OAuth/refresh tokens, put API
   secrets in source, commit `.env`, or log secrets.
2. **Never** let a tenant id bypass authorization, trust frontend
   authorization, or publish without validating tenant/account ownership.
3. **Never** allow arbitrary callback URLs, or disable CSRF/security to make
   something work.
4. **Never invent an external API.** Endpoints, scopes, field names, limits and
   webhook names are marked `[VERIFY]` until read from the provider's live
   documentation. A wrong field name does not throw — it publishes the wrong
   thing or records a metric nobody measured, and looks normal doing it.
5. Super Admin must never see an agency's raw secrets, and no secret may appear
   in an audit record.

**Tenancy**, enforced in five layers: `BelongsToTenant` + `TenantScope`, with
`acrossTenants()` confined to an allow-list of namespaces in
`config/tenancy.php` (`App\Domain\Platform`, `App\Domain\Tenancy\Services`,
`App\Http\Controllers\Admin`, `App\Http\Livewire\Admin`, `App\Console\Commands`,
`App\Jobs`, `App\Domain\Media\Jobs`). `ScopeBypassTest` fails on any use
outside it. `TenantIsolationTest` enumerates the live schema and fails on any
`tenant_id` table with no registered model — register new ones in
`tenantOwnedModels()` in `tests/Pest.php`, or add to that test's exclusion list
if it is a child row reached only through a scoped parent.

## 6. Conventions

**Test helpers** (`tests/Pest.php`, all global): `seedPermissions()`,
`actingForTenant()`, `withoutTenantContext()`, `memberWithRole()`,
`provisionTenant()` (does **not** switch context), `givePlanLimit()`,
`givePlanFlag()`, `asAgencyUser()`, `tenantOwnedModels()`.

`actingForTenant()` leaks context into HTTP test requests — use
`asAgencyUser($user)` for those.

**Status is owned by `PostStatusMachine`.** An architecture test asserts no
direct status write exists anywhere. Approval row + audit entry are written in
the same transaction as the change.

**Entitlements** go through `EntitlementResolver::guard()`, called **in a
service, never a controller** — its own docblock explains why: a limit checked
in a controller is a limit the console and the queue skip.

**Audit**: `AuditLogger::log(action:, auditable:, oldValues:, newValues:,
actor:)`. Record which fields changed, never their values, for anything
sensitive.

**Notifications** are dispatched *after* the transaction commits, never inside
it — a job picked up before the commit queries a row that does not exist yet.

**Enums carry the rules.** `MediaStatus::countsTowardStorage()`,
`AccountStatus::countsTowardLimit()`, `PostStatus::isEditable()`. Do not restate
one as a literal list somewhere else; two copies of a rule agree until somebody
changes one.

## 7. The failure mode this codebase keeps producing

**Twenty mechanisms have been built, fully tested, and left one wire short of
reachable.** The checklist said all twenty were done, and narrowly it was right:
the code existed and its tests passed. A checklist counts what was *built*.

Examples: Super Admin had 38 passing tests and no way for a human to become one;
`posts.scheduled_per_month` was sold on every plan with its usage counter
hardcoded to `0`; bring-your-own credentials had four load-bearing pieces and no
wire between them.

The table with all twenty is in `docs/12-ROADMAP.md`. **Before believing any
completion claim — including the ones in this file — run the sweep:** find
public methods, query scopes and permission keys with no call site outside their
own file. Ignore Eloquent relations (read as properties, so they false-positive).
What is left is dead code or an unreachable feature. Pure Python, not `grep` via
subprocess — that returned empty and produced a bogus list once.

One is still open: `post_versions`… is now closed, but `SupportsAnalytics` and
`SupportsInbox` on the Meta adapters are not implemented (§8).

## 8. Phase status

| Phase | State |
|---|---|
| 0 Architecture | ✅ |
| 1 Foundation | ✅ (Razorpay deferred by the user) |
| 2 Social connections | ⚠ Meta adapters built + documented, **never proven live** |
| 3 Publishing | ✅ |
| 4 AI | ✅ (`AnthropicProvider` is real and runs in production) |
| 5 Analytics | ⚠ needs Meta Insights metric names |
| 6 Collaboration | ✅ |
| 7 Engagement | ⚠ needs Meta comment endpoints |
| 8 Business expansion | ⚠ white-label + domains done; reseller deferred **by design** |

Completion reports exist for 0, 1, 3, 4, 6. Phases with an open exit criterion
have progress reports instead — writing a completion report for one would make
the document lie.

**Blocked on the user, not on code:**

- A Meta developer app, App Review for `pages_manage_posts`,
  `pages_manage_engagement`, `instagram_content_publish`, an `APP_URL` Meta can
  reach over HTTPS, and **one real post**.
- Razorpay: checkout, `ProcessRazorpayWebhook`, gapless invoice numbering,
  `billing:reconcile-subscriptions`.
- `gh auth login` so CI can be read.

**Deliberately not implemented, with reasons in `docs/providers/*.md`:** Meta
Insights (metric names for impressions/reach/clicks are not in the public
reference — guessing returns a number for something else and looks fine),
comment sync, video/Reels (resumable upload, different host), and Instagram
`SupportsRecentPostLookup` (a lost `media_publish` reply can currently
double-post).

**Reseller is not a gap.** `TenantType`'s docblock records the decision: the
column and enum case *are* the V1 deliverable, "no reseller behaviour ships in
V1".

## 9. Documentation map

| File | What |
|---|---|
| `docs/HANDOVER.md` | **start here** — run it, what remains, what to do next |
| `docs/12-ROADMAP.md` | phase exit criteria + the twenty-gap table |
| `docs/providers/facebook.md`, `instagram.md` | what was verified, when, what was not |
| `docs/00`–`12` | architecture, ERD, tenancy, RBAC, providers, publishing, queue, AI, billing, security, deployment |
| `docs/PHASE-*-COMPLETION.md` | 0, 1, 3, 4, 6 |
| `docs/PHASE-*-PROGRESS.md` | 2–3, 4, 5–8 |

## 10. Local demo

```bash
C:/php84/php.exe artisan serve --port=8321
```

Sign in at `/login` as `demo@example.test`. The seeded password is random and
printed once; reset it in `tinker` if lost.

`DemoContentSeeder` fills three brands with a month of posts across every
workflow state, connected (fake-provider) accounts, client conversations,
analytics and an inbox. It is **additive** — it works from a named list of
brands, so anything created by hand is never read, counted or written to, and a
brand that already has posts is left alone. It refuses to run in production.

Portal logins are `approver@<brand-slug>.test` and `viewer@<brand-slug>.test`.

Accounts and metrics in the demo are the **fake provider**. No real network is
contacted, and `FakeProvider` is refused outside local and test.
