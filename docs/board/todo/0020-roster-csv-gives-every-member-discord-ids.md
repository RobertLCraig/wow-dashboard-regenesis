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
