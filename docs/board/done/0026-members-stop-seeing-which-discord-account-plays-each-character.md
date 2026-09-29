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
- [x] #1 WHEN a `member` downloads `/roster.csv`, THE APP SHALL leave `discord_user_id`, `discord_username` and `last_online_at` out of the header and every row, and keep the other columns in their current order. proves: `it leaves the Discord and last-online columns out of a member's roster CSV`
- [x] #2 WHEN a `raid_leader` or any higher tier downloads `/roster.csv`, THE APP SHALL include all three columns. proves: `it gives a raid leader the full roster CSV`
- [x] #3 WHEN a `member` opens the Roster page, THE APP SHALL show no Discord column and no Discord username or id anywhere in the page. proves: `it shows a member no Discord accounts on the Roster`
- [x] #4 WHEN a `raid_leader` or any higher tier opens the Roster page, THE APP SHALL still show the Discord column with its edit button. proves: `it still shows a raid leader the Discord column`
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

## Comments

**2026-09-29** RESULT: done
TESTS: +4 new, all green
TOUCHED: app/Http/Controllers/Dashboard/RosterController.php, resources/views/dashboard/roster.blade.php, tests/Feature/RosterTest.php, docs/HANDOVER.md, docs/board/in-progress/0026-members-stop-seeing-which-discord-account-plays-each-character.md
OUT-OF-SCOPE: none

`csv()` now builds header and row from one `name => closure` list and unsets the three columns
below `raid_leader`. The page wraps the Discord header, help text and body cell in `$seesDiscord`
(an `isAtLeast(TIER_RAID_LEADER)` check, not `roster.kick`), and the explainer/empty-row `colspan`
drops by one when the column is hidden.

Seen red first: #1 (header still carried the three columns) and #3 (page printed `secretplayer`).
#2 and #4 were green before the change, because they pin behaviour officers already had; they are
regression guards against the new gate reaching too far, and could not be watched failing without
breaking the code on purpose. #4 checks the edit button through the `open-discord-link` dispatch.

Browser check still owed: Herd serves `C:\Dev\Regenesis`, not this worktree.

### 2026-09-29 review (v20260929022746-c62a)

**suite**

`vendor\bin\pest.bat` exited 0 after 74s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break each of the four criteria. I could not break any of them.

- **#1 (a member's CSV):** `RosterController::csv()` builds the header and each row from one `$columns` list. For a user below `raid_leader`, it removes `last_online_at`, `discord_user_id` and `discord_username` from that list. The other columns keep their order. The test checks the exact header, the width of each row, and that the Discord id is not in the row.
- **#2 (a raid leader's CSV):** The code removes the columns only when `isAtLeast(TIER_RAID_LEADER)` fails. So officers still get all three.
- **#3 (a member's Roster page):** In `resources/views/dashboard/roster.blade.php`, `$seesDiscord` hides four things: the header cell, its sort key, its help text and the body cell. I searched the whole view for the word "discord". I found no other place that prints a Discord name or id. The link modal (`member-discord-link-modal`) gets its data only from the edit button. That button is inside the hidden cell, so a member never fills the modal. No included widget shows Discord data.
- **#4 (a raid leader's Roster page):** For a raid leader, the cell still shows. The edit button is still inside `@can('roster.kick')`, as before.

I found nothing that disproves a criterion.

VERDICT: sound

**scope: sound**

I checked the scope of card 0026. I found no scope problem.

- **Commit `45d3b93` changes only this card's work.** It changes five files: `RosterController.php`, `roster.blade.php`, `RosterTest.php`, `docs/HANDOVER.md` and the card. The card's TOUCHED list names the same five files.
- **The large diff comes from other cards.** The BiS source work (card 0025), the migration and cards 0027 to 0029 are not in this commit.
- **The fence holds.**
  - `RosterController::csv()` removes only `last_online_at`, `discord_user_id` and `discord_username`.
  - The page still shows the "Last seen" cell to everyone, as the card says it must.
  - The officer view keeps the Discord header, the help text and the body cell with the `open-discord-link` edit button.
  - No other page's Discord display changed.
- **Nothing is half done.** Every Discord output in `roster.blade.php` is inside `@if ($seesDiscord)`. The one exception is `<x-member-discord-link-modal />`, and it holds no member data.
- The `colspan` change keeps the table lined up when the column is hidden.
- **Owed:** a browser check. That is not an acceptance criterion.

My findings disprove no criterion.

VERDICT: sound

**breakage: sound**

I tried to break card 0026 and I could not. The Discord link and last-online time no longer reach members.

- **The CSV:** `RosterController::csv()` builds the header and every row from one column list. The code removes the three columns from that one list for anyone below raid leader. So the header and the data cannot get out of step.
- **The page:** In `roster.blade.php`, all the Discord parts are inside `@if ($seesDiscord)`. That covers the header cell, the sort key, the help text, and the body cell with its `open-discord-link` data. I found no other Discord output on the page.
- **Search:** The search runs in the browser and reads the row text. A member's rows no longer hold the Discord cell. So a member cannot search by a Discord name. The help text that says Discord names are searchable is hidden with the column, so no text on the page is now wrong.
- **The pop-up:** `member-discord-link-modal` only gets data when someone clicks the edit button. Members do not get that button, so the pop-up holds no member data.
- **Column count:** The `colspan` (how many columns the help row spans) now subtracts one when the column is hidden. The edit-column count stays as it was.

None of the four checks fails.

VERDICT: sound

