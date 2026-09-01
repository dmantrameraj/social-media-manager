# 05 — Social Provider Architecture

> **Standing rule for this module:** do not invent endpoints, scopes, field names, rate
> limits or response shapes. Every concrete API detail must be verified against current
> official provider documentation at implementation time. Where this document states a
> specific API fact it is marked **[VERIFY]** and must be re-checked before code ships.
> Provider APIs change on a quarterly cadence and this file will drift.

## 1. Principle

No provider name appears in domain logic. The publishing engine knows about
`SocialAccount` and `PostTarget`; it resolves a provider through a registry and asks it
what it can do. Adding TikTok must not require editing the publisher.

```
Publishing engine
      |
ProviderRegistry::for($providerKey)
      |
SocialProviderInterface  (+ optional capability interfaces)
      |
FacebookProvider | InstagramProvider | LinkedInProvider | XProvider | YouTubeProvider
      |
ProviderHttpClient (per-tenant credentials, retry classification, redacted logging)
```

## 2. Interface design

A single fat interface would force every provider to implement methods it cannot support —
X has no Stories, YouTube has no carousel, LinkedIn has no Reels. Instead: one small
required interface plus optional capability interfaces.

```php
interface SocialProviderInterface
{
    public function key(): string;

    /** Capabilities for a specific account, given its type and granted scopes. */
    public function capabilities(SocialAccount $account): CapabilitySet;

    // ---- OAuth ----
    public function authorizationUrl(OAuthContext $context): string;
    public function exchangeCode(string $code, OAuthContext $context): TokenSet;
    public function refresh(SocialConnection $connection): TokenSet;
    public function revoke(SocialConnection $connection): void;

    /** Destinations this grant can publish to (Pages, IG accounts, channels…). */
    public function discoverAccounts(SocialConnection $connection): DiscoveredAccountCollection;

    // ---- Publishing ----
    public function validate(PublishPayload $payload, SocialAccount $account): ValidationResult;
    public function publish(PublishPayload $payload, SocialAccount $account): PublishResult;
    public function getPostStatus(SocialAccount $account, string $externalId): PostStatusResult;
}
```

Optional capability interfaces, implemented only where the platform supports them:

```php
interface SupportsDeletion    { public function deletePost(SocialAccount $a, string $externalId): void; }
interface SupportsScheduling  { public function scheduleNatively(...): PublishResult; }
interface SupportsAnalytics   { public function fetchAnalytics(...): AnalyticsResult; }   // Phase 5
interface SupportsComments    { public function fetchComments(...): CommentCollection; }  // Phase 7
interface SupportsFirstComment{ public function publishFirstComment(...): void; }
interface RequiresMediaUpload { public function uploadMedia(...): RemoteMediaHandle; }
```

Call sites check with `instanceof`, and the UI reads `CapabilitySet` so unsupported options
are never offered in the first place.

## 3. Value objects

Provider adapters exchange DTOs, never Eloquent models or raw arrays. This is what keeps
providers unit-testable without a database.

| DTO | Contents |
|---|---|
| `OAuthContext` | tenant, user, provider key, credential, redirect URI, state, code verifier, requested scopes |
| `TokenSet` | access token, refresh token, token type, expires at, refresh expires at, granted scopes, external user ID |
| `DiscoveredAccount` | external ID, name, username, type, avatar, page token, scopes, raw meta |
| `PublishPayload` | body, media collection, link, first comment, per-platform meta, scheduled at, idempotency key |
| `PublishResult` | external post ID, external URL, published at, raw response snapshot |
| `ValidationResult` | passed flag, list of `ValidationError{field, code, message}` |
| `ProviderError` | error class, provider code, message, retryable flag, retry-after |

## 4. Capability model

Capabilities are declared in `config/social.php` per provider **and per account type**, then
narrowed at runtime by the scopes actually granted. A Facebook Page connected without
`pages_manage_posts` is not publish-capable regardless of what the config says.

```php
'facebook' => [
    'account_types' => ['page'],
    'page' => [
        'capabilities' => [
            'text' => true, 'images' => true, 'carousel' => true,
            'video' => true, 'reels' => true, 'stories' => false,
            'link' => true, 'document' => false, 'poll' => false,
            'first_comment' => true, 'native_scheduling' => true,
            'deletion' => true, 'analytics' => true, 'comments' => true,
        ],
        'limits' => [
            'text_max' => 63206,          // [VERIFY]
            'images_max' => 10,           // [VERIFY]
            'video_max_bytes' => null,    // [VERIFY]
            'video_max_seconds' => null,  // [VERIFY]
        ],
        'required_scopes' => ['pages_manage_posts', 'pages_read_engagement'], // [VERIFY]
    ],
],
```

Every numeric limit in that config is **[VERIFY]** until confirmed against live docs. They
are in config precisely because they change without notice; a limit change must be a config
edit, never a code change.

