# Phase 1 Step 12 — Super Admin

**Date:** 2026-09-02
**Status:** Built. The client portal (`/portal`) is now the last unbuilt surface in Phase 1.

---

## 1. What was built

`/admin`, the platform-operator surface: an operations dashboard, agency
list/detail/create, suspend and reactivate, entitlement overrides, AI credit
adjustment, impersonation, a read-only plan catalogue, an audit-log viewer and a
failed-jobs viewer. Sixteen routes.

Also the two-factor **enrolment** screen, which did not exist and without which
none of the above was reachable — see §3.

## 2. Three gates, not one

Every admin route passes three independent checks:

1. **`admin` middleware** — `auth:web` + `EnsureSuperAdmin`, which additionally
   requires confirmed 2FA and answers **404 rather than 403**. A 403 would confirm
   `/admin` exists and is worth attacking.
2. **A platform gate inside every action** — `platform.tenants.manage`,
   `platform.impersonate` and so on. Super Admins hold all of them today; the check
   is what lets a narrower staff role ("support can read, only finance can adjust
   credits") ship later as configuration rather than a rewrite.
3. **An audit entry**, written by the service inside the transaction — never by the
   controller, so console and future API paths cannot skip it.

**Platform permissions are gates, not spatie roles.** spatie's permissions are
team-scoped to a tenant, so a `platform.*` permission held that way would be a
*per-agency* grant — exactly backwards for authority that spans every agency.
They resolve through `is_super_admin`, which no tenant can assign.

**There is deliberately no `Gate::before` granting Super Admins every ability.**
If there were, a support engineer would silently satisfy the policies written to
protect an agency's data, with nothing in the trail. Reaching agency data is what
impersonation is for, and impersonation is recorded. A test asserts a Super Admin
*fails* `can('view', $brand)`.

## 3. Four bugs found while building

**`EnsureSuperAdmin` redirected to a route that did not exist.** It sent
unenrolled administrators to `route('two-factor.enrol')`; Fortify's names are
`two-factor.enable`, `two-factor.qr-code` and so on. Any Super Admin without 2FA
got a `RouteNotFoundException`. There is now a real enrolment screen at that name,
behind `auth:web` only — gating it on `super-admin` would be a redirect loop,
since that middleware is what sends people there.

**The `User` model was missing Fortify's `TwoFactorAuthenticatable` trait.**
`config/fortify.php` enabled `twoFactorAuthentication()`, the columns were
migrated, and `two_factor_secret` was cast as encrypted — but nothing could
*generate* a secret, because the QR code, recovery codes and enabled-check all
live in that trait. So `two_factor_confirmed_at` could never be set, and since
`EnsureSuperAdmin` demands confirmed 2FA, **the admin surface would have been
permanently unreachable.** The feature looked complete from every angle except
use.

**A blocked-route pattern that protected nothing.** The impersonation block list
carried `agency.billing.*`, which does *not* match the route actually named
`agency.billing`: `Str::is` compiles the pattern to a regex requiring the
separating dot. Billing — the money screen — stayed reachable while impersonating,
and the config read as correct. A trailing `.*` now also matches the bare parent
name, and a test asserts **every live pattern matches at least one registered
route**, because a pattern that matches nothing reads as a protection and is a
hole. Six of the original fourteen matched nothing; the genuinely forward-looking
ones moved to a separate `blocked_routes_pending` list that is merged at match
time but exempt from that test.

**A Super Admin could log in and land on a 403.** Fortify's `home` is a single
static path, so every principal was sent to `/app` — but platform staff usually
belong to no agency at all, and `ResolveTenant` answered 403 there. Sign-in
succeeded and went nowhere; `/admin` was reachable only by typing the URL. Login
now routes by principal through one `HomeRedirector`, bound into **both**
`LoginResponse` and `TwoFactorLoginResponse` — a user with 2FA never passes
through the first, so binding only that would have fixed the redirect for exactly
the accounts that do not have 2FA, and left it broken for every Super Admin, who
are required to have it. The root route uses the same class, because a user who
lands somewhere different depending on which door they came through is a support
ticket.

## 4. Impersonation

The feature is a deliberate hole in the authorisation model, so the guardrails
*are* the feature:

- **A reason is required**, minimum ten characters — the longest minimum on the
  surface. "Support" is not an account of why it was necessary.
- **A Super Admin may never impersonate another Super Admin.** That would turn one
  compromised admin account into all of them, and it defeats the trail: the entry
  would name a second administrator rather than the person who acted.
- **Both identities are recorded.** `AuditLogger` reads `impersonator_id` from the
  session, so every write during support attributes to the admin *and* the
  customer. An action taken during support must never read as the customer's own.
- **The session expires on a clock**, not on the admin remembering to leave.
  `HandleImpersonation` ends it on the next request; `platform:heartbeat` closes
  the row for an admin who simply closed the tab.
- **The banner is on every agency page**, reading from the session rather than
  from each controller — a banner that depends on every controller remembering to
  supply it will be missing from exactly the page where it matters.
- **The exit is never blockable.** `admin.impersonation.stop` sits outside the
  admin group: while impersonating, the principal *is* the customer, so gating the
  exit on `super-admin` would trap the admin inside the account.

`HandleImpersonation` runs on the whole `web` group, not the agency group alone. A
restriction that covers only the routes someone remembered to protect is not a
restriction.

## 5. The credentials guarantee

Agencies supply their own provider credentials on the understanding that platform
staff cannot read them (docs/05-SOCIAL-PROVIDERS.md §11, docs/10-SECURITY.md §5).
No admin screen reads `social_app_credentials`, and none may be added.

This is asserted, not merely intended: a test plants a credential row with a known
secret, renders **every** admin GET screen, and fails if the secret, the client id,
or the `client_secret` column name appears in any response body. A second test
writes a secret through `AuditLogger` and asserts the audit viewer cannot print it
— redaction happens on write, which is the correct place, because redacting on
read leaves the secret in the database for anything else to find.

`admin/tenants/show.blade.php` carries a note saying so, for whoever extends it.

## 6. Manual provisioning has no password field

Staff creating an agency do not choose a customer's credential. The owner is
created with a random password nobody sees and arrives through the normal
password-reset flow. A test asserts the stored hash matches none of the obvious
guesses.

`owner_email` validates as `email:rfc`, **not** `email:rfc,dns`. A DNS lookup makes
a staff-facing form fail whenever resolution is slow or unavailable, trading a real
outage for a typo check the password-reset email already performs.

## 7. Operations dashboard

Health before business metrics: a revenue number read off a platform that silently
stopped running is worse than no number.

- **Scheduler heartbeat.** Publishing, credit resets and the reservation sweeper
  are all scheduled work, so a dead scheduler is *silent* — nothing errors, things
  simply stop happening. `platform:heartbeat` writes a timestamp every minute and
  the dashboard alarms on its absence. Never having beaten counts as stale, which
  is exactly the post-deploy case this exists to catch.
- **Queue depth and oldest wait.** Oldest-waiting is the better signal: a deep
  queue that is moving is fine, a shallow one that is stuck is not.
- **Open impersonation sessions**, so "who is inside a customer account right now"
  is answerable at a glance.

## 8. What the browser confirmed

All six admin screens rendered and were read: the operations dashboard (with the
scheduler alarm correctly firing, since no scheduler runs in the dev database),
the agency list, agency detail, plans, the audit viewer and failed jobs. Plus the
two-factor challenge and the full login path.

Two cosmetic bugs came out of it. The dashboard tab read "Platform · Platform ·
…" because the layout already appends the word. And the limits table rendered
"AllowanceSource" with the columns touching — the fix was a padding utility that
**did not appear until `npm run build` was re-run**, because Tailwind 4 only
generates the utilities it finds in templates at build time. A class that is not
in the compiled stylesheet fails silently and looks like a bad selector.

## 9. Deliberately not built

- **Plan editing.** Read-only, and that is the decision, not an omission. Editing a
  plan changes what every tenant on it is entitled to, retroactively and with no
  invoice to reconcile against — so plan changes ship as migrations. Per-agency
  differences go through entitlement overrides, which are scoped to one account and
  audited. A half-built plan editor would be the most dangerous screen in the
  product.
- **Feature-flag and announcement management.** The permissions exist; the screens
  do not.
- **Tenant deletion / data purge.** Cancellation and the purge job are Phase 8.
- **Narrower staff roles.** The gates are in place so this is configuration when it
  is wanted; there is one role today.
- **Impersonation was not walked in a browser.** The six tests covering it pass,
  including ones asserting the banner renders on agency pages, that billing and
  credential routes are blocked, that the exit works and that the timeout closes
  the session. The browser pane's screenshot capture became unreliable partway
  through, so the banner has not been seen by eye.
- **Responsive polish.** At 900px the detail page collapses to one column, which
  reads correctly but has not been designed for; there has been no mobile pass and
  no screen-reader audit.
