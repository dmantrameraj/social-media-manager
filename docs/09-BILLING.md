# 09 — Billing, Plans & Entitlements

> **[VERIFY]** All Razorpay API specifics — endpoint paths, request/response field names,
> subscription state names, webhook event names and payload shapes — must be confirmed
> against current official Razorpay documentation before implementation. This document
> specifies *our* architecture and the integration's shape, not Razorpay's contract.

## 1. Gateway abstraction

```php
interface PaymentGatewayInterface
{
    public function key(): string;

    public function createCustomer(Tenant $tenant): GatewayCustomer;
    public function createSubscription(Tenant $tenant, PlanPrice $price, ?Coupon $coupon): GatewaySubscription;
    public function cancelSubscription(Subscription $s, bool $atPeriodEnd): GatewaySubscription;
    public function resumeSubscription(Subscription $s): GatewaySubscription;
    public function fetchSubscription(string $externalId): GatewaySubscription;

    public function createOrder(Invoice $invoice): GatewayOrder;      // one-off payments
    public function verifyPaymentSignature(array $payload): bool;
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;
    public function parseWebhook(array $payload): WebhookEvent;
    public function refund(Payment $payment, ?int $amountMinor): GatewayRefund;
}
```

V1 implements `RazorpayGateway`. `ManualGateway` implements the same interface for
admin-activated accounts, so subscription lifecycle code has **no** conditional for "manual
vs paid" — manual is just a gateway whose payment operations are no-ops. This is the design
detail that keeps the sales flow from becoming a second code path.

Stripe is a future implementation; nothing outside `Domain/Billing/Gateways/` will change.

## 2. Plans and prices

`plans` describe the product; `plan_prices` describe what it costs.

```
plans           slug, name, description, is_public, is_active, trial_days, sort_order
plan_prices     plan_id, billing_period, currency, amount_minor, gateway,
                gateway_plan_id, is_active
plan_features   plan_id, key, value_type (boolean|limit|unlimited), value
```

The split lets one plan carry monthly and yearly prices in multiple currencies with
different gateway plan IDs, without duplicating features. Feature keys are validated against
`config('entitlements.keys')` at seed and save time, so a typo cannot create a silently
unenforced limit.

## 3. Entitlements

**No plan limit is ever hardcoded.** Resolution order:

```
subscription_overrides (tenant, unexpired)      ← Super Admin per-tenant override
        ↓ falls through
plan_features (active subscription's plan)
        ↓ falls through
config('entitlements.defaults')                 ← system default
```

```php
final class EntitlementResolver
{
    public function value(Tenant $tenant, string $key): Entitlement
    {
        return Cache::remember(
            "entitlements:{$tenant->id}:{$key}",
            config('billing.entitlement_cache_ttl'),
            fn () => $this->resolve($tenant, $key),
        );
    }

    public function allows(Tenant $tenant, string $key, int $requested = 1): bool
    {
        $entitlement = $this->value($tenant, $key);

        return $entitlement->isUnlimited()
            || ($this->currentUsage($tenant, $key) + $requested) <= $entitlement->limit();
    }
}
```

Cache is invalidated on subscription change, plan change, and override change. A stale
entitlement either blocks a paying customer or gives away product, so invalidation is
explicit, not TTL-dependent.

### Entitlement catalogue

```php
'keys' => [
    'brands.max', 'social_accounts.max', 'team_members.max',
    'portal_users.max', 'posts.scheduled_per_month', 'ai.credits_per_month',
    'storage.max_bytes', 'customers.approval_workflow', 'ai.autopilot',
    'white_label.enabled', 'analytics.enabled', 'api.enabled', 'support.priority',
],
```

### Reference plan configuration (all values admin-editable)

| Key | Starter | Professional | Agency | Enterprise |
|---|---|---|---|---|
| brands.max | 5 | 15 | 25 | unlimited |
| social_accounts.max | 10 | 40 | 100 | unlimited |
| team_members.max | 2 | 5 | 10 | unlimited |
| portal_users.max | 5 | 20 | 50 | unlimited |
| posts.scheduled_per_month | 100 | 500 | 2000 | unlimited |
| ai.credits_per_month | 100 | 500 | 2000 | custom |
| storage.max_bytes | 5 GB | 25 GB | 100 GB | custom |
| analytics.enabled | false | true | true | true |
| white_label.enabled | false | false | true | true |

These are seed values, not constants. They live in a seeder and in the database, and Super
Admin edits them without a deploy.

### Enforcement

Checked at the point of creation, in the service layer, not in the controller:

```php
$this->entitlements->guard($tenant, 'brands.max');   // throws EntitlementExceeded
```

`EntitlementExceeded` is rendered as a clear message plus an upgrade CTA naming the limit
hit and the plan that raises it. It is never a 500.

Monthly counters (`posts.scheduled_per_month`, `ai.credits_per_month`) reset on the tenant's
billing anniversary, not on the calendar month — a tenant billed on the 17th gets its reset
on the 17th.

## 4. Trial

- 7 days (`config('billing.trial_days')`), no card required.
- `tenants.status = trialing`, `trial_ends_at = now + 7 days`.
- Trial entitlements come from a designated trial plan, so trial limits are configurable
  like everything else.
- Reminders at day 5 and day 7 (in-app plus email).
- On expiry with no subscription: `status = grace` for `config('billing.grace_days')` (7),
  then `suspended`.
- One trial per tenant. Abuse signals (same email domain, same payment fingerprint, rapid
  re-registration) are recorded for Super Admin review rather than auto-blocking.

