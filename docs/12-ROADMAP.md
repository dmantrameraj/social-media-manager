# 12 — Roadmap

Each phase has explicit exit criteria. **A phase is not complete until its tests pass and
`/docs/PHASE-X-COMPLETION.md` exists.**

Effort estimates assume one experienced full-stack Laravel developer and are for sequencing,
not commitment.

---

## Phase 0 — Architecture & Documentation ✅ *(this phase)*

Deliverables: the 13 documents in `/docs`, the ERD, and the Phase 1 checklist.

**Exit criteria**
- [x] Repository inspected; environment capabilities and gaps recorded
- [x] All architecture documents written and internally consistent
- [x] ERD covers every V1 entity with keys, indexes and constraints
- [x] Tenancy, RBAC, provider, publishing, AI-credit and billing strategies decided
- [x] Shared-hosting → VPS migration path defined
- [x] Phase 1 checklist produced
- [ ] **Human review and approval** ← the actual gate

---

## Phase 1 — Foundation *(~3–4 weeks)*

Laravel scaffold; multi-tenancy; auth (both guards) with 2FA; RBAC; agency and brand
management; team invitations; customer portal login; media library foundation; audit log;
notifications; plans, entitlements and subscription models; Razorpay foundation; manual
activation; Super Admin foundation.

**Exit criteria**
- [ ] **All tenant-isolation tests pass** — mandatory, no override
- [ ] Authorization tests pass, including brand assignment and portal isolation
- [ ] An agency can be created (self-serve and manual) and log in
- [ ] Brands can be created, and entitlement limits are enforced
- [ ] Team invitations work with role assignment
- [ ] A portal user can log in and see only their assigned brands
- [ ] Media upload/list/delete works on a private disk with signed delivery
- [ ] Audit logs capture the actions listed in `10-SECURITY.md` §10
- [ ] Entitlement resolution honours override → plan → default
- [ ] Super Admin can create, suspend and reactivate a tenant
- [ ] `PHASE-1-COMPLETION.md` written

---

## Phase 2 — Social Connections *(~3–4 weeks)*

Provider interface and registry; capability model; per-tenant credential management; OAuth
framework with state and PKCE; connection and account models; account discovery and
selection; token refresh; health checks; reconnect flow. Then providers **incrementally**:
Facebook → Instagram → *stabilise* → LinkedIn → X → YouTube.

**Exit criteria**
- [ ] OAuth state tests pass (single use, tenant-bound, expiry, redirect validation)
- [ ] Tokens are encrypted; a serialisation test proves they never appear in output
- [ ] Facebook Page and Instagram Business connect, discover and assign to a brand
- [ ] Token refresh works; failure sets `needs_reconnect` and notifies
- [ ] Reconnect updates the existing connection and preserves account assignments
- [ ] Capabilities resolve correctly from account type plus granted scopes
- [ ] `/docs/providers/{provider}.md` exists per provider, dated
- [ ] `PHASE-2-COMPLETION.md` written

> Do **not** start all five providers in parallel. Meta must be stable before LinkedIn
> begins — Meta is the most complex and will teach the abstraction what it is missing.

---

## Phase 3 — Publishing Engine *(~4–5 weeks)*

Unified composer with per-platform overrides; provider validation; approval workflow and
state machine; scheduling with timezones; content calendar; queued publishing with claim
locking; retry and error classification; failure UI; publication history; CSV bulk import;
recurring post architecture.

**Exit criteria**
- [ ] All `06-PUBLISHING-ENGINE.md` §12 tests pass
- [ ] A post publishes independently to five targets; one failure does not fail the others
- [ ] Concurrent dispatch claims a target exactly once
- [ ] Stale locks go to verification, not blind retry
- [ ] Approval workflow enforces transitions and permissions, with a full audit trail
- [ ] Calendar drag-and-drop re-validates server-side
- [ ] Timezone handling verified across at least three zones including a DST boundary
- [ ] CSV import handles partial success with per-row reporting
- [ ] `PHASE-3-COMPLETION.md` written

**This phase delivers the core product.** After it, the platform is usable.

---

## Phase 4 — AI *(~3–4 weeks)*

AI provider abstraction; Brand Brain CRUD and context builder; credit ledger with
reservations; caption, hashtags, ideas, rewrite, tone, translate, platform adaptation, reel
scripts, YouTube metadata; monthly content plan. Autopilot **last**, only once manual AI is
stable.

**Exit criteria**
- [ ] All `08-AI-ARCHITECTURE.md` §9 tests pass
- [ ] Brand Brain context never crosses tenant or brand boundaries
- [ ] Ledger sum reconciles to the cached balance under randomised transactions
- [ ] Failed generations do not charge credits
- [ ] Monthly reset applies rollover caps and is idempotent
- [ ] Swapping the provider implementation leaves feature tests green
- [ ] Autopilot cannot bypass approval gates
- [ ] `PHASE-4-COMPLETION.md` written

---

## Phase 5 — Analytics & Reporting *(~4 weeks)*

Provider analytics adapters; normalised metrics with provider-specific raw retention;
dashboards; PDF and Excel export; secure share links; scheduled monthly reports.

Prerequisite: **VPS migration is likely required here** — analytics polling adds sustained
background load that the shared-hosting worker window will not absorb.

---

## Phase 6 — Collaboration *(~2–3 weeks)*

Advanced team permissions; brand-scoped assignment refinement; internal review threads;
richer client approval UX; approval history views; notification preferences.

---

## Phase 7 — Engagement *(~4–5 weeks)*

Unified inbox: comments and messages where provider APIs permit; reply, assign, status,
internal notes; lead conversion hooks. Kept structurally separate from publishing.

---

## Phase 8 — Business Expansion *(ongoing)*

Social CRM; WhatsApp Business API (a separate messaging architecture, **not** another post
provider); Threads, Google Business Profile, Pinterest, TikTok, Reddit, Quora; white-label
theming; custom domains; reseller system.

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
