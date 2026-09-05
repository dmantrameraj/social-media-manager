# 12 — Roadmap

Each phase has explicit exit criteria. **A phase is not complete until its tests pass and
`/docs/PHASE-X-COMPLETION.md` exists.**

Effort estimates assume one experienced full-stack Laravel developer and are for sequencing,
not commitment.

---

> **Status at 2026-09-05.** Phases 0–7 are built and their suites pass. Phase 8 is
> partial. Two items block a real launch and neither is a design problem: **no real social
> provider adapter exists** (production registers none; the fake driver is refused outside
> local and test), and **Razorpay checkout, webhooks and invoicing do not exist**. Both
> need live provider documentation, which §64 of the brief forbids guessing at.
>
> Exit criteria below are ticked only where a test proves them. A criterion proven against
> the fake provider says so.

---

## Phase 0 — Architecture & Documentation ✅

Deliverables: the 13 documents in `/docs`, the ERD, and the Phase 1 checklist.

**Exit criteria**
- [x] Repository inspected; environment capabilities and gaps recorded
- [x] All architecture documents written and internally consistent
- [x] ERD covers every V1 entity with keys, indexes and constraints
- [x] Tenancy, RBAC, provider, publishing, AI-credit and billing strategies decided
- [x] Shared-hosting → VPS migration path defined
- [x] Phase 1 checklist produced
- [x] **Human review and approval** — given by the user directing Phase 1 to start
- [x] `PHASE-0-COMPLETION.md` written

---

## Phase 1 — Foundation ✅

Laravel scaffold; multi-tenancy; auth (both guards) with 2FA; RBAC; agency and brand
management; team invitations; customer portal login; media library foundation; audit log;
notifications; plans, entitlements and subscription models; Razorpay foundation; manual
activation; Super Admin foundation.

**Exit criteria**
- [x] **All tenant-isolation tests pass** — mandatory, no override
- [x] Authorization tests pass, including brand assignment and portal isolation
- [x] An agency can be created (self-serve and manual) and log in
- [x] Brands can be created, and entitlement limits are enforced
- [x] Team invitations work with role assignment
- [x] A portal user can log in and see only their assigned brands
- [x] Media upload/list/delete works on a private disk with signed delivery
- [x] Audit logs capture the actions listed in `10-SECURITY.md` §10
- [x] Entitlement resolution honours override → plan → default
- [x] Super Admin can create, suspend and reactivate a tenant
- [x] `PHASE-1-COMPLETION.md` written

**Not delivered:** Razorpay beyond the plan/subscription models — no checkout initiation,
no webhook processing, no invoice numbering, no reconciliation command. Manual activation
covers it for now. See `PHASE-1-CHECKLIST.md` Step 9.

---

## Phase 2 — Social Connections ⚠ *(framework complete, no real provider)*

Provider interface and registry; capability model; per-tenant credential management; OAuth
framework with state and PKCE; connection and account models; account discovery and
selection; token refresh; health checks; reconnect flow. Then providers **incrementally**:
Facebook → Instagram → *stabilise* → LinkedIn → X → YouTube.

**Exit criteria**
- [x] OAuth state tests pass (single use, tenant-bound, expiry, redirect validation)
- [x] Tokens are encrypted; a serialisation test proves they never appear in output
- [ ] **Facebook Page and Instagram Business connect, discover and assign to a brand** —
      the whole flow works end to end against the fake provider: connect, callback,
      destination picker, assignment, disconnect. No Meta adapter exists, so nothing
      connects to Meta.
- [x] Token refresh works; failure sets `needs_reconnect` and notifies — driven hourly by
      `social:refresh-tokens`, verified against the fake provider
- [x] Reconnect updates the existing connection and preserves account assignments
- [x] Capabilities resolve correctly from account type plus granted scopes
- [x] **Per-tenant credential management** — an agency stores its own developer app per
      network, and the OAuth grant runs through it. The credential id is written onto the
      state row and read back at callback, so a code is exchanged against the app that
      issued it. No stored value is ever rendered back, and audit entries record which
      fields changed rather than what they changed to.
- [ ] `/docs/providers/{provider}.md` exists per provider, dated — nothing to document yet
- [ ] `PHASE-2-COMPLETION.md` written — `PHASE-2-3-PROGRESS.md` stands in until a real
      provider closes the gate

**This is the phase that gates launch.** Everything above the adapter is built and proven;
the adapter itself must be written against live Meta documentation.

> Do **not** start all five providers in parallel. Meta must be stable before LinkedIn
> begins — Meta is the most complex and will teach the abstraction what it is missing.

---

## Phase 3 — Publishing Engine ✅

Unified composer with per-platform overrides; provider validation; approval workflow and
state machine; scheduling with timezones; content calendar; queued publishing with claim
locking; retry and error classification; failure UI; publication history; CSV bulk import;
recurring post architecture.

