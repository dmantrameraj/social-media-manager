# Team management — removing people, not just adding them

**Date:** 2026-09-03

## 1. The gap

An agency could invite team members and never remove them.

`MembershipStatus::Suspended` was defined. `permitsAccess()` already returned
false for it. `ResolveTenant` already re-read the membership with `->active()`
on every request and aborted 403 when it found none.

**Nothing could set it.** `TeamController` had exactly two actions, `index` and
`invite`. The permission catalogue defined `team.update`, `team.remove` and
`team.manage_roles` — none of them used anywhere. `Invitation::revoked_at`
existed and `scopePending()` already excluded revoked rows; nothing set that
either.

So a person who left the agency kept full access to every brand, every post and
every media file, permanently, and an invitation sent to the wrong address
stayed usable until it expired on its own.

The enforcement was built. The switch was missing. Third instance of that shape
this week — after the Brand Brain editor and the media variants job — but the
first one that is a security hole rather than a dead end.

## 2. What was added

`ManageTeamMemberService`, plus four routes on the existing team screen:

| Action | Permission | Effect |
|---|---|---|
| Suspend | `team.remove` | Membership → `suspended`; access stops next request |
| Reinstate | `team.remove` | → `active`, subject to the seat limit |
| Change role | `team.manage_roles` | `syncRoles` to exactly one role |
| Revoke invitation | `team.invite` | Sets `revoked_at` |

Suspend rather than delete. The posts, approvals, comments and audit entries a
person created stay attributable; deleting the membership would leave those
records pointing at somebody who is no longer in the workspace.

`team.remove` and `team.manage_roles` are deliberately separate: deciding what
someone may do is a different authority from deciding whether they are here at
all. Manager holds `team.view` and neither of the other two.

## 3. The guards

**You cannot act on yourself.** Self-suspension locks you out on the very next
request, and a self-demotion can strip the permission needed to undo it. Either
way the person who could fix it is the person who just lost the ability to.

**You cannot act on the workspace owner.** The owner is the billing and legal
contact; losing them leaves a subscription nobody can manage.

**No change may leave the workspace unadministrable.** An agency with nobody
holding `team.manage_roles` cannot add members, cannot undo a mistaken
suspension, and cannot recover without somebody reaching into the database.

That last one is asserted **after** the change, inside the transaction, by
asking the permission system directly. Predicting it beforehand would mean
expanding `['*']` and `['except' => [...]]` role templates in a second place,
and a second implementation of that eventually disagrees with the real one.
Making the change and then asking cannot drift; the transaction rolls it back.

**Reinstating is subject to the seat limit.** A suspended member stops consuming
a seat — `currentUsage` counts active rows only — so suspending somebody to hire
their replacement works, and bringing them back has to fit the plan like any
other addition.

**An invited member cannot be "reinstated".** Flipping an `invited` row to
`active` would grant access to somebody who never followed their invitation and
never set a password. That is a different and much worse thing than restoring
someone who once had access.

## 4. Tenant scoping

`TenantUser` is the join that *defines* tenant access, so it deliberately carries
no tenant scope — scoping it by the active tenant would be circular. That makes
the controller's `assertOwnMember()` the only thing standing between a guessed id
and another agency's membership row. Tested directly.

## 5. Timing

Suspension lands on the member's **next request**, not at a later sweep, because
`ResolveTenant` re-reads the membership every time and never trusts the session.
The response is a 403 that also clears the tenant from the session — not a
redirect, which would imply somewhere else to go.

## 6. Verified

`tests/Feature/Tenancy/ManageTeamTest.php` covers the lockout timing, the seat
accounting, every guard above, the permission split, cross-tenant refusal, and
the audit entries.

Two setup notes worth keeping: the default plan allows a single team member,
which the owner alone fills, so tests that add or reinstate anyone must raise
`team_members.max` first or the seat guard decides the result instead of the
behaviour under test. And `syncRoles()` runs against a different instance of the
same user, so the administrability check calls `fresh()` — the loaded relation
still holds the roles from before the change.

## 7. Not done

- **Leaving a workspace voluntarily.** A member cannot remove themselves; the
  self-guard blocks it. Doing it properly needs the same last-administrator
  check plus a confirmation, and is a different screen.
- **Bulk actions.** One member at a time.
- **Notifying the suspended person.** Arguably correct to stay silent, but it
  should be a decision rather than an omission.
- **Session revocation.** Suspension stops the *next* request; it does not tear
  down an in-flight session anywhere else. `sessions.guard` exists for this and
  the handler that populates it still does not — see
  `PHASE-1-COMPLETION.md` §7.
