# Where does the weekly digest post, and should it go in a thread?

## What I need from you
1. Open <https://regenesis.enhanceify.co.uk/admin/webhooks> and say what the **Weekly digest** rows show: none, or each row's label and the channel it posts to. If any row points somewhere other than officer-text, untick its **Enabled** box before Sunday 09:00 and say so.
   Pass: "none", or a label and channel per row. Fail: "looks fine" with no rows named.
2. In officer-text, make one thread (for example "Weekly digest") and give its ID. To get the ID, turn on Discord Developer Mode (User Settings, Advanced), right-click the thread, and pick Copy Thread ID.
   Pass: a number about 18 to 20 digits long. Fail: a thread name or a link with no number.

Paste to answer: `**2026-10-13** Weekly digest rows: <none / list>. Thread id <id>. DIGEST_DISCORD_WEBHOOK_URL: <unset / channel / don't know>.`

## What you need to know
- You chose option 1, a thread, on 2026-10-11.
- The digest posts every Sunday at 09:00 UK time to every enabled Weekly digest row, so any row there is posting now. It includes parse rankings, which card 0010 said to keep out of guild-wide channels.
- If there are no rows, it falls back to `DIGEST_DISCORD_WEBHOOK_URL` in the live site's settings. Say which channel that points at if you know.
- Thread support (card 0030) is built but not live yet. Card 0036 deploys it. You can do both steps above now; saving the thread row waits on that deploy.

## See it
- <https://regenesis.enhanceify.co.uk/admin/webhooks>

---
## For the agent (Rob can stop reading here)
Steps after the answer, all live changes and so Rob's: (1) officer-text, Channel settings, Integrations, Webhooks, New Webhook, and copy its URL; (2) make the thread and copy its ID; (3) deploy card 0030 to the live site if it is not there; (4) on `/admin/webhooks` add a row, purpose Weekly digest, URL = webhook URL + `?thread_id=<id>`, press **Test**. Pass: the test ping shows inside the thread.

Related: 0036 (deploys main, which takes 0030 live; step (3) above is that card); 0010 (decided the digest goes to the officer channel and suggested a thread; this settles that); 0030 (webhook form accepts a thread, done); 0031 (quiet-week and broken-sync guard, done).

## Comments


**2026-10-11** **Decided:** **2026-10-11** **Decided:** Option 1: a thread. Needs a deploy of the thread support first.

**2026-10-13** Ask cut to what Rob still owes: the /admin/webhooks rows and the thread ID. Thread support (0030) is built and on origin/main but NOT deployed: over SSH the live checkout is at `500d88a` (2026-08-19, 219 commits behind) and its webhook controller has no `thread_id`. Raised 0036 in todo/ to deploy main; it is not_for_the_loop, so an attended session runs it. (agent, after Rob's 2026-10-13 clear-down)
