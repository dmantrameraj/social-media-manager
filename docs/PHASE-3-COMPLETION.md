# Phase 3 — Completion Report

**Date:** 2026-09-05
**Status:** Complete. All exit criteria met and tested.

This is the phase that delivers the core product: after it, the platform is usable —
against a real provider adapter, which Phase 2 still owes it.

---

## 1. Verified state

| Gate | Result |
|---|---|
| Publishing suite | **107 passing** across 7 files |
| Full suite | 915 passing, 2411 assertions |
| Static analysis | PHPStan level 5, 0 errors |
| Formatting | Pint clean |

```bash
php artisan migrate:fresh --env=testing --force
vendor/bin/pest tests/Feature/Publishing
```

## 2. Exit criteria

- [x] All `06-PUBLISHING-ENGINE.md` §12 tests pass
- [x] A post publishes independently to five targets; one failure does not fail the others
- [x] Concurrent dispatch claims a target exactly once
- [x] Stale locks go to verification, not blind retry
- [x] Approval workflow enforces transitions and permissions, with a full audit trail
- [x] Calendar drag-and-drop re-validates server-side
- [x] Timezone handling verified across three zones including a DST boundary
- [x] CSV import handles partial success with per-row reporting

## 3. Features delivered

**The composer** — one master post plus a target per selected account, exactly the shape
the engine consumes, so there is no translation layer between what is written and what is
published.

**Editing** — `PostStatus::isEditable()` decides: Idea, Draft, Rejected. Editing a rejected
post returns it to Draft through the status machine, which is the recovery the machine's
own transition map anticipated and nothing implemented until now.

**The status machine** — the only thing permitted to change a post's status; an
architecture test asserts no direct attribute write exists anywhere. Legality and
permission are both enforced inside it, and the approval row and audit entry are written in
the same transaction as the status change, so history can never disagree with state.

**Independent per-target publishing** — the single most important rule in the engine.
`deriveStatusFromTargets()` has no path that marks a post wholly failed because one
provider failed; `PartiallyPublished` exists for that reason.

**Claim locking** — `SKIP LOCKED`, tested concurrently. Two workers racing the same target
must not both win, or the post goes out twice. Stale locks (a worker that died holding one)
go to `NeedsVerification` rather than blind retry, because the first attempt may have
succeeded before the process was killed.

**Error classification** — adapters map raw provider errors into `ProviderErrorClass`, so
the engine never sees a Meta subcode. The class decides retryable, consumes-an-attempt, and
requires-reconnect independently, because those are three different questions.

**Rescheduling** — the post and its targets move together. The dispatcher reads
`post_targets.scheduled_at` and never the post's, so an implementation that moved only the
post would change what the calendar shows and nothing about when the post goes out.
Refused while a target is `Processing`, checked on the targets rather than the post status,
because a post only becomes `Processing` once the first target is claimed.

**Timezones** — a time entered for a brand means that time in the brand's zone and is
stored as the UTC instant it denotes. A post keeps the zone it was written in, so a brand
changing timezone does not drag every post already on the calendar. Moving a post to
another day keeps its wall clock across a DST transition.

**CSV bulk import** — each row is its own transaction and its own verdict, reported by its
line number in the file. Everything lands as a draft: a CSV that could schedule would put
content past the approval gate by uploading a file.

**Plan enforcement** — `posts.scheduled_per_month` is counted from `post_approvals`, in
distinct posts, over the tenant's billing period. The guard sits in `PostStatusMachine`
because that is the one place every path to `Scheduled` passes through.

## 4. Schema

`posts`, `post_targets`, `post_media`, `post_approvals`, `post_comments`,
`publication_attempts`, `post_versions`.

`post_targets` is unique on `(post_id, social_account_id)` — publishing the same post twice
to the same account is a duplicate, not a second target — and carries a per-target
`idempotency_key`, which is the anchor a retry is attempted against.

## 5. New commands and cron

| Command | Cadence | Purpose |
|---|---|---|
| `publishing:dispatch-due` | every minute | claim and dispatch due targets |

Bounded by `PUBLISHING_DISPATCH_BATCH` so a backlog cannot turn one scheduled run into an
hour of provider calls. `withoutOverlapping()`.

## 6. Queue requirements

Jobs go to the `publishing` queue (`PUBLISHING_QUEUE`). The scheduled `queue:work` runs
with `--stop-when-empty --max-time=50`, which is the shared-hosting worker window; jobs
therefore carry a `PUBLISHING_JOB_TIME_BUDGET` and checkpoint rather than running long.

## 7. New environment variables

| Variable | Default | Meaning |
|---|---|---|
| `PUBLISHING_MAX_ATTEMPTS` | 3 | attempts before a target is failed |
| `PUBLISHING_LOCK_TTL` | 900 | seconds before a held lock is stale |
| `PUBLISHING_DISPATCH_BATCH` | 200 | most targets claimed in one pass |
| `PUBLISHING_QUEUE` | publishing | queue name |
| `PUBLISHING_JOB_TIME_BUDGET` | 35 | per-job seconds for chunked work |
| `PUBLISHING_DEFAULT_SCHEDULE_TIME` | 09:00 | time of day for a post dragged onto a day |
| `PUBLISHING_IMPORT_MAX_ROWS` | 500 | most rows one CSV upload may carry |

## 8. Known limitations and outstanding TODOs

**Nothing publishes to a real network.** The engine is proven against `FakeProvider`, which
is refused outside local and test. This is Phase 2's debt, not Phase 3's, but it is the
reason none of the above has met a real API.

**`post_versions` has a table, no model and no reader.** It was created for revision
history that was never built. Either build it or drop the table; leaving it is how a
schema stops describing the application.

**Recurring posts** are architecturally provided for (`recurrence_horizon_days` in config)
and not implemented.

**No `first_comment` or per-platform override UI.** The columns and the
`SupportsFirstComment` capability exist; the composer does not offer them.

**Media cannot be imported by CSV**, deliberately — matching a filename to a library item
is a guess, and a wrong guess posts the wrong picture.
