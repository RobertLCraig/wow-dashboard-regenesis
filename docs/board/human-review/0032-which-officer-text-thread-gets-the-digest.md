# Which officer-text thread gets the weekly digest, and where it posts today

## What I need from you

**Two answers.**

1. Thread or channel? Pick **1** or **2** under Options. If 1, give the thread's id.
2. Open https://regenesis.enhanceify.co.uk/admin/webhooks and tell me what the **Weekly digest**
   rows say: none, or the label of each one and which channel it posts to.

---

**On 1.** Pass is a number, and a thread id if it is 1. To get the id, turn on Developer Mode in
Discord (User Settings, Advanced), then right-click the thread and pick Copy Thread ID.

**On 2.** This one matters more than it looks. The digest is **already scheduled**. It runs every
Sunday at 09:00 UK and posts to every enabled Weekly digest row on that page. Card `0010` was
written as if an officer had to trigger it, and that was wrong. So if a row is there now, the
digest is already posting there every week. If that row points at a guild-wide channel, parse
rankings are already going where everyone sees them, which is the drift `0010` warned against.

Pass is either of:
- no Weekly digest row, or
- every row points at officer-text.

Fail is a row pointing anywhere else. Untick its Enabled box on that page, today, before Sunday,
and say so here.

If there are no rows, one older setting can still post: `DIGEST_DISCORD_WEBHOOK_URL` in the live
`.env`. The digest falls back to it when the page has no Weekly digest row. If you know it is set,
say which channel it points at.

**Why it needs you.** Only you can see the live page and the guild's Discord. Nothing in the
repository holds either.

## Why
`0010` decided the digest goes to the officer channel on a schedule, and added "maybe we can post
to a thread on the officer-text channel". The "maybe" is the open part. A thread keeps a weekly
post out of officer chat. It also means somebody creates the thread once, because a webhook
cannot create a thread in an ordinary text channel.

## Links

**Relates to**
- `0010` - its answer suggested a thread in officer-text, and this card settles that suggestion.
- `0030` - teaches the webhook form to accept a thread. Option 1 needs it deployed first.
- `0031` - the quiet-week and broken-sync guard. It is worth having live before the first
  unattended post either way.

## Options
1. **One standing thread in officer-text, for example "Weekly digest".** Every Sunday's digest
   lands in it, one under the other. Cost: you create the thread once and copy its id. Discord
   archives a quiet thread, but a webhook post into it unarchives it, so it does not die. Needs
   `0030` built and on the live site before the row can be saved.
2. **Straight into officer-text, no thread.** Works today, with no code. Cost: a long message
   lands in officer chat every Sunday morning and pushes the conversation up.

## Recommendation
Option 1. It is what `0010` leaned towards, the cost is one thread made once, and moving to
option 2 later is deleting `?thread_id=...` from one URL.

After you answer, these are the steps, and all of them are yours, because each one is a live
change:
1. In officer-text: Channel settings, Integrations, Webhooks, New Webhook. Copy its URL.
2. Make the thread, and copy its id as above.
3. Once `0030` is merged, deploy it to the live site.
4. On `/admin/webhooks`, add a row: purpose Weekly digest, URL is the webhook URL followed by
   `?thread_id=` and the id. Press **Test**. Pass is the test ping showing up inside the thread.

Paste this into `## Comments`, with your numbers:

    **2026-10-04** **Decided:** Option 1, thread id <paste id>. Weekly digest rows on /admin/webhooks today: <none / list them>. DIGEST_DISCORD_WEBHOOK_URL: <unset / channel / don't know>.

## Comments
