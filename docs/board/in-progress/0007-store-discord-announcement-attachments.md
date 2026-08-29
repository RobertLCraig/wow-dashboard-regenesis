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
- [ ] #1 WHEN an announcement carries attachments, THE IMPORTER SHALL store their URLs and metadata
      on the `discord_announcements` row.
- [ ] #2 WHEN an announcement has attachments and no text, THE IMPORTER SHALL keep it rather than
      skipping it.
- [ ] #3 WHEN the feed renders such an announcement, THE APP SHALL show the images as thumbnails.
<!-- AC:END -->

## Tasks
- [ ] Add a JSON attachments column to `discord_announcements`
- [ ] Stop skipping text-empty posts that carry attachments
- [ ] Render thumbnails in the Latest from Discord feed

## Plan
Discord attachment URLs expire on some CDN routes. Store enough metadata to re-fetch by message id
rather than treating the URL as durable, or the feed works for a fortnight and then shows broken
images.