**Exit criteria**
- [x] All `06-PUBLISHING-ENGINE.md` §12 tests pass
- [x] A post publishes independently to five targets; one failure does not fail the others
- [x] Concurrent dispatch claims a target exactly once — `SKIP LOCKED`, tested concurrently
- [x] Stale locks go to verification, not blind retry
- [x] Approval workflow enforces transitions and permissions, with a full audit trail
- [x] Calendar drag-and-drop re-validates server-side — dropping a post on a day posts to
      `posts.reschedule`, which decides permission, tenancy, brand access, post state,
      targets in flight and lead time again. The browser is told what it may drag only so
      the UI does not offer the impossible. The post AND its targets move together: the
      dispatcher reads `post_targets.scheduled_at`, so moving only the post would change
      what the calendar shows and nothing about when the post goes out.
- [x] Timezone handling verified across at least three zones including a DST boundary —
      Asia/Kolkata (no DST), Europe/London and America/New_York, across both the spring
      and autumn transitions. The rule tested is that moving a post to another day keeps
      its WALL CLOCK: 09:00 stays 09:00 when the offset changes underneath it. A post also
      keeps the zone it was written in, so a brand changing timezone does not drag every
      post already on the calendar.
- [x] CSV import handles partial success with per-row reporting — each row is its own
      transaction and its own verdict, reported by its line number in the file. Everything
      lands as a draft: a CSV that could schedule would be a way to put content past the
      approval gate by uploading a file. Closes `posts.bulk_import`, which had been in the
      permission catalogue since Step 5 governing nothing.
- [x] `PHASE-3-COMPLETION.md` written

Also closed here, though it belongs to Phase 1's entitlement work:
**`posts.scheduled_per_month` is now enforced.** Its usage counter was hardcoded to 0 with
a "Phase 3" note beside it, so every plan sold a scheduling limit that nothing checked. The
guard sits in `PostStatusMachine`, the one place every path to Scheduled passes through,
and counts distinct posts — so retrying a failed post, or moving one twice, is free.

**This phase delivers the core product.** After it, the platform is usable.

---

## Phase 4 — AI ✅

AI provider abstraction; Brand Brain CRUD and context builder; credit ledger with
reservations; caption, hashtags, ideas, rewrite, tone, translate, platform adaptation, reel
scripts, YouTube metadata; monthly content plan. Autopilot **last**, only once manual AI is
stable.

**Exit criteria**
- [x] All `08-AI-ARCHITECTURE.md` §9 tests pass
- [x] Brand Brain context never crosses tenant or brand boundaries
- [x] Ledger sum reconciles to the cached balance under randomised transactions
- [x] Failed generations do not charge credits — reservations released, swept hourly
- [x] Monthly reset applies rollover caps and is idempotent
- [x] Swapping the provider implementation leaves feature tests green
- [x] Autopilot cannot bypass approval gates — output is always a draft, and the brand
      approval requirement carries through
- [x] `PHASE-4-COMPLETION.md` written

Unlike the social providers, the AI provider is real: `AnthropicProvider` is written
against the published PHP SDK and runs in production.

---

## Phase 5 — Analytics & Reporting ✅ *(CSV, not PDF)*

Built: normalised `post_metrics` with raw payload retention and a purge; `analytics:collect`
every six hours, bounded per run; the agency dashboard; CSV export; secure share links; and
`reports:send-monthly` on the 1st.

- [x] Metrics are collected, normalised, de-duplicated per target per window
- [x] One query (`BuildReportService`) behind dashboard, CSV and share link, so two screens
      cannot report different numbers for the same month
- [x] Share links: 256-bit token stored only as a SHA-256 hash, frozen window, mandatory
      expiry, separate revocation, one 404 for unknown/expired/revoked
- [x] Scheduled monthly reports, minted per brand, skipped for suspended agencies
- [ ] **PDF and Excel export** — CSV only. Excel opens it (BOM included for that reason);
      a PDF needs a rendering dependency and a designed layout, and was not worth adding
      before a real provider produces real numbers.
- [ ] **Provider analytics adapters** — blocked with the rest of Phase 2. `SupportsAnalytics`
      exists and the fake provider implements it.

Prerequisite noted in Phase 0 still stands: **VPS migration is likely required here** —
analytics polling adds sustained background load that a shared-hosting worker window will
not absorb.

---

## Phase 6 — Collaboration ✅

Built: internal review threads on a post, visible to the agency and — where marked
client-visible — to the portal, so the agency finally reads what the client wrote back;
approval history; notification preferences per user; brand-scoped assignment.

- [x] Post conversations, with client-visible and internal-only comments
- [x] The agency can read client comments (this was the gap that motivated the phase)
- [x] Notification preferences honoured by `PostEventDispatcher`
- [x] Brand-scoped assignment enforced in policies, not just in the UI
- [x] `PHASE-6-COMPLETION.md` written

---

## Phase 7 — Engagement ⚠ *(unified inbox built, no real provider)*

Built: `inbox_threads` and `inbox_messages`, the sync command (`inbox:sync`, every fifteen
minutes), assignment, status, internal notes, and reply queued through the provider layer.

- [x] Threads and messages, normalised across providers, tenant-scoped
- [x] Assign, status, internal note, reply
- [x] Structurally separate from publishing — shares only the OAuth and provider layers
- [ ] **Comments and messages from a real network** — same block as Phase 2
- [ ] Lead conversion hooks — waits on the CRM below

