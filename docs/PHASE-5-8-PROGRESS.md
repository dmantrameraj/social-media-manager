# Phases 5–8 — Progress Report

**Date:** 2026-09-05
**Status:** Phases 5, 6 and 7 built and verified. Phase 8 partial — white-label theming and
custom portal domains are done; the reseller system and Social CRM are not started.

This is the fourth progress report. It follows `PHASE-1-COMPLETION.md`,
`PHASE-2-3-PROGRESS.md` and `PHASE-4-PROGRESS.md`, and covers everything built after the AI
phase.

---

## 1. Verified state

| Gate | Result |
|---|---|
| Test suite | **915 passing**, 2411 assertions |
| Static analysis | PHPStan level 5, 0 errors |
| Formatting | Pint clean |
| Schema | 30 migrations |
| Test files | 65 |
| Scheduled commands | 5 (§7) |

---

## 2. Phase 5 — Analytics & Reporting

### Collection

`post_metrics` stores a normalised row per target per collection, plus the provider's raw
payload. Two decisions worth recording:

- **Raw payloads are kept, but not for ever.** The raw column exists so a renamed or newly
  discovered metric can be backfilled without re-polling an API that has already aged the
  data out. That value decays, so `ANALYTICS_RAW_RETENTION_DAYS` (default 400) bounds it.
- **The per-network metric mapping is deliberately absent.** Every provider names its
  metrics differently and what each name counts differs too — one platform's "reach" is not
  another's. A wrong mapping is invisible: a number that is merely wrong still looks like a
  number, and an agency would report it to their client as fact. Mapping is an adapter's
  job, done against live documentation.

`analytics:collect` runs every six hours, bounded by `ANALYTICS_COLLECT_BATCH_SIZE` so a
backlog cannot turn one scheduled run into an hour of provider calls, and only looks at
posts published inside `ANALYTICS_WINDOW_DAYS` — engagement on most networks is finished
well inside a month, and polling older posts spends rate limit re-reading numbers that no
longer move.

### One query behind three surfaces

`BuildReportService` is what the dashboard, the CSV export and a shared link all read. Two
screens reporting different numbers for the same month is the failure that makes an agency
stop trusting the feature, and it happens whenever the query gets written twice.

A bug in this service, caught by its own test: `latestPerTarget()` originally took the
highest metric id per target and *then* filtered by date, so a post still being polled
would report last month's figures inside this month's window. The window now applies inside
the subquery.

### Share links — the only unauthenticated view of tenant data

Every column of `report_shares` exists to bound what a leaked link can reach:

- The token is 256 bits and stored **only** as a SHA-256 hash, the same shape
  `oauth_states` uses. A database read — a backup, a support query, a leaked dump — must
  not yield a working link. The plaintext exists once, in the flash message, and the screen
  says so rather than letting somebody assume they can come back for it.
- The **window is frozen at creation**. "Last 30 days" evaluated on view would mean a link
  sent in January quietly shows April's numbers, and the client reads a report nobody at
  the agency ever saw.
- **Expiry is required, not nullable.** A link that never expires is a permanent
  unauthenticated view of a client's performance, and nobody remembers to clean those up.
  Revocation is separate, because a decision and a deadline are different things.
- **Nothing is read from the request.** Brand and window come from the row, so a leaked
  link cannot be edited into a wider one.
- Unknown, expired and revoked all return the **same 404**. Distinguishing them tells a
  stranger that a report for some client exists.

Views are counted, not logged: enough to answer "did the client open it?" without keeping
an access log of an unauthenticated endpoint, which is a privacy question of its own. The
page is `noindex` and the route is throttled.

The lookup lives in `ResolveReportShareService` under `App\Domain\Platform`, beside
`ResolveDomainService` and for the same reason: an anonymous request arrives before any
tenant is known, that namespace is already on the scope-bypass allow-list, and widening the
list to every controller would be a far larger permission than this needs.

### CSV export

Streamed, so a year of a busy brand does not build in memory. It carries a BOM because
Excel otherwise reads UTF-8 as the system codepage and mangles a non-ASCII brand name. Null
stays blank rather than becoming `0` — unmeasured and zero mean different things in a
client's spreadsheet. Export and sharing both require a single brand: a file covering "all
brands" would put one client's figures in front of another.

**PDF and Excel export are not built.** Excel opens the CSV, and a PDF needs a rendering
dependency plus a designed layout — not worth adding before a real provider produces real
numbers.

### Scheduled monthly reports

`reports:send-monthly` runs on the 1st at 06:00. It is built **on** the share link rather
than beside it: one link type, one expiry rule, one revocation path.

- Reports on **last** month, always. Run on the 1st, "this month" is a few hours old and
  the report would be empty; naming the period explicitly also means a retried or late run
  sends the same thing rather than a different one.
- **No audience, no link.** Minting a share nobody was sent is an unauthenticated view of a
  client's data created for no reason.
- **A suspended agency does not send client-facing mail** (`permitsPublishing()`). Their
  client relationship is not ours to maintain while they are cut off.
- Mail only. A database notification would put the link in the portal's own bell, where the
  recipient is already signed in and can see the live dashboard. The point is to reach
  somebody who is *not* looking at the product. `toArray()` deliberately omits the URL — a
  stored notification row would be a second copy of a working link.
- Per-brand `try`/`catch`: a month-end run that dies on the third of forty clients is worse
  than one that reports what it missed.

---

## 3. Phase 6 — Collaboration

`post_comments` carries a conversation on a post, with an explicit client-visible flag.

