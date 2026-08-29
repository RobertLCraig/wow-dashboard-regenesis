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