---

## Phase 8 — Business Expansion ⚠ *(partial)*

- [x] **White-label theming** — per-agency `branding_settings` (name, logo, colours),
      resolved for the portal and for outbound mail, with an editing screen behind an
      entitlement
- [x] **Custom domains** — an agency claims a hostname, verifies it by DNS TXT, and the
      portal answers on it. Portal-only by design: the agency application stays on the
      platform hostname, so a misconfigured client domain cannot take an agency offline.
      TLS provisioning is deployment work, not application code — see `11-DEPLOYMENT.md`.
- [ ] **Reseller system** — not started, and deliberately so. `TenantType`'s own
      docblock records the decision: "Reseller exists so the hierarchy column
      (tenants.parent_tenant_id) has meaning from day one and a reseller tier can be
      inserted later without a data migration. No reseller behaviour ships in V1." The
      column and the enum case are the whole V1 deliverable; building the tier now would
      contradict that decision rather than complete it.
- [ ] **Social CRM** — not started
- [ ] **WhatsApp Business API** — not started; a separate messaging architecture, **not**
      another post provider
- [ ] **Threads, Google Business Profile, Pinterest, TikTok, Reddit, Quora** — not started,
      and correctly behind the first real provider

---

---

## Gaps closed after the phase they belonged to

A recurring failure in this repository deserves its own section, because it is
the one a checklist cannot catch: **a mechanism built, tested, and one wire
short of reachable.** A checklist counts what was BUILT, which is exactly what
this pattern hides. Everything below was fully implemented, covered by tests,
and impossible to reach from the product.

They were found by sweeping for methods, scopes and permissions with no call
site — not by reading the checklist, which said all of them were done.

| What existed | What was missing | Phase it belonged to |
|---|---|---|
| `OAuthStateService`, provider contract, every adapter capability | Any route or controller. A social account could only be created by hand | 2 |
| `social_accounts.max` on every plan | Anything that counted them | 1 |
| `Tenant::permitsPublishing()` | Any caller. Suspended agencies kept publishing | 1 |
| Token refresh service | Anything that drove it | 2 |
| AI feature registry | A path from it to a screen | 4 |
| Client portal comments | A screen where the agency could read them | 6 |
| `post_metrics`, `SupportsAnalytics` | A collector, a table, a screen | 5 |
| `branding_settings` | A reader, then a writer | 8 |
| `domains` | Anything attached to it | 8 |
| `reports.generate`, `reports.share` | Anything they governed | 5 |
| `PostStatus::isEditable()` | Any caller. A post could never be edited, so `Rejected` was a dead end | 3 |
| No reschedule route | The calendar rendered posts nobody could move | 3 |
| `posts.scheduled_per_month` | A usage counter — it read `0` with a "Phase 3" note | 1 |
| `posts.bulk_import` | Anything it governed | 3 |
| `login_histories`, and an index built for the query | Any screen. A security log only the database could see | 1 |
| `social_app_credentials`, `toSafeArray()`, `social_credentials.manage`, `oauth_states.social_app_credential_id`, `OAuthContext::$clientId` | A screen, and anything that selected a credential. Bring-your-own credentials — a stated differentiator — was a schema comment | 2 |
| `MediaStatus::countsTowardStorage()`, `AccountStatus::countsTowardLimit()` | Callers. Both quotas restated the rule as a literal list | 1 |
| `BrandingResolver::supportEmail()`, `secondaryColor()` | Any template. An agency filled in fields nothing read | 8 |
| `InboxMessage::scopeUndelivered()` | Any caller. A reply that never sent was visible only inside its own thread | 7 |

**How to find the next one.** Sweep for public methods, query scopes and
permission keys with no call site outside their own file. Most hits are
Eloquent relations read as properties — ignore those. What is left is either
dead code that should go, or a feature that was built and never connected.

---

## Sequencing rationale

- **Tenancy before everything.** Retrofitting isolation onto existing tables and queries is
  a rewrite, and the tests that prove it are worthless if written after the fact.
- **Social connections before publishing.** The publisher cannot be designed against
  imagined provider behaviour; Meta's two-phase Instagram flow, for example, changes the
  idempotency design.
- **Publishing before AI.** AI generates content for a pipeline that must already exist.
  AI-first would produce text with nowhere to go.
- **Analytics after publishing.** There is nothing to measure until posts are going out,
  and analytics is the phase that forces the infrastructure upgrade.
- **Inbox and CRM last.** They are separate problem domains sharing only the OAuth layer;
  starting them early would destabilise the provider abstraction while it is still settling.

## Cross-phase running obligations

Applies to every phase:

- Update `/docs` in the same PR as the change, not afterwards.
- Write `PHASE-X-COMPLETION.md` recording features delivered, schema changes, decisions,
  new environment variables, new commands, cron and queue requirements, known limitations,
  outstanding TODOs, and test instructions.
- Tenant-isolation tests are re-run and extended for every new tenant-owned model.
- Any `[VERIFY]` marker in the docs that a phase touches must be resolved against live
  provider documentation, and the marker replaced with the verified fact plus the date.