## 5. V1 provider notes

Known structural differences that the abstraction must accommodate. Specific endpoints and
field names are deliberately omitted — they belong in the implementation PR, verified.

**Facebook Pages** — Publishing uses a *Page* access token derived from the user token, not
the user token itself. Hence `social_accounts.page_access_token`, stored encrypted per
account. Page tokens and user tokens have different lifetimes and must be refreshed
independently. Long-lived token exchange is a distinct step from the initial code
exchange. **[VERIFY]**

**Instagram** — Business/Creator accounts only, reached through a linked Facebook Page, so
Instagram is not an independent OAuth flow — it is discovered from a Meta connection. This
is why `discoverAccounts()` returns a heterogeneous collection and why `social_connections`
is separate from `social_accounts`. Publishing is a two-phase container/publish operation,
which has direct idempotency consequences: **a created-but-unpublished container is a
resumable state, not a failure.** Store the container handle on the post target so a retry
resumes rather than restarting. **[VERIFY]**

**LinkedIn** — Personal profiles and organization pages use different author identifiers
and different permission sets. Media is registered/uploaded before the post references it.
Token lifetime and refresh availability differ by app configuration and must be confirmed
per app. **[VERIFY]**

**X** — Tier-dependent access; the write volume available on a given tier directly limits
what the product can promise. Media upload is a separate, chunked flow. Confirm the current
tier, quota and pricing before committing to X in a customer-facing plan. **[VERIFY]** —
this is a commercial as well as a technical dependency.

**YouTube** — Uploads are resumable and long-running, which is the one V1 flow that does not
fit a short shared-hosting worker window. Mitigation in `07-QUEUE-ARCHITECTURE.md` §7:
chunked upload state is persisted so a worker that runs out of time resumes on the next
tick. Quota is measured in units, not requests, and an upload is expensive. **[VERIFY]**

## 6. Developer app credentials

Agencies may supply their own app credentials per provider. Resolution order:

```
tenant credential (active, verified)  ->  platform default from .env  ->  refuse to connect
```

Rules:

- `client_id`, `client_secret` and `extra` use `encrypted` casts.
- Both are in `$hidden`, excluded from every API resource, and write-only in the UI: after
  saving, the form shows a masked placeholder and an empty value means "unchanged".
- A `SecretRedactor` scrubs credential values from exception messages and log context
  before anything is written. Provider HTTP clients log request/response bodies only after
  passing through it.
- **Super Admin has no interface that displays these values**, and none may be added.
  Admin sees existence, label, provider, `verified_at` and last error only.
- Rotation replaces the secret and marks dependent connections `needs_reconnect` if the
  provider invalidates existing grants. Whether it does is **[VERIFY]** per provider.
- A "Verify credentials" action performs a minimal read-only call and records
  `verified_at` / `last_verify_error`.

## 7. OAuth flow

```
1. User clicks Connect for provider P on brand B
2. Resolve credential (tenant, else platform default)
3. Generate state (256-bit random) and PKCE verifier where supported
4. Persist oauth_states { state_hash, tenant_id, user_id, provider_key,
                          customer_id, code_verifier (encrypted), redirect_to,
                          expires_at = now + 10 min }
5. Redirect to provider authorization URL
6. Callback: /oauth/{provider}/callback
     a. Look up state by hash
     b. Atomically consume it (single-use)
     c. Reject if missing, expired, already consumed, or tenant mismatch
     d. Exchange code (+ verifier) for tokens
     e. Upsert social_connection with encrypted tokens and GRANTED scopes
     f. discoverAccounts()
7. User selects which destinations to activate for brand B
8. social_accounts rows created; capabilities resolved; status = active
```

Security requirements, all enforced:

- Redirect URIs come from configuration and are exact-matched. Arbitrary
  `redirect_uri` values are never accepted from a request.
- `redirect_to` (the post-connect in-app landing page) is validated as a relative path on
  our own host. An open redirect here is a phishing primitive.
- State is single-use, bound to tenant *and* user, and 10-minute lived.
- The connection records **granted** scopes as returned by the provider, never the
  requested set — users can decline individual scopes, and assuming otherwise produces
  publish failures that look like bugs.
- The authorization code, tokens and secrets are never logged.
- OAuth callbacks are rate-limited per IP and per tenant.

## 8. Token lifecycle and connection health

```
TokenRefreshSweeper (scheduled, every 30 min)
  find social_connections
    where status = active
      and refresh_token is not null
      and expires_at <= now + config('social.refresh_lead_time')   // default 24h
  dispatch RefreshSocialConnectionToken per connection (queue: default, throttled)
```

Outcomes:

