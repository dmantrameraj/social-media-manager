# Phase 1 — Client portal

**Date:** 2026-09-02
**Status:** Built. This was the last unbuilt surface in Phase 1.

---

## 1. What was built

`/portal`, the client surface: sign-in, an overview of what needs the client's
answer, a content list, a post detail page, and the three things a client can
actually do — approve, request changes, reject — plus comments. Ten routes.

It is deliberately small. A client portal that grows into a second dashboard
becomes a support burden for the agency, and every extra panel is another chance
to show a client something that was never meant for them.

## 2. Separation is the security control

The portal shares **nothing** with the agency surface: a different guard
(`customer`), a different table (`customer_portal_users`), its own route file
(`routes/portal.php`), its own controllers, and its own layout namespace.

That is not tidiness. A portal user cannot reach an agency screen because
`auth('web')->user()` *cannot return one* — not because a check somewhere
remembered to say no. A shared controller with a branch in it would be one
forgotten `else` away from serving agency data to a client, and a shared layout
partial would put an agency nav item on a client's page by omission.

The cost is real — some query shapes are near-duplicates — and it is worth
paying. See docs/04-AUTH-RBAC.md §1 and §8.

## 3. One definition of what a client may see

`PortalPostQuery` is the only place that answers it. Two filters, both
load-bearing:

1. **Brand assignment** — a portal user may hold access to some of an agency's
   clients and not others, and the same person may approve for one and only view
   another.
2. **Workflow stage** — nothing below `CLIENT_REVIEW` has been shown to the
   client on purpose. Drafts hold half-written copy, internal notes, and ideas the
   agency has not decided to propose.

The status filter is an **allow-list**, not "anything at or past client_review".
Enum ordering is not a security boundary, and a status inserted in the middle
later must not silently become visible. A test asserts the hidden statuses stay
hidden.

Centralising this means a new portal screen cannot quietly widen the boundary,
and the boundary can be tested in one place.

## 4. Two bugs found, one of them serious

**A view-only client could approve a post.** `PostStatusMachine::assertCan()`
guarded its permission check with `$actor instanceof User`, so a
`CustomerPortalUser` fell straight through every check and could make any
transition the machine considered legal. From `client_review` that is approve,
reject and send-to-draft — so a portal **Viewer**, who has explicitly view-only
access, could approve their agency's content.

The first fix did not work, and the reason is worth recording: the new portal
check was placed *after* the existing `if ($permission === null || $actor ===
null) return;`. `REQUIRED_PERMISSIONS` is a map of *agency* permissions and has no
entry for `client_approved`, so that early return fired first and the portal check
never ran. The test caught it. Portal actors are now checked **before** the
agency permission lookup, against this post's own brand via `canApproveFor()`,
plus an explicit `PORTAL_TRANSITIONS` allow-list so scheduling, cancelling and
publishing stay agency decisions.

**`post_comments.author_type` is varchar(40) and an FQCN is 45 characters.**
Writing `$user::class` truncated on a lax connection and failed outright on a
strict one. It now stores the `ActorType` discriminator (`user`,
`customer_portal_user`) — the same value `audit_logs` and `post_approvals` already
hold, which is what makes the three trails joinable, and which does not tie the
record to today's namespaces.

## 5. Product decisions worth recording

- **"Request changes" sits beside approve**, not buried. It is the answer clients
  actually want most of the time, and it returns the post to the agency as a draft
  rather than rejecting it — which reads very differently to the person who wrote
  it.
- **404, never 403**, for a post the client may not see. A 403 confirms the post
  exists; a client must not be able to learn what an agency is working on by
  probing ids. A draft, another brand's post, and a post that never existed are
  indistinguishable.
- **Internal comments are filtered in the QUERY**, by `clientVisible()`, not in the
  view. Agency staff discuss a client's brief, budget and last round of changes
  candidly; a view-side filter is one refactor away from a leak.
