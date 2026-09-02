# Phase 4 — AI

**Date:** 2026-09-02
**Status:** Complete, apart from the UI. All twelve features, autopilot, and the
maintenance commands are built and verified.

---

## 1. What was built

**Provider abstraction** — `AiProviderInterface` plus `AiRequest` / `AiResponse` DTOs.
`AnthropicProvider` is the only class in the codebase that names a vendor; every feature
above it speaks DTOs. Swapping providers is a config change.

**`AnthropicProvider`** — written against the official `anthropic-ai/sdk` PHP package
(v0.46), not from memory. Details that matter:

- Model comes from `config/ai.php`, never hardcoded in a feature — models are deprecated
  on a schedule, and a hardcoded ID becomes an outage.
- `thinking: ['type' => 'adaptive']`. `budgetTokens` is rejected on this model family.
- Structured output is requested in a final user turn, **not** an assistant prefill, which
  this model family rejects.
- Content blocks are iterated rather than read at index 0 — with adaptive thinking a
  thinking block can precede the text.
- The brand context sits in a single `cacheControl` system block: it is identical across
  every generation for that brand and is the largest part of the request.
- Vendor errors map onto our own retryable/permanent split, so the caller knows whether
  releasing the reservation and retrying is worthwhile.

**Brand Brain** — per-customer grounding profile with a completeness score, surfaced
because output quality tracks it directly and users need to understand why thin input
yields thin output.

**`BrandBrainContextBuilder`** — assembles the grounding context. Two security properties,
both tested:

- The customer is verified against the **active tenant** before any of its content reaches
  a prompt. Cross-tenant grounding would be a data leak dressed as a feature.
- Brand Brain content is user-supplied and lands in a **system** prompt, so it is treated
  as untrusted: capped per field, delimiter-forgery stripped, and explicitly labelled as
  reference data rather than instructions. Without that, a brand tone of *"ignore all
  previous instructions…"* is a prompt injection carrying operator authority.

**`GenerateContentService`** — the orchestration, in a deliberate order:

```
entitlement -> reserve credits -> build prompt -> call provider -> commit/release -> log
```

Credits are reserved **before** the provider is called, so concurrent requests cannot
overspend, and released on failure, so a failed generation is never charged.

**Features — all twelve built.** Each declares only the Brand Brain sections it needs: a
hashtag generator does not need competitor analysis, and padding the prompt with it costs
credits and dilutes the output.

| Feature | Credits | Output |
|---|---|---|
| `caption` | 1 | Single caption |
| `hashtags` | 1 | Deduped, normalised list |
| `rewrite` | 1 | Transformed text |
| `tone` | 1 | Transformed text |
| `translate` | 1 | Transformed text |
| `platform_adaptation` | 1 | Text valid for the target platform |
| `youtube_title` | 1 | Several candidates |
| `ideas` | 2 | Hook / angle / format objects |
| `youtube_description` | 2 | Description, length-capped |
| `reel_script` | 3 | Hook, scenes, call to action |
| `blog_to_social` | 3 | Several standalone posts |
| `monthly_plan` | 25 | Dated, platform-tagged entries |

Three of them — `rewrite`, `tone`, `translate` — share a `TransformsText` trait, because
they genuinely differ only in their instruction. Features with different output shapes
deliberately do not use it.

**`PlatformAdaptationFeature` is the one that ties AI to publishing.** Its limits come from
`config/social.php` — the same source the provider validators read — so an adapted variant
is valid for its destination *by construction* rather than being generated and then
rejected at publish time. It also knows, from the capability flags, that an Instagram
caption cannot carry a clickable link, so it stops the model writing "click the link below"
into a dead end.

**`AiFeatureRegistry`** resolves a feature key to a class through an explicit map. Keys
arrive from requests, so deriving a class name from user input was not an option.

**`FakeAiProvider`** — scriptable, so the credit accounting, brand grounding and failure
handling are provable without an API key and without spending money on every test run.

