# Media variants — the step that made images usable

**Date:** 2026-09-03

## 1. The gap

`StoreMediaService` marked every uploaded image `processing`, with a comment
saying variants would be generated on the `media` queue. No such job existed.

Nothing else moved a row to `ready`, and `ready` is what four separate things
require:

| Consumer | Requirement |
|---|---|
| Composer attachment | offers `->ready()` media only |
| `MediaStatus::isUsable()` | returns true only for `ready` |
| Publishing validation | re-checks usability immediately before sending |
| Media library previews | `isImage() && isUsable()` |

So **no image ever uploaded could be attached to a post or published.** Alt text
capture, the library grid and the portal preview surface were all built on rows
that could never leave the waiting room.

This is the same shape as the Brand Brain editor: a feature complete in every
part except the one that connects it to the rest.

## 2. The job

`App\Domain\Media\Jobs\GenerateMediaVariants`, on the `media` queue.

Reads the original, records its true dimensions, writes each configured variant,
then sets `ready`. Two variants ship: `thumb` (320) and `preview` (1080).

**Re-encoding is a security control, not only a size one.** Decoding to a raster
and writing a fresh file discards EXIF — including GPS coordinates a client's
phone wrote into a photo — colour profiles, and anything smuggled into a trailing
segment. `WebpEncoder(strip: true)` makes that explicit rather than incidental.

Decisions worth keeping:

- **`scaleDown`, not `scale`.** A 64px logo stays 64px. Upscaling invents detail,
  costs bytes, and makes a small asset look worse than the original.
- **Decoded per variant.** Modifiers mutate in place, so a shared instance would
  scale the preview from the already-shrunken thumbnail.
- **Idempotent.** A redelivered message or a retry after a partial run returns
  early on `ready`, so bytes are never double-counted.
- **Dispatched after the transaction commits.** A worker can otherwise pick the
  job up before the commit lands and find no row.
- **`failed()` marks the row `failed`.** `processing` reads as "nearly there" in
  every list view, so a permanent one is a file the user waits on for ever.
- **A missing source throws; a deleted row does not.** Someone changing their
  mind between upload and processing is ordinary, not an error.

## 3. Serving them

Generating variants nothing asks for would repeat the same mistake, so the
signed URL carries a variant name:

- Agency library grid → `thumb`. It was streaming full-size originals into 320px
  tiles, one per image on the page.
- Portal post view → `preview`. The client is judging the creative, so 1080px is
  the smallest size honest about what they are approving — but not the original,
  which was being streamed to clients on every post view.

`ResolveMediaVariant` is shared by both surfaces so they cannot drift.

**The name is a lookup key, never a path.** It indexes the `variants` array the
job recorded on the row. A name that is not a key simply misses and falls back to
the original, so there is no filesystem to escape from — and the name is inside
the signature, so it cannot be swapped after the URL is minted. Both properties
are tested, including a traversal attempt.

Falling back rather than erroring matters for real data: every image uploaded
before this shipped has no variants, and the grid still has to render it.

## 4. Storage accounting

Variants are real files on the same disk. `media.size_bytes` is the uploaded
file's own size — shown to users, compared against the per-upload limit — so
folding derivatives into it would make both of those wrong.

New column `variant_bytes`, summed alongside `size_bytes` in the storage
entitlement. Without it a tenant sitting on their quota would keep writing two
extra files per image that nothing counted.

## 5. Existing rows

`php artisan media:regenerate-variants` queues images stuck without variants.
Needed because every image uploaded before the job existed is in `processing`,
and nothing re-examines an old row.

`--failed` opts previously-failed rows back in. Not the default: a permanently
corrupt upload fails on every pass, and sweeping them in by default would
re-queue the same doomed files for ever.

## 6. A guard that was not guarding

`BelongsToTenant::acrossTenants()` says in its own docblock that "an
architecture test enforces" the bypass is confined to allow-listed namespaces.

**No such test existed.** The list in `config/tenancy.php` recorded intent, not
fact — any file anywhere could bypass tenant scoping and nothing objected.

Writing `ScopeBypassTest` found five namespaces already bypassing without being
listed: the AI autopilot and reservation sweeper, the subscription lifecycle
service, and the two publishing services that claim and release post targets.

All five are legitimate — scheduled sweepers and queue workers, cross-tenant by
definition, with no request to resolve a tenant from. They are now recorded as
what they are. The point is that this was established by a test rather than
assumed.

The scan tokenises rather than greps: a docblock reading "prefer
`Model::acrossTenants()`" is advice, not a bypass, and counting it would grow the
allow-list to cover files that call nothing.

## 7. Verified

- `tests/Feature/Media/MediaVariantsTest.php` — the job
- `tests/Feature/Media/MediaVariantServingTest.php` — variant delivery and its
  signature/traversal properties
- `tests/Feature/Tenancy/ScopeBypassTest.php` — the missing architecture guard

Test images are drawn with GD in the test rather than via
`UploadedFile::fake()`: that writes to the system temp directory and the file is
gone by the time the job reads it, which fails as "no such file" and reads like a
bug in the job.

## 8. Not done

- **Video thumbnails.** Would need ffmpeg, which is a deployment dependency
  rather than a PHP extension. Video and PDF tiles show their type instead.
- **Serving variants directly from object storage.** Bytes pass through PHP on
  every request. On S3 this is worth revisiting so large video is served by the
  object store — the same trade-off `SignedMediaUrl` already documents.
- **Purging variants on media delete.** `purge_after` is still consumed by
  nothing; when that job is written it must delete `variants` as well as `path`.
