# 00 — Project Overview

**Product:** AI-powered multi-tenant Social Media Management SaaS
**Status:** Phase 0 (architecture & documentation) — no application code exists yet
**Last updated:** 2026-09-01

---

## 1. What we are building

A multi-tenant SaaS that lets digital agencies and freelancers manage social media
publishing for many client brands from one workspace, with AI assistance grounded in
per-brand context, and a client-facing approval portal.

Functional peers: Buffer, Hootsuite, SocialPilot, Metricool.

Intended differentiation:

| Differentiator | Why it matters | Phase |
|---|---|---|
| Agency → Customer/Brand hierarchy as a first-class model | Competitors bolt "clients" onto a flat account model | 1 |
| Client approval portal with audit trail | Agencies currently do this over WhatsApp/email | 1 |
| AI **Brand Brain** (per-brand grounding context) | Generic AI captions are the current norm and are low value | 4 |
| Bring-your-own developer app credentials | Removes platform-wide API quota as a scaling ceiling | 2 |
| White-label + custom domains | Agencies resell under their own identity | 8 |
| Reseller tier | Distribution channel | 8 |

## 2. Hierarchy

```
SUPER ADMIN  (platform operator)
  └── TENANT  (type = agency | reseller)          ← the unit of isolation & billing
        ├── Users (team members, RBAC roles)
        └── CUSTOMER / BRAND (workspace)
              ├── Brand Brain
              ├── Media library
              ├── Social accounts (pages, IG accounts, channels…)
              ├── Posts → per-platform targets
              └── Portal users (client logins)
```

`tenants.parent_tenant_id` is present from day one so a RESELLER tier can be inserted
above AGENCY later without a data migration. **No reseller behaviour ships in V1.**

## 3. Scope

### In scope for V1

**Product core**
1. Customer/Brand workspaces
2. Social account connections (Facebook, Instagram, LinkedIn, X, YouTube)
3. Unified post composer with per-platform overrides
4. Scheduling + content calendar
5. AI brand content assistant

**Required SaaS foundation**
Authentication · multi-tenancy · RBAC · customer portal login · approval workflow ·
billing (Razorpay) · 7-day trial · subscription lifecycle · AI credit ledger ·
media library · audit logs · 2FA · social provider abstraction · queue architecture ·
notifications · basic Super Admin · configurable plan limits · manual account activation

### Explicitly NOT in V1

Analytics dashboards · reporting/PDF export · unified inbox · social CRM · WhatsApp ·
full white-label theming · custom domains · reseller portal · AI autopilot ·
TikTok/Pinterest/Threads/GBP/Reddit/Quora.

Some of these have **schema stubs** in V1 (`domains`, `branding_settings`,
`feature_flags`, `tenants.parent_tenant_id`) purely so that adding them later is
additive rather than a rewrite. Schema stub ≠ feature.

## 4. Definition of Done for V1

The following end-to-end flow works, with tests:

```
Super Admin creates Agency (manual) OR agency self-signs up on trial
  → Agency creates Customer/Brand
  → Agency configures Brand Brain
  → Agency connects the Customer's Facebook Page + IG Business account
  → Content Creator drafts a post targeting FB + IG + LinkedIn
  → Manager approves internally
  → Customer approves in the portal
  → Post is scheduled
  → Queue publishes each target INDEPENDENTLY
  → LinkedIn fails; FB + IG succeed; post is not marked wholly failed
  → LinkedIn retries with backoff, then surfaces a Retry button
  → Audit log records every transition
```

## 5. Constraints that shape the architecture

| Constraint | Consequence |
|---|---|
| Launch on Hostinger **shared hosting** | No root, no Supervisor, no Redis guarantee, no long-running processes. Queue = `database` driver, driven by a 1-minute cron. No FFmpeg dependency in V1. |
| Migrate to VPS later without rewriting | All infra touchpoints go through Laravel abstractions (Queue, Cache, Storage, Mail). Swapping drivers must be a `.env` change. |
| ~10,000 tenants eventually | Single database + row-level tenant isolation. Indexed `tenant_id` on every tenant-owned table. No per-tenant database. |
| Social APIs are unstable and inconsistent | Providers sit behind an interface with declared capabilities. No provider name appears in domain logic. |
| Tenant data leakage is an existential risk | Isolation is enforced in five independent layers (see `03-TENANCY.md`). Isolation tests are a merge gate. |
| Agencies supply their own API credentials | Secrets are encrypted at rest, write-only from the UI, never serialised, redacted from logs, and invisible to Super Admin. |

## 6. Glossary

| Term | Meaning |
|---|---|
| **Tenant** | The billable, isolated account. In V1 always an agency. Row in `tenants`. |
| **Customer / Brand** | A client workspace inside a tenant. Row in `customers`. Never shared across tenants. |
| **User** | Agency team member or Super Admin. `web` guard. |
| **Portal user** | A client login with approval rights only. `customer` guard, separate table. |
| **Social connection** | One OAuth grant (one authorised identity on one provider). Holds tokens. |
| **Social account** | One publishable destination derived from a connection (a Page, IG Business account, LinkedIn org, YouTube channel). |
| **Post** | The master content record for a customer. |
| **Post target** | The per-social-account publication record. Owns its own status, schedule, retries and external ID. |
| **Entitlement** | A resolved numeric/boolean limit for a tenant (`override > plan feature > default`). |
| **AI credit** | Internal accounting unit for AI usage. Ledger-backed, never a bare integer. |

## 7. Document index

| Doc | Contents |
|---|---|
| `00-PROJECT-OVERVIEW.md` | This file — scope, hierarchy, constraints |
| `01-ARCHITECTURE.md` | Layers, module boundaries, directory layout |
| `02-DATABASE-ERD.md` | Full entity model and table definitions |
| `03-TENANCY.md` | Isolation strategy and its five enforcement layers |
| `04-AUTH-RBAC.md` | Guards, roles, permission catalogue, 2FA |
| `05-SOCIAL-PROVIDERS.md` | Provider interface, capabilities, OAuth, token lifecycle |
| `06-PUBLISHING-ENGINE.md` | Composer, validation, state machine, idempotency, retries |
| `07-QUEUE-ARCHITECTURE.md` | Queues, scheduler, shared-hosting worker strategy |
| `08-AI-ARCHITECTURE.md` | AI provider abstraction, Brand Brain, credit ledger |
| `09-BILLING.md` | Plans, entitlements, Razorpay, subscription lifecycle |
| `10-SECURITY.md` | Threat model and controls |
| `11-DEPLOYMENT.md` | Shared hosting today, VPS tomorrow |
| `12-ROADMAP.md` | Phase plan and exit criteria |
| `PHASE-1-CHECKLIST.md` | Ordered implementation checklist for Phase 1 |
