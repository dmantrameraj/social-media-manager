# Phase 6 — Completion Report

**Date:** 2026-09-05
**Status:** Complete.

The smallest phase, and the one that fixed the most embarrassing gap: the client could
already talk to the agency, and the agency had no screen that showed it.

---

## 1. Verified state

| Gate | Result |
|---|---|
| Post conversation | **9 passing** |
| Notification preferences | **9 passing**, plus 12 on the settings screen |
| Post event notifications | **18 passing** |
| Full suite | 915 passing, 2411 assertions |
| Static analysis | PHPStan level 5, 0 errors |

```bash
vendor/bin/pest tests/Feature/Publishing/PostConversationTest.php \
                tests/Feature/Agency/NotificationSettingsTest.php \
                tests/Feature/Notifications
```

## 2. Features delivered

**Post conversations** — `post_comments` carries a thread on a post with an explicit
client-visible flag, and both halves now have a screen.

The gap this closed is worth naming, because it is the shape this repository keeps
producing. The client portal could already leave a comment. The agency had **no screen that
showed it**. A client's approval note went into the database and stopped there: the feature
was complete except for the part where somebody reads it.

**Internal notes** — a member can think out loud without addressing the client.
`is_internal` is never taken from the request; it is derived from a validated `visibility`
value, because a flag the browser sets is a flag the browser can unset, and the failure
mode is an internal note appearing in front of a client.

**Notification preferences per user, per event, per channel** — resolved by
`NotificationPreferences::channelsFor()` and honoured inside `PostEventDispatcher` rather
than checked at each call site. A preference checked at the call site is a preference the
next call site forgets.

Two behaviours worth recording, both tested:

- Every combination is recorded, not only the ticked ones. An unchecked box that writes no
  row is indistinguishable from a box nobody has seen, so a later default change silently
  re-enables something the user turned off.
- No preference row is ever written for an event the user cannot receive. The screen offers
  switches only for messages that would actually be sent to that recipient.

**Approval history** — `post_approvals` is written in the same transaction as every status
change, and the post timeline reads it. Who moved this post, when, from what, to what, and
with what comment is answerable for every transition.

**Brand-scoped assignment** — enforced in policies, not in the UI. A member assigned to one
client cannot comment on, or read, another's post; a test asserts the cross-brand and
cross-tenant cases separately, because they fail for different reasons.

## 3. Schema

No new tables. `post_comments` shipped with the publishing tables in Phase 1's migration
set; `notification_preferences` shipped with the platform tables. Phase 6 gave both a
reader and a writer.

`notification_preferences` is unique on (user, event, channel), which is what makes "record
every combination" storable rather than inferred.

## 4. New commands, cron and queue requirements

None. Notifications ride the existing `notifications` queue, already in the scheduled
`queue:work` list.

Delivery is dispatched **after** the status transaction commits, never inside it: a queued
job enqueued inside an open transaction can be picked up by a worker before the commit
lands, and then queries a post that does not exist yet. That race only appears under load
and reads in production as a phantom "post not found".

## 5. New environment variables

None. Channels and per-event defaults live in `config/notifications.php`.

## 6. Known limitations and outstanding TODOs

**Email only.** `config/notifications.php` lists channels and the resolver is
channel-agnostic, but mail is the only one implemented. In-app notifications exist
separately as database notifications with their own screen; they are not driven by these
preferences.

**No @mention.** A comment cannot address a specific colleague, so a thread on a busy post
notifies everyone or no one.

**No comment editing or deletion.** Deliberate for the client-visible half — an approval
conversation somebody can rewrite is not an audit trail — but an internal note with a typo
is stuck too, and those are different cases.

**Richer client approval UX was scoped and not built.** The portal offers approve, reject
and request-changes with a comment, which is the whole workflow; what was imagined beyond
that (side-by-side revisions, per-target approval) is not there.
