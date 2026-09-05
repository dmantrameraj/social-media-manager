# Phase 4 — Completion Report

**Date:** 2026-09-05
**Status:** Complete. All exit criteria met and tested.

Unlike the social providers, the AI provider is real: `AnthropicProvider` is written
against the published PHP SDK and runs in production.

---

## 1. Verified state

| Gate | Result |
|---|---|
| AI suite | **109 passing** across 7 files |
| Full suite | 915 passing, 2411 assertions |
| Static analysis | PHPStan level 5, 0 errors |
| Formatting | Pint clean |

```bash
php artisan migrate:fresh --env=testing --force
vendor/bin/pest tests/Feature/AI
```

## 2. Exit criteria

- [x] All `08-AI-ARCHITECTURE.md` §9 tests pass
- [x] Brand Brain context never crosses tenant or brand boundaries
- [x] Ledger sum reconciles to the cached balance under randomised transactions
- [x] Failed generations do not charge credits
- [x] Monthly reset applies rollover caps and is idempotent
- [x] Swapping the provider implementation leaves feature tests green
- [x] Autopilot cannot bypass approval gates

## 3. Features delivered

**Twelve AI features**, all through one registry: caption, hashtags, ideas, rewrite, tone,
translate, platform adaptation, reel script, YouTube title, YouTube description, blog to
social, monthly plan.

**Provider abstraction** — `AiProviderInterface` plus `AiRequest` / `AiResponse` DTOs.
`AnthropicProvider` is the only class in the codebase that names a vendor; every feature
above it speaks DTOs, so swapping providers is a config change and the feature tests prove
it.

**`AnthropicProvider`**, written against the official `anthropic-ai/sdk` PHP package rather
than from memory. The details that matter:

- The model comes from `config/ai.php`, never hardcoded in a feature. Models are deprecated
  on a schedule, and a hardcoded id becomes an outage.
- `thinking: ['type' => 'adaptive']`. `budgetTokens` is rejected on this model family.
- Structured output is requested in a final user turn, **not** an assistant prefill, which
  this model family rejects.
- Content blocks are iterated rather than read at index 0 — with adaptive thinking, a
  thinking block can precede the text.
- The brand context sits in a single `cacheControl` system block: it is identical across
  every generation for that brand and is the largest part of the request.
- Vendor errors map onto our own retryable/permanent split, so the caller knows whether
  releasing the reservation and retrying is worthwhile.

**Brand Brain** — a per-customer grounding profile with a completeness score, surfaced
because output quality tracks it directly and users need to understand why thin input
yields thin output.

**`BrandBrainContextBuilder`** — two security properties, both tested. The customer is
verified against the **active tenant** before any of its content reaches a prompt;
cross-tenant grounding would be a data leak dressed as a feature. And Brand Brain content
is user-supplied and lands in a **system** prompt, so it is treated as untrusted: capped
per field, delimiter-forgery stripped, and explicitly labelled as data rather than
instruction.

**Credit ledger with reservations** — a generation reserves credits before the call and
settles or releases after. A crashed worker leaves a reservation, not a charge, and the
sweeper releases it. Failed generations therefore cost nothing, which is the exit criterion
and also the only behaviour a customer will accept.

**Autopilot** — generates content on a per-brand cadence, always as a **draft**, always
carrying the brand's approval requirement through. It stops when a tenant runs out of
credits, skips a suspended tenant rather than generating for free, and keeps its output
inside its own tenant.

## 4. Schema

`ai_credit_accounts`, `ai_credit_transactions`, `brand_brains`, `ai_generations`,
`autopilot_settings`.

`ai_credit_transactions` is append-only and is the source of truth; the account's balance
is a cache of it, and `ai:reconcile-credits` proves the two agree.

## 5. New commands and cron

| Command | Cadence | Purpose |
|---|---|---|
| `ai:run-autopilot` | hourly | generate drafts for brands whose cadence has come around |
| `ai:reset-monthly-credits` | hourly | reset each tenant on its own anniversary |
| `ai:sweep-reservations` | every 10 min | release reservations from workers that died |
| `ai:purge-snapshots` | daily 03:40 | drop prompt snapshots past retention |
| `ai:reconcile-credits` | daily 04:20 | assert the ledger sum matches the cached balance |

`ai:reset-monthly-credits` runs **hourly, per-tenant anniversary, idempotent per period** —
a nightly run would leave some tenants exhausted for up to 23 extra hours every month.

## 6. Queue requirements

Generations run on the `ai` queue, included in the scheduled `queue:work` list.

## 7. New environment variables

| Variable | Default | Meaning |
|---|---|---|
| `AI_DEFAULT_PROVIDER` | anthropic | which adapter the registry resolves |
| `ANTHROPIC_API_KEY` | — | required for real generation |
| `ANTHROPIC_MODEL` | (see config) | model id, never hardcoded in a feature |
| `AI_RESERVATION_TTL` | 900 | seconds before a reservation is swept |
| `AI_SNAPSHOT_RETENTION_DAYS` | (see config) | how long prompt snapshots are kept |
| `AI_TOKEN_OVERAGE` | (see config) | overage handling for token-heavy features |

## 8. Known limitations and outstanding TODOs

**Cost is a flat credit price per feature**, not measured tokens. Predictable for the
customer, and it means a pathological prompt costs us more than it charges. `token_overage`
exists to bound that and is worth revisiting once real usage exists.

**No streaming.** Generations are request/response, so a long monthly plan is a long wait
with no partial output.

**Prompt snapshots are retained by default.** They are the only way to explain why a
generation produced what it did, and they contain brand context — which is why the
retention window exists and why `ai:purge-snapshots` runs nightly.

**Autopilot has no per-brand preview.** A brand owner opts in and finds drafts; they cannot
see what a run would produce before enabling it.
