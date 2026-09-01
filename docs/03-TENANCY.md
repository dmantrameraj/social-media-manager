# 03 — Multi-Tenancy

> Tenant data leakage is the single failure mode that would end this product.
> Isolation is therefore enforced in **five independent layers**. Any one layer failing
> must not, on its own, produce a leak.

## 1. What a tenant is

A **tenant** is the billable, isolated account — in V1 always an agency.
`tenants.type` is `agency|reseller` and `tenants.parent_tenant_id` is nullable, so a
reseller tier can be inserted above agencies later. **No reseller logic ships in V1.**

Every tenant-owned table carries a non-null `tenant_id` with a foreign key to `tenants`.
There are no exceptions "for convenience".

## 2. Isolation strategy: single database, row-level

**Decision: one database, shared schema, `tenant_id` discriminator column.**

Rejected alternatives:

| Option | Why rejected |
|---|---|
| Database-per-tenant (`stancl/tenancy` multi-DB) | Hostinger shared hosting caps database count. Running migrations across thousands of schemas is operationally hostile, and every cross-tenant Super Admin screen becomes a fan-out query problem. |
| Schema-per-tenant | Not meaningfully supported by MySQL; same operational problems. |
| Instance-per-tenant | Untenable at 10,000 tenants. |

Trade-off accepted: row-level isolation places the burden on application correctness
rather than on the database engine. The five layers below exist specifically to pay down
that debt.

If a single enterprise tenant later requires physical isolation, it can be moved to a
dedicated deployment of the same codebase pointed at its own database. The schema does not
need to change for that.

## 3. Layer 1 — Tenant context resolution

Tenant identity is resolved **server-side only**, from one of:

1. **Host** — `agency.platform.com` or a verified custom domain (`domains` table). Future.
2. **Session** — the tenant the user selected, re-validated against their memberships on
   every request.
3. **Sole membership** — if the user belongs to exactly one tenant, it is selected.

```php
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void { $this->tenant = $tenant; }
    public function get(): Tenant { return $this->tenant ?? throw new TenantNotResolved(); }
    public function id(): int { return $this->get()->id; }
    public function hasTenant(): bool { return $this->tenant !== null; }
}
```

Registered as a **scoped** singleton — fresh per request, and safe if Octane is adopted.

`ResolveTenant` middleware:

```php
$tenantId = $request->session()->get('tenant_id');

$membership = TenantUser::query()
    ->where('user_id', $request->user()->id)
    ->where('tenant_id', $tenantId)
    ->where('status', MembershipStatus::Active)
    ->first();

abort_if($membership === null, 403);

app(TenantContext::class)->set($membership->tenant);
setPermissionsTeamId($membership->tenant_id);   // spatie team binding
```

**Non-negotiable:** a `tenant_id` arriving in a request body, query string, JSON payload or
hidden form field is **ignored**. It is never used to select the tenant. Where code must
accept a tenant identifier — Super Admin tooling only — it goes through an explicit,
policy-gated path.

## 4. Layer 2 — Automatic query scoping

A `BelongsToTenant` trait is applied to every tenant-owned model.

```php
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->tenant_id === null && $context->hasTenant()) {
                $model->tenant_id = $context->id();
            }

            if ($model->tenant_id === null) {
                throw new MissingTenantException(static::class);
            }
        });

        // A tenant_id must never be reassigned after creation.
        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantReassignmentException(static::class);
            }
        });
    }

    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
```

```php
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasTenant()) {
            return; // console/admin context — Layers 3 and 4 still apply
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
```

### Known holes in this layer, and how each is covered

| Hole | Cover |
|---|---|
| `withoutGlobalScopes()` | Banned outside `Domain/Platform` and Super Admin services. Use the named `Model::acrossTenants()` helper instead, so every bypass greps cleanly and can be reviewed. |
| Raw `DB::table()` / raw SQL | Banned in tenant-facing code. Any exception requires an explicit `where('tenant_id', …)` and a comment justifying the raw query. |
| Relationship queries from a loaded parent | Safe: the parent was scoped, and the FK chain enforces the rest. |
| `find($id)` with a foreign ID | Returns null under the global scope — the correct outcome. |
| Queued jobs (no request, no session) | Jobs carry `tenant_id` explicitly and re-establish `TenantContext` in `handle()` before touching models. Serialised context is never relied upon. |
| Console commands | Run with no tenant context; they must iterate tenants and set context per tenant. |
| Super Admin surface | Deliberately unscoped, policy-gated, and written to `audit_logs`. |

### Job tenant context

Every tenant-aware job follows this shape:

```php
public function __construct(public int $tenantId, public int $postTargetId) {}

public function handle(PublishPostTargetService $service): void
{
    $tenant = Tenant::query()->acrossTenants()->findOrFail($this->tenantId);

    app(TenantContext::class)->set($tenant);
    setPermissionsTeamId($tenant->id);

    $service->execute($this->postTargetId);
}
```

