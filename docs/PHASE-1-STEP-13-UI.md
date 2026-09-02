# Phase 1 Step 13 — Agency UI

**Date:** 2026-09-02
**Status:** Agency shell built and walked in a browser. Super Admin (Step 12) and the client portal still outstanding.

---

## 1. What was built

The agency application at `/app`: dashboard, brands (list/create/show/archive/restore),
content list, composer, calendar, media library, team management, and billing.

**Stack note:** Livewire 4 defaults to *single-file components* in
`resources/views/components/` — a real departure from Livewire 3's class-plus-view pair.
These screens are plain controllers and Blade, which suits list/detail work and keeps
authorisation where the route-coverage test can read it. Livewire is available for the
genuinely interactive parts (drag-and-drop calendar, live AI panel) when they arrive.

**Branding is never hardcoded.** Every template reads `BrandingResolver`, so white
labelling in Phase 8 becomes a change in one class rather than a sweep through a hundred
Blade files.

## 2. Three real problems the tests found

**Route-model binding ran before tenant resolution.** `SubstituteBindings` sits in
Laravel's middleware priority list; `ResolveTenant` did not, so it ran *after*. The tenant
global scope was therefore inactive during binding: another tenant's record was found, and
the request survived to the policy check. That still denied access, but answered **403
rather than 404** — confirming the record exists. Fixed by inserting `ResolveTenant` into
the priority list before `SubstituteBindings`. A dedicated test now asserts that ordering,
because it is invisible in the route definitions themselves.

**Billing had no authorisation check.** Plan, spend and usage were readable by any member
of the tenant, including a Designer. Now gated on `billing.view`.

**Fixing that exposed a redirect trap.** `EnsureTenantActive` redirected suspended tenants
to billing — which, once gated, would bounce a member without billing rights between a 403
and a redirect with no way out. There is now a dedicated `/app/paused` page requiring no
permission, which shows the billing link only to someone who can act on it and otherwise
tells them who to ask.

## 3. The route-coverage test — a Phase 1 exit criterion

Two assertions, both previously impossible because no routes existed:

1. **Every `agency.*` route is behind `auth:web` and `tenant`.** Middleware groups are
   expanded first — `gatherMiddleware()` returns group *names*, not their members, which
   would have made a naive version of this test pass vacuously.
2. **Every agency controller action performs an authorisation check**, asserted by reading
   the method source. Middleware proves who is asking and which tenant; only the controller
   can decide whether this user may do this thing to this record. A new route cannot
   quietly ship without one — which is exactly how authorisation gaps appear.

Only `agency.dashboard` is exempt, and its queries are already filtered to the caller's own
tenant and brands.

## 3b. Four more the tests could not find, and a browser did

The feature suite proved routing, authorisation and data. It could not prove that
the application is *usable*, and all four of these were found within a minute of
pointing a browser at it.

**Every successful login landed on a 404.** Fortify's `home` config defaults to
`/home`, a path this application does not define. Sixteen tests asserted that
login redirects; none asserted the destination renders. `config/fortify.php` now
points at `/app`, and a test resolves `config('fortify.home')` and requests it.

**Entitlements were cached as a serialized object, and could not be read back.**
Laravel 13 ships `cache.serializable_classes => false` — a deliberate defence
against gadget chains if `APP_KEY` leaks — so `unserialize` runs with
`allowed_classes: false` and the cached `Entitlement` returns as
`__PHP_Incomplete_Class`, fatalling against the `: Entitlement` return type.
Every limit check 500'd on the second request: brands, billing, media, the
composer. **The entire suite missed it because the `array` cache store defaults
to `serialize => false` and hands objects back by reference, so nothing was ever
serialized.** The resolver now caches scalars and rehydrates
(`Entitlement::toCacheArray()` / `fromCacheArray()`), which is also immune to a
property rename poisoning every cache entry across a deploy. Three tests cover
it with serialization forced on, including one that plants a pre-fix entry and
asserts it is treated as a miss rather than a fatal.

The fix was deliberately *not* to add the class to `serializable_classes`. That
would trade a real security default for a caching convenience.

**`/app/paused` told healthy tenants they were paused.** The page sits outside
the `tenant.active` group — it has to, or a blocked tenant could never reach it —
so it also answers for tenants that are fine, and it rendered unconditionally: a
*trialing* workspace was told content and publishing were unavailable. It now
redirects to the dashboard when the tenant actually has product access, which
doubles as the return path after a lapsed tenant pays.

**`/` served the stock Laravel welcome splash.** Replaced with a redirect —
dashboard when signed in, login when not. There is no marketing site in this
repository; that is a separate concern, and shipping the framework's splash page
in its place is worse than routing people where they were going.

Two smaller things fixed in the same pass: the auth layout never loaded the
compiled assets, so all seven auth screens rendered as unstyled HTML; and every
auth screen shared the browser title "Sign in", because Fortify renders those
views itself and cannot pass a `$title`.

## 3c. What the browser confirmed

Dashboard, brands index and detail, calendar, content list, composer, media,
team, billing, the paused page and all seven auth screens were rendered and
read. Layout, navigation state, empty states, plan-limit copy and branding
resolve correctly.

## 4. Product decisions worth recording

- **Navigation is gated by permission, not role.** A tenant editing its own roles must not
  gain or lose menu items unexpectedly, and a link the user cannot use is never rendered —
  offering an action that then 403s is worse than not offering it.
- **Lists filter by brand assignment as well as permission**, so a list never shows a row
  that would 403 when clicked.
- **Scheduling is entered in the brand's timezone and stored as UTC**, with the timezone
  snapshotted onto the post. The form says so, because "it went out an hour early" is the
  classic support ticket here.
- **Plan limits are stated with their remedy.** At the brand limit, the create button is
  replaced by an explanation and a link to billing, rather than silently vanishing.
- **Archiving is offered instead of deletion** — it frees the plan slot while keeping every
  post, media file and approval, which is what an agency losing a client actually wants.
- **Per-destination status is shown separately** on a post, because one provider failing
  must never read as the whole post having failed.
- **Only publishable accounts are offered** in the composer; one behind an expired
  connection would fail at publish time.
- **Media is never rendered by direct path** — files are on a private disk and reachable
  only through signed, policy-checked URLs.
- **The calendar caps previews per day.** A busy agency has hundreds of posts a month, and
  rendering every one is a guaranteed incident.

## 5. Not done

- **Step 12, Super Admin** — no `/admin` surface. `EnsureSuperAdmin` and the middleware
  group exist; the dashboard, tenant management, impersonation and audit viewer do not.
- **Client portal** — no `/portal` surface. The guard, model and policies exist.
- **Composer depth** — no media attachment, no per-platform overrides, no live validation,
  no AI panel. The engine supports all four; the UI does not surface them yet.
- **Calendar** is read-only — no drag-and-drop rescheduling.
- **Brand Brain editor** — no UI, so autopilot and AI grounding are configured only through
  the database.
- **Social connection screens** — nothing to connect until Phase 2's providers exist.
- **Responsive and accessibility polish.** The screens were walked in a desktop browser
  only. There has been no mobile pass, no screen-reader audit and no keyboard-order check;
  semantic markup, labels and focus styles are in place, which is the starting point rather
  than the finish.
