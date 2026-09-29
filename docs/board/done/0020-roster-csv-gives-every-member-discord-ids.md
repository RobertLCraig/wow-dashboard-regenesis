# Should the roster CSV give every member the guild's Discord ids?

## What I need from you

1. Pick option 1 or option 2 below.

---

**Why it needs you.** It is a privacy call about your guild's members, and no lookup settles it.

## Why
Card 0005 opened `/roster.csv` to the `member` tier. The page itself hides the officer controls, but
`RosterController::csv()` writes the same columns for everybody, including `discord_user_id`,
`discord_username` and `last_online_at` for every character. So any guild member can download a
file that maps each character to a Discord account and shows when each one was last on.

The 2026-08-29 review of 0005 noted this as "worth a look, not a blocker", and no card carried it.

## Links

**Relates to**
- `0005` - opened the CSV to members.

## Options
1. **Drop those three columns from the CSV for anyone below `raid_leader`.** A small change in
   `RosterController::csv()`. Officers keep the full export.
2. **Leave it as it is.** No work. Every member can see which Discord account plays which character.

## Recommendation
Option 1. Officers use the Discord link to act on the roster; a member has no use for it.

## Comments


**2026-09-28** The loop moved this card from todo/ to human-review/. 3 takes in a row ended with it still in in-progress/, and the last one said: `staying in in-progress: 2 of 2 named test(s) never ran`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-29** **Decided:** Option 1, drop `discord_user_id`, `discord_username` and `last_online_at` from `/roster.csv` for anyone below `raid_leader`; officers keep the full export. Settled by an attended agent under Rob's rule that human-review holds only what he must decide: it is the card's own recommendation and the data-minimising default, since a member has no use for the Discord mapping and officers lose nothing.

**2026-09-29** The loop moved this card from todo/ to human-review/. 3 takes in a row ended with it still in in-progress/, and the last one said: `staying in in-progress: 2 of 2 named test(s) never ran`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-29** Returned to todo/ by an attended session. The park above was not this card's: `Get-NoProgressCounts` in ProgressBoard's `bin/work-card.ps1` read another board's holds for the same card number out of the shared log, so it parked this card with no take of its own since it was sent back. Fixed in ProgressBoard `6d49915`, which reads each log line's board from its run id.

**2026-09-29** Carried forward by an attended session: `0026` Members stop seeing which Discord account plays each character.
