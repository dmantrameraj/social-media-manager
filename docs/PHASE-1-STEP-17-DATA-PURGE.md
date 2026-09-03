# Data purge — honouring the retention clock

**Date:** 2026-09-03

## 1. The gap

`docs/10-SECURITY.md` §9 specifies a 60-day retention clock on cancellation and
a daily `platform:purge-expired-data` job.

`SubscriptionLifecycleService` has been stamping `tenants.purge_after` on
cancellation since billing shipped. `Tenant::duePurge()` was written and ready
to find those rows.

**Nothing consumed either of them.** The command did not exist. So the retention
promise was a date written into a column and never acted on: every cancelled
agency's client posts, brand profiles, portal users and media files stayed on
disk indefinitely.

Unlike the previous three gaps of this shape, this one fails in the direction of
keeping data the product said it would destroy — which is the direction with a
legal deadline attached.

## 2. Order of operations

Deliberate, and the opposite of convenience:

1. **Revoke OAuth grants, then delete the connection rows.**
2. **Delete media bytes, then the rows that point at them.**
3. **Anonymise people.**
4. **Record what happened.**

Each step destroys the pointer only *after* the thing it points at is gone.
Reversing any of them loses the ability to finish: delete a connection row first
and there is a live grant on the provider with nothing left to identify it.

§9 calls token revocation out separately as "the step most often forgotten", and
this is why.

## 3. OAuth revocation

Uses the project's own `SocialProviderInterface::revoke()`. No endpoint, scope or
response shape is assumed at the call site — each provider implements `revoke()`
against its current documentation.

Two failure modes, deliberately distinguished because they need different
responses from an operator:

- **Provider not implemented yet** (`ProviderRegistry::has()` is false) — logged
  as `INTEGRATION TODO` with the connection id. This grant must be revoked by
  hand in the provider's console; no retry will do it. Recorded rather than
  skipped silently, because the alternative is a live token nobody knows about.
- **Provider unreachable** — logged and the purge continues. A retention
  deadline is not conditional on a third party's uptime.

Neither logs the token.

`forceDelete`, not `delete`: a soft-deleted connection row still holds the
encrypted tokens, which is precisely what a purge exists to remove.

## 4. Media

Deletes the original **and every variant**, including `thumbnail_path`. They are
separate files on the same disk, so a purge that skipped them would leave a
legible copy of every image it deleted.

Includes soft-deleted rows — a soft-deleted `media` row still points at bytes.

## 5. Anonymisation, not deletion

Rows survive with their identifiers replaced. Audit entries, post authorship and
approval records point at these rows; deleting them would either cascade the
history away or leave it dangling. Anonymising keeps the record intact and
truthful while removing the person from it — which is how §9's "retain records
*and* remove personal data" is satisfied at once.

Replaced: `email` (unique, `@purged.invalid` — RFC 2606 reserves `.invalid` as
a TLD that can never resolve, so a stray send cannot reach a real address),
`name`, `password` (an unusable random hash, since the column is not nullable),
`phone`, `avatar_path`, `last_login_ip`, both two-factor columns, and
`remember_token`.

**Users shared across agencies are not anonymised.** A freelancer working for
three agencies has one login; destroying it because one of those agencies
cancelled would delete a person on the say-so of somebody who is not them. Their
membership row goes; their account does not. Portal users have exactly one
tenant, so there is no such question for them.

## 6. Idempotency and record

`purge_after` is cleared and `purged_at` set. `duePurge()` then no longer
matches, so a tenant is not purged twice — harmless for the deletes, which are
idempotent, but it would write a fresh audit entry daily and make the log
unreadable.

`purged_at` is a new column because `purge_after` alone cannot distinguish a
tenant whose data was destroyed from a cancelled one that was never due. That
difference is the entire answer to "was this customer's data deleted?".

The audit entry records **counts only**. Naming what was deleted would put the
data back into the log that the purge just removed from the tables.

## 7. Operating it

Scheduled daily at 04:10, `withoutOverlapping(60)`, and deliberately *not* in the
background — this is the most destructive thing the application does, and its
output should land where `schedule:run`'s own logging captures it.

- `--dry-run` lists what would be purged and changes nothing. Offered because
  nobody should have to trust that they read the date arithmetic correctly.
- `--tenant=<id>` purges one tenant immediately, ignoring its due date, so an
  erasure request can be honoured without waiting out the clock. It bypasses
  nothing else.

One tenant failing does not stop the rest: a retention deadline is per-customer,
and letting one unrelated failure hold everybody else's data past their date
turns a bug into a compliance problem for every customer in the queue. The
command exits non-zero if any tenant failed, so a scheduler notices.

## 8. Verified

`tests/Feature/Tenancy/PurgeExpiredDataTest.php` — 15 tests covering selection,
dry run, media and variant deletion, soft-deleted rows, force-deleted
connections, the shared-user exemption, anonymisation, idempotency, the audit
entry's contents, the single-tenant override, and that other tenants are
untouched.

## 9. Not done — and one of these matters

- ~~Warning emails at 30 and 7 days.~~ **Built** — see §10 below.
- **Financial record retention** is inherited rather than implemented:
  subscriptions, payments and invoices are simply not touched by the purge. That
  is the correct outcome, but it is not asserted anywhere.
- **Provider `revoke()` implementations** do not exist beyond the fake. Every
  real grant will currently take the `INTEGRATION TODO` path.

## 10. Warnings

`platform:warn-pending-purge`, daily at 04:00, ten minutes before the purge
itself. Separate from it on purpose: by the time a tenant is *due*, warning them
is pointless — this command deals only with deadlines that have not yet arrived.

`config('tenancy.purge_warning_days')` has held `[30, 7]` since Phase 0 and
nothing read it until now.

**One message, quoting the real days remaining — not the stage that fired it.**
If the job does not run for a month a tenant crosses both 30 and 7 between two
runs; sending both would put two contradictory deadlines in one inbox on one
morning, and the 30-day one would state a date already past. Every crossed stage
is recorded, including skipped ones, so a late run never produces a staler
warning afterwards.

**Deliberately outside `NotificationPreferences`.** Every other notification in
this application can be switched off and this one must not be: an unsubscribe
made months earlier for post updates must not silently suppress "your data is
deleted in seven days".

Mail *and* database. The in-app copy is not redundant — a cancelled agency's
billing contact may have left, and whoever logs in to reactivate should see it
without needing the original email.

A tenant with **no owner** is logged rather than skipped silently, and the stage
is *not* marked sent, so a restored owner still receives their warning.

The mail names what goes — "every brand, post, scheduled item, uploaded image and
client login" — rather than "your data", which is easy to skim past, and offers
both the reactivation link and an export request.

Covered by `tests/Feature/Tenancy/PurgeWarningTest.php`.
