# 06 — Publishing Engine

## 1. Model

```
Post (master content, one per customer)
 ├── body, media, link, content_type, scheduled_at, workflow status
 └── PostTarget  (one per selected social account)
        ├── body_override        null = inherit master
        ├── meta_override        per-platform fields
        ├── own status, own schedule, own retries, own external ID
        └── PublicationAttempt[] immutable per-try log
```

**One master, many independent targets.** A post to five networks creates five
`post_targets`. Each succeeds or fails on its own. The post-level status is *derived* for
display and never overwrites per-target truth:

| Targets | Post display status |
|---|---|
| all published | Published |
| some published, some failed | Partially published |
| all failed | Failed |
| any pending/processing | Publishing |

There is no code path that marks a post wholly failed because one provider failed.

## 2. Unified composer

```
Create Post ─ brand selected
  ├── Target picker: connected accounts grouped by provider, capability-aware
  ├── Master content: body, media, link, first comment
  ├── Per-platform tabs (only for selected targets)
  │     └── override body / title / privacy / thumbnail / media subset
  ├── Live preview per platform
  ├── Live validation per platform
  └── Schedule: now | date+time (brand timezone) | add to queue
```

Rules:

- The picker only offers accounts whose `CapabilitySet` supports the chosen content type.
  A Reel cannot be targeted at LinkedIn because the option is not rendered — capability
  filtering happens before the user can make a mistake, not after.
- An empty override means "inherit". Overrides are stored as `null`, not as a copy of the
  master, so editing the master still propagates to untouched targets. Copying master text
  into every override at creation time is the obvious implementation and it is wrong: it
  breaks master edits silently.
- Validation runs per target and blocks submission only for the targets that fail. The user
  may drop a failing target and proceed with the rest.
- Media is attached to the post; a target may reference a subset via
  `post_media.post_target_id`.

## 3. Validation

Provider validators are resolved from the registry and read `config/social.php`. No
validation rule is written inline in a controller, a Livewire component or a Blade file.

```php
interface ContentValidatorInterface
{
    public function validate(PublishPayload $payload, SocialAccount $account): ValidationResult;
}
```

Checked: text length, media count, MIME type, file size, dimensions, aspect ratio, video
duration, required media for the content type, link presence, hashtag rules, and required
scopes on the account.

Validation runs **three times**:

1. **Composer (live)** — user feedback while typing.
2. **On submit** — authoritative; blocks the transition.
3. **Immediately before publish, in the job** — mandatory. Between scheduling and
   publishing, the media may have been deleted, the account disconnected, the scope
   revoked, or the platform's limits changed in config. A target that fails this check is
   marked `failed` with a `validation` error class and is **not** retried.

## 4. Workflow state machine

```
      IDEA
        │
      DRAFT ──────────────────────────┐
        │ submit                      │
  INTERNAL_REVIEW ──reject──> REJECTED│
        │ approve                     │
  MANAGER_APPROVED                    │ (edit returns to DRAFT)
        │                             │
        ├── approval_required = false ─┴──> SCHEDULED
        │
  CLIENT_REVIEW ──reject/changes──> REJECTED
        │ approve
  CLIENT_APPROVED
        │ schedule
    SCHEDULED ──cancel──> CANCELLED
        │ due                 ▲
    PROCESSING ──────────────┘ (pause -> PAUSED)
        │
   PUBLISHED / PARTIALLY_PUBLISHED / FAILED
```

Terminal-ish states: `REJECTED` (returns to `DRAFT` on edit), `CANCELLED`, `FAILED`
(retryable), `PUBLISHED`.
`PAUSED` covers subscription grace/suspension and connection `needs_reconnect`.

Implementation:

```php
final class PostStatusMachine
{
    private const TRANSITIONS = [
        PostStatus::Draft->value => [PostStatus::InternalReview, PostStatus::Cancelled],
        PostStatus::InternalReview->value => [
            PostStatus::ManagerApproved, PostStatus::Rejected, PostStatus::Draft,
        ],
        // …
    ];

    public function assertCan(Post $post, PostStatus $to, Authorizable $actor): void
    {
        if (! in_array($to, self::TRANSITIONS[$post->status->value] ?? [], true)) {
            throw new IllegalTransition($post->status, $to);
        }

        if (! $actor->can($this->permissionFor($post->status, $to))) {
            throw new UnauthorizedTransition();
        }
    }
}
```