| Result | Action |
|---|---|
| Success | Rotate encrypted tokens, update `expires_at`, `last_refreshed_at`; clear errors |
| Retryable failure (5xx, network) | Backoff, retry up to 3 times |
| Invalid/expired refresh token | `status = needs_reconnect`; propagate to accounts; notify |
| Revoked by user | `status = revoked`; same propagation |
| No refresh capability, nearing expiry | `status = needs_reconnect`; notify ahead of expiry |

On `needs_reconnect`:

1. Dependent `social_accounts` become `status = needs_reconnect`, `health = failed`.
2. Scheduled `post_targets` for those accounts move to `paused_reconnect` rather than
   failing — the content is intact and should publish once the connection is restored.
3. In-app notification plus email to users holding `social_accounts.connect`.
4. A persistent banner and a Reconnect CTA appear on the brand and calendar screens.
5. Reconnect re-runs OAuth, matches on `external_user_id`, and **updates the existing
   connection row** rather than creating a new one — so post targets, history and account
   assignments survive.

A `ConnectionHealthCheck` runs daily, makes one cheap authenticated read per connection, and
sets `health` to `healthy`/`warning`/`failed`. This surfaces silent revocations that no
refresh attempt would otherwise reveal until a publish fails.

## 9. Error classification

Every provider adapter maps raw errors into one taxonomy. The publishing engine only ever
sees the taxonomy — this is the whole point of the abstraction.

| Class | Retryable | Engine behaviour |
|---|---|---|
| `rate_limit` | yes | Honour `Retry-After`; per-provider backoff; do not count against `max_attempts` |
| `network` / `timeout` | yes | Exponential backoff |
| `server_error` (5xx) | yes | Exponential backoff |
| `auth_expired` | no | Flag connection `needs_reconnect`; pause targets |
| `permission` | no | Fail target; message names the missing scope |
| `validation` | no | Fail target; map to a field-level message |
| `media` | no | Fail target; report the offending media item |
| `duplicate` | no | Treat as **success** if an external ID can be recovered; see §10 |
| `platform_rejection` | no | Fail target; surface the provider's reason verbatim but sanitized |
| `unknown` | once | One cautious retry, then fail |

`ProviderError::message` is sanitized before storage. Raw responses go to
`publication_attempts.response_snapshot` after redaction, and are visible only to users
holding `posts.retry` — never rendered to portal users.

## 10. Idempotency at the provider boundary

Duplicate posts are the most visible possible failure of this product; a client seeing the
same post twice is worse than seeing it late.

Mechanisms, in order of preference:

1. **Native idempotency key** where the provider supports one — pass
   `post_targets.idempotency_key`. **[VERIFY]** per provider; most do not.
2. **Resumable handles** — where publishing is multi-phase (Instagram containers, YouTube
   resumable uploads, LinkedIn media registration), persist the handle in
   `post_targets.meta` immediately on creation. A retry resumes from the handle instead of
   creating a second one.
3. **Pre-flight recency check** — before creating, if `attempts > 0` and the provider can
   list recent posts for the account, search for one matching this target's fingerprint. If
   found, record its external ID and mark the target published. **[VERIFY]** the listing
   capability per provider.
4. **Claim locking** — the engine never dispatches a target that is already `processing`;
   see `06-PUBLISHING-ENGINE.md` §7.

If a `duplicate` error is returned and no external ID can be recovered, the target is marked
`failed` with a distinct `error_code = duplicate_unresolved` and an explicit operator note:
**do not auto-retry** — a human must check the platform.

## 11. Rate limiting

Two layers:

- **Outbound throttle** — a token bucket per `(provider, credential)` in the cache store,
  sized from `config('social.rate_limits')`. Jobs that cannot acquire a token release
  themselves back to the queue with a delay instead of spinning.
- **Reactive backoff** — on a `rate_limit` response, honour `Retry-After`; absent that,
  apply provider default backoff and record a cooldown that suppresses further dispatch for
  that credential until it expires.

Rate-limit waits never count toward `max_attempts`. A tenant that is merely busy must not
consume its retry budget.

Because each agency can supply its own app credentials, limits are naturally partitioned per
tenant — this is a real architectural benefit of the bring-your-own-app model, not just a
compliance detail.

## 12. Adding a provider (checklist)

1. Verify current API docs: auth flow, scopes, endpoints, limits, rate limits, media rules.
2. Add the `config/social.php` entry with capabilities, limits and required scopes.
3. Implement `SocialProviderInterface` plus applicable capability interfaces.
4. Implement the error mapper into the §9 taxonomy.
5. Implement the content validator against the config limits.
6. Register in `ProviderRegistry` and seed the `social_providers` row.
7. Tests: OAuth state handling, token refresh, capability resolution, validation rules,
   error mapping, idempotent retry. Provider HTTP is faked; no live calls in CI.
8. Run one manual end-to-end publish against a real sandbox/test account.
9. Document in `/docs/providers/{provider}.md`: scopes, review requirements, quotas, known
   limitations, and the date the docs were last verified.
