# Where does the weekly digest post, and should it go in a thread?

## What I need from you
1. Open <https://regenesis.enhanceify.co.uk/admin/webhooks> and tell me what the **Weekly digest** rows say: none, or each row's label and the channel it posts to. If any row points somewhere other than officer-text, untick its **Enabled** box before Sunday 09:00 and say so.
2. Thread or channel? Reply 1 or 2 (below). If 1, give the thread's ID. To get it, turn on Discord Developer Mode (User Settings, Advanced), right-click the thread, and pick Copy Thread ID.
   - **1. One standing thread in officer-text**, for example "Weekly digest". Every Sunday's post lands there, out of officer chat. You make the thread once.
   - **2. Straight into officer-text.** No setup. A long post lands in officer chat every Sunday morning.

**My recommendation:** check 1 today. For 2, choose option 1. It costs one thread, made once, and you can switch to option 2 later by deleting `?thread_id=...` from one URL.
Paste to answer: `**2026-10-07** **Decided:** Option 1, thread id <id>. Weekly digest rows on /admin/webhooks today: <none / list>. DIGEST_DISCORD_WEBHOOK_URL: <unset / channel / don't know>.`

## What you need to know
- The digest is already scheduled. It posts every Sunday at 09:00 UK time to every enabled Weekly digest row, so any row there is posting now.
- It includes parse rankings (players' raid performance scores). In a guild-wide channel everyone sees them, which card 0010 said to avoid.
- If there are no rows, it falls back to `DIGEST_DISCORD_WEBHOOK_URL` in the live site's settings. Say which channel that points at if you know.
- Only you can see the live admin page and the guild Discord.
- Thread support (card 0030) is built. It must be deployed to the live site before option 1's row can be saved.

## See it
- <https://regenesis.enhanceify.co.uk/admin/webhooks>

---
## For the agent (Rob can stop reading here)
Steps after the answer, all live changes and so Rob's: (1) officer-text, Channel settings, Integrations, Webhooks, New Webhook, and copy its URL; (2) make the thread and copy its ID; (3) deploy card 0030 to the live site if it is not there; (4) on `/admin/webhooks` add a row, purpose Weekly digest, URL = webhook URL + `?thread_id=<id>`, press **Test**. Pass: the test ping shows inside the thread.

Related: 0010 (decided the digest goes to the officer channel and suggested a thread; this settles that); 0030 (webhook form accepts a thread, done); 0031 (quiet-week and broken-sync guard, done).

## Comments


**2026-10-11** **Decided:** **2026-10-11** **Decided:** Option 1: a thread. Needs a deploy of the thread support first.
