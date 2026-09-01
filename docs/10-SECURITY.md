# 10 — Security

## 1. Threat model

| # | Threat | Impact | Primary control |
|---|---|---|---|
| T1 | Cross-tenant data access | Existential | Five isolation layers (`03-TENANCY.md`) |
| T2 | OAuth token or API secret disclosure | Severe — attacker posts as the client | Encryption at rest, no-read UI, log redaction |
| T3 | Portal user reaching agency data | Severe | Separate guard + table + routes + layouts |
| T4 | Privilege escalation within a tenant | High | Permission checks, non-fillable `is_super_admin` |
| T5 | Unauthorized publishing | High | Ownership checks at dispatch and publish time |
| T6 | Webhook forgery (billing) | High — free subscriptions | HMAC over raw body, `hash_equals`, dedupe |
| T7 | OAuth CSRF / code interception | High | Single-use bound state, PKCE, exact redirect match |
| T8 | Account takeover | High | 2FA, throttling, password confirmation, session revocation |
| T9 | Stored XSS via post content or Brand Brain | Medium-High | Blade escaping, sanitised rich text, CSP |
| T10 | Media upload abuse (RCE, storage exhaustion) | Medium-High | MIME sniffing, extension allow-list, private disk, quotas |
| T11 | IDOR via sequential IDs | Medium | ULIDs in URLs + policy checks (defence in depth) |
| T12 | Open redirect in OAuth return | Medium — phishing | Relative-path allow-list validation |
| T13 | Secret leakage through error pages/logs | Medium | `APP_DEBUG=false`, redaction filter |
| T14 | Enumeration via unique-constraint errors | Low-Medium | Composite unique keys with `tenant_id` |

## 2. Secrets at rest

Encrypted with `APP_KEY` via Laravel `encrypted` casts (AES-256-CBC with HMAC):

| Table | Columns |
|---|---|
| `social_app_credentials` | `client_id`, `client_secret`, `extra` |
| `social_connections` | `access_token`, `refresh_token` |
| `social_accounts` | `page_access_token` |
| `users`, `customer_portal_users` | `two_factor_secret`, `two_factor_recovery_codes` |
| `oauth_states` | `code_verifier` |

Handling rules, enforced by tests:

1. Every such column is in the model's `$hidden`.
2. No API resource, JSON response, Blade view or export includes them. A test iterates
   these models, serialises them, and asserts no secret value appears in the output.
3. Write-only in the UI: after saving, forms show a masked placeholder; an empty submitted
   value means "unchanged", never "clear".
4. `SecretRedactor` scrubs values from exception messages, log context and queue payloads
   before write. Provider HTTP clients pass every logged body through it.
5. **Super Admin has no screen that displays agency credentials**, and none may be added.
   Admin sees provider, label, `verified_at` and last error only.
6. `APP_KEY` rotation is documented in `11-DEPLOYMENT.md` — re-encrypting these columns is
   a required step, not an optional one, and the app is unusable if it is skipped.

## 3. Authentication

Covered in `04-AUTH-RBAC.md`. Summary of controls: Argon2id/bcrypt(12); 12-character
minimum checked against HIBP by k-anonymity; login throttled per email+IP with exponential
lockout; failures logged without the attempted password; database sessions with device
listing and revocation; session ID regenerated on login and tenant switch; TOTP 2FA with
encrypted secrets and single-use recovery codes, mandatory for Super Admins; password
confirmation on sensitive actions.

## 4. Authorization

- Policies on every model; controllers and Livewire actions authorize before touching
  anything.
- Permission-based checks only — `hasRole()` never appears in application logic.
- Two-dimensional agency access: permission **and** brand assignment.
- `is_super_admin` is guarded, not fillable, and only settable through an audited console
  command.
- A route-coverage test fails CI if any route lacks a policy or permission middleware,
  unless explicitly allow-listed with a reason.

## 5. Input and output

- All input validated through Form Requests or Livewire rules; `$request->all()` is never
  passed to `create()`/`update()`.
- Every model declares `$fillable`. `$guarded = []` is banned.
- Blade escapes by default; `{!! !!}` requires a sanitiser and a code-review justification.
- Rich text (post bodies, comments) is sanitised server-side against an allow-list before
  storage. Client-side sanitisation is not a control.
- Brand Brain content is treated as untrusted data when interpolated into prompts
  (`08-AI-ARCHITECTURE.md` §3).