## 5. Subscription lifecycle

```
trialing ──subscribe──> active ──payment fails──> past_due ──> grace ──> suspended
    │                      │                                                │
    └──trial expires──> grace                                   renewal ────┘
                                     cancel (at period end) ──> cancelled
                                                                    │
                                                          60-day retention
                                                                    │
                                                              anonymised
```

`billing:process-lifecycle` runs hourly and is idempotent:

| Transition | Trigger | Side effects |
|---|---|---|
| trialing → grace | `trial_ends_at` passed, no subscription | Notify; renewal CTA |
| active → past_due | Payment failure webhook | Notify; retry per gateway schedule |
| past_due → grace | Gateway retries exhausted | Notify |
| grace → suspended | `grace_ends_at` passed | Block product; billing routes only; `purge_after = now + 60d` |
| any → active | Successful payment | Restore access; clear `purge_after`; resume paused targets |
| cancelled → purged | `purge_after` passed | Anonymise (`10-SECURITY.md` §9) |

**During grace:** login yes, read yes, publishing governed by
`config('billing.publish_during_grace')` (default **true** — cutting off a client's
scheduled posts because their card expired is a business decision, not a technical one, and
the default should be forgiving). A persistent banner shows days remaining.

**On suspension:** scheduled `post_targets` move to `PAUSED` rather than `FAILED` — they
resume on reactivation. Data is never deleted.

## 6. Razorpay integration **[VERIFY]**

Two flows:

- **Subscriptions** — recurring plans, gateway-managed billing cycles and retries.
- **Orders** — one-off charges (manual invoices, add-on credit packs).

Client-side checkout returns a signed payload that must be verified **server-side** before
anything is marked paid. The signature is an HMAC-SHA256 over the returned identifiers,
computed with the key secret. A client-reported success is never trusted. **[VERIFY]** the
exact fields concatenated for each flow.

### Webhooks

```
POST /webhooks/razorpay
 1. Read the RAW request body (never the parsed array — re-encoding breaks the HMAC)
 2. Verify HMAC-SHA256 of the raw body against the X-Razorpay-Signature header,
    using the webhook secret, compared with hash_equals()          [VERIFY header name]
 3. Reject unverified with 400; log the attempt WITHOUT the payload
 4. Upsert webhook_events on UNIQUE (provider, event_id) — duplicate = 200, no work
 5. Dispatch ProcessRazorpayWebhook to the `webhooks` queue
 6. Return 200 immediately
```

The endpoint does no business logic and touches no subscription state. It verifies,
deduplicates, records, and returns — so a slow handler can never cause the gateway to
retry-storm.

Events to handle **[VERIFY names]**: subscription activated / charged / halted / cancelled /
completed / pending; payment captured / failed; refund processed.

Webhook routes are excluded from CSRF, rate-limited per IP, and accept only the configured
content type.

### Reconciliation

Gateway webhooks can be missed. `billing:reconcile-subscriptions` runs daily, fetches the
gateway state for every subscription updated in the last 7 days, and corrects local drift,
writing an audit entry when it changes anything. Without this, a single dropped webhook
leaves a paying customer suspended.

## 7. Invoices, payments, coupons

- Invoice numbers are sequential per financial year, allocated inside a transaction with a
  row lock on a counter. `AUTO_INCREMENT` is not used — it gaps on rollback, and accounting
  does not tolerate gaps.
- `payments` is unique on `(gateway, gateway_payment_id)`; stored gateway payloads are
  redacted (no card data, no tokens). We store no card data at any point — the gateway
  holds it.
- PDF generation is Phase 5; V1 stores structured invoice data and renders HTML.
- Coupons: percent or fixed, `once`/`repeating`/`forever`, redemption caps, validity
  windows, plan restrictions. Validation is server-side; a coupon code in a request is never
  trusted for its discount value.

## 8. Manual activation (sales flow)

```
Super Admin -> Create Agency
  name, slug, owner email, plan, billing period,
  period start, period end, optional entitlement overrides, notes
  -> tenant created, status = active
  -> subscription created with gateway = manual, no payment required
  -> AI credit account opened with the plan allowance
  -> activation email with a set-password link (single-use, expiring)
```

Admin may subsequently: change plan, extend the period, adjust entitlements, grant credits,
extend trial, suspend, reactivate. **Every one of these writes an audit log entry** with the
admin's identity, the before/after values and a reason.

Because `ManualGateway` implements `PaymentGatewayInterface`, expiry, grace and suspension
behave identically for manual and paid tenants. There is no "manual account" branch in the
lifecycle code.

## 9. Tests (Phase 1 gate for foundation, Phase 5 for reporting)

1. Entitlement resolution honours override → plan → default precedence.
2. Creating past a limit throws `EntitlementExceeded` with the correct key.
3. An override raises a limit with no plan change and no deploy.
4. Entitlement cache is invalidated on plan, subscription and override changes.
5. Webhook signature verification rejects tampered bodies (`hash_equals`, raw body).
6. Duplicate webhook `event_id` is processed exactly once.
7. Client-side payment payloads with an invalid signature are rejected.
8. Trial → grace → suspended transitions fire on time and are idempotent when the hourly
   job runs twice.
9. Suspension pauses post targets rather than failing them; reactivation resumes them.
10. Manual and Razorpay subscriptions follow identical lifecycle transitions.
11. Invoice numbering is gapless under concurrent creation.
12. Monthly counters reset on the billing anniversary, not on the 1st.
13. Reconciliation corrects a subscription whose webhook was dropped.
