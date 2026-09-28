# Store Discord announcement attachments

## Why
The announcements importer drops any post whose content is only an image or only a sticker, because
attachments are not kept. Those are exactly the posts an announcements feed most wants: a raid
roster screenshot, a poster, a boss-kill shot. The feed silently shows fewer announcements than the
channel has, and nothing says so.

## Not this card
Attachments on anything other than `discord_announcements`. Moderation of what comes in, which this
project does not do.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN an announcement carries attachments, THE IMPORTER SHALL store their URLs and metadata
      on the `discord_announcements` row.
- [x] #2 WHEN an announcement has attachments and no text, THE IMPORTER SHALL keep it rather than
      skipping it.
- [x] #3 WHEN the feed renders such an announcement, THE APP SHALL show the images as thumbnails.
<!-- AC:END -->

## Tasks
- [x] Add a JSON attachments column to `discord_announcements`
- [x] Stop skipping text-empty posts that carry attachments
- [x] Render thumbnails in the Latest from Discord feed

## Plan
Discord attachment URLs expire on some CDN routes. Store enough metadata to re-fetch by message id
rather than treating the URL as durable, or the feed works for a fortnight and then shows broken
images.

## Comments

**2026-08-29** Added a nullable `attachments` JSON column to `discord_announcements` and an
`array` cast on the model. The importer now narrows each Discord attachment to id, filename,
content_type, size, width, height, url and proxy_url, and only skips a message when it has neither
text nor attachments. `DiscordAnnouncement::imageAttachments()` filters to entries Discord typed
`image/*`, and the Latest from Discord feed draws those as thumbnails linking to the full-size
original; the text paragraph is suppressed on an attachment-only post so it does not render an
empty line.

On the Plan's expiry worry: the durable identifiers - the message id, channel id and each
attachment id - are all stored, so a re-fetch by message id is open. I did not build one. The
hourly `discord:fetch-announcements` pull already re-upserts every message in the last 50 and so
refreshes the signed URLs well inside their expiry, and the feed only shows the 10 most recent
posts in a low-traffic channel. The ceiling is marked with a `ponytail:` comment on
`DiscordAnnouncementsImporter::attachments()`: if the channel ever exceeds 50 posts a day, a
still-displayed post could fall out of the refresh window and its thumbnail would break. The
upgrade path is re-fetching the message by id on render.

Two things I could not settle from the repository. First, stickers: the Why names sticker-only
posts as a drop case, but Discord carries those in `sticker_items`, not `attachments`, and the
acceptance only covers attachments, so a sticker-only post is still skipped. That needs its own
card if it matters. Second, this was built in a worktree, so the thumbnails have not been seen in
a browser against real Discord CDN URLs - the tests cover the stored shape and the rendered
markup only. Worth one look at /dashboard/social after merge.

727 tests pass. Pint reformats about 180 pre-existing files repo-wide, which is unrelated churn,
so I reverted all of it and kept only this card's files; `pint --test` passes on those.

### 2026-08-29 review (v20260829191734-5abe)

**suite**

`vendor\bin\pest.bat` exited 0 after 70s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 7:40pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 7:40pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 7:40pm (Europe/London)


**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: sent to `ai-review/`, because the finding does not hold. There is
no finding: the 2026-08-29 review job hit a session limit before any lens ran, so all three lenses
say only "You've hit your session limit" and VERDICT is missing from each. The suite exited 0 in that
run. The loop read that as a bounce and parked the card. It has still never been reviewed, and a
review is what it needs.

### 2026-09-28 review (v20260928190712-1ae0)

**suite**

`vendor\bin\pest.bat` exited 0 after 71s, run by this job rather than reported by the card.

**acceptance: sound**

I checked each of the three acceptance criteria against the code. All three are met.

**#1 Store URLs and metadata.** Met.
- A migration adds the column: `2026_05_02_120000_add_attachments_to_discord_announcements_table.php`.
- `DiscordAnnouncement` casts `attachments` to an array, and the column is in `$fillable`.
- `DiscordAnnouncementsImporter::attachments()` keeps these fields: id, filename, content_type, size, width, height, url and proxy_url.
- `DiscordAnnouncementsImporter::pull()` writes those fields to the row with `updateOrCreate`.

**#2 Keep a post that has attachments and no text.** Met.
- `DiscordAnnouncementsImporter::pull()` skips a post only when `trim($content) === ''` and `$attachments === []` are both true.
- The feed query in `SocialController` filters only on `posted_at`. It does not remove posts that have empty text.

**#3 Show images as thumbnails.** Met.
- `DiscordAnnouncement::imageAttachments()` keeps only items with an `image/*` type.
- `resources/views/dashboard/social.blade.php` shows each image as an `<img>` with class `h-24`, inside a link to the full-size image.
- The view hides the text paragraph when the text is empty.

The card says that stickers stay out of scope, and that the URLs can expire. The acceptance criteria do not include either one, so they do not disprove a criterion.

VERDICT: sound

**scope: sound**

I found no scope defect. The card's own commit is `fd98cc0`. It changes only 8 files, and all of them belong to this card:

- the migration
- `DiscordAnnouncement::imageAttachments()`
- `DiscordAnnouncementsImporter::attachments()` and the rule that decides which posts to skip
- the thumbnails in `social.blade.php`
- two test files
- the card and `next-session.md`

The 224-file diff you were given is too big because its start point is wrong. It includes work from other cards: the member tier (`RequireTier`, `User`), Pint reformatting, the digest DB-size check, equipment dedup, and the holiday calendar. None of that is in `fd98cc0`, so this card did not grow past its fence. The review script should compare against the parent of `fd98cc0`.

Two things are not finished, but neither breaks a criterion:
- **Stickers.** The Why names sticker-only posts, but the acceptance only covers attachments. Discord keeps stickers in `sticker_items`, so those posts are still skipped. The builder said so. They need their own card.
- **Re-fetch.** The Plan asks to store enough to re-fetch a post by message id. The ids are stored. The re-fetch code is not built, and a `ponytail:` comment on `DiscordAnnouncementsImporter::attachments()` marks that limit.

VERDICT: sound

**breakage: sound**

I tried to break this card and could not. No acceptance criterion fails.

**What I checked**

- **Importer** (`DiscordAnnouncementsImporter::pull`): it skips a message only when it has no text and no attachments. It saves attachments on a new row and on an updated row. When it finds no attachments, it writes `null`. That matches the nullable column and the `imageAttachments()` fallback `?? []`.
- **Callers**: only one place reads `DiscordAnnouncement`, and that is `SocialController::index`. It passes the rows straight to `social.blade.php`. No other feed or digest reads the empty `content`, so nothing else breaks.
- **Migration**: it adds a nullable JSON column. Its `down()` drops that column. Both are correct.
- **Docblocks**: the comments on the importer, the model and the migration still describe the code correctly.

**One small edge case (not a defect)**

A post that has only a file that is not an image (a PDF or a video) is now kept. The feed shows it as the author name and the "open in Discord" link, with no body. The builder's note about removing the empty line is true only for image posts. No criterion covers files that are not images. The link still takes the reader to the post, so nothing is lost.

**Things the builder already told you**

- Discord links expire. The hourly pull refreshes them, and the `ponytail:` comment names that limit.
- Posts that have only a sticker are still skipped. That needs its own card.

VERDICT: sound

