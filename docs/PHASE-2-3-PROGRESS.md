# Phases 2 & 3 — Progress Report

**Date:** 2026-09-02
**Status:** Foundations built and verified. Provider implementations blocked on
external verification — see §4.

---

## 1. Verified state

| Gate | Result |
|---|---|
| Test suite | **229 passing**, 500 assertions |
| Tenant isolation | **34 passing** — now covering social and publishing models |
| Publishing engine | **20 passing**, including duplicate-recovery |
| Static analysis | PHPStan level 5, **0 errors** |
| Formatting | Pint clean |
| Schema | 20 migrations, 56 tables |

## 2. Phase 2 — Social connections (foundation complete)

**Built and tested:**

- `SocialProviderInterface` — deliberately small, plus optional capability
  interfaces (`SupportsDeletion`, `SupportsFirstComment`,
  `SupportsRecentPostLookup`, `SupportsAnalytics`) checked with `instanceof`.
  A single fat interface would force every provider to implement methods it
  cannot support.
- **DTO layer** — `TokenSet`, `PublishPayload`, `MediaItem`, `PublishResult`,
  `CapabilitySet`, `ValidationResult`, `DiscoveredAccount`. Providers exchange
  these, never Eloquent models, so adapters are unit-testable without a
  database and cannot mutate domain state.
- **`ProviderErrorClass`** — the single error taxonomy. Adapters map their raw
  errors into it, so the engine never sees a Meta subcode.
- **`ProviderRegistry`** — resolves adapters by key and narrows capabilities by
  *granted* scopes. A Page connected without `pages_manage_posts` is not
  publish-capable regardless of config.
- **Encrypted credential storage** — `social_app_credentials`,
  `social_connections`, `social_accounts` with encrypted casts, `$hidden`, and
  a `toSafeArray()` projection that never returns a value even masked.
- **`OAuthStateService`** — single-use (atomic conditional UPDATE), bound to
  tenant *and* user, ten-minute expiry, hash-only storage, PKCE support, and
  relative-path-only redirect validation to prevent open redirects.
- **`FakeProvider`** — scriptable in-memory provider. Not a stand-in for a real
  network: it exists so the engine's hardest behaviour is provable without a
  live API or platform review.

## 3. Phase 3 — Publishing engine (core complete)

**Built and tested:**

- **`PostStatusMachine`** — 14 states, explicit legal-transition map, permission
  per transition. Every move writes a `post_approvals` row and an audit entry in
  the same transaction, so history can never disagree with state.
- **`ClaimPostTargetService`** — atomic claiming via conditional UPDATE with an
  affected-rows check. No application lock, no cache lock, and no
  `SELECT … FOR UPDATE` held across a network call.
- **`PublishPostTargetService`** — full error classification, backoff, pause
  semantics and idempotent recovery.
- **Schema** — posts, versions, targets, media, attempts, approvals, comments.

**Behaviours proven by test:**

| Property | Why it matters |
|---|---|
| Exactly one worker can claim a target | Two claims means a duplicate post on a client's timeline |
| One provider failing does not fail the post | The single most important rule in the engine |
| Rate limits do not consume the retry budget | A busy account must not exhaust attempts doing nothing wrong |
| `auth_expired` pauses rather than fails | The content is fine; it waits for a reconnect |
| Validation runs before the provider is called | Nothing is published, and nothing is charged |
| **A post that landed before the worker died is recovered, not re-posted** | This is the scenario that produces duplicates in naive engines |
| An unresolvable duplicate is flagged for human review | Auto-retrying here is how you double-post |
| `UNIQUE (post_id, social_account_id)` | Storage-level guarantee against double-queueing |

## 4. Bugs found

| Bug | Consequence |
|---|---|
| **`connection` is a reserved Eloquent property** — `Model::$connection` holds the database connection name, so the relation was shadowed and returned a string | `canPublish()` crashed on every publish. Renamed to `socialConnection()`. |
| `ProviderRegistry` was not bound as a singleton | Every resolution returned an empty registry; no provider was ever findable. |
| New factories could not stand alone | Required FKs were unset, and naive defaults would have satisfied the keys while producing incoherent cross-tenant rows. Now lazily resolved within one tenant. |

The **registry-completeness test did exactly its job**: adding 11 tenant-owned
tables failed the suite until each was registered or exempted with a reason.
That is the check that stops isolation coverage rotting as the schema grows.

## 5. NOT done — and why

**Real provider adapters (Facebook, Instagram, LinkedIn, X, YouTube) are not
implemented.** The abstraction, capability model, credential storage, OAuth
machinery and error taxonomy are all in place and tested, but every adapter
needs endpoint paths, scope names, field names, media rules and rate limits
confirmed against live provider documentation. `config/social.php` marks every
such value **[VERIFY]**. Writing adapters against guessed endpoints would
produce code that compiles, passes review, and fails in production.

**Facebook and Instagram additionally require Meta App Review** — weeks of
calendar time and a working demo. You have chosen to defer that.

**X requires a commercial decision first.** It ships `enabled => false`: write
volume is tier-dependent, so the tier and its cost determine whether X can be
offered in a plan at all.

**Also outstanding across Phases 2–8:**

- OAuth connect/callback controllers and the account-selection UI
- Token refresh sweeper, connection health check, reconnect flow
- Unified composer, content calendar, drag-and-drop rescheduling
- `DispatchDuePublications` command and `PublishPostTarget` job
  *(the services they call are built and tested)*
- Stale-lock recovery sweep *(the `stale()` scope and verification status exist)*
- CSV import, recurring posts, evergreen pools
- **Phase 4** — AI provider abstraction, Brand Brain, generation features
- **Phase 5** — analytics and reporting
- **Phase 6–8** — collaboration, unified inbox, CRM, WhatsApp, white-label,
  custom domains, reseller

## 6. Honest assessment of remaining scope

Phases 4–8 represent many months of work and depend on external inputs that do
not exist yet: platform approvals, API credentials, and commercial decisions
about tiers and pricing. What has been built is the part that can be built
correctly today — the abstractions, the isolation guarantees, and the engine
whose failure modes are expensive to discover in production.

The next genuinely productive step is **Phase 4 (AI)**, because it depends on
one vendor API rather than five platforms and needs no app review.