The gap this closed is worth naming, because it is the shape this repository keeps
producing. The client portal could already leave a comment. The agency had **no screen that
showed it**. A client's approval note went into the database and stopped there — the
feature was complete except for the part where somebody reads it.

Also in this phase: notification preferences per user, honoured by `PostEventDispatcher`
rather than checked at the call sites, and approval history on the post timeline.

---

## 4. Phase 7 — Engagement

A unified inbox: `inbox_threads` and `inbox_messages`, normalised across providers, with
assignment, status, internal notes, and replies queued through the provider layer.
`inbox:sync` runs every fifteen minutes.

Structurally separate from publishing, as the roadmap requires — it shares only the OAuth
and provider layers. Nothing in the inbox touches `posts` or `post_targets`.

Same caveat as everywhere else: it syncs from the fake provider. No real network's comments
or messages reach it yet.

---

## 5. Phase 8 — White-label and custom domains (partial)

### Branding

`branding_settings` was a **schema stub** shipped in the platform migration during Phase 1 —
a documented placeholder, not a feature. Phase 8 gave it a reader (`BrandingResolver`,
covering the portal chrome and outbound mail) and then an editing screen behind an
entitlement, so an agency can set its own name, logo and colours.

A note for whoever adds the next stub-backed feature: I wrote a second
`create_branding_settings` migration because I read the resolver's "once that feature ships"
comment as "the table does not exist". `migrate:fresh` then created the table in the
platform migration and died on mine, and because a failed migration leaves `RefreshDatabase`
without its "already migrated" flag, **every test in the suite retried the full migration**.
No run converged for over an hour. Grep `database/migrations/` for the table name before
writing a migration.

### Custom portal domains

An agency claims a hostname, verifies ownership by DNS TXT record, and the client portal
answers on it. `ResolvePortalHost` middleware wraps the **whole** portal route group,
including login — a host that resolves for the dashboard but not the login screen is a
broken product.

**Portal-only, by decision.** The agency application stays on the platform hostname. A
misconfigured client domain can therefore break that client's portal and nothing else; if
custom domains also served the agency app, a bad DNS change would take the agency itself
offline.

Hostnames are normalised to lowercase **before** validation, not after. Validating first
meant `PORTAL.example` was rejected by a lowercase-only regex, and worse, `unique:domains`
ran against the un-normalised value — on a case-sensitive collation, two agencies could
claim the same domain.

TLS provisioning is deployment work, not application code. See `11-DEPLOYMENT.md`.

### Not started

Reseller system, Social CRM, WhatsApp Business API, and the additional networks (Threads,
Google Business Profile, Pinterest, TikTok, Reddit, Quora). The networks are correctly
behind the first real provider adapter.

---

## 6. What blocks a launch

Neither of these is a design problem. Both need documentation this project is not allowed
to guess at (§64 of the brief; the `[VERIFY]` rule in `README.md`).

**1. No real social provider adapter exists.** Production registers none. `FakeProvider` is
refused outside `local` and `testing`. Everything above the adapter — registry, capability
narrowing, OAuth with state and PKCE, encrypted credential storage, the publishing engine,
token refresh, analytics collection, the inbox — is built and tested against it. Writing
the Meta adapter is what turns this from a proven framework into a product that can post.

**2. Razorpay is a foundation only.** Plans, prices, entitlements and subscription models
exist and manual activation works. Checkout initiation, `ProcessRazorpayWebhook`, invoice
records with gapless numbering under a row lock, and `billing:reconcile-subscriptions` do
not exist.

**3. CI has never been read.** The workflow runs on every push, but `gh` is not
authenticated on this machine and the repository is private, so no result has been seen.
The MariaDB service image tag and the action versions are still assumptions.

---

## 7. Scheduled work

| Command | Cadence | Purpose |
|---|---|---|
| `publishing:dispatch-due` | every minute | claim and dispatch due targets |
| `social:refresh-tokens` | hourly | refresh before expiry; flag reconnect on failure |
| `inbox:sync` | every 15 minutes | pull threads and messages |
| `analytics:collect` | every 6 hours | poll metrics for in-window posts |
| `reports:send-monthly` | 1st at 06:00 | last month's report to each client |

All are `withoutOverlapping()`. Verify with `php artisan schedule:list`.

---

## 8. New environment variables

| Variable | Default | Meaning |
|---|---|---|
| `ANALYTICS_WINDOW_DAYS` | 30 | how long a post stays worth re-polling |
| `ANALYTICS_COLLECT_BATCH_SIZE` | 200 | most targets polled in one pass |
| `ANALYTICS_RAW_RETENTION_DAYS` | 400 | how long raw provider payloads are kept |
| `ANALYTICS_MONTHLY_REPORT_EXPIRY_DAYS` | 60 | how long a monthly report link stays open |

---

## 9. Testing

```bash
php artisan migrate:fresh --env=testing --force
vendor/bin/pest
```

`phpunit.xml` sets `memory_limit=512M`. This is not decoration: past roughly 800 tests the
suite began dying inside Intervention Image's GD cloner, which clones full-size images for
the media-variant tests, and PHP's default 128M is not enough. It is set in the config
rather than passed on a command line, because a limit that only exists in somebody's shell
alias is a limit CI does not have.

Do not run two suites concurrently — they drop each other's tables and produce dozens of
fabricated failures.

If a run is interrupted mid-migration, the schema is left with a table that has no
migrations row and nothing will converge. Recovery is `php artisan db:wipe --env=testing
--force`, which takes about three seconds.
