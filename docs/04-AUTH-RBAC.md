# 04 — Authentication & RBAC

## 1. Principals and guards

| Principal | Table | Guard | Surface |
|---|---|---|---|
| Super Admin | `users` (`is_super_admin = true`) | `web` | `/admin` |
| Agency user | `users` via `tenant_user` | `web` | `/app` |
| Customer portal user | `customer_portal_users` | `customer` | `/portal` |

**Decision: portal users get their own table and guard.**

The alternative — one `users` table with a `type` column — is less plumbing but strictly
weaker. With separate guards, a bug in role resolution cannot escalate a client into the
agency dashboard, because `auth('web')->user()` simply cannot return a portal user; the
session cookie resolves through a different provider. That property is worth the duplicated
password-reset and 2FA wiring, which is in any case shared through traits
(`HasTwoFactorAuthentication`, `RecordsLoginHistory`, `Notifiable`).

Super Admins stay in `users` because they are ordinary platform staff who also need normal
authentication; the distinction is a capability, not a different kind of identity. The
`/admin` route group requires both `auth:web` and an `EnsureSuperAdmin` middleware, and
`is_super_admin` is not mass-assignable and is only settable through a dedicated,
audited console command.

## 2. Registration and onboarding

**Self-serve**

```
Sign up -> verify email -> create tenant (name, slug, timezone)
        -> tenant.status = trialing, trial_ends_at = now + config('billing.trial_days')
        -> user gets Agency Owner role scoped to that tenant
        -> AI credit account opened with the trial allowance
        -> onboarding checklist: create brand, connect account, first post
```

**Manual (Super Admin sales flow)**

```
Admin creates tenant + owner user -> assigns plan -> sets period start/end
-> optional entitlement overrides -> activation email with a set-password link
-> tenant.status = active, subscription.gateway = manual
```

No payment is required for manual activation. Every field the admin sets is audited.

## 3. Password and session policy

- Argon2id where available, else bcrypt with cost 12 (`config/hashing.php`).
- Minimum 12 characters, checked against Laravel's `Password::uncompromised()`
  (k-anonymity range query against HIBP; no password leaves the server).
- Login throttling: 5 attempts per email+IP per minute, then exponential lockout.
  Failures are written to `login_histories` **without** the attempted password.
- Sessions in the database, so devices can be listed and revoked.
- `password.confirm` middleware guards: changing email or password, enabling/disabling 2FA,
  managing social app credentials, deleting a brand, changing billing, managing team roles.
- Session fixation prevented by regenerating the session ID on login and on tenant switch.

## 4. Two-factor authentication

Fortify TOTP. `two_factor_secret` and `two_factor_recovery_codes` are `encrypted` casts and
listed in `$hidden`.

- Enrolment: generate secret, show QR, require one valid code before setting
  `two_factor_confirmed_at`. An unconfirmed secret never gates login.
- 8 single-use recovery codes, regenerable, shown once.
- A tenant may set `settings.require_2fa`; `EnsureTwoFactorConfirmed` then redirects
  non-enrolled members to enrolment before any other screen.
- 2FA challenges are rate-limited independently of password login.
- Super Admin accounts require 2FA unconditionally — enforced in middleware, not policy.
- Available on both guards.

## 5. Permission catalogue

Roles are collections of permissions. **All authorization checks are against permissions,
never against role names.** `$user->hasRole('manager')` must not appear anywhere in
application code; it is a data question, not a logic question.

`config/permissions.php` is the source of truth and is synced by a seeder.

```
customers.view            customers.view_all        customers.create
customers.update          customers.delete          customers.archive

posts.view                posts.create              posts.update
posts.delete              posts.publish             posts.approve_internal
posts.schedule            posts.retry               posts.bulk_import

media.view                media.upload              media.update        media.delete
media.manage_folders

social_accounts.view      social_accounts.connect   social_accounts.disconnect
social_accounts.assign    social_credentials.manage

ai.use                    ai.manage_brand_brain     ai.view_usage

team.view                 team.invite               team.update
team.remove               team.manage_roles

portal_users.view         portal_users.invite       portal_users.remove

analytics.view            reports.generate          reports.share       (Phase 5)

billing.view              billing.manage            billing.view_invoices

settings.view             settings.update           branding.manage
audit_logs.view
```

Platform permissions (Super Admin only, never assignable to a tenant role):

```
platform.tenants.manage       platform.plans.manage      platform.subscriptions.manage
platform.entitlements.override platform.impersonate      platform.feature_flags.manage
platform.jobs.view            platform.audit.view        platform.announcements.manage
```

