# 08 — AI Architecture

## 1. Layering

```
AI feature UI  (Livewire)
      |
AiFeature        caption | hashtags | ideas | rewrite | translate | tone |
      |          reel_script | yt_title | yt_description | monthly_plan
BrandBrainContextBuilder      assembles per-brand grounding
      |
CreditGuard                   reserve -> execute -> commit/release
      |
AiProviderInterface           vendor-neutral
      |
AnthropicProvider             (default V1)
```

No feature class knows which vendor is in use, and no vendor adapter knows what a brand is.

## 2. Provider abstraction

```php
interface AiProviderInterface
{
    public function key(): string;

    public function generate(AiRequest $request): AiResponse;

    /** @return \Generator<AiChunk> */
    public function stream(AiRequest $request): \Generator;

    public function estimateCredits(AiRequest $request): int;

    public function supports(AiCapability $capability): bool;   // streaming, vision, json_mode
}
```

```php
final readonly class AiRequest
{
    public function __construct(
        public string $system,
        public array $messages,
        public ?string $model = null,
        public int $maxTokens = 1024,
        public float $temperature = 0.7,
        public ?array $jsonSchema = null,
        public array $meta = [],       // tenant, customer, feature, request id
    ) {}
}

final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
        public string $stopReason,
        public array $raw = [],
    ) {}
}
```

`config/ai.php` holds providers, model IDs per tier (`fast`/`balanced`/`quality`), token
limits, credit costs, and per-feature model selection. **Model IDs are never hardcoded in
feature classes** — models are deprecated on a schedule and a hardcoded ID becomes an outage.

Default provider for V1 is Anthropic. Adding a second vendor means implementing the
interface and adding a config entry; no feature class changes.

## 3. Brand Brain

Per-customer structured brand context (`brand_brains`, one row per customer). It is what
makes generated content specific rather than generic, and it is the product's main AI
differentiator.

Captured: business description, industry, target audience, locations, products, services,
USPs, brand tone and voice notes, languages, website, competitors, CTAs, forbidden words,
preferred keywords, brand colours, social goals, content themes.

### Context assembly

```php
final class BrandBrainContextBuilder
{
    public function build(Customer $customer, AiFeature $feature): string
    {
        $brain = $customer->brandBrain;

        // Only the sections this feature actually needs. A hashtag generator
        // does not need competitor analysis, and padding the prompt with it
        // costs credits and dilutes the output.
        $sections = $feature->requiredBrainSections();

        return $this->render($brain, $sections, $customer);
    }
}
```

Rules:

- Context is **always** built from the current tenant's brand. `BrandBrainContextBuilder`
  takes a `Customer` that has already passed the tenant scope and a policy check. A test
  asserts that no AI request can be constructed for a customer outside the active tenant.
- Cross-tenant brand data is never mixed into a prompt. There is no shared corpus, no
  "learn from all agencies" path.
- Forbidden words are enforced **twice**: instructed in the prompt, and checked in
  post-processing. Models do not reliably honour negative constraints, so an instruction
  alone is not enforcement. Violations are flagged to the user rather than silently
  rewritten.
- Brand Brain completeness (0–100%) is shown in the UI, because output quality tracks it
  directly and users need to understand why thin input yields thin output.

### Prompt safety

Brand Brain content is **user-supplied data**, and it is interpolated into a system prompt.
It is therefore treated as untrusted:

- Delimited clearly and labelled as data, never as instructions.
- Length-capped per field so one field cannot dominate or overflow the context.
- Generated output is escaped on render and is never executed, evaluated, or used to build
  a query.
- Output is not auto-published. Manual AI requires human review; Autopilot (Phase 4, late)
  still enters the normal approval workflow at `DRAFT` and cannot bypass client approval.

## 4. Features

Each feature is a class implementing a common contract, registered in `config/ai.php`.

```php
interface AiFeatureInterface
{
    public function key(): string;
    public function creditCost(): int;
    public function requiredBrainSections(): array;
    public function buildRequest(AiFeatureInput $input, string $brandContext): AiRequest;
    public function parseResponse(AiResponse $response): AiFeatureResult;
}
```

Phase 4 set: caption, hashtags, content ideas, rewrite, tone adjustment, translate,
platform adaptation, reel script, YouTube title, YouTube description, blog-to-social,
monthly content plan.

Features that must return structure (hashtags, monthly plan, ideas) declare a JSON schema
and validate the parsed result. A parse failure is retried once with a repair instruction,
then fails cleanly — it never silently returns malformed content to the composer.

`platform adaptation` is the feature that ties AI to publishing: given master content and a
target provider, it rewrites within that provider's limits from `config/social.php`, so the
generated variant is valid by construction rather than validated afterwards.

## 5. Credit ledger

**A bare editable integer is not acceptable.** Balance is derived from an append-only
ledger; the cached balance exists only for fast reads and is reconciled on schedule.

