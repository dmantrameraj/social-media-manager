# Phase 1 Step 11 — Notifications

**Date:** 2026-09-02
**Status:** The post event set is delivered on mail and database channels.

---

## 1. The gap this closes

The approval loop was mechanically complete and told nobody. An agency sent a
post for client review and the client had no idea until they happened to log in;
the client approved it and the agency had no idea either. Both sides had to poll
a screen. The `notifications` and `notification_preferences` tables existed;
nothing wrote to them.

## 2. The event set

Six events, defined in `config/notifications.php`. Code owns the catalogue and
`notification_preferences` is a projection of it, so a new event reaches people
without a migration backfilling preference rows for every user who ever signed
up.

| Event | Audience | mail | database |
|---|---|---|---|
| `post.client_review` | client | on | on |
| `post.client_approved` | agency | on | on |
| `post.client_rejected` | agency | on | on |
| `post.changes_requested` | agency | on | on |
| `post.publish_failed` | agency | on | on |
| `post.published` | agency | **off** | on |

**`audience` is the load-bearing field.** An event is `agency` or `client`,
never both. Sending an agency-audience message to a portal user would put
internal language — "the client rejected this" — in front of the client it is
about. Tests assert both directions: no client receives an agency event, and no
agency user receives a client event.

**`post.published` defaults to database only, deliberately.** An agency running
fifty posts a day does not want fifty emails. Defaulting it to mail is how a
product teaches people to filter its mail into a folder they never read —
including the failure notices, which are the one thing they must see.
`post.publish_failed` defaults to mail on for the same reason: the customer will
notice a missing post before the agency does otherwise.

**Most transitions notify nobody.** Draft → internal review, manager approval and
the rest are internal bookkeeping. A product that emails on every status change
trains people to ignore it.

## 3. Absence means default, not opted out

`NotificationPreferences` reads a stored row when one exists and falls back to
the catalogue default when it does not.

Treating a missing row as "off" would silently mute every notification the
product ever adds, for every user who signed up before that event existed. It is
the failure mode that never gets reported, because nothing arrives for anyone to
complain about.

Portal users have no preferences at all by design — `notification_preferences`
has a foreign key to `users`. A client receives exactly one kind of message, and
a client who does not want it should be removed from the brand rather than left
assigned and silently muted, which would look to the agency like the client is
ignoring them.

An unknown event key **throws** rather than resolving to "notify nobody", for the
same reason: silence is not a reportable failure.

## 4. Two ordering decisions in the dispatch

Both live in `PostStatusMachine::transition()`.

**Notifications are dispatched after the transaction commits, never inside it.**
A queued job enqueued while a transaction is open can be picked up by a worker
before the commit lands, and then queries a post that does not exist yet. That
race only appears under load and reads in production as a phantom "post not
found". The notification also sets `$afterCommit = true` as a second guard.

**Delivery cannot fail the transition.** The dispatch is wrapped and reported
rather than thrown. A post that moved but whose email bounced is a missing email;
a transition rolled back because a mail server was down is a *lost decision* —
the client clicked approve and the system forgot.

## 5. Who receives what

- **Clients:** every portal user assigned to that brand, approvers *and* viewers.
  Excluding viewers would mean a brand whose only portal user is a viewer never
  hears that anything arrived.
- **Agency:** the post's author first, because it is their work, plus anyone else
  in the tenant who can reach that brand. Brand assignment is enforced here as a
  boundary, not a preference — a user restricted to some brands is not told about
  another's work.
- **Nobody suspended.** `canAuthenticate()` filters both lists; a message to an
  account that cannot sign in is a bounce and a support ticket.

The client's own words are carried through on reject and request-changes. "They
asked for changes" without the changes is a message that generates a second
message asking what they were.

## 6. One class, not six

A single `PostEventNotification` carries the event key rather than six
near-identical classes differing only in a subject line. Six classes is how the
copy drifts and how one of them quietly forgets to check preferences.

Its payload is **flat scalars, snapshotted at dispatch** — never the Post model.
A queued notification is serialized, so carrying the model would re-query it at
send time, and a post edited or deleted in between would produce a message
describing something that never happened. The database row is read months later
and must still render after the post is gone.

## 7. Not done

- **No in-app notification screen.** Rows are written to `notifications`, and
  nothing displays them yet. The data is there; the bell icon is not.
- **No preference editing UI.** Preferences are honoured when rows exist, but
  only the database can create them — so today the defaults are what everyone
  gets.
- **No digest or batching.** A busy day means one email per event. `post.published`
  defaulting to database-only softens this, but an agency approving twenty posts
  at once will send twenty emails to the same person.
- **Mail rendering is Laravel's default template**, not branded. `BrandingResolver`
  exists and white-labelled mail is Phase 8.
- **No real mail delivery has been exercised.** Tests use `Notification::fake()`
  and the dev environment uses the log driver; nothing has been sent through an
  SMTP server.
- **`post.published` and `post.publish_failed` are wired but unproven end to end**,
  because they fire from the publishing engine and no real provider adapter
  exists yet.
