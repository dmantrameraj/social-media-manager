# Handover

**Date:** 2026-09-05
**Suite:** 925 passing, 2427 assertions · PHPStan level 5, 0 errors · Pint clean
**Repository:** `github.com/dmantrameraj/social-media-manager`, branch `main`

---

## 1. Where this stands, in one paragraph

A production-quality multi-tenant SaaS foundation with a complete publishing, AI and
reporting layer, and **Meta adapters that are documentation-correct but have never spoken
to the live API.** Facebook Pages and Instagram Business are built, registered in
production and verified against Graph API v25.0 as documented on 2026-09-05 — text and
image posts to a Page, image and carousel posts to Instagram.

What stands between this and a working product is **no longer code**: a Meta developer
app, App Review for the publishing scopes, an `APP_URL` Meta can reach over HTTPS, and one
real post to prove the chain. See [providers/facebook.md](providers/facebook.md) and
[providers/instagram.md](providers/instagram.md) for exactly what was verified and what
was deliberately left out.

## 2. Running it locally

```bash
C:\php84\php.exe artisan serve --port=8321
```

MariaDB must be running first — it is not registered as a Windows service, so it stops
on reboot:

```bash
"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --defaults-file=D:\mariadb-smm\data\my.ini --console
```

| Surface | Path | Sign in as |
|---|---|---|
| Agency application | `/app` | `demo@example.test` |
| Client portal | `/portal` | `approver@<brand-slug>.test` |
| Super Admin | `/admin` | the same agency account, once promoted |

The demo password is generated at seed time and printed once. To set a known one, or to
promote somebody to Super Admin:

```bash
C:\php84\php.exe artisan platform:super-admin demo@example.test
```

**`/admin` also requires two-factor.** `EnsureSuperAdmin` demands three things
independently — a `User` (not a portal user), the flag, and confirmed 2FA — and enrolment
is at `/user/two-factor`. Without the flag the surface returns **404, not 403**:
whether an admin panel exists is not something an unauthorised visitor is told.

### Demo data

`php artisan db:seed --class=DemoContentSeeder` fills three brands with a month of posts
across every workflow state, connected accounts, client conversations, analytics and an
inbox. It is **additive**: it works from a named list of brands, so anything you created
yourself is never read, counted or written to, and a brand that already has posts is left
alone. It refuses to run in production.

Accounts and metrics in the demo are the **fake provider**. No real network is contacted.

## 3. What was completed in this stretch

| Commit | What |
|---|---|
| `cd4c90f` | Report export and secure share links |
| `8f909f5` | Scheduled monthly reports — the last Phase 5 item |
| `125f242` | Documentation corrected; the overview still said "no application code exists yet" |
| `0c23a97` | Reschedule with drag-and-drop, DST-correct timezones, CSV bulk import, and the monthly scheduling limit |
| `0299e39` | Post editing — `isEditable()` had no caller, so `Rejected` was a dead end |
| `afd8b78` | Account activity screen; two quotas that restated their own enums |
| `7842ed0` | Bring-your-own developer app credentials |
| `f54f60a` | Two branding fields nothing read; failed replies nobody could find |
| `8337a80` | Completion reports for phases 0, 3, 4 and 6 |
| `46773a5` | A way to become Super Admin |

## 4. The finding that matters most

**Twenty mechanisms in this codebase were built, tested, and left one wire short of
reachable, and have now been wired.** They are tabulated in [12-ROADMAP.md](12-ROADMAP.md) with the phase each
belonged to and what was missing.

The checklist said all twenty were done, and in a narrow sense it was right: the code
existed and its tests passed. A checklist counts what was **built**, which is exactly what
this pattern hides.

The worst three:

- **Super Admin.** 38 passing tests covering tenant creation, suspension, impersonation
  and audit viewing, and no way for any human to become one. The model promised "an
  audited console command" that was never written.
- **Bring-your-own credentials.** A stated product differentiator with four load-bearing
  pieces built around it — encrypted table, safe projection, permission, a column on
  `oauth_states`, a parameter on `issue()` — and no wire between them.
- **`posts.scheduled_per_month`.** Sold on every plan, its usage counter hardcoded to
  `0`. A plan advertising twenty scheduled posts permitted any number.

**Do not assume twenty is the total.** The sweep that finds them takes about a minute
and is described at the bottom of that table. Run it before trusting any completion claim,
including the ones in this document. One is already known and still open: `post_versions`
has a table, no model and no reader (§5).

## 5. What remains, and why

### Blocked on you

**Nobody has published one real post.** The Meta adapters are written against Meta's
documentation and proven only against `Http::fake()`, which pins the shape of what we send
and how we read a reply and cannot confirm the other end agrees. Closing this needs:

1. A Meta developer app, its credentials entered under **Developer apps**.
2. `APP_URL` on a host Meta can resolve over HTTPS — image publishing mints a signed URL
   that Meta fetches itself, and localhost cannot work.
3. App Review for `pages_manage_posts`, `pages_manage_engagement` and
   `instagram_content_publish`.
4. One real post to one real Page.

**Insights and comment sync are still unwritten.** `SupportsAnalytics` and
`SupportsInbox` are not implemented on either adapter, because those endpoints were not
verified. Each is now a small specific piece of work rather than a blocked phase — but a
wrong metric mapping is invisible, so both need the documentation read first.

**Razorpay.** Plans, prices, entitlements and subscriptions exist and manual activation
works. Checkout initiation, `ProcessRazorpayWebhook`, invoice records with gapless
numbering, and `billing:reconcile-subscriptions` do not.

**CI has never been read.** The workflow runs on every push, but `gh` is not authenticated
on this machine and the repository is private, so nobody has confirmed the MariaDB service
image tag and action versions actually resolve. One `gh auth login` away.

### Deferred by design, not missing

**The reseller tier.** `TenantType`'s own docblock records the decision: the hierarchy
column and the enum case are the V1 deliverable, and "no reseller behaviour ships in V1".
Building it now would contradict that decision rather than complete it.

### Needs a product decision

**Social CRM and WhatsApp Business.** Unlike the reseller tier there is no schema stub to
follow, so there is nothing to build against. Both need specifying before code.

**PDF and Excel export.** Reports are CSV, which Excel opens. A PDF needs a rendering
dependency and a designed layout, and was not worth adding before a real provider produces
real numbers.

### Small and self-contained

- **`post_versions`** has a table, no model and no reader — created for revision history
  that was never built. Either build it or drop the table.
- **Recurring posts** are provided for in config (`recurrence_horizon_days`) and not
  implemented.
- **The client portal has no signed-in-devices screen.** The agency side does.
- **No `@mention`** in post conversations, so a thread notifies everyone or no one.

## 6. What to do next, in order

1. **`gh auth login`**, then read the CI run. Everything below assumes CI is honest, and
   nobody has ever seen it pass.
2. **Publish one real post to one real Facebook Page.** Nothing else validates the whole
   chain, and until it happens the adapters are documentation-correct and unproven.
3. **Verify Insights and comment endpoints**, then implement `SupportsAnalytics` and
   `SupportsInbox` on the Meta adapters. Phase 5 and Phase 7 close on those two.
4. **Verify the Instagram media-listing endpoint** and implement
   `SupportsRecentPostLookup` before real volume: a lost `media_publish` reply can
   currently double-post.
5. **Re-run the unreachable-code sweep** before believing any part of this is finished.
6. Razorpay, when you are ready to charge anybody.