## 6. Default role templates

Seeded per tenant on creation. Tenants may edit their own roles; system templates are not
editable in V1.

| Role | Permissions |
|---|---|
| **Agency Owner** | All tenant permissions including `billing.manage`, `team.manage_roles`, `social_credentials.manage` |
| **Agency Admin** | All except `billing.manage` and `social_credentials.manage` |
| **Manager** | Customers view/update, full posts including `posts.approve_internal` and `posts.publish`, media, social view/assign, `ai.use`, `team.view` |
| **Content Creator** | `posts.view/create/update`, media view/upload, `ai.use`, customers.view (assigned only) |
| **Designer** | Media full, `posts.view`, customers.view (assigned only) |
| **Approver** | `posts.view`, `posts.approve_internal`, `posts.update` |
| **Analyst** | `posts.view`, `analytics.view`, `reports.generate` (Phase 5) |
| **Client Viewer** | `posts.view`, `customers.view` — read-only agency-side seat |

Portal roles (`customer` guard), assigned per brand in
`customer_portal_user_customer.role`:

| Role | Permissions |
|---|---|
| **Portal Approver** | `portal.posts.view`, `portal.posts.approve`, `portal.posts.reject`, `portal.posts.comment` |
| **Portal Viewer** | `portal.posts.view`, `portal.reports.view` (Phase 5) |

## 7. Two-dimensional authorization

Agency-side access is the **intersection** of two independent questions:

1. *Does this user hold the permission?* — RBAC.
2. *Is this user assigned to this brand?* — `customer_user`, or the `customers.view_all`
   permission.

Both must pass. A Content Creator with `posts.create` still cannot create a post for a brand
they are not assigned to.

```php
trait InteractsWithCustomers
{
    public function canAccessCustomer(int $customerId): bool
    {
        if ($this->can('customers.view_all')) {
            return true;
        }

        return $this->assignedCustomerIds()->contains($customerId);
    }

    public function assignedCustomerIds(): Collection
    {
        // cached per request; invalidated on assignment change
        return $this->customers()->pluck('customers.id');
    }
}
```

Policy shape, applied uniformly:

```php
public function approveInternal(User $user, Post $post): bool
{
    return $post->tenant_id === app(TenantContext::class)->id()
        && $user->canAccessCustomer($post->customer_id)
        && $user->can('posts.approve_internal')
        && $post->status === PostStatus::InternalReview;
}
```

The final clause matters: authorization and workflow state are checked together, so an
approve action on an already-published post is refused rather than silently re-running.

## 8. Portal authorization

Portal users are constrained by an explicit allow-list, not by an absence of permissions:

- They may read a post only if it has reached `CLIENT_REVIEW` or later. Drafts and
  internal-review content are invisible.
- They may read only non-internal comments (`post_comments.is_internal = false`), enforced
  in the query *and* asserted in the policy.
- They never see: other brands, the content calendar, team members, media library,
  billing, analytics, AI tooling, or any `/app` route.
- Portal routes live in `routes/portal.php` behind `auth:customer`. There is no shared
  controller between portal and agency surfaces.

## 9. Impersonation

Super Admin may impersonate an agency user for support.

```
Admin (has platform.impersonate, 2FA confirmed)
  -> selects target user, enters a reason (required, free text)
  -> impersonation_sessions row opened; audit log written
  -> session stores impersonator_id; the admin session is preserved, not replaced
  -> persistent banner on every page: who, target, elapsed, "Exit" button
  -> exit or 60-minute timeout closes the session and writes the closing audit entry
```

Blocked while impersonating (`EnsureNotImpersonating` middleware):

- Changing passwords, email, or 2FA settings
- Viewing or editing social app credentials
- Deleting brands, posts, or team members
- Initiating payments or changing the subscription
- Disconnecting social accounts

Every audit entry written during impersonation carries `impersonator_user_id`, so the
trail attributes the action to both identities. Impersonation of another Super Admin is
refused.

## 10. Test coverage (Phase 1 gate)

- Each role template resolves to exactly its expected permission set.
- Permission checks are enforced on every route (iterate the route table; a route with no
  policy or permission middleware fails the test unless explicitly allow-listed).
- Brand assignment is enforced independently of permissions, in both directions.
- Portal users receive 403/404 on all `/app` and `/admin` routes.
- Portal users cannot read posts below `CLIENT_REVIEW`, or internal comments.
- 2FA cannot be bypassed by deep-linking past the challenge.
- Impersonation writes both audit entries and blocks every restricted action.
- `is_super_admin` cannot be set through any mass-assignment path.
- Recovery codes are single-use.
