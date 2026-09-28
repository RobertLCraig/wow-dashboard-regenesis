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