Jobs pass IDs, not serialised models. A serialised model reloads through
`SerializesModels` without tenant context and produces confusing failures; an explicit ID
plus an explicit context set is legible and testable.

## 5. Layer 3 — Authorization

Global scopes prevent *accidental* reads. Policies prevent *deliberate* ones, and cover
what scopes cannot: cross-module references and IDs supplied in payloads.

Every policy method asserts three things:

```php
public function update(User $user, Post $post): bool
{
    return $post->tenant_id === app(TenantContext::class)->id()   // 1. tenant match
        && $user->canAccessCustomer($post->customer_id)           // 2. customer assignment
        && $user->can('posts.update');                            // 3. permission
}
```

Rule: **no controller or Livewire action may touch a model it did not authorize.** Nested
resources are authorized on the parent as well as the child — attaching media to a post
authorizes the post *and* each media item, because the media IDs arrive from the client.

## 6. Layer 4 — Database constraints

- `tenant_id BIGINT UNSIGNED NOT NULL` plus a foreign key with `ON DELETE CASCADE` on
  every tenant-owned table.
- Uniqueness is **always composite with `tenant_id`**: `UNIQUE (tenant_id, slug)`,
  `UNIQUE (tenant_id, provider_key, external_id)`. A bare `UNIQUE (slug)` on a tenant
  table is a bug, and also a cross-tenant information leak — it lets one tenant discover
  another's slugs through constraint violations.
- Cross-entity foreign keys are declared, so a post cannot reference another tenant's
  customer at the storage level even if the application layer is wrong.
- Composite indexes lead with `tenant_id`: `INDEX (tenant_id, customer_id, status)`,
  `INDEX (tenant_id, scheduled_at)`. This keeps every tenant-scoped query on an index
  prefix as tables grow.

## 7. Layer 5 — Automated tests (merge gate)

A dedicated `tests/Feature/Tenancy/` suite. **CI fails if any of these fail, with no
override path.**

Mandatory cases:

1. **Read isolation** — Tenant A cannot read any Tenant B record, for *every* tenant-owned
   model. Written as a data-provider test over a registry of tenant models, so a newly
   added model is covered automatically.
2. **Write isolation** — Tenant A cannot update or delete Tenant B records; the response
   is 403/404 and the row is verified unchanged.
3. **Create binding** — records created under Tenant A's context persist `tenant_id = A`
   even when the request payload contains `tenant_id = B`.
4. **Reassignment** — updating `tenant_id` on an existing record throws.
5. **Missing context** — creating a tenant-owned model with no context throws, rather than
   writing a null or leaked tenant.
6. **Route enumeration** — for every agency route, a user from another tenant receives
   403/404, never 200. Generated by iterating the route table so new routes are covered by
   default.
7. **Customer assignment** — a user restricted to Customer A cannot read Customer B's posts
   *within the same tenant*.
8. **Portal isolation** — a portal user sees only posts at `CLIENT_REVIEW` or later for
   their assigned customers, and cannot reach any `/app` or `/admin` route.
9. **Job isolation** — a publishing job for Tenant A cannot resolve Tenant B's social
   account or tokens.
10. **Registry completeness** — a test asserts that every model with a `tenant_id` column
    uses `BelongsToTenant`. This is the test that stops the suite rotting as the schema
    grows.

## 8. Customers that work with multiple agencies

The requirement: one real-world business may be served by two agencies, and neither may see
the other's work.

**V1 design:** `customers` is an *agency-scoped workspace*, not a global business record.
Two agencies serving the same restaurant hold two independent `customers` rows. All
content, media, social accounts and approvals hang off the workspace row, so isolation is
free — it is the ordinary tenant rule with nothing special layered on top.

**Future-proofing:** `customers.customer_identity_id` (nullable) exists from day one. If a
global directory of real-world businesses is ever needed, a `customer_identities` table can
be added and workspaces linked to it **without moving any foreign keys**, because nothing
points at an identity today.

Portal users are likewise tenant-scoped (`UNIQUE (tenant_id, email)`). The same person
working with two agencies has two logins. This is deliberate: a shared portal identity
would create a cross-tenant join and a credential-sharing blast radius that V1 has no
reason to take on.

## 9. Tenant lifecycle

| Status | Login | Read | Publish | Notes |
|---|---|---|---|---|
| `trialing` | yes | yes | yes | 7 days, trial entitlements |
| `active` | yes | yes | yes | |
| `grace` | yes | yes | per policy | 7 days post-expiry; renewal banner and CTA |
| `suspended` | yes | no | no | Billing/renewal routes only; data retained |
| `cancelled` | yes | no | no | 60-day retention, then anonymisation |

Status is checked by `EnsureTenantActive` middleware. Renewal and billing routes are
excluded from the block, so a suspended tenant can always pay to return.

**Data is never hard-deleted on suspension or cancellation.** After the 60-day retention
window a scheduled, audited cleanup job anonymises PII and purges media. The process is
described in `10-SECURITY.md` §9.
