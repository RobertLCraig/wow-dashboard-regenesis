# A Discord webhook can target a thread in its channel

## Why
The weekly digest is to go to a thread inside the officer-text channel, not into the channel
itself. Today the dashboard cannot do that. The `/admin/webhooks` form only accepts a bare webhook
URL, and its pattern rejects anything after the token, so there is nowhere to say which thread.

Without it, the digest either goes straight into officer-text, where it competes with officer
chat every Sunday, or it does not go at all.

Discord itself already supports this. Its "execute webhook" call takes a `thread_id` query
parameter that sends the message into that thread of the webhook's channel, and unarchives the
thread if it has gone quiet. Nobody needed it until now.

## Links

**Relates to**
- `0010` - decided the digest auto-posts to the officer channel, and suggested a thread in
  officer-text as the place.

## Not this card
- Creating the thread, the webhook or the `/admin/webhooks` row on the live site. A person does
  that, and the decision card raised beside this one asks for it.
- Creating threads from the app. Discord only lets a webhook create a thread in a forum channel,
  and officer-text is a text channel.
- What the digest says on an empty week. That is its own card, also raised from `0010`.
- Any purpose other than how the URL is stored and validated. Every sender reads through
  `WebhookRouter`, so all of them gain thread support for free, and none needs changing.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN an officer saves a webhook URL ending in `?thread_id=` and a numeric id, THE APP SHALL accept and store it. proves: `it accepts a webhook url that names a thread`
- [x] #2 WHEN an officer saves a webhook URL with any other query string, THE APP SHALL reject it with a validation error. proves: `it rejects a webhook url with any other query string`
- [x] #3 WHEN `digest:weekly` runs against a webhook stored with a thread id, THE APP SHALL make its request to that URL with the `thread_id` query parameter intact. proves: `digest:weekly posts into the thread a webhook names`
<!-- AC:END -->

## Tasks
- [ ] Widen the URL rule in `DiscordWebhookController::validatePayload()` to allow an optional `?thread_id=<digits>`.
- [ ] Add the three tests named above.
- [ ] Add one line of help text to the URL field on `resources/views/admin/webhooks/index.blade.php` saying how to target a thread.

## Plan
Stand in the repository root on the card branch. Run `.\vendor\bin\pest.bat` once first, so you
know the suite is green before you touch it.

1. **The rule.** In `app/Http/Controllers/Admin/DiscordWebhookController.php`, the `url` regex
   in `validatePayload()` ends at the token with `$`. Add an optional `(\?thread_id=[0-9]+)?`
   before the `$`. Allow nothing else after the token. The URL is a secret and is encrypted at
   rest by `DiscordWebhook::url()`, so a loose rule here is a loose rule on a secret.
2. **The send path.** `DiscordWebhookPoster::post()` hands the URL straight to Laravel's
   `Http::post()`, which keeps a query string. So the expected change there is none. Confirm it
   with test #3, using `Http::fake()` and `Http::assertSent()` on the request URL. Do not hit
   Discord.
3. **Where the tests go.** #1 and #2 beside the existing form tests in
   `tests/Feature/DiscordWebhookAdminTest.php`. #3 beside the `digest:weekly` tests in
   `tests/Feature/WeeklyDigestTest.php`. Copy the setup of the nearest existing test in each
   file, including how it logs in an officer.
4. **The help text.** One sentence under the URL field: "To post into a thread, add
   `?thread_id=` and the thread's id. Right-click the thread in Discord, Copy Thread ID." The
   copy menu item needs Discord's Developer Mode on. Say so in the same sentence.

Why the URL and not a new column: it is the format Discord documents, it needs no migration,
and it is a one-line change. If the builder finds a reason a separate column is needed, say why
on this card before building it.

"It worked" is the three named tests passing, and `.\vendor\bin\pint.bat --test` exiting 0.

Discord's reference for the parameter is the `thread_id` query string on "Execute Webhook":
https://discord.com/developers/docs/resources/webhook#execute-webhook

## Comments

**2026-10-04** RESULT: done
TESTS: +3 new (one of them a 5-row dataset, so 7 cases), all green; suite 780 passed; pint --test exit 0
TOUCHED: app/Http/Controllers/Admin/DiscordWebhookController.php
TOUCHED: resources/views/admin/webhooks/index.blade.php
TOUCHED: tests/Feature/DiscordWebhookAdminTest.php
TOUCHED: tests/Feature/WeeklyDigestTest.php
OUT-OF-SCOPE: none