- Raw SQL is banned in tenant-facing code; the rare justified exception uses bindings and
  an explicit `tenant_id` predicate.
- File paths from user input are never concatenated into filesystem calls. Media is
  addressed by database ID; the stored path is server-generated.

## 6. Uploads

- Extension allow-list plus MIME verification by content sniffing, not by the client-sent
  `Content-Type`.
- Stored on a **private** disk, outside the web root, with server-generated random names.
  Original filenames are metadata only.
- Delivered through signed, expiring URLs after a policy check — never by direct public
  path.
- Size limits from entitlements; storage quota enforced before write.
- SVG uploads are disabled by default (`config('media.allow_svg')`); SVG is an XSS vector
  and enabling it requires sanitisation.
- Images are re-encoded through GD on processing, which strips embedded payloads and EXIF.

## 7. Transport and headers

- HTTPS enforced in production; HSTS with a 1-year max-age once the certificate is stable.
- Secure, HttpOnly, `SameSite=Lax` session cookies.
- `Content-Security-Policy` (report-only first, then enforcing), `X-Content-Type-Options:
  nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` minimal.
- CSRF on all state-changing routes. Webhook routes are excluded and instead protected by
  signature verification — the exclusion list is short, explicit and reviewed.

## 8. Rate limiting

| Surface | Limit |
|---|---|
| Login (both guards) | 5/min per email+IP, exponential lockout |
| 2FA challenge | 5/min per session |
| Password reset request | 3/hour per email |
| OAuth initiate/callback | 10/min per IP, 30/min per tenant |
| Webhooks | 100/min per IP |
| AI generation | Per-tenant concurrency cap plus credit limits |
| General authenticated | 300/min per user |
| Media upload | 60/min per user |

## 9. Data retention and deletion

- Soft deletes on customers, posts, media metadata, portal users, credentials.
- Cancellation starts a 60-day retention clock (`tenants.purge_after`).
- `platform:purge-expired-data` (daily) then: anonymises PII on users and portal users,
  deletes media files from storage, **revokes and deletes OAuth tokens**, and retains
  aggregate financial records required for accounting.
- Audit logs and financial records survive anonymisation with identifiers replaced — the
  legal obligation to retain records and the obligation to remove personal data are both
  satisfied.
- The purge is a job, is audited, and is preceded by warning emails at 30 and 7 days.
- Token revocation on purge is called out separately because it is the step most often
  forgotten: deleting our row without revoking the grant leaves a live token on the
  provider side.

## 10. Audit logging

Recorded: authentication events, permission and role changes, tenant lifecycle changes,
credential create/update/delete (existence and metadata only — never values), social
connect/disconnect, post status transitions, approvals and rejections, publish attempts,
subscription and entitlement changes, credit adjustments, impersonation start/end, data
exports and purges.

Each entry: tenant, actor (polymorphic across guards), impersonator, action, entity,
old/new values, IP, user agent, request ID, timestamp.

Append-only: no `updated_at`, no soft delete, no update path on the model. Values pass
through the redaction filter before write — a secret must never be recoverable from the
audit trail.

## 11. Production configuration

```
APP_ENV=production
APP_DEBUG=false          # non-negotiable
APP_KEY=<32-byte, generated per environment>
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
```

- `.env` is never committed; `.gitignore` covers it from the first commit.
- `.env` permissions `600`; never served — verified by a smoke test hitting `/.env` and
  expecting 404.
- No secret is ever committed. A pre-commit secret scan is part of the Phase 1 checklist.
- Custom error pages; stack traces never rendered in production.
- `php artisan config:cache route:cache view:cache` on deploy.

## 12. Dependency and code security

- `composer audit` and `npm audit` in CI; the build fails on high or critical findings.
- Larastan level 5+, ratcheting.
- Dependencies pinned via lock files; updates reviewed, not auto-merged.
- Security-relevant tests (tenancy, authorization, webhook signatures, secret exposure) are
  a merge gate with no override.

## 13. Incident readiness

Documented in `11-DEPLOYMENT.md`:

- Credential compromise: rotate app credentials, invalidate connections, force reconnect,
  notify affected tenants.
- `APP_KEY` compromise: rotate and re-encrypt every encrypted column; all OAuth connections
  must be re-established.
- Suspected tenant leak: freeze writes, audit-log review, scoped notification.
- Database backups tested by restore, not merely taken.
