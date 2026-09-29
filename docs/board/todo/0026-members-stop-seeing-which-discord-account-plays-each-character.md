# Members stop seeing which Discord account plays each character

## Why
Any guild member can download `/roster.csv` and get a file that pairs every character with the
Discord account behind it (`discord_user_id`, `discord_username`) and the time it was last online
(`last_online_at`). An ordinary member has no use for that mapping. It is the kind of list that
makes it easy to follow someone across servers, or to see which alts a person plays.

It happened because card `0005` opened the Roster page and its CSV to the `member` tier. The page
hid the officer controls, but `RosterController::csv()` writes the same columns for everybody.

**The page shows the same mapping.** Checked on 2026-09-29: `resources/views/dashboard/roster.blade.php`
renders the Discord column for every row. `@can('roster.kick')` wraps only the edit button, and its
`@else` branch prints the same username or id as plain text. So hiding the CSV columns alone hides
nothing: the mapping is one click away on the page the CSV is downloaded from.

## Links

**Relates to**
- `0020` - the decision this card carries out. Option 1 was chosen on 2026-09-29: drop the three
  columns from the CSV for anyone below `raid_leader`, officers keep the full export. Its reason
  was that a member has no use for the Discord mapping. That reason covers the page's Discord
  column as well, so this card hides it there too.
- `0005` - opened the Roster and its CSV to members.

## Not this card
Not the page's "Last seen" column. The decision named `last_online_at` for the CSV only, and the
page's inactivity filter chips show much the same fact. Whether a member may see who has been
online is a separate question. Not the officer view, which keeps every column and the edit button.
Not any other page's Discord display.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a `member` downloads `/roster.csv`, THE APP SHALL leave `discord_user_id`, `discord_username` and `last_online_at` out of the header and every row, and keep the other columns in their current order. proves: `it leaves the Discord and last-online columns out of a member's roster CSV`
- [ ] #2 WHEN a `raid_leader` or any higher tier downloads `/roster.csv`, THE APP SHALL include all three columns. proves: `it gives a raid leader the full roster CSV`
- [ ] #3 WHEN a `member` opens the Roster page, THE APP SHALL show no Discord column and no Discord username or id anywhere in the page. proves: `it shows a member no Discord accounts on the Roster`
- [ ] #4 WHEN a `raid_leader` or any higher tier opens the Roster page, THE APP SHALL still show the Discord column with its edit button. proves: `it still shows a raid leader the Discord column`
<!-- AC:END -->

## Tasks
- [ ] `RosterController::csv()`: build the header and each row from one column list, and drop the three columns when the user fails `isAtLeast(User::TIER_RAID_LEADER)`
- [ ] `resources/views/dashboard/roster.blade.php`: render the Discord header cell, its sort key, its help text and its body cell only for `raid_leader` and above
- [ ] Pest tests named in Acceptance, in `tests/Feature/RosterTest.php` or `tests/Feature/MemberTierTest.php` beside `it('lets a member reach the Roster and its CSV')`

## Plan
Stand in `C:\Dev\Regenesis`, on `main`, and run `php artisan test` from the PowerShell tool first.
`tierUser()` in `tests/Feature/MemberTierTest.php` builds a user of any tier, and
`it('CSV export streams the filtered set with header row')` in `tests/Feature/RosterTest.php` shows
how to read a streamed CSV body.

Gate on `User::isAtLeast(User::TIER_RAID_LEADER)`, which is how the decision was worded. Do not
reuse `roster.kick` for this: that gate is about who may edit, not who may see.

Keep one column list for the CSV header and row and filter both from it. Two lists that must be
trimmed in step is how a header ends up one column out from its data.
