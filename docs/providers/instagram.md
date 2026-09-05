# Instagram Business

**Adapter:** `App\Domain\Social\Providers\Meta\InstagramProvider`
**Graph API version:** v25.0 (`META_GRAPH_VERSION`)
**Documentation read:** 2026-09-05, developers.facebook.com
**Live-API status:** **unproven.** Read from Meta's reference, never exercised
against a real account.

---

## 1. What is implemented

| Operation | Endpoint | Verified |
|---|---|---|
| OAuth (all four) | delegated to `FacebookPageProvider` | 2026-09-05 |
| Account discovery | `GET /{user-id}/accounts?fields=…instagram_business_account{…}` | 2026-09-05 |
| Container | `POST /{ig-id}/media` (`image_url`, `caption`, `media_type`, `children`) | 2026-09-05 |
| Container status | `GET /{container-id}?fields=status_code` | 2026-09-05 |
| Publish | `POST /{ig-id}/media_publish` (`creation_id`) | 2026-09-05 |

## 2. It is reached through a Page

An Instagram Business account is administered through the Facebook Page that
owns it. There is one grant, one token and one refresh path, so
`authorizationUrl()`, `exchangeCode()`, `refresh()` and `revoke()` all delegate
to the Facebook adapter rather than being written twice.

Discovery reads `instagram_business_account` off each Page. A Page without a
linked professional account is not an Instagram destination and is filtered out.

**Publishing authenticates with the PAGE token**, not an Instagram one. That is
the single detail here most easily got wrong.

## 3. Scopes

```
instagram_basic
instagram_content_publish
```

`instagram_basic` was missing from the original `[VERIFY]` guess. Without it the
account cannot be discovered at all, so publishing permission alone would leave
a destination nobody can select.

## 4. The two-phase flow, and why a retry is safe

1. `POST /{ig-id}/media` creates a **container**. This publishes nothing.
2. Poll `GET /{container-id}?fields=status_code` until `FINISHED`.
3. `POST /{ig-id}/media_publish` with `creation_id` posts it.

`status_code` values: `EXPIRED`, `ERROR`, `FINISHED`, `IN_PROGRESS`,
`PUBLISHED`.

A crash between steps 1 and 3 leaves an unused container that Meta expires after
24 hours, and a retry creates a fresh one. That leaks a container and **cannot
duplicate a post**, which is the right way round.

Polling is bounded (`META_CONTAINER_POLL_ATTEMPTS`, default 8, three seconds
apart). Exceeding the bound raises `Timeout`, which is retryable and does not
consume an attempt — the container is still there and may well be ready next
time. A job that waits indefinitely on someone else's queue is a worker that
never comes back.

### The risk that remains

If `media_publish` succeeds and the reply is lost, a retry may double-post.
Recovering from that needs `SupportsRecentPostLookup`, and the media-listing
endpoint it would use has **not** been verified — so it is deliberately not
implemented rather than guessed at. **Verify that endpoint before this carries
real volume.**

## 5. Carousels

`media_type=CAROUSEL` with `children` listing up to **10** container ids, each
created with `is_carousel_item`. Meta's own ceiling, verified; the config limit
matches it.

## 6. There is no text-only post

Instagram requires media. `validate()` refuses a text-only payload in the
composer rather than at publish time, because this is the difference between the
two networks that most often surprises somebody composing for both at once.

## 7. Images need a public URL — this is not optional here

Unlike Facebook photos, Instagram offers **no** byte-upload path for images: it
takes `image_url` and fetches the file itself, anonymously, from its own
network.

`ProviderMediaUrl` mints a signed, ten-minute, ULID-addressed URL served by
`GET /m/{media}`. The adapter refuses to publish when one cannot be produced, or
when `APP_URL` is not a host Meta can reach — a URL Instagram cannot fetch
surfaces as a container `ERROR` minutes later with no explanation.

## 8. Deliberately not implemented

- **Video and Reels** — resumable upload against `rupload.facebook.com`, not
  verified.
- **Stories** — not verified.
- **Insights** — `SupportsAnalytics` not implemented.
- **Comments** — `SupportsInbox` not implemented.
- **Deletion** — Meta's own capability matrix in `config/social.php` records
  Instagram as not supporting it; unverified either way.

## 9. Before this goes live

1. The Instagram account must be a **Business or Creator** account, linked to a
   Facebook Page the connecting user administers.
2. App Review is required for `instagram_content_publish`.
3. Verify the media-listing endpoint and implement
   `SupportsRecentPostLookup` before this carries real volume — see §4.
4. Publish one real image to one real account before trusting any of the above.
