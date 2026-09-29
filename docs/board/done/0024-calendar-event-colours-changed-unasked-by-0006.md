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

### 2026-09-29 review (v20260929021455-6413)

**suite**

`vendor\bin\pest.bat` exited 0 after 77s, run by this job rather than reported by the card.

**acceptance: sound**

I found no fault. The one criterion holds.

**#1: the old colours are back.** This holds.

- **The code:** the `$eventToneClasses` map is in the `@if ($view === 'grid')` branch of `resources/views/dashboard/social.blade.php`. It now holds exactly the three old values:
  - `bg-sky-900/40 text-sky-200 border-sky-800/60`
  - `bg-violet-900/40 text-violet-200 border-violet-800/60`
  - `bg-amber-900/40 text-amber-200 border-amber-800/60`
- **The bars use this map.** Each bar looks up its tone in `$eventToneClasses`.
- **The list view did not change.** The `$kindBadge` tones stay as they were. The card fences those out.
- **The proof exists.** The card asks for measured contrast ratios. The Comments give a table of them, and the lowest is 9.05:1. The pass line (AA) is 4.5:1.
- **A test checks it.** The test `draws event bars in the tones they had before the bars rewrite, each clearing AA over every day cell` is in `tests/Feature/SocialPageTest.php`. It reads the colour classes off each drawn bar. It lays each bar over each background and computes the ratio. It uses the Tailwind v3 colour values and the layout's own background colours. It fails if any ratio is below 4.5.

**Limit:** nobody has checked the page in a browser. That does not disprove the criterion.

VERDICT: sound

**scope: sound**

I reviewed card 0024 through the scope lens. I found no scope problem.

- **Only this card's work is in its commit.** Commit `91890ea` changes three files: `resources/views/dashboard/social.blade.php`, `tests/Feature/SocialPageTest.php`, and the card itself. The card's TOUCHED list names the same three files.
- **The colour change is the three tone lines only.** In the grid branch of `social.blade.php`, `$eventToneClasses` now holds exactly the three values the card asks for. Nothing else in the view changed.
- **The fence holds.** Bar shape, lanes, `$edges`, the tooltip (0021) and the lane comment (0022) did not change. The list view's `$kindBadge` still has its own tones (`text-sky-300` and `text-violet-300`), so the build did not touch it.
- **The test stays in scope.** The new test in `SocialPageTest.php` checks the contrast and the old map. It adds no other behaviour.
- **The large diff is other cards.** The BiS source work (0025), the migration and the board moves did not come from this commit.
- **Nothing is half done.** The card owes a browser check, and the builder says so. That check is not a criterion.

My findings disprove no criterion.

VERDICT: sound

**breakage: sound**

I tried to break the card 0024 change, and I could not find a break.

- **The colours:** `$eventToneClasses` in the grid branch of `resources/views/dashboard/social.blade.php` now has the three old values again. They match the card word for word.
- **Other code that uses the map:** The bar loop in the same file uses the map. It has a fallback for a missing tone. The tone keys are still `sky`, `violet` and `amber`, so the fallback does not get used by mistake.
- **Old tests:** I searched `tests/` for the newer `/60` tone values. No test uses them. So the revert fails no old test.
- **The list view:** The `$kindBadge` tones in the list view did not change. They still use shade 300, as before. The card puts them out of scope.
- **Comments:** No comment near the map talks about the colours, so no comment is now wrong.
- **What is still not checked:** Nobody has looked at the page in a browser. The contrast numbers come from the test and use Tailwind v3 colour values. That matches the CDN script the layout loads. This gap does not disprove the criterion.

VERDICT: sound

