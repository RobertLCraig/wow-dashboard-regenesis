# Grid lane comment claims a long event keeps its lane across weeks, and it does not

## Why
In `SocialController::gridWeeks()` (`app/Http/Controllers/Dashboard/SocialController.php`), the
comment above the lane sort says a long world event "keeps the same lane from one week row to the
next". Lanes are packed per week on the bar clipped to that week, so a bar clipped short in its first
week can land in a different lane in the next. The next person to touch the lanes will trust a
promise the code does not keep. Card 0006's 2026-08-29 review found it.

## Links

**Relates to**
- `0006` - the multi-day bars rewrite that wrote this comment.

## Not this card
Making lanes actually stable across weeks. That is a behaviour change and would be its own card.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a reader opens the lane packing in `gridWeeks()`, THE COMMENT SHALL describe what the code does: lanes are packed per week, longest clipped bar first. proves: none, a comment has no test
<!-- AC:END -->

## Tasks
- [x] Reword the comment above the `usort` in `gridWeeks()`

## Comments

**2026-09-29**
RESULT: done
TESTS: +0 new, all green
TOUCHED: app/Http/Controllers/Dashboard/SocialController.php, docs/board/in-progress/0022-grid-lane-comment-claims-lanes-hold-across-weeks.md
OUT-OF-SCOPE: none

Comment-only change, so no test (proves: none). The comment now says lanes are packed per week,
longest clipped bar first, ties by earlier column then name, and that a long event is not promised
the same lane across week rows. No behaviour changed, so no browser check is owed.

### 2026-09-29 review (v20260929013921-3a64)

**suite**

`vendor\bin\pest.bat` exited 0 after 82s, run by this job rather than reported by the card.

**acceptance: sound**

I found no fault in the one criterion.

**#1 holds. The comment now says what the code does.** I read the comment above the `usort` in `SocialController::gridWeeks()` (`app/Http/Controllers/Dashboard/SocialController.php`) and checked it against the code:

- **Packed per week:** `$occupied` starts empty inside the loop for each week, so each week packs its lanes again from nothing.
- **Longest bar first:** the sort puts `$b['span']` before `$a['span']`, so the longest bar comes first. `span` is the bar after it is cut to fit this week (`$lastCol - $firstCol + 1`).
- **Ties:** the sort then puts the earlier `col` first, and then orders by `name` A to Z. The comment says the same.
- **Lanes:** each bar takes the first free lane, up to `GRID_LANES`. So the first bar in the sort gets the top lane.
- **No promise across weeks:** the old false claim is gone. The new comment says a long event can change lane from one week row to the next.

I tried to break it and could not.

VERDICT: sound

**scope: sound**

**The card's own work stayed in scope.** Commit `0342816` is the only commit for this card. It changes two files:
- `app/Http/Controllers/Dashboard/SocialController.php`: only the comment above the `usort` in `gridWeeks()`.
- The card file itself.

**The code did not change.** The `usort` line and the lane packing are the same as before. So the card did not make lanes stable across weeks, and it was fenced out of doing that.

**The other changes in the big diff are not this card's work.** The tooltip change in `gridWeeks()` belongs to card `0021`. The other files belong to other cards: `WorldEventsCalendar` is `0008` and `0023`, the colours are `0024`, and the equipment count is `0019`. The README and HANDOVER edits come from other passes. None of them come from commit `0342816`.

**Nothing is left half done.** The new comment says what the code does. Lanes are packed per week, the longest clipped bar goes first, and ties go to the earlier column and then the name. It also says a long event does not keep its lane across week rows. That matches the one criterion and the one task.

This finding disproves no criterion.

VERDICT: sound

**breakage: sound**

I tried to break card 0022 and could not.

**The sort matches the new comment.** In `SocialController::gridWeeks()`, the `usort` compares `[$b['span'], $a['col'], $a['name']]` against `[$a['span'], $b['col'], $b['name']]`.
- Span is compared in reverse, so the longest bar comes first.
- Column is compared in normal order, so on a tie the earlier column wins.
- Name is compared in normal order, so on a second tie the name decides.

**Lanes are packed for each week on its own.** The `$occupied` array is reset to empty inside the week loop, and `$segments` holds bars already cut to that week. So "packed afresh for each week" is true. It is also true that a long event is not promised the same lane in each week row.

**Other comments stay true.** The docblock of `gridWeeks()` says "packed into a fixed number of lanes, longest first". That is still correct, and it makes no promise about lanes across weeks.

**Nothing else changed in how it works.** The card changed only a comment in this function. The tooltip line nearby belongs to card 0021 and is outside my lens.

No criterion is disproved.

VERDICT: sound