Every transition — legal or refused — is recorded. Legal transitions write a
`post_approvals` row (actor, stage, comment, from, to) and an `audit_logs` entry. Status is
**never** assigned by direct attribute write anywhere in the codebase; a test asserts that
`status` does not appear in any `update()`/`fill()` call outside the machine.

Whether `CLIENT_REVIEW` is required is per-brand (`customers.settings.approval_required`),
overridable per post by a user holding `posts.approve_internal`. AI Autopilot (Phase 4)
**cannot** bypass this: autopilot enters the same machine at `DRAFT` and follows the same
gates.

## 5. Scheduling and timezones

- `posts.scheduled_at` and `post_targets.scheduled_at` are **UTC**.
- The composer shows and accepts the **brand's** timezone (`customers.timezone`), falling
  back to the tenant's.
- `posts.timezone` snapshots the timezone the author used, so a later brand timezone change
  does not silently retime already-scheduled posts, and the calendar can explain what the
  author intended.
- Scheduling in the past is refused; a minimum lead time
  (`config('publishing.min_lead_seconds')`, default 60s) prevents races with the sweeper.
- Drag-and-drop on the calendar issues a normal authorized request. The server re-runs
  policy, state-machine and lead-time checks. **Frontend positioning is a hint, never a
  decision** — a dragged post that fails validation snaps back with an explanation.

Queue mode (`publish_mode = queue`) places the post in the brand's posting-time slots. Slot
definitions live in brand settings. Best-time recommendations are Phase 5, when analytics
exist to base them on; until then, slots are user-defined.

## 6. Dispatch pipeline

```
Cron every minute -> schedule:run
  -> publishing:dispatch-due  (Laravel scheduled command, withoutOverlapping)

DispatchDuePublications:
  select post_targets
    where status = 'scheduled'
      and scheduled_at <= now()
      and (next_attempt_at is null or next_attempt_at <= now())
    order by scheduled_at
    limit config('publishing.dispatch_batch_size')          // default 200

  for each target:
    if (! claim($target)) continue;                          // atomic, see §7
    PublishPostTarget::dispatch($target->tenant_id, $target->id)
        ->onQueue('publishing');
```

The dispatcher never calls a provider. It only claims and dispatches, so it finishes in
milliseconds and cannot be the thing that times out.

`PublishPostTarget` job:

```
1. Re-establish tenant context from tenantId
2. Reload target; abort unless status = 'processing' and lock is ours
3. Guard: tenant active? account active? connection healthy?
     -> if not, release to 'paused_reconnect' or 'scheduled' as appropriate
4. Re-run provider validation (§3)
5. Acquire rate-limit token; if unavailable, release with delay (no attempt recorded)
6. Open publication_attempts row
7. Provider->publish(payload, account)
8. Success: store external ID/URL, status = published, close attempt
   Failure:  classify error, close attempt, apply §8
9. Fire PostTargetPublished / PostTargetFailed
10. Recompute derived post status
```

## 7. Claiming and locking

The single most important correctness detail in the engine: a target must never be
dispatched twice.

```php
$claimed = PostTarget::query()
    ->whereKey($target->id)
    ->where('status', TargetStatus::Scheduled)
    ->whereNull('locked_at')
    ->update([
        'status'    => TargetStatus::Processing,
        'locked_at' => now(),
        'locked_by' => $workerId,
    ]);

if ($claimed !== 1) {
    return; // someone else has it
}
```

A conditional `UPDATE` with an affected-rows check is atomic in InnoDB and needs no
application lock, no cache lock, and no `SELECT … FOR UPDATE` transaction held across a
network call. This matters on shared hosting where overlapping cron ticks are a real
possibility.

**Stale lock recovery** — a worker killed mid-publish leaves a `processing` row. A recovery
sweep every 5 minutes finds `status = processing AND locked_at < now() - lock_ttl`
(`config('publishing.lock_ttl')`, default 15 min) and:

- If the last attempt has no outcome, it is closed as `unknown`.
- The target is **not** blindly rescheduled. It goes to `needs_verification` and, where the
  provider supports listing recent posts, a verification job checks whether the post
  actually landed (see `05-SOCIAL-PROVIDERS.md` §10). Only a confirmed non-publish returns
  the target to `scheduled`.

Blindly retrying a stale lock is the classic way to double-post. We do not do it.

## 8. Retry strategy

