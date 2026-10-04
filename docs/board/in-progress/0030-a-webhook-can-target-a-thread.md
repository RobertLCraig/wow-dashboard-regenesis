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
- [ ] #1 WHEN an officer saves a webhook URL ending in `?thread_id=` and a numeric id, THE APP SHALL accept and store it. proves: `it accepts a webhook url that names a thread`
- [ ] #2 WHEN an officer saves a webhook URL with any other query string, THE APP SHALL reject it with a validation error. proves: `it rejects a webhook url with any other query string`
- [ ] #3 WHEN `digest:weekly` runs against a webhook stored with a thread id, THE APP SHALL make its request to that URL with the `thread_id` query parameter intact. proves: `digest:weekly posts into the thread a webhook names`
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