- **`is_internal` is hardcoded on write**, never read from input. A client comment
  created with the flag set would be invisible to the person it was written for.
- **A viewer-only client is told why there are no buttons**, rather than being left
  to assume the page is broken.
- **Times render in the brand's timezone**, not the viewer's. "It went out an hour
  early" is the classic support ticket here.
- **One sign-in failure message** for unknown address, wrong password and suspended
  account alike. Distinguishing them tells an attacker which client emails exist
  on the platform.
- **No self-service sign-up.** Client logins are issued by the agency through an
  invitation; offering a registration link would be a way in for anyone who guesses
  the URL.
- **Sign-out invalidates the session** rather than forgetting it, because clients
  sign in from shared machines.

## 6. Test coverage

The Phase 1 gate criteria from docs/04-AUTH-RBAC.md §10 and docs/03-TENANCY.md §7:

- A portal user gets a non-200 on **every** `agency.*` and `admin.*` route,
  asserted by iterating the route table so a route added later is covered by
  default.
- Posts below `CLIENT_REVIEW` are invisible in listings and 404 on direct access.
- Another brand's and another tenant's content are both invisible and 404.
- Internal comments never reach the client.
- A viewer cannot approve; an approver can, and the decision is recorded to
  `post_approvals` with `stage = client` and the portal user as actor.
- A client cannot make a transition that is not theirs (scheduling, cancelling).
- A decision on a post not awaiting one is refused rather than silently re-run.
- A portal login never establishes a `web` session.

## 7. Media previews

A client approving an image post was approving copy they could not see. The post
detail page now renders the attachments — images inline, video with controls,
PDFs embedded with a real link fallback, anything else as a download link — in
`sort_order`, because a carousel shown in a different order than the client
approved is a genuine complaint and the pivot's order is the only record of the
intent.

**Files are streamed, never linked.** Media lives on a private disk, so
`portal.media.show` serves the bytes behind three independent checks:

1. a valid **signature**, so ids cannot be walked;
2. an authenticated **portal session**, because a signed URL stays shareable
   until it expires and a signature alone must not be enough;
3. the file is attached to a post **this** client may see.

The third is the one that matters, and it is stricter than brand assignment: a
client must not see everything in their brand's media library, only what the
agency actually put in a post and sent for review. It reuses `PortalPostQuery`
rather than re-deriving the rule, so there is still one definition of what a
client may see.

**Why not `Media::temporaryUrl()`.** That method delegates to the filesystem
driver, and the `local` driver — the default, and what shared hosting will run —
cannot sign URLs at all; it throws. The method is currently unused, and it is a
trap for the next person, because it is the obvious thing to reach for. A signed
application route works on every disk. The trade-off is that bytes pass through
PHP; on S3 this is worth revisiting so large video is served by the object store
directly.

Responses carry `X-Content-Type-Options: nosniff` and the mime type established
by sniffing at upload — never a client-supplied header — because an "image" a
browser decides is HTML would execute in the application's own origin. Caching is
`private`, since this is one client's content behind a short-lived URL.

## 8. Not done

- **Password reset for portal users.** The `customers` broker and the
  `customer_password_reset_tokens` table are configured; there is no screen. A
  client who forgets their password currently needs the agency to re-invite them.
- **Notifications.** Nothing emails a client that something is waiting, or the
  agency that a decision was made. Both sides currently have to look.
- **Reports.** `portal.reports.view` exists as a permission; analytics is Phase 5.
- **No alt text.** Nothing captures alt text at upload, so image previews fall
  back to the filename. A client who uses a screen reader currently cannot review
  an image at all — and the platforms this content publishes to support alt text,
  so it is missing from the published post too. This is the next thing worth
  fixing here.
- **Video and PDF previews are untested against real files.** The tests use a
  faked disk with placeholder bytes, which proves the authorisation and headers
  but not that a large MP4 streams acceptably through PHP.
- **No browser walkthrough**, and no mobile pass — clients are the most likely of
  all three audiences to open this on a phone.