## 2. Verified

| Gate | Result |
|---|---|
| AI suite | **73 passing** (18 generation + 55 feature) |
| Tenant isolation | **36 passing** (now covering `brand_brains`) |
| Static analysis | PHPStan level 5, **0 errors** |

Two registry tests keep the catalogue and the code honest in both directions: a feature
with a configured price but no implementation would be offered in the UI and then fail,
and a feature without a price would be given away. Every feature is also exercised
end-to-end — charged its configured cost, grounded in the brand profile, leaving no
reservation stranded.

Properties proven by test:

- A failed generation costs the tenant nothing, and leaves no reservation stranded.
- An unaffordable request throws **before** the provider is called, so it never costs real
  money.
- Another tenant's brand can never reach a prompt.
- Only the brand sections a feature declares are sent.
- A forged `</brand_profile>` tag in a brand field is stripped, so injected text cannot
  break out of the data block.
- An oversized field is capped, so one field cannot dominate the context.
- Forbidden words are flagged in post-processing, not silently rewritten — models do not
  reliably honour negative constraints, and the agency should decide what to do.
- Hashtags survive a markdown-fenced response; a usable list in the wrong wrapper is still
  a usable list.
- The ledger reconciles to zero drift after a mix of successes and failures.

## 3. Not done

- **No live API call has been made.** `ANTHROPIC_API_KEY` is unset, so every test runs
  against `FakeAiProvider`. The adapter is written to the documented SDK surface but has
  not been exercised against the real service — set the key and run one real generation
  before trusting it.
- **UI** — no Brand Brain editor and no generation surface in the composer.

## 5. Autopilot

Built, with one property that is load-bearing and tested from several angles:
**autopilot creates posts at `DRAFT` and has no path to `SCHEDULED`.** It does not set
approval state. Everything it produces traverses the same `PostStatusMachine` and the same
client-approval gate as human-authored content.

That is not a limitation to be relaxed later. An agency's client did not agree to let a
model post on their behalf unreviewed, and a feature that quietly bypassed approval would
be the fastest way to lose an account.

Gating, all tested:

| Gate | Behaviour |
|---|---|
| Per-brand opt-in | `enabled` defaults false — never runs for a brand nobody switched on |
| Global kill switch | `config('features.autopilot')`, independent of any tenant setting |
| Plan entitlement | `ai.autopilot` must be enabled |
| Tenant status | A suspended tenant does not get free generation |
| Brand status | Archived brands are skipped |
| Credits | Running dry is a skip, not a crash |

Every generated post carries `source = 'ai'` and an audit entry, so provenance is never
ambiguous. Cadence is spread across the week rather than firing a week of drafts at once —
a client seeing seven drafts appear in one minute reads as a malfunction.

Failures are caught broadly and deliberately: credits, entitlement and provider errors all
mean the same thing here — this brand gets nothing this run, and the sweep continues. One
broken brand must not halt every other, and the clock still advances so it cannot
monopolise future runs.

## 6. Maintenance commands

**`ai:sweep-reservations`** (every ten minutes) returns credits held by generations that
never finished. A worker killed mid-generation leaves credits neither spent nor available,
so without this the tenant slowly and permanently loses spending power with nothing to show
for it. Idempotent via the ledger's idempotency key, and it runs platform-wide rather than
per tenant.

**`ai:purge-snapshots`** (daily) clears request/response snapshots past
`ai.snapshot_retention_days`. The snapshots hold customer business content; the generation
**row survives**, so token counts and cost per tenant stay measurable after the content is
gone.

## 4. Cost note

Credits are an internal unit decoupled from vendor pricing, so changing model does not
change what a customer was sold. Token counts are still recorded on every generation, so
real cost per tenant stays measurable independently of what is charged. Flat per-feature
cost keeps the common case predictable; token overage protects margin on outliers.