`config/publishing.php`:

```php
'max_attempts' => 3,
'backoff'      => [60, 300, 900],   // seconds; 1m, 5m, 15m
'lock_ttl'     => 900,
'dispatch_batch_size' => 200,
```

Applied by error class (`05-SOCIAL-PROVIDERS.md` §9):

| Class | Behaviour |
|---|---|
| `rate_limit` | Reschedule at `Retry-After`; **attempt counter not incremented** |
| `network`, `timeout`, `server_error` | Increment; back off per schedule |
| `auth_expired` | No retry. Connection flagged; target -> `paused_reconnect` |
| `validation`, `media`, `permission`, `platform_rejection` | No retry. Target -> `failed` |
| `duplicate` | Resolve external ID -> `published`; else `failed` with `duplicate_unresolved` |
| `unknown` | One retry, then `failed` |

Retries use `next_attempt_at`, not the queue's own delayed-job mechanism, so retry state is
visible in the database and survives a queue driver change. This is a deliberate trade:
slightly more code, but the retry schedule is inspectable, editable by an operator, and
identical on database and Redis drivers.

After exhaustion: `status = failed`, notification to users with `posts.view`, failure reason
shown in plain language plus a **Retry** button for `posts.retry` holders. Manual retry
resets `attempts` to 0, clears the lock, sets `status = scheduled`, and writes an audit
entry naming the operator.

## 9. Failure presentation

Users see a plain-language cause and a next action; operators see the diagnostics.

| Internal | Shown to user | Action offered |
|---|---|---|
| `auth_expired` | "Instagram connection expired." | Reconnect account |
| `permission` | "Missing permission: publish to Pages." | Reconnect with permissions |
| `validation` | "Caption exceeds LinkedIn's 3,000-character limit." | Edit post |
| `media` | "Video is 4:3; Reels require 9:16." | Replace media |
| `rate_limit` | "Rate limit reached. Retrying automatically at 14:32." | none (auto) |
| `platform_rejection` | Provider's reason, sanitized | Edit and retry |
| `duplicate_unresolved` | "May already be published — please check before retrying." | Verify on platform |

`publication_attempts.response_snapshot` is redacted, gated behind `posts.retry`, and never
exposed to portal users. Tokens, secrets and credential values never reach it.

## 10. Calendar

Month / week / day views over `posts.scheduled_at`, colour-coded by status, filterable by
brand, provider, status and author.

- Queries are bounded by date range and always hit `(tenant_id, scheduled_at)`.
- The month view loads counts plus a capped preview per day, not every post — a busy agency
  can have thousands of posts in a month and an unbounded month query is a guaranteed
  incident.
- Drag-and-drop re-validates server-side (§5).
- Published and processing posts are not draggable; the server refuses the transition
  regardless of what the client sends.

## 11. Bulk operations (Phase 3)

**CSV import** — upload, map columns, dry-run validation report, then queued row-by-row
creation under a `job_batch`. Partial success is normal: valid rows are created, invalid
rows are reported with row numbers. The batch is never all-or-nothing, because a single bad
row in a 500-row file should not discard the other 499.

**Recurring posts** — `recurring_post_rules` (RRULE-shaped) with a materialiser that
generates concrete posts a bounded window ahead (`config('publishing.recurrence_horizon_days')`).
Never generate infinite future rows.

**Evergreen** — a content pool per brand with a reuse cooldown so the same item is not
re-posted within N days.

## 12. Tests (Phase 3 gate)

1. Five targets; one provider fails — four publish, post is `partially_published`.
2. Concurrent dispatch of the same target claims exactly once (asserted with parallel
   claim attempts).
3. Worker dies mid-publish: stale lock goes to `needs_verification`, not to a blind retry.
4. Retry honours backoff; rate limits do not consume the attempt budget.
5. `auth_expired` pauses targets and does not fail them.
6. Illegal transitions throw and are audited.
7. Media deleted after scheduling: publish-time validation fails the target cleanly.
8. Timezone: a post scheduled 09:00 Asia/Kolkata dispatches at 03:30 UTC.
9. Drag-and-drop to a past date is rejected server-side even when the client permits it.
10. A user cannot target a social account belonging to another tenant or an unassigned
    brand.
11. Editing master body propagates to targets with no override, and does not touch
    overridden ones.
12. Manual retry resets attempts and writes an audit entry naming the operator.
