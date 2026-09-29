# The Social calendar's event colours were changed by a card that did not ask for it

## Why
Card 0006's first build (commit `23be726`) rewrote `$eventToneClasses` in the grid branch of
`resources/views/dashboard/social.blade.php`: all three tones moved shade and opacity, for example
`bg-sky-900/40 text-sky-200 border-sky-800/60` became `bg-sky-900/60 text-sky-100 border-sky-700/70`.
0006 asked for bar shape, not a repaint, and no comment on it gives a reason. Two reviews of 0006
flagged it as over the fence, and it was never reverted or carried, so it sits on `main` as an
unexplained design change.

## Links

**Relates to**
- `0006` - the multi-day bars rewrite that made the change.

## Not this card
Bar shape, lanes, the tooltip (`0021`) or the lane comment (`0022`). The list view's `$kindBadge`
tones, which 0006 did not touch.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE `$eventToneClasses` map in the grid branch of `social.blade.php` SHALL hold the values
      it had before `23be726`: `bg-sky-900/40 text-sky-200 border-sky-800/60`,
      `bg-violet-900/40 text-violet-200 border-violet-800/60`,
      `bg-amber-900/40 text-amber-200 border-amber-800/60`; UNLESS those fail the AA text ratio on
      a bar drawn over the day-cell backdrop, in which case the current values SHALL stay and this
      card's Comments SHALL record the measured ratios that justify them.
      proves: the ratio of each tone's text over its bar, computed from the Tailwind palette values
      composited over the page ground and recorded in Comments
<!-- AC:END -->

## Tasks
- [x] Measure text-on-bar contrast for the three pre-`23be726` tones as bars now render
- [x] Revert to them if they clear AA, otherwise keep the current tones and record the numbers

## Plan
`git show 23be726^:resources/views/dashboard/social.blade.php` holds the old map. The bars now sit
over a day-cell backdrop rather than inside a cell, so the ground under the text may differ from
what the old chips had; that is the only reason the new values could be needed, and the one to check.

## Comments

**2026-09-29** Raised by an attended unblock pass from 0006's 2026-09-28 scope review, which said
the next session must either revert the colours or give them their own card with a reason.

**2026-09-29** RESULT: done
TESTS: +1 new, all green
TOUCHED: resources/views/dashboard/social.blade.php
TOUCHED: tests/Feature/SocialPageTest.php
TOUCHED: docs/board/in-progress/0024-calendar-event-colours-changed-unasked-by-0006.md
OUT-OF-SCOPE: none

Reverted `$eventToneClasses` to the pre-`23be726` values. The old tones clear AA everywhere, so the
revert holds. New test `draws event bars in the tones they had before the bars rewrite, each clearing
AA over every day cell` renders one week with all three tones, reads the classes off each bar,
composites the bar over each ground and asserts 4.5:1, then asserts the old map. Watched it fail
first on the map (it rendered `/60 ... -100 ... -700/70`); the ratio half passed on both sets.

Measured ratios, text over bar over ground (old /40 + shade 200, then current /60 + shade 100):

| tone | panel | today, discord | today, phoenix | outside window |
|---|---|---|---|---|
| sky | 11.07 / 11.17 | 9.05 / 9.71 | 9.54 / 10.10 | 10.80 / 10.97 |
| violet | 11.24 / 11.84 | 9.28 / 10.38 | 9.64 / 10.64 | 10.98 / 11.65 |
| amber | 11.74 / 11.35 | 9.70 / 9.97 | 9.90 / 10.06 | 11.46 / 11.16 |

Lowest old value is 9.05, twice the AA line. Assumptions: the page's colours come from the layout's
`cdn.tailwindcss.com` script (Tailwind v3), so the test uses v3 hex, not the v4 oklch in
`node_modules`; grounds are `panel #15151f`, and `accent/10` and `bg/50` composited over the week's
`bg-line #252533`. The bar's own border is not measured, because it carries no text. A browser check
is still owed: this worktree is not what Herd serves.
