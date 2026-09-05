# Facebook Pages

**Adapter:** `App\Domain\Social\Providers\Meta\FacebookPageProvider`
**Graph API version:** v25.0 (`META_GRAPH_VERSION`)
**Documentation read:** 2026-09-05, developers.facebook.com
**Live-API status:** **unproven.** Every fact below was read from Meta's own
reference. None has been exercised against a real Page — that needs a developer
app and platform review.

---

## 1. What is implemented

| Operation | Endpoint | Verified |
|---|---|---|
| Authorisation dialog | `GET https://www.facebook.com/v25.0/dialog/oauth` | 2026-09-05 |
| Code exchange | `GET /oauth/access_token` (`client_id`, `client_secret`, `redirect_uri`, `code`) | 2026-09-05 |
| Long-lived upgrade | `GET /oauth/access_token` (`grant_type=fb_exchange_token`) | 2026-09-05 |
| Page discovery | `GET /{user-id}/accounts` | 2026-09-05 |
| Text post | `POST /{page-id}/feed` (`message`) | 2026-09-05 |
| Photo post | `POST /{page-id}/photos` (`url`, `caption`) | 2026-09-05 |

## 2. Scopes

```
pages_manage_posts
pages_manage_engagement
pages_read_engagement
```

`pages_manage_engagement` was missing from the original `[VERIFY]` guess in
`config/social.php`. Meta's Pages API reference lists it as required for
publishing a post, not merely for interacting with one.

**A scope is not a role.** The person must also hold the `CREATE_CONTENT` task
on the Page itself. `discoverAccounts()` filters on it, because Meta returns
every Page a user has any role on — including `ANALYZE`-only — and offering one
of those as a destination produces a permission error at publish time, long
after the choice was made.

## 3. Tokens

The code exchange returns a **short-lived** token lasting a couple of hours. The
adapter immediately upgrades it, because a connection that works this afternoon
and is dead tomorrow is the most confusing failure this integration can produce.

- **Long-lived user token:** ~60 days. Refreshed by re-exchanging through the
  same `fb_exchange_token` grant — Meta issues no refresh token, so
  `refresh()` re-exchanges rather than refreshing.
- **Page tokens:** obtained from `/{user-id}/accounts` with a long-lived user
  token, and **do not expire**. This is what publishing uses, and it is stored
  on `social_accounts.page_access_token`.

`revoke()` deliberately does nothing at Meta. `DELETE /{user-id}/permissions`
revokes the entire grant, and an agency may hold several connections through one
Facebook user — revoking centrally would silently disconnect the others.
Removing our stored token ends our access, which is what disconnecting a brand
means.

## 4. Response shapes

A text post returns `{"id": "page_post_id"}`.

A photo returns **both** `{"id": "photo_id", "post_id": "page_post_id"}`. The
adapter prefers `post_id`: that is the one a person can open, and the one
deletion and insights take.

## 5. Errors

Mapped onto `ProviderErrorClass` in `MetaGraphClient::classify()`. The engine
never sees a Meta subcode.

| Meta | Ours |
|---|---|
| `4` API Too Many Calls, `17` API User Too Many Calls, `341` Application limit | `RateLimit` |
| `190` (subcodes `458`, `459`, `460`, `463`, `464`, `467`) | `AuthExpired` |
| `10` Permission Denied, `200`–`299` | `Permission` |
| `506` Duplicate post | `Duplicate` |
| `100` Invalid parameter | `Validation` |
| HTTP 5xx | `ServerError` |

Subcodes `458`–`464` mean the person must return to Facebook — not something a
retry fixes. `MetaGraphClient::requiresUserAction()` draws that line.

The exception carries `error_user_msg` where Meta supplies one: `message` is
written for developers and frequently names internal fields, and is not safe to
show a person.

## 6. Publishing an image needs a public URL

Meta fetches the file itself rather than accepting bytes. Our media lives on a
private disk, so `ProviderMediaUrl` mints a signed, ten-minute, ULID-addressed
URL served by `GET /m/{media}` — the second unauthenticated view of tenant data
in this application, built to the same rules as the report share link.

**This does not work on localhost.** A signed URL Meta cannot reach is valid and
useless, so the adapter refuses before publishing rather than failing later with
something unrecognisable. `APP_URL` must be a host Meta can resolve.

## 7. Deliberately not implemented

Each of these needs its own reading of the documentation. `validate()` rejects
what it cannot do, with a sentence, rather than attempting it:

- **Video and Reels** — a resumable upload against a different host.
- **`scheduled_publish_time`** — we schedule ourselves; native scheduling would
  put a second scheduler in play, and the two would disagree.
- **Insights** — `SupportsAnalytics` is not implemented. See §9: most of what is
  needed is verified, and one specific thing is not.
- **Comments** — `SupportsInbox` is not implemented, so Phase 7's inbox syncs
  nothing real.
- **Deletion** — `SupportsDeletion` is not implemented.

## 8. Before this goes live

1. Create a Meta developer app and add its credentials under **Developer apps**
   in the agency settings.
2. Set `APP_URL` to a host Meta can reach over HTTPS.
3. Submit for App Review: `pages_manage_posts` and `pages_manage_engagement` are
   not available to a live app without it.
4. Connect one real Page and publish one real text post before trusting any of
   the above.

## 9. Insights — what is verified, and the one thing that is not

Partly researched on 2026-09-05 and **deliberately not implemented**, because the
part that is missing is the part that matters.

**Verified:**

| Fact | Value |
|---|---|
| Endpoint | `GET /{post-id}/insights` |
| Parameters | `metric`, `period` (`day`, `week`, `days_28`, `month`, `lifetime`, `total_over_range`), `date_preset`, `since`, `until` |
| Response | `{"data": [InsightsResult], "paging": {}}` |
| Permission | `read_insights` |
| Also required | a **Page access token**, and the **ANALYZE task** on the Page |
| Metric names confirmed | `post_reactions_like_total`, `post_reactions_love_total`, `post_reactions_wow_total` |

**Not verified:** the metric names for impressions, reach, clicks and engaged
users. Meta's public reference describes the `metric` parameter as "a valid
metric for an insights endpoint" without enumerating post-level values, and the
Insights Reference Guide it points to was not reachable.

Those four are exactly the numbers an agency puts in front of a client. Guessing
`post_impressions` because it looks right is the failure mode §64 exists to
prevent: a wrong metric name either errors loudly — which is survivable — or
returns a number for something else entirely, which is not. `post_metrics`
stores null for unmeasured rather than zero, so a partial collector would report
blanks for impressions and reach while appearing to work.

**To finish this** (a small piece of work, once you have Meta access):

1. Read the Insights Reference Guide from a logged-in developer account, or call
   `/{post-id}/insights` against a real post and read back what it accepts.
2. Put the confirmed names in `config/analytics.php` as a per-provider map —
   that file already says the mapping belongs in configuration and explains why.
3. Implement `SupportsAnalytics` on `FacebookPageProvider` reading that map.
4. `analytics:collect` already drives everything above it, so Phase 5 closes on
   step 3.

Note the documentation page rendered examples against **v26.0** while the rest
of this integration was verified at v25.0. `META_GRAPH_VERSION` makes that a
deployment decision; read the changelog before moving it.