```
ai_credit_accounts    tenant_id, balance, reserved, monthly_allowance,
                      period_start, period_end, rollover_enabled, rollover_cap

ai_credit_transactions  append-only
                      type: grant | reset | reserve | release | consume |
                            refund | adjustment
                      amount (signed), balance_after, reference, idempotency_key
```

### Reserve → commit/release

AI calls are asynchronous and can fail after the request is sent. Deducting on completion
allows overspend under concurrency; deducting up front and never refunding overcharges on
failure. So:

```php
$reservation = $credits->reserve($tenant, $feature->creditCost(), $idempotencyKey);

try {
    $response = $provider->generate($request);
    $credits->commit($reservation, $this->actualCost($response));   // may differ from estimate
} catch (AiProviderException $e) {
    $credits->release($reservation);                                // no charge on failure
    throw $e;
}
```

- `reserve` increments `reserved` and decrements available; it fails closed if the balance
  is insufficient.
- `commit` writes a `consume` transaction for the actual cost and clears the reservation.
- `release` writes a `release` transaction and restores the balance.
- Reservations older than `config('ai.reservation_ttl')` (15 min) are swept and released, so
  a crashed worker cannot strand credits.
- Every transaction carries an `idempotency_key`; a duplicated request cannot double-charge.

### Cost model

Credits are an internal unit decoupled from vendor pricing, so switching models does not
change what a customer was sold.

```php
'costs' => [
    'caption'       => 1,
    'hashtags'      => 1,
    'ideas'         => 2,
    'rewrite'       => 1,
    'monthly_plan'  => 25,
],
'token_overage' => [
    'enabled'          => true,
    'tokens_per_credit'=> 2000,   // charge beyond the flat cost for unusually long outputs
],
```

Flat cost per feature is predictable for the customer; token overage protects margin on
outliers. Actual vendor token counts are recorded in `ai_generations` regardless, so real
cost per tenant is always measurable even though it is not what is billed.

### Monthly reset

`ai:reset-monthly-credits` runs hourly and processes accounts whose `period_end` has passed:

```
new_balance = monthly_allowance
            + (rollover_enabled ? min(unused, rollover_cap) : 0)
period_start = period_end; period_end = period_start + 1 month
```

Written as a `reset` transaction, never as a direct balance update. Tenant billing
anniversaries differ, so the job is per-tenant and idempotent per period (`ShouldBeUnique`
on tenant + period).

### Super Admin adjustments

Admins may grant or deduct credits. Every adjustment writes an `adjustment` transaction with
the admin's user ID and a mandatory reason, plus an audit log entry. There is no path that
edits `balance` directly — a test asserts `ai_credit_accounts.balance` is never mass-assigned
or updated outside the ledger service.

## 6. Enforcement and UX

Before any AI call: check the `ai.use` permission, the `ai_enabled` entitlement, and
sufficient credits.

- Below 20% remaining: in-app warning plus one email (not repeated).
- At zero: AI features are disabled with an upgrade CTA. **Nothing else in the product
  breaks** — AI exhaustion never blocks composing, scheduling or publishing.
- Usage is visible per tenant, per brand, per user and per feature at `/app/ai/usage`.

## 7. Observability

`ai_generations` records feature, provider, model, tokens, credits charged, latency, status
and error code for every call. This answers the three questions that matter: what does AI
actually cost per tenant, which features are used, and which fail.

Request/response snapshots are retained for
`config('ai.snapshot_retention_days')` (default 30) and then purged — they contain customer
business content and should not accumulate indefinitely.

## 8. Autopilot (Phase 4, late)

Autopilot generates and schedules on a cadence. Non-negotiable constraints:

- Enters the workflow at `DRAFT` and follows the **same** state machine and approval gates
  as human-authored content. If the brand requires client approval, autopilot content
  requires client approval.
- Requires an explicit per-brand opt-in with a configured cadence and content mix.
- Respects credit limits and stops cleanly at zero.
- Every autopilot-created post has `source = ai` and an audit entry, so its provenance is
  never ambiguous.
- A single switch disables it per brand, and a global feature flag disables it platform-wide.

## 9. Tests (Phase 4 gate)

1. Brand Brain context never includes another tenant's or another brand's data.
2. Insufficient credits blocks the call before any provider request is made.
3. Concurrent requests cannot overspend — reservations serialise correctly.
4. Provider failure releases the reservation; the tenant is not charged.
5. Duplicate idempotency key does not double-charge.
6. Monthly reset applies rollover caps and is idempotent when run twice in a period.
7. Ledger sum equals the cached balance after a randomised transaction sequence.
8. Forbidden words are detected in post-processing, not merely requested in the prompt.
9. Swapping the provider implementation leaves every feature test passing.
10. Autopilot output cannot reach `SCHEDULED` without traversing the required approvals.
