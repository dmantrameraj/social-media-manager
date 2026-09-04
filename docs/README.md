# Documentation Index

Multi-Tenant AI Social Media Management SaaS

**Current state:** Phases 1–7 are built and tested; Phase 8 is partial (white-label and
custom portal domains done, reseller and Social CRM not started). Two things stand between
this and a product an agency could pay for, and neither is a design problem:

- **No real social provider is registered in production.** The registry, OAuth flow,
  publishing engine and analytics collector all work against a fake driver that is refused
  outside local and test environments. Nothing can publish to a live network.
- **Razorpay is a foundation only** — no checkout initiation, no webhook processing, no
  invoices.

Both are blocked on live provider documentation, per the rule below about `[VERIFY]` markers.

## Read in this order

| # | Document | Read when |
|---|---|---|
| 00 | [Project Overview](00-PROJECT-OVERVIEW.md) | Start here — scope, hierarchy, constraints |
| 01 | [Architecture](01-ARCHITECTURE.md) | Stack, layering, module boundaries |
| 02 | [Database ERD](02-DATABASE-ERD.md) | Before writing any migration |
| 03 | [Tenancy](03-TENANCY.md) | Before writing any model or query |
| 04 | [Auth & RBAC](04-AUTH-RBAC.md) | Before writing any policy |
| 05 | [Social Providers](05-SOCIAL-PROVIDERS.md) | Before Phase 2 |
| 06 | [Publishing Engine](06-PUBLISHING-ENGINE.md) | Before Phase 3 |
| 07 | [Queue Architecture](07-QUEUE-ARCHITECTURE.md) | Before writing any job |
| 08 | [AI Architecture](08-AI-ARCHITECTURE.md) | Before Phase 4 |
| 09 | [Billing](09-BILLING.md) | Before touching plans or limits |
| 10 | [Security](10-SECURITY.md) | Continuously |
| 11 | [Deployment](11-DEPLOYMENT.md) | Before first deploy; environment prerequisites |
| 12 | [Roadmap](12-ROADMAP.md) | Phase planning |
| — | [Phase 1 Checklist](PHASE-1-CHECKLIST.md) | Phase 1 execution |

## What actually got built

| Document | Covers |
|---|---|
| [Phase 1 Completion](PHASE-1-COMPLETION.md) | Foundation: tenancy, auth, RBAC, media, audit, entitlements |
| [Phases 2 & 3 Progress](PHASE-2-3-PROGRESS.md) | Social connection and publishing foundations |
| [Phase 4 Progress](PHASE-4-PROGRESS.md) | AI features, Brand Brain, credit ledger, autopilot |
| [Phases 5–8 Progress](PHASE-5-8-PROGRESS.md) | Analytics, collaboration, inbox, white-label, domains |

The `PHASE-1-STEP-*.md` files record individual steps of Phase 1 and are mostly of
historical interest.

## The rules that do not bend

1. **Tenant isolation** — five layers, tests are a merge gate with no override.
2. **No hardcoded limits** — plan limits, retries, rate limits and content caps live in
   config or the database.
3. **Secrets never leave the database** — encrypted at rest, never serialised, never
   logged, never shown to Super Admin.
4. **No passwords for social networks, ever** — OAuth only.
5. **Independent per-target publishing** — one provider failing never fails the post.
6. **No provider name in domain logic** — everything goes through the abstraction.
7. **Documentation updates ship in the same PR as the change.**

## `[VERIFY]` markers

Any external API fact — endpoints, scopes, field names, limits, quotas, webhook event names
— is marked **[VERIFY]** and must be confirmed against current official provider
documentation before code depending on it ships. Do not implement against a guess. Replace
the marker with the verified fact and the date it was checked.

Concentrations of these markers: `05-SOCIAL-PROVIDERS.md` (Meta, LinkedIn, X, YouTube) and
`09-BILLING.md` (Razorpay).

## Phase completion documents

At the end of each phase, add `/docs/PHASE-X-COMPLETION.md` recording: features delivered,
schema changes, architecture decisions, new environment variables, new commands, cron and
queue requirements, known limitations, outstanding TODOs, and test instructions.
