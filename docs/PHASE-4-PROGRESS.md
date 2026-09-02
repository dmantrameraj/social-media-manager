# Phase 4 — AI

**Date:** 2026-09-02
**Status:** Core built and verified. Autopilot and the remaining features outstanding.

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

**Features** — `CaptionFeature` and `HashtagsFeature`, each declaring only the Brand Brain
sections it needs. A hashtag generator does not need competitor analysis, and padding the
prompt with it costs credits and dilutes the output.

**`FakeAiProvider`** — scriptable, so the credit accounting, brand grounding and failure
handling are provable without an API key and without spending money on every test run.

## 2. Verified

| Gate | Result |
|---|---|
| AI suite | **18 passing** |
| Tenant isolation | **36 passing** (now covering `brand_brains`) |
| Static analysis | PHPStan level 5, **0 errors** |

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
- **Remaining features:** ideas, rewrite, tone, translate, platform adaptation, reel
  script, YouTube title/description, blog-to-social, monthly plan. The interface and cost
  table cover them; the classes are not written.
- **Autopilot** — deliberately last, per the roadmap. When built it must enter the same
  workflow at `DRAFT` and traverse the same approval gates; it cannot bypass client
  approval.
- **Reservation sweeper** — `ai.reservation_ttl` is configured but nothing consumes it, so
  a crashed worker's reservation is not yet auto-released.
- **Snapshot purge** — `AiGeneration::snapshotsExpired()` exists; the scheduled command
  that calls it does not.
- **UI** — no Brand Brain editor and no generation surface in the composer.

## 4. Cost note

Credits are an internal unit decoupled from vendor pricing, so changing model does not
change what a customer was sold. Token counts are still recorded on every generation, so
real cost per tenant stays measurable independently of what is charged. Flat per-feature
cost keeps the common case predictable; token overage protects margin on outliers.