The `url` regex in `validatePayload()` now ends `(\?thread_id=[0-9]+)?$`. Nothing else may follow the token. One help line under the URL field says how to get the thread id, and that Discord's Developer Mode must be on. No change to `DiscordWebhookPoster`: `Http::post()` keeps the query string, as the Plan said.

How each test was watched failing:
- #1 `it accepts a webhook url that names a thread` failed first with "The url field format is invalid." (the old rule), then went green with the new rule.
- #2 `it rejects a webhook url with any other query string` was green first, because the old rule rejected every query. To watch it catch the fault, I loosened the rule on purpose to `(\?.*)?$`. All 5 rows went red (`?wait=true`, `?thread_id=abc`, `?thread_id=`, `?thread_id=123&wait=true`, a bare `?`). Then I set the tight rule.
- #3 `digest:weekly posts into the thread a webhook names` was green first, because the send path needed no change. To watch it catch the fault, I made the poster strip the query string with `strtok($url, '?')`. It went red. Then I reverted that, and `git diff` shows no change to the poster.

The edit form has no URL field (update keeps the old URL when it is blank), so the help text is only on the add form.

A browser check is still owed. Herd serves C:\Dev\Regenesis, not this worktree, so nobody has looked at the help line on the page.

### 2026-10-04 review (v20261004191413-5aea)

**suite**

`vendor\bin\pest.bat` exited 0 after 117s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break each criterion. I could not break any of them.

**#1: a thread URL is accepted and stored.**
- **Code:** `DiscordWebhookController::validatePayload()`. The `url` regex now ends with `(\?thread_id=[0-9]+)?$`, so it allows `?thread_id=` with digits.
- **Test:** `it accepts a webhook url that names a thread`. It posts the form and checks that the stored `url` matches the input exactly.

**#2: any other query string is rejected.**
- **Code:** the same regex. The `$` comes straight after the optional `?thread_id=<digits>`, so nothing else can follow the token.
- **Test:** five bad URLs, each rejected, with no row saved: `?wait=true`, `?thread_id=abc`, `?thread_id=`, `?thread_id=123&wait=true` and a bare `?`.

**#3: the digest posts with `thread_id` intact.**
- **Code:** `DiscordWebhookPoster::post()` sends `$this->webhookUrl` to `Http::post()` as it is. Nothing adds to the URL or cuts it.
- **Test:** `digest:weekly posts into the thread a webhook names`. It checks the exact URL that was sent, query string included.

The full test suite passed (exit 0).

VERDICT: sound

**scope: sound**

I checked what this build changed against what the card asked for.

**The build stayed inside the fence.** The card's own commits are `ec7f49f`, `06b4585` and `c322904`. They change only these files:
- `DiscordWebhookController::validatePayload()`. The regex now allows one optional `?thread_id=<digits>` and nothing else.
- The add form in `resources/views/admin/webhooks/index.blade.php`. It has one help line, and the line names Developer Mode.
- The two test files that the Plan named.
- The card itself.

**The extra files in the diff came from other work.** The moves of `0010` and `0011`, and the new cards `0033`, `0034` and `0035`, are in commits `b8b6263` and `374818d`. Those commits came before this build. They are not scope creep by this card.

**No fence was crossed.**
- `DiscordWebhookPoster` did not change.
- No thread is made from the app.
- No new column or migration was added.
- The empty-week text was not touched.
- No other webhook purpose was touched.

**Nothing is half done.**
- The three named tests exist. Each one tests what its criterion says.
- The edit form has no URL field, so the help line is only on the add form. That is correct, not a gap.
- The browser check that is still owed is a manual follow-up. It is not an acceptance criterion.
- The boxes under `## Tasks` are still unticked. That is only bookkeeping. The work for each task is in the diff.

I did not disprove any criterion.

VERDICT: sound

**breakage: sound**

I tried to break this card. I could not.

**What I checked:**
- Only one place in `app/` checks a webhook URL: the `url` rule in `DiscordWebhookController::validatePayload()`. Create and update both use it, so the new rule applies to both.
- No sender adds its own query string to the URL, such as `?wait=`. So no code builds a bad URL like `...?thread_id=1?wait=true`.
- The `DiscordWebhook` model does not mask or cut the URL. The full URL, with its thread id, is stored and read back unchanged. Test #1 proves this.
- `DiscordWebhookPoster::post()` did not change. Test #3 checks that the request goes to the exact URL, with `thread_id` still in it.
- Test #2 rejects five bad query strings, and the builder showed that each one fails under a loose rule.
- The edit form has no URL field, so the help text only needs to be on the add form.
- No docblock or comment says "no query string", so the change made no comment false.

**Not a defect:** nobody has looked at the help line in a browser yet. This card does not ask for that check.

VERDICT: sound

